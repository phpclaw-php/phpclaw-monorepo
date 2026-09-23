<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\PsIdentityResolver;
use PhpClaw\PrestaShop\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that runs read-only SELECT queries on the store database, restricted to
 * SuperAdmin employees since arbitrary SQL cannot be expressed as a per-tab grant.
 */
final class DatabaseTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract {
        runningInConsole as private consoleMarkerIsSet;
    }

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_OUTPUT_BYTES = ToolOutputEncoder::MAX_OUTPUT_BYTES;

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    private const BLOCKED_TABLES = ['configuration', 'phpclaw_conversations', 'phpclaw_messages'];

    private const RESTRICTED_IDENTIFIERS = [
        'passwd', 'password', 'secure_key', 'secret', 'private_key',
        'api_key', 'api_token', 'access_token', 'auth_key',
        'salt',
        'wholesale_price', 'product_supplier_price_te',
    ];

    /**
     * Create a new DatabaseTool instance.
     *
     * @param  PsDbInterface|null  $db  Native PrestaShop DB handle.
     * @param  string  $tablePrefix  Table prefix used by this installation.
     * @param  bool  $isConsole  True only when an interactive console entrypoint says so.
     */
    public function __construct(
        private readonly ?PsDbInterface $db,
        private readonly string $tablePrefix = 'ps_',
        private readonly bool $isConsole = false,
    ) {}

    /**
     * Return the canonical tool name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'database';
    }

    /**
     * Return a rich description including blocked keywords, capabilities, and examples.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<DESC
        EXECUTE a read-only SQL SELECT against the PrestaShop database and return real rows.

        Table prefix: {$this->tablePrefix}

        BLOCKED keywords (word-boundary): INSERT, UPDATE, DELETE, DROP, TRUNCATE, ALTER, GRANT, REVOKE, UNION, EXCEPT, INTERSECT, WITH, CREATE, REPLACE, RENAME, CALL, EXEC, EXECUTE, LOAD_FILE, INTO OUTFILE, INTO DUMPFILE.

        CAPABILITIES:
        - Run any SELECT query with optional positional ? bindings
        - Schema mode (schema: true): returns all tables with row counts
        - Auto-LIMIT 200 appended when no LIMIT clause is present
        - Output hard-capped at 8 KB

        EXAMPLES:
        {"sql": "SELECT * FROM {$this->tablePrefix}orders ORDER BY date_add DESC"}
        {"sql": "SELECT id_order, total_paid, date_add FROM {$this->tablePrefix}orders WHERE date_add >= ?", "bindings": ["2026-01-01"]}
        {"schema": true}
        {"sql": "SELECT id_product, price FROM {$this->tablePrefix}product", "limit": 50}

        Invoke. Never describe SQL, run it.
        DESC;
    }

    /**
     * Return the JSON Schema for accepted input parameters.
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
                    'description' => 'The SQL SELECT query. Use table prefix '
                        .$this->tablePrefix
                        .' (e.g. '.$this->tablePrefix.'orders). '
                        .'Omit when using schema mode.',
                ],
                'bindings' => [
                    'type' => 'array',
                    'description' => 'Optional positional ? bindings for the query.',
                    'items' => ['type' => 'string'],
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'When true, return a list of all tables with row counts (ignores sql).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Override the auto-LIMIT value (1–500, default 200). '
                        .'Only applied when the query has no LIMIT clause.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Return the back-office tab grant required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Whether this tool may be offered to the model. PrestaShop evaluates employee permissions when the tool runs, so every tool stays eligible for routing.
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
            examples: ['run a select against the orders table'],
        );
    }

    /**
     * Report whether this request runs through the console. This tool is also handed the flag
     * by the entry point that built it, so both console signals gate it identically.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return $this->isConsole || $this->consoleMarkerIsSet();
    }

    /**
     * Plan the execution: authorise first, then validate the SQL, before any query runs.
     *
     * @param  array<string, mixed>  $input  Runtime input.
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
            throw new ToolException('database: no database connection available.');
        }

        if (! empty($input['schema'])) {
            return ['input' => ['schema' => true], 'result' => null];
        }

        return [
            'input' => [
                'schema' => false,
                'sql' => $this->prepareSql($input),
                'bindings' => (array) ($input['bindings'] ?? []),
            ],
            'result' => null,
        ];
    }

    /**
     * Run the planned read against the store database.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if ($input['schema'] === true) {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        return [
            'type' => 'query',
            'payload' => $this->queryData((string) $input['sql'], (array) $input['bindings']),
        ];
    }

    /**
     * Verify the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['payload']['rows'] ?? null)) {
            throw new ToolException('database: query returned an incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Complete a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $payload = $execution['payload'];
        $mode = $execution['type'] === 'schema' ? 'schema' : 'query';

        return $this->success(
            ['rows' => $payload['rows']],
            [
                'mode' => $mode,
                'total' => $payload['total'],
                'shown' => $payload['shown'],
                'truncated' => $payload['truncated'],
                'max_output_bytes' => self::MAX_OUTPUT_BYTES,
            ],
            ToolOutputEncoder::warnings($payload),
        );
    }

    /**
     * Run the validated SELECT and cap the rows at the output budget.
     *
     * @param  string  $sql  Validated read-only SQL.
     * @param  array<int, mixed>  $bindings  Positional bindings.
     * @return array{rows: array<int, mixed>, total: int, shown: int, truncated: bool}
     *
     * @throws ToolException When the query fails.
     */
    private function queryData(string $sql, array $bindings): array
    {
        try {
            $results = $this->db->query($sql, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw DatabaseTool: '.$e->getMessage(), 3);
            throw new ToolException('database: query execution failed.', previous: $e);
        }

        return ToolOutputEncoder::cap($results, self::MAX_ROW_BYTES);
    }

    /**
     * Return all PrestaShop tables with row counts via SHOW TABLE STATUS.
     *
     * @return array{rows: array<int, mixed>, total: int, shown: int, truncated: bool}
     *
     * @throws ToolException When the schema query fails.
     */
    private function schemaData(): array
    {
        try {
            $rows = $this->db->query('SHOW TABLE STATUS')->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw DatabaseTool: '.$e->getMessage(), 3);
            throw new ToolException('database: schema query failed.', previous: $e);
        }

        $tables = [];

        foreach ($rows as $row) {
            $name = $row['Name'] ?? '';

            if ($name === '') {
                continue;
            }

            $tables[] = [
                'table' => $name,
                'rows' => (int) ($row['Rows'] ?? 0),
                'engine' => $row['Engine'] ?? '',
                'size_kb' => round(
                    ((int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0)) / 1024,
                    1,
                ),
            ];
        }

        return ToolOutputEncoder::cap($tables, self::MAX_ROW_BYTES);
    }

    /**
     * Validate the requested SQL and return the read-only statement that will be run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string
     *
     * @throws ToolException When the SQL is missing, not read-only, or references a secret.
     */
    private function prepareSql(array $input): string
    {
        $sql = trim((string) ($input['sql'] ?? ($input['query'] ?? '')));
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);

        if ($sql === '') {
            throw new ToolException("database: 'sql' input is required (or set schema: true).");
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::MySql))->validate($sql);
        } catch (SqlGuardException $e) {
            throw new ToolException($e->getMessage(), previous: $e);
        }

        $this->validateBindings((array) ($input['bindings'] ?? []));
        $this->assertNoRestrictedIdentifier($safe->sql());

        if (! $this->hasLimitClause($sql)) {
            $safe = $safe->withLimit($limit);
        }

        return $safe->sql();
    }

    /**
     * Refuse a caller who is not a SuperAdmin employee. The console path is exempt.
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

        if (PsIdentityResolver::manageAll()) {
            return;
        }

        throw new ToolException(
            'database: running raw SQL requires a PrestaShop SuperAdmin employee. '
            .'The acting identity is not one.',
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
                        'database: the query references the "'.$name.'" table, which holds phpClaw '
                        .'conversations scoped to their owner and cannot be read as raw SQL. Ask for '
                        .'your own conversation history instead.',
                    );
                }

                throw new ToolException(
                    'database: the query references the "'.$name.'" table, which holds module '
                    .'settings and credentials and cannot be read as raw SQL. The ps_config tool is '
                    .'the one that answers questions about configuration.',
                );
            }
        }

        foreach (self::RESTRICTED_IDENTIFIERS as $identifier) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($identifier, '/').'(?![A-Za-z0-9])/i', $sql) === 1) {
                throw new ToolException('database: query references a restricted table or column and was blocked.');
            }
        }
    }

    /**
     * Validate that all bindings are scalar or null.
     *
     * @param  array<int, mixed>  $bindings  Positional bindings supplied by the caller.
     * @return void
     *
     * @throws ToolException When a binding is not scalar or null.
     */
    private function validateBindings(array $bindings): void
    {
        foreach ($bindings as $i => $binding) {
            if (! is_scalar($binding) && $binding !== null) {
                throw new ToolException(
                    "database: binding at index {$i} must be scalar.",
                );
            }
        }
    }

    /**
     * Check whether the SQL already contains a LIMIT clause (word-boundary).
     *
     * @param  string  $sql  The SQL as the caller wrote it.
     * @return bool
     */
    private function hasLimitClause(string $sql): bool
    {
        return preg_match('/\bLIMIT\b/i', $sql) === 1;
    }
}
