<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\SqlGuard\SafeSql;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Database tool - read-only SELECT access through $wpdb.
 */
final class DatabaseTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const MAX_OUTPUT_BYTES = OutputByteCap::MAX_OUTPUT_BYTES;

    private const AUTO_LIMIT = 200;

    private const MAX_LIMIT = 1000;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'wordpress.database.read';

    private const RISK_LEVEL = 'read';

    private const BLOCKED_TABLES = ['options', 'phpclaw_conversations', 'phpclaw_messages'];

    private const RESTRICTED_IDENTIFIERS = [
        'user_pass', 'user_activation_key', 'session_tokens',
        'password', 'passwd', 'secret', 'private_key',
        'auth_key', 'api_key', 'api_token', 'access_token',
        'salt', 'secure_key',
    ];

    private const ALLOWED_KEYS = [
        'sql',
        'bindings',
        'schema',
        'limit',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what tables can you read?',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'how many published posts are there?',
            'arguments' => ['sql' => 'SELECT COUNT(*) AS published FROM {prefix}posts WHERE post_status = %s', 'bindings' => ['publish']],
        ],
        [
            'prompt' => 'list a few page titles straight from the database',
            'arguments' => ['sql' => 'SELECT post_title FROM {prefix}posts WHERE post_type = %s LIMIT 3', 'bindings' => ['page']],
        ],
    ];

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
     * Return the phpClaw capability identifier this tool exercises.
     *
     * @return string
     */
    public function capability(): string
    {
        return self::PHPCLAW_CAPABILITY;
    }

    /**
     * Return the WordPress capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the risk classification for this tool.
     *
     * @return string
     */
    public function risk(): string
    {
        return self::RISK_LEVEL;
    }

    /**
     * Report whether repeated identical calls produce the same result.
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * Return worked example prompts for this tool.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        global $wpdb;

        $prefix = isset($wpdb) ? (string) $wpdb->prefix : 'wp_';

        return <<<DESC
Run one read-only SQL SELECT against the WordPress database. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true   List every table with row counts. The sql argument is ignored.
  default       Run the SELECT in sql, with optional positional bindings.

NEVER USE FOR
  INSERT, UPDATE, DELETE, DROP, CREATE, ALTER, TRUNCATE, RENAME, GRANT, REVOKE,
  LOCK, CALL, EXEC, SET, LOAD, INTO OUTFILE, PREPARE, KILL or SHUTDOWN. Those are
  rejected. Queries naming credential or session columns are rejected too.
  WITH (CTEs) is allowed as a leading keyword.

NOTES
  Table prefix on this install is "{$prefix}". Prefix core tables, e.g. {$prefix}posts,
  or write {prefix} and it is resolved for you, e.g. {prefix}posts.
  Use %s, %d and %f placeholders with bindings; never interpolate values into sql.
  LIMIT 200 is appended when the query has none. Output is capped at 8 KB.
DESC;
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
            'additionalProperties' => false,
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'The SQL SELECT to run. Use %s, %d and %f placeholders for bindings.',
                    'minLength' => 1,
                ],

                'bindings' => [
                    'type' => 'array',
                    'description' => 'Positional bindings for the placeholders in sql.',
                    'items' => ['type' => ['string', 'integer', 'number', 'boolean', 'null']],
                ],

                'schema' => [
                    'type' => 'boolean',
                    'description' => 'List all tables with row counts. The sql argument is ignored.',
                    'default' => false,
                ],

                'limit' => [
                    'type' => 'integer',
                    'description' => 'Row cap appended when sql has no LIMIT of its own.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::AUTO_LIMIT,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Plan the execution by enforcing authorization, validating input, and determining the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read the database');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned database operation without handling model policy.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            throw new ToolException('DatabaseTool: $wpdb is not available.');
        }

        if (($input['schema'] ?? false) === true) {
            return [
                'type' => 'schema',
                'payload' => $this->schemaData($wpdb),
            ];
        }

        return [
            'type' => 'query',
            'payload' => $this->queryData($wpdb, $input),
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
        $key = $execution['type'] === 'schema' ? 'tables' : 'rows';

        if (! isset($execution['payload'][$key]) || ! is_array($execution['payload'][$key])) {
            throw new ToolException('DatabaseTool returned an incomplete result.');
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

        if ($execution['type'] === 'schema') {
            $warnings = [];

            foreach (['sql', 'bindings', 'limit'] as $argument) {
                if (array_key_exists($argument, $input)) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because schema mode lists tables only.',
                            $argument,
                        ),
                    ];
                }
            }

            return $this->success(
                ['tables' => $payload['tables']],
                [
                    'mode' => 'schema',
                    'count' => count($payload['tables']),
                ],
                $warnings,
            );
        }

        $warnings = [];

        if ($payload['truncated']) {
            $warnings[] = [
                'code' => 'OUTPUT_TRUNCATED',
                'message' => sprintf(
                    'Output was capped at %d bytes. %d of %d rows are included.',
                    self::MAX_OUTPUT_BYTES,
                    count($payload['rows']),
                    $payload['total'],
                ),
            ];
        }

        return $this->success(
            ['rows' => $payload['rows']],
            [
                'mode' => 'query',
                'count' => count($payload['rows']),
                'total' => $payload['total'],
                'truncated' => $payload['truncated'],
                'limit_applied' => $payload['limit_applied'],
            ],
            $warnings,
        );
    }

    /**
     * Run the validated SELECT and collect the capped result rows.
     *
     * @param  object  $wpdb  Live $wpdb or an in-memory test double.
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the query fails.
     */
    private function queryData(object $wpdb, array $input): array
    {
        $safe = $this->guardedSql((string) $input['sql']);
        $safe = $this->withAutoLimit($safe, $input);
        $bindings = array_values((array) ($input['bindings'] ?? []));

        try {
            $prepared = $bindings !== []
                ? $wpdb->prepare($safe->sql(), ...$bindings)
                : $safe->sql();

            $results = $wpdb->get_results($prepared, 'ARRAY_A');
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('db_query execution failed', previous: $e);
        }

        if ($results === null) {
            $failure = new \RuntimeException((string) ($wpdb->last_error ?? 'unknown database error'));

            $this->logExecutionError($failure);

            throw new ToolException('db_query execution failed', previous: $failure);
        }

        return $this->capRows(is_array($results) ? $results : []) + [
            'limit_applied' => $this->resolveLimit($input),
        ];
    }

    /**
     * List every table with its row count and storage engine.
     *
     * @param  object  $wpdb  Live $wpdb or an in-memory test double.
     * @return array<string, mixed> Schema result data.
     *
     * @throws ToolException When the table list cannot be read.
     */
    private function schemaData(object $wpdb): array
    {
        try {
            $tables = $wpdb->get_results('SHOW TABLE STATUS', 'ARRAY_A');
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('db_query schema failed', previous: $e);
        }

        if ($tables === null) {
            $failure = new \RuntimeException((string) ($wpdb->last_error ?? 'unknown database error'));

            $this->logExecutionError($failure);

            throw new ToolException('db_query schema failed', previous: $failure);
        }

        $rows = [];

        foreach (is_array($tables) ? $tables : [] as $table) {
            $rows[] = [
                'table' => (string) ($table['Name'] ?? ''),
                'rows' => (int) ($table['Rows'] ?? 0),
                'engine' => (string) ($table['Engine'] ?? ''),
            ];
        }

        return ['tables' => $rows, 'examples' => self::EXAMPLES];
    }

    /**
     * Keep whole rows until the output byte cap is reached.
     *
     * @param  array<int, array<string, mixed>>  $results  Raw result rows.
     * @return array<string, mixed> Capped rows plus truncation metadata.
     */
    private function capRows(array $results): array
    {
        $kept = [];
        $bytes = 0;
        $truncated = false;

        foreach ($results as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                $truncated = true;

                break;
            }

            $kept[] = $row;
            $bytes += strlen($encoded);
        }

        return [
            'rows' => $kept,
            'total' => count($results),
            'truncated' => $truncated,
        ];
    }

    /**
     * Validate runtime input and return a structured error when it is unusable.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validate(array $input): ?string
    {
        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return $unknown;
        }

        if (array_key_exists('schema', $input) && ! is_bool($input['schema'])) {
            return $this->error('INVALID_ARGUMENT', '"schema" must be a boolean.');
        }

        $paging = $this->validatePaging($input, self::MAX_LIMIT, 0);

        if ($paging !== null) {
            return $paging;
        }

        if (($input['schema'] ?? false) === true) {
            return null;
        }

        if (! isset($input['sql']) || ! is_string($input['sql']) || trim($input['sql']) === '') {
            return $this->error(
                'INVALID_ARGUMENT',
                'Provide "sql", or set "schema" to true.',
                ['accepted_arguments' => self::ALLOWED_KEYS],
            );
        }

        if (array_key_exists('bindings', $input)) {
            if (! is_array($input['bindings'])) {
                return $this->error('INVALID_ARGUMENT', '"bindings" must be an array.');
            }

            foreach (array_values($input['bindings']) as $index => $binding) {
                if (! is_scalar($binding) && $binding !== null) {
                    return $this->error(
                        'INVALID_BINDING',
                        sprintf('Binding at index %d must be a string, integer, float, boolean or null.', $index),
                    );
                }
            }
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::MySql))->validate(self::resolvePrefix((string) $input['sql']));
        } catch (SqlGuardException $e) {
            return $this->error('BLOCKED_STATEMENT', $e->getMessage());
        }

        $blockedTable = self::blockedTable($safe->sql());

        if ($blockedTable !== null) {
            $isConversationTable = str_contains(strtolower($blockedTable), 'phpclaw_');

            return $this->error(
                'BLOCKED_IDENTIFIER',
                $isConversationTable
                    ? 'The query references the "'.$blockedTable.'" table, which holds phpClaw '
                      .'conversations scoped to their owner and cannot be read as raw SQL. Ask for '
                      .'your own conversation history instead.'
                    : 'The query references the "'.$blockedTable.'" table, which holds plugin settings '
                      .'and credentials and cannot be read as raw SQL. The wp_option tool is the one that '
                      .'answers questions about options.',
                array_filter([
                    'blocked_tables' => self::BLOCKED_TABLES,
                    'use_instead' => $isConversationTable ? null : 'wp_option',
                ]),
            );
        }

        foreach (self::RESTRICTED_IDENTIFIERS as $identifier) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($identifier, '/').'(?![A-Za-z0-9])/i', $safe->sql()) === 1) {
                return $this->error(
                    'BLOCKED_IDENTIFIER',
                    'The query references a restricted table or column and was blocked.',
                    ['restricted_identifiers' => self::RESTRICTED_IDENTIFIERS],
                );
            }
        }

        return null;
    }

    /**
     * Return the blocked table a prefix-resolved query references, or null.
     *
     * @param  string  $sql  Prefix-resolved SQL.
     * @return string|null The prefixed table name that was referenced, or null.
     */
    private static function blockedTable(string $sql): ?string
    {
        global $wpdb;

        $prefix = isset($wpdb) && property_exists($wpdb, 'prefix') ? (string) $wpdb->prefix : '';

        foreach (self::BLOCKED_TABLES as $table) {
            $name = $prefix.$table;

            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/i', $sql) === 1) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Expand the {prefix} placeholder to this install's real table prefix.
     *
     * @param  string  $sql  Raw SQL as supplied by the caller.
     * @return string SQL with every {prefix} token replaced and the string trimmed.
     */
    private static function resolvePrefix(string $sql): string
    {
        global $wpdb;

        $prefix = isset($wpdb) && property_exists($wpdb, 'prefix') ? (string) $wpdb->prefix : '';

        return str_replace('{prefix}', $prefix, trim($sql));
    }

    /**
     * Re-run the read-only guard over already validated SQL.
     *
     * @param  string  $sql  The SQL statement to guard.
     * @return SafeSql The guard-validated statement.
     *
     * @throws ToolException When the guard rejects SQL that already passed validation.
     */
    private function guardedSql(string $sql): SafeSql
    {
        try {
            return (new SqlReadOnlyGuard(SqlDialect::MySql))->validate(self::resolvePrefix($sql));
        } catch (SqlGuardException $e) {
            throw new ToolException('db_query: statement rejected by the read-only guard.', previous: $e);
        }
    }

    /**
     * Append a LIMIT clause when the validated SQL does not already specify one.
     *
     * @param  SafeSql  $safe  Guard-validated query.
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return SafeSql Query with LIMIT appended when needed.
     */
    private function withAutoLimit(SafeSql $safe, array $input): SafeSql
    {
        $upperSql = strtoupper((string) preg_replace('/\s+/', ' ', $safe->sql()));

        if (preg_match('/\bLIMIT\b/', $upperSql) === 1) {
            return $safe;
        }

        return $safe->withLimit($this->resolveLimit($input));
    }

    /**
     * Resolve the row cap appended to queries without their own LIMIT.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return int The row cap.
     */
    private function resolveLimit(array $input): int
    {
        return isset($input['limit']) && is_int($input['limit'])
            ? $input['limit']
            : self::AUTO_LIMIT;
    }

    /**
     * Whether this tool may be offered to the model. WordPress evaluates the caller's capability when the tool runs, so every tool stays eligible for routing.
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
            tags: ['sql', 'select', 'query', 'row', 'rows', 'table', 'tables', 'column', 'columns', 'count', 'database', 'wpdb'],
            intents: ['run sql', 'query the database', 'list rows from a table'],
            examples: ['run a select against the posts table'],
        );
    }
}
