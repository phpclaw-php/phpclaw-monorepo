<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\OcTablePrefix;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Database query tool: read-only SELECT via OC's native DB wrapper.
 */
final class DatabaseTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const AUTO_LIMIT = 200;

    private const MAX_LIMIT = 1000;

    private const BLOCKED_TABLES = ['setting', 'phpclaw_conversations', 'phpclaw_messages'];

    private const RESTRICTED_IDENTIFIERS = [
        'password', 'passwd', 'salt', 'secret', 'private_key',
        'api_key', 'api_token', 'access_token', 'secure_key',
        'auth_key',
    ];

    private readonly string $tablePrefix;

    /**
     * Bind the database handle, the table prefix, and the module and raw-SQL grants.
     *
     * @param  OcDbInterface|null  $db  OpenCart native DB instance, or null when DB is unavailable.
     * @param  string|null  $tablePrefix  OpenCart table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @param  bool  $mayQueryRaw  Whether the caller holds the grant that permits raw SQL.
     */
    public function __construct(
        private readonly ?OcDbInterface $db,
        ?string $tablePrefix,
        private readonly bool $callerMayUseModule,
        private readonly bool $mayQueryRaw,
    ) {
        $this->tablePrefix = OcTablePrefix::resolve($tablePrefix);
    }

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'db_query';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Execute a read-only SQL SELECT query against the OpenCart database and return real rows. '
             .'Use for orders, products, customers, categories, settings, custom tables. '
             .'Table prefix: '.$this->tablePrefix.'. '
             .'Pass schema=true to list all tables with row counts. '
             .'BLOCKED: INSERT, UPDATE, DELETE, DROP, UNION, WITH, EXCEPT, TRUNCATE, ALTER, GRANT, REVOKE. '
             .'Auto-appends LIMIT 200 if none present. '
             .'EXAMPLES: {"sql":"SELECT * FROM '.$this->tablePrefix.'order WHERE order_status_id = ?","bindings":["5"]}, '
             .'{"schema":true}, '
             .'{"sql":"SELECT COUNT(*) AS total FROM '.$this->tablePrefix.'customer"}.';
    }

    /**
     * Return the JSON Schema describing the tool's accepted input parameters.
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
                    'description' => 'The SQL SELECT query to execute. Only SELECT is allowed. '
                                   .'Use ? placeholders for bindings. Table prefix: '.$this->tablePrefix
                                   .' (e.g. '.$this->tablePrefix.'order, '.$this->tablePrefix.'product).',
                ],
                'bindings' => [
                    'type' => 'array',
                    'description' => 'Optional positional ? bindings for the query.',
                    'items' => ['type' => ['string', 'integer', 'number', 'boolean', 'null']],
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to return a list of all OpenCart tables with row counts via SHOW TABLE STATUS. '
                                   .'When true, the sql parameter is ignored.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Override the auto-limit (default 200). Max 1000.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Return the action a caller must hold to reach this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_ACTION;
    }

    /**
     * Authorise the caller, then validate the query before any statement runs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the caller may not run raw SQL, or the SQL is unusable.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('run read-only SQL against the store database');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $this->assertCallerMayQuery();

        if ($this->db === null) {
            throw new ToolException('db_query: no database connection available.');
        }

        if (! empty($input['schema'])) {
            return ['input' => ['schema' => true], 'result' => null];
        }

        $sql = trim((string) ($input['sql'] ?? ''));
        $bindings = (array) ($input['bindings'] ?? []);

        if ($sql === '') {
            throw new ToolException("db_query: 'sql' parameter is required (or pass schema=true).");
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::MySql))->validate($sql);
        } catch (SqlGuardException $e) {
            throw new ToolException($e->getMessage());
        }

        foreach ($bindings as $i => $binding) {
            if (! is_scalar($binding) && $binding !== null) {
                throw new ToolException(
                    "db_query: binding at index {$i} must be scalar (string/int/float/null).",
                );
            }
        }

        $this->assertNoRestrictedIdentifier($safe->sql());

        if (! preg_match('/\bLIMIT\b/i', $safe->sql())) {
            $limit = self::AUTO_LIMIT;

            if (isset($input['limit']) && is_numeric($input['limit']) && (int) $input['limit'] > 0) {
                $limit = min((int) $input['limit'], self::MAX_LIMIT);
            }

            $safe = $safe->withLimit($limit);
        }

        return [
            'input' => ['schema' => false, 'sql' => $safe->sql(), 'bindings' => $bindings],
            'result' => null,
        ];
    }

    /**
     * Run the planned query, or the schema listing.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => ['tables' => $this->schemaRows()]];
        }

        if ($this->db === null) {
            throw new ToolException('db_query: no database connection available.');
        }

        try {
            $result = $this->db->query((string) $input['sql'], (array) $input['bindings']);
            $rows = is_array($result->rows ?? null) ? $result->rows : [];
        } catch (\Throwable $e) {
            throw new ToolException('database query: operation failed.', previous: $e);
        }

        return ['type' => 'query', 'payload' => $this->capRows($rows)];
    }

    /**
     * Cap a row set at the output byte budget, reporting what was kept.
     *
     * @param  list<array<string, mixed>>  $rows  Raw result rows.
     * @return array<string, mixed>
     */
    private function capRows(array $rows): array
    {
        $kept = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > ToolOutputEncoder::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $row;
            $bytes += strlen($encoded);
        }

        return [
            'rows' => $kept,
            'total' => count($rows),
            'shown' => count($kept),
            'truncated' => count($kept) < count($rows),
        ];
    }

    /**
     * Check the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        $key = $execution['type'] === 'schema' ? 'tables' : 'rows';

        if (! is_array($execution['payload'][$key] ?? null)) {
            throw new ToolException('db_query: the query returned an incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Convert a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        if ($execution['type'] === 'schema') {
            $tables = $execution['payload']['tables'];

            return $this->success(
                ['tables' => $tables],
                ['mode' => 'schema', 'count' => count($tables)],
            );
        }

        $payload = $execution['payload'];

        return $this->success(
            ['rows' => $payload['rows']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'shown' => $payload['shown'],
                'truncated' => $payload['truncated'],
                'max_output_bytes' => ToolOutputEncoder::MAX_OUTPUT_BYTES,
            ],
        );
    }

    /**
     * Reject queries that reference credential identifiers so secrets never reach the model.
     *
     * @param  string  $sql  The validated read-only SQL string.
     * @return void
     *
     * @throws ToolException When the SQL references a restricted table or column name.
     */
    private function assertNoRestrictedIdentifier(string $sql): void
    {
        foreach (self::BLOCKED_TABLES as $table) {
            $name = $this->tablePrefix.$table;

            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/i', $sql) === 1) {
                if (str_contains(strtolower($table), 'phpclaw_')) {
                    throw new ToolException(
                        'db_query: the query references the "'.$name.'" table, which holds phpClaw '
                        .'conversations scoped to their owner and cannot be read as raw SQL. Ask for '
                        .'your own conversation history instead.',
                    );
                }

                throw new ToolException(
                    'db_query: the query references the "'.$name.'" table, which holds extension '
                    .'settings and credentials and cannot be read as raw SQL. OpenCart ships no '
                    .'tool that reads it, so this data is not available through phpClaw.',
                );
            }
        }

        foreach (self::RESTRICTED_IDENTIFIERS as $identifier) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($identifier, '/').'(?![A-Za-z0-9])/i', $sql) === 1) {
                throw new ToolException('db_query: query references a restricted table or column and was blocked.');
            }
        }
    }

    /**
     * Refuse a caller who does not hold the raw-SQL grant. The console path is exempt, and
     * the decision is a required constructor argument because a tool sees no OpenCart identity.
     *
     * @return void
     *
     * @throws ToolException When the caller is not entitled to run raw SQL.
     */
    private function assertCallerMayQuery(): void
    {
        if ($this->runningInConsole()) {
            return;
        }

        if ($this->mayQueryRaw) {
            return;
        }

        throw new ToolException(
            'db_query: running raw SQL requires the phpClaw manage-all permission for this '
            .'user group. The acting user does not hold it.',
        );
    }

    /**
     * List every table with its row count and engine via SHOW TABLE STATUS.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ToolException On execution failure.
     */
    private function schemaRows(): array
    {
        if ($this->db === null) {
            throw new ToolException('db_query: no database connection available.');
        }

        try {
            $queryResult = $this->db->query('SHOW TABLE STATUS');
            $tables = is_array($queryResult->rows ?? null) ? $queryResult->rows : [];
        } catch (\Throwable $e) {
            throw new ToolException('database query: operation failed.', previous: $e);
        }

        $result = [];

        foreach ($tables as $table) {
            $result[] = [
                'table' => $table['Name'] ?? '',
                'rows' => (int) ($table['Rows'] ?? 0),
                'engine' => $table['Engine'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Whether this tool may be offered to the model. OpenCart evaluates module access when the tool runs, so every tool stays eligible for routing.
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
            examples: ['run a select against the order table'],
        );
    }
}
