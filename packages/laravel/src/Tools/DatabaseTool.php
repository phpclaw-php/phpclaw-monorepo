<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use Illuminate\Support\Facades\DB;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that runs read-only SELECT queries on the application database,
 * redacting credential columns from every returned row.
 */
final class DatabaseTool extends AbstractLaravelTool
{
    private const BLOCKED_TABLES = ['phpclaw_conversations', 'phpclaw_messages'];

    private const RESTRICTED_IDENTIFIERS = [
        'password', 'passwd', 'remember_token', 'secret', 'private_key',
        'api_key', 'api_token', 'access_token', 'auth_key',
    ];

    private const ALLOWED_KEYS = ['query', 'bindings'];

    /**
     * Return the tool identifier used by the agent to invoke this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'db_query';
    }

    /**
     * Return the human-readable description shown to the LLM for tool selection.
     *
     * @return string
     */
    public function description(): string
    {
        return 'EXECUTE a read-only SQL SELECT against the live Laravel DB and return real rows. Use for tables, row counts, schema, data. Examples: list tables, count users, show last orders. Invoke, never describe SQL, run it.';
    }

    /**
     * Return the JSON Schema describing the tool\'s accepted input parameters.
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
                    'description' => 'Optional positional or named bindings for the query',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Return the platform ability required to invoke this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::MANAGE_ALL_ABILITY;
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
            tags: ['sql', 'select', 'query', 'row', 'rows', 'table', 'tables', 'column', 'columns', 'count', 'database', 'eloquent'],
            intents: ['run sql', 'query the database', 'list rows from a table'],
            examples: ['run a select against the users table'],
        );
    }

    /**
     * Guard the caller and validate input before any query runs.
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

        $query = $this->stringInput($input, 'query');

        if ($query === '') {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', '"query" is required and must be a non-empty string.'),
            ];
        }

        $bindings = (array) ($input['bindings'] ?? []);

        foreach ($bindings as $index => $binding) {
            if (! is_scalar($binding) && $binding !== null) {
                return [
                    'input' => $input,
                    'result' => $this->error(
                        'INVALID_BINDING',
                        sprintf('Binding at index %d must be a string, integer, float, boolean or null.', $index),
                    ),
                ];
            }
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::Generic))->validate($query);
        } catch (SqlGuardException $e) {
            return ['input' => $input, 'result' => $this->error('BLOCKED_STATEMENT', $e->getMessage())];
        }

        try {
            $this->assertNoBlockedTable($safe->sql());
        } catch (ToolException $e) {
            return [
                'input' => $input,
                'result' => $this->error('BLOCKED_IDENTIFIER', $e->getMessage(), ['blocked_tables' => self::BLOCKED_TABLES]),
            ];
        }

        try {
            $this->assertNoRestrictedIdentifier($safe->sql());
        } catch (ToolException) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'BLOCKED_IDENTIFIER',
                    'The query references a restricted table or column and was blocked.',
                    ['restricted_identifiers' => self::RESTRICTED_IDENTIFIERS],
                ),
            ];
        }

        $input['query'] = $safe->sql();
        $input['bindings'] = $bindings;

        return ['input' => $input, 'result' => null];
    }

    /**
     * Execute the validated SELECT query and return the raw result.
     *
     * @param  array<string, mixed>  $input  Validated runtime input with `query` and `bindings`.
     * @return array<string, mixed> Internal execution result with rows, total, and truncated flag.
     *
     * @throws ToolException On database execution failure.
     */
    protected function perform(array $input): array
    {
        try {
            $results = DB::select($input['query'], $input['bindings']);
        } catch (\Throwable $e) {
            throw new ToolException('db_query execution failed.', previous: $e);
        }

        $rows = $this->redactRestrictedColumns($results);
        $total = count($rows);
        $rows = $this->capRowsToOutputBytes($rows);

        return ['rows' => $rows, 'total' => $total, 'truncated' => count($rows) < $total];
    }

    /**
     * Check that the execution produced a valid row list.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['rows'])) {
            throw new ToolException('db_query returned an incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Convert a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded success envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $dropped = $execution['total'] - count($execution['rows']);

        $meta = [
            'mode' => 'query',
            'count' => count($execution['rows']),
            'total' => $execution['total'],
            'truncated' => $execution['truncated'],
        ];

        $warnings = [];

        if ($execution['truncated']) {
            $warnings[] = [
                'code' => 'OUTPUT_TRUNCATED',
                'message' => sprintf(
                    '%d %s dropped to keep the response within the 8 KB output limit. Narrow the query or add a smaller LIMIT.',
                    $dropped,
                    $dropped === 1 ? 'row was' : 'rows were',
                ),
            ];
        }

        if ($execution['total'] === 0) {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => 'The query matched no rows.',
            ];
        }

        return $this->success(['rows' => $execution['rows']], $meta, $warnings);
    }

    /**
     * Reject queries that reference a phpClaw conversation table, whose rows are owner-scoped
     * by the conversation memory driver and must never be reachable as raw SQL.
     *
     * @param  string  $sql  The validated read-only SQL string.
     * @return void
     *
     * @throws ToolException When the SQL references a blocked table.
     */
    private function assertNoBlockedTable(string $sql): void
    {
        try {
            $prefix = (string) DB::connection()->getTablePrefix();
        } catch (\Throwable) {
            $prefix = '';
        }

        foreach (self::BLOCKED_TABLES as $table) {
            foreach (array_unique([$prefix.$table, $table]) as $name) {
                if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/i', $sql) === 1) {
                    throw new ToolException(
                        'db_query: the query references the "'.$name.'" table, which holds phpClaw '
                        .'conversations scoped to their owner and cannot be read as raw SQL. Ask for '
                        .'your own conversation history instead.'
                    );
                }
            }
        }
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
        foreach (self::RESTRICTED_IDENTIFIERS as $identifier) {
            if (preg_match('/\b'.preg_quote($identifier, '/').'\b/i', $sql) === 1) {
                throw new ToolException('db_query: query references a restricted table or column and was blocked.');
            }
        }
    }

    /**
     * Replace the value of every credential column in the result set with the redaction marker.
     *
     * @param  array<int, mixed>  $results  Rows as returned by DB::select().
     * @return array<int, mixed>
     */
    private function redactRestrictedColumns(array $results): array
    {
        $redacted = [];

        foreach ($results as $row) {
            if (is_array($row)) {
                $redacted[] = $this->redactRow($row);

                continue;
            }

            if ($row instanceof \stdClass) {
                $redacted[] = (object) $this->redactRow((array) $row);

                continue;
            }

            $redacted[] = $row;
        }

        return $redacted;
    }

    /**
     * Replace the value of every credential column in a single row.
     *
     * @param  array<string, mixed>  $row  One result row keyed by column name.
     * @return array<string, mixed>
     */
    private function redactRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if ($this->isSecretIdentifier((string) $column)) {
                $row[$column] = self::REDACTED;
            }
        }

        return $row;
    }
}
