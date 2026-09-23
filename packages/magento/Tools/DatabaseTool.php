<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Service\JsonToolResult;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that executes read-only SELECT queries via ResourceConnection, bounded by the read-only guard and the blocked-table list.
 */
// non-final: Magento interceptor required
class DatabaseTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;
    use JsonToolResult;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const BLOCKED_TABLES = ['core_config_data', 'phpclaw_conversations', 'phpclaw_messages'];

    private const RESTRICTED_IDENTIFIERS = [
        'password', 'passwd', 'secret', 'private_key',
        'api_key', 'api_token', 'access_token', 'auth_key',
        'salt', 'secure_key',
    ];

    /**
     * Create a new DatabaseTool instance.
     *
     * @param  ResourceConnection  $resourceConnection  Magento DB connection provider.
     * @param  IdentityResolver  $identity  Acting admin identity and its ACL grants.
     * @param  AuthorizationInterface  $acl  Magento authorization service.
     * @return void
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IdentityResolver $identity,
        private readonly AuthorizationInterface $acl,
    ) {}

    /**
     * Return the ACL resource a caller must hold to run raw SQL.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Whether this tool may be offered to the model. Magento evaluates ACL when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['database', 'data'],
            tags: ['sql', 'select', 'query', 'row', 'rows', 'table', 'tables', 'column', 'columns', 'count', 'database'],
            intents: ['run sql', 'query the database', 'list rows from a table'],
            examples: ['run a select against the sales_order table'],
        );
    }

    /**
     * Return the identity resolver the contract trait reads the area from.
     *
     * @return IdentityResolver
     */
    protected function identity(): IdentityResolver
    {
        return $this->identity;
    }

    /**
     * Return the ACL service the contract trait checks capabilities against.
     *
     * @return AuthorizationInterface
     */
    protected function authorization(): AuthorizationInterface
    {
        return $this->acl;
    }

    /**
     * Returns the tool identifier used in agent tool dispatch.
     *
     * @return string
     */
    public function name(): string
    {
        return 'db_query';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'EXECUTE a read-only SQL SELECT against the Magento database (ResourceConnection) and return real rows. Use for orders, catalog, customers, config, custom tables. Invoke it: never describe SQL, run it.';
    }

    /**
     * Returns the JSON Schema object describing accepted inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The SQL SELECT query to run',
                ],
                'bindings' => [
                    'type' => 'array',
                    'description' => 'Optional positional bindings for the query',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Guard the caller, validate the query and apply the read-only SQL guard.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('run a raw SQL query');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['query', 'bindings']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            return [
                'input' => $input,
                'result' => $this->error('MISSING_ARGUMENT', 'db_query: "query" input is required.'),
            ];
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::MySql))->validate($query);
        } catch (SqlGuardException $e) {
            return [
                'input' => $input,
                'result' => $this->error('SQL_REJECTED', $e->getMessage()),
            ];
        }

        $bindings = (array) ($input['bindings'] ?? []);

        foreach ($bindings as $i => $binding) {
            if (! is_scalar($binding) && $binding !== null) {
                return [
                    'input' => $input,
                    'result' => $this->error(
                        'INVALID_ARGUMENT',
                        "db_query: binding at index {$i} must be scalar (string/int/float/null).",
                    ),
                ];
            }
        }

        $restricted = $this->restrictedIdentifier($safe->sql());

        if ($restricted !== null) {
            return [
                'input' => $input,
                'result' => $this->error('RESTRICTED_IDENTIFIER', $restricted),
            ];
        }

        if (preg_match('/\bLIMIT\s+\d+\b/i', $safe->sql()) !== 1) {
            $safe = $safe->withLimit(500);
        }

        $input['__sql'] = $safe->sql();
        $input['__bindings'] = $bindings;

        return ['input' => $input, 'result' => null];
    }

    /**
     * Execute the validated read-only query.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the query fails at the database.
     */
    protected function perform(array $input): array
    {
        try {
            $rows = $this->resourceConnection->getConnection()->fetchAll(
                (string) $input['__sql'],
                (array) $input['__bindings'],
            );
        } catch (\Throwable $e) {
            throw new ToolException('db_query execution failed.', previous: $e);
        }

        return ['rows' => $rows, 'sql' => (string) $input['__sql']];
    }

    /**
     * Confirm the execution produced a row list.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is structurally incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['rows'] ?? null)) {
            throw new ToolException('db_query: incomplete query result.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified rows into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $capped = $this->capRows($execution['rows']);

        return $this->success(
            ['rows' => $capped['rows']],
            [
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }

    /**
     * Report why a query is blocked when it references a restricted table or credential column.
     *
     * @param  string  $sql  The validated read-only SQL string.
     * @return string|null Explanation when the SQL is blocked, or null when it is allowed.
     */
    private function restrictedIdentifier(string $sql): ?string
    {
        foreach (self::BLOCKED_TABLES as $table) {
            $name = (string) $this->resourceConnection->getTableName($table);

            if ($name === '') {
                $name = $table;
            }

            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/i', $sql) === 1) {
                if (str_contains(strtolower($table), 'phpclaw_')) {
                    return 'db_query: the query references the "'.$name.'" table, which holds phpClaw '
                        .'conversations scoped to their owner and cannot be read as raw SQL. Ask for '
                        .'your own conversation history instead.';
                }

                return 'db_query: the query references the "'.$name.'" table, which holds module '
                    .'configuration and credentials and cannot be read as raw SQL. Magento ships no '
                    .'phpClaw tool that reads it, so this data is not available through phpClaw.';
            }
        }

        foreach (self::RESTRICTED_IDENTIFIERS as $identifier) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($identifier, '/').'(?![A-Za-z0-9])/i', $sql) === 1) {
                return 'db_query: query references a restricted table or column and was blocked.';
            }
        }

        return null;
    }
}
