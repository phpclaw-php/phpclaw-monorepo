<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tools;

use Doctrine\DBAL\Connection;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * ToolInterface implementation that executes read-only SELECT queries via Doctrine DBAL.
 */
final class DatabaseTool extends AbstractSymfonyTool
{
    private const MAX_ROWS = 100;

    private const DEFAULT_LIMIT = 20;

    private const ALLOWED_KEYS = ['sql', 'limit'];

    private const BLOCKED_TABLES = ['phpclaw_conversations', 'phpclaw_messages'];

    private const RESTRICTED_IDENTIFIERS = [
        'password', 'passwd', 'secret', 'private_key',
        'api_key', 'api_token', 'access_token', 'auth_key',
        'salt', 'secure_key',
    ];

    /**
     * Bind the DBAL connection and base-class context for this tool.
     *
     * @param  Connection|null  $connection  Doctrine DBAL connection, or null when resolved lazily.
     * @param  ConsoleContext|null  $console  Marks an interactive console run; null is not console.
     * @param  SymfonyIdentityResolver|null  $identity  Names the acting user; null denies every caller.
     * @param  bool  $requireChatRole  True when the host app requires ROLE_PHPCLAW_CHAT.
     * @return void
     */
    public function __construct(
        private readonly ?Connection $connection = null,
        ?ConsoleContext $console = null,
        ?SymfonyIdentityResolver $identity = null,
        bool $requireChatRole = false,
    ) {
        parent::__construct(
            console: $console,
            identity: $identity,
            requireChatRole: $requireChatRole,
        );
    }

    /**
     * Returns the tool name registered with ToolRegistry.
     *
     * @return string
     */
    public function name(): string
    {
        return 'db_query';
    }

    /**
     * Returns the tool description surfaced to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'EXECUTE a read-only SQL SELECT against the live Symfony/Doctrine DB and return real rows. '
             .'Use for tables, row counts, schema, data. Examples: list tables, count users, show last orders. '
             .'Invoke it, never describe SQL.';
    }

    /**
     * Returns the JSON Schema describing accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'A valid SQL SELECT statement. No INSERT/UPDATE/DELETE/DROP permitted.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum rows to return (1-100). Default: 20.',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_ROWS,
                ],
            ],
            'required' => ['sql'],
        ];
    }

    /**
     * Returns the platform role required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return SymfonyIdentityResolver::MANAGE_ALL_ROLE;
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
            tags: ['sql', 'select', 'query', 'row', 'rows', 'table', 'tables', 'column', 'columns', 'count', 'database', 'doctrine'],
            intents: ['run sql', 'query the database', 'list rows from a table'],
            examples: ['run a select against the user table'],
        );
    }

    /**
     * Guard the caller, validate input, and prepare the safe SQL before any query runs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('run a raw SQL query');
        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);
        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        if (array_key_exists('sql', $input) && ! is_string($input['sql'])) {
            return ['input' => $input, 'result' => $this->error('INVALID_ARGUMENT', '"sql" is required and must be a non-empty string.')];
        }

        if (array_key_exists('limit', $input) && ! is_int($input['limit'])) {
            return ['input' => $input, 'result' => $this->error('INVALID_ARGUMENT', '"limit" must be an integer between 1 and 100.')];
        }

        $sql = trim((string) ($input['sql'] ?? ''));
        if ($sql === '') {
            return ['input' => $input, 'result' => $this->error('INVALID_ARGUMENT', '"sql" is required and must be a non-empty string.')];
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::Generic))->validate($sql);
        } catch (SqlGuardException $e) {
            return ['input' => $input, 'result' => $this->error('BLOCKED_STATEMENT', $e->getMessage())];
        }

        try {
            $this->assertNoBlockedTable($safe->sql());
        } catch (ToolException $e) {
            return ['input' => $input, 'result' => $this->error('BLOCKED_IDENTIFIER', $e->getMessage(), ['blocked_tables' => self::BLOCKED_TABLES])];
        }

        try {
            $this->assertNoRestrictedIdentifier($safe->sql());
        } catch (ToolException) {
            return ['input' => $input, 'result' => $this->error('BLOCKED_IDENTIFIER', 'The query references a restricted table or column and was blocked.', ['restricted_identifiers' => self::RESTRICTED_IDENTIFIERS])];
        }

        $limit = min(max((int) ($input['limit'] ?? self::DEFAULT_LIMIT), 1), self::MAX_ROWS);
        if (! preg_match('/\bLIMIT\b/i', $sql)) {
            $safe = $safe->withLimit($limit);
        }

        $input['_safe_sql'] = $safe->sql();

        return ['input' => $input, 'result' => null];
    }

    /**
     * Execute the planned SELECT, streaming rows and stopping at MAX_ROWS so a statement whose own
     * LIMIT clause was not detected can never be materialised in full.
     *
     * @param  array<string, mixed>  $input  Validated runtime input including the prepared SQL.
     * @return array{rows: array<int, array<string, mixed>>, total: int, truncated: bool, capped: bool}
     *
     * @throws ToolException When the connection is absent or the query execution fails.
     */
    protected function perform(array $input): array
    {
        $conn = $this->resolveConnection();

        try {
            $rows = [];
            $exceededCap = false;

            foreach ($conn->iterateAssociative((string) $input['_safe_sql']) as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    $exceededCap = true;
                    break;
                }

                $rows[] = $row;
            }
        } catch (\Throwable $e) {
            throw new ToolException('Query execution failed.', previous: $e);
        }

        $total = count($rows);
        $truncated = false;

        while ($rows !== []) {
            $encoded = json_encode($rows, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) <= self::MAX_OUTPUT_BYTES) {
                break;
            }
            array_pop($rows);
            $truncated = true;
        }

        return ['rows' => $rows, 'total' => $total, 'truncated' => $truncated, 'capped' => $exceededCap];
    }

    /**
     * Verify that the execution produced a usable row set before completing.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is not a valid array.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['rows'])) {
            throw new ToolException('db_query returned an unexpected result structure.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $rows = $execution['rows'];
        $warnings = [];

        $meta = [
            'mode' => 'query',
            'count' => count($rows),
            'total' => $execution['total'],
            'truncated' => $execution['truncated'] || $execution['capped'],
        ];

        if ($execution['capped']) {
            $warnings[] = [
                'code' => 'ROW_CAP_REACHED',
                'message' => 'The query returned more than '.self::MAX_ROWS.' rows; reading stopped at that ceiling. Add a LIMIT or narrow the query to see a complete result.',
            ];
        }

        if ($execution['truncated']) {
            $dropped = $execution['total'] - count($rows);
            $warnings[] = [
                'code' => 'OUTPUT_TRUNCATED',
                'message' => $dropped.' rows were dropped to keep the response within the 8 KB output limit. Narrow the query or add a smaller LIMIT.',
            ];
        }

        if ($rows === []) {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => 'The query matched no rows.',
            ];
        }

        return $this->success(['rows' => $rows], $meta, $warnings);
    }

    /**
     * Return the injected DBAL connection or throws if none was provided.
     *
     * @return Connection
     *
     * @throws ToolException When no connection was injected.
     */
    private function resolveConnection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        throw new ToolException(
            'DatabaseTool requires a Doctrine DBAL Connection. '
            .'Either pass it via constructor or register doctrine/dbal.',
        );
    }

    /**
     * Rejects queries referencing a phpClaw conversation table, whose rows are owner-scoped by the
     * conversation memory driver and must never be reachable as raw SQL.
     *
     * @param  string  $sql  The validated read-only SQL string.
     * @return void
     *
     * @throws ToolException When the SQL references a blocked table.
     */
    private function assertNoBlockedTable(string $sql): void
    {
        foreach (self::BLOCKED_TABLES as $table) {
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i', $sql) === 1) {
                throw new ToolException(
                    'db_query: the query references the "'.$table.'" table, which holds phpClaw '
                    .'conversations scoped to their owner and cannot be read as raw SQL. Ask for '
                    .'your own conversation history instead.'
                );
            }
        }
    }

    /**
     * Rejects queries referencing sensitive column or table identifiers so secrets never reach the model.
     *
     * @param  string  $sql  The validated read-only SQL string.
     * @return void
     *
     * @throws ToolException When the SQL references a restricted identifier.
     */
    private function assertNoRestrictedIdentifier(string $sql): void
    {
        foreach (self::RESTRICTED_IDENTIFIERS as $identifier) {
            if (preg_match('/\b'.preg_quote($identifier, '/').'\b/i', $sql) === 1) {
                throw new ToolException('db_query: query references a restricted table or column and was blocked.');
            }
        }
    }
}
