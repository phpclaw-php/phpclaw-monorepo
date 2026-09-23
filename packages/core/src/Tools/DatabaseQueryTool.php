<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\SqlGuard\SafeSql;
use PhpClaw\SqlGuard\SqlDialect;
use PhpClaw\SqlGuard\SqlGuardException;
use PhpClaw\SqlGuard\SqlReadOnlyGuard;
use PhpClaw\Support\Log;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/**
 * Executes read-only SQL SELECT queries via an injected PDO connection.
 */
#[Tool(
    name: 'db_query',
    description: 'Run read-only SQL SELECT against the live DB and return rows as JSON.',
    since: '1.0.0',
    default: false,
)]
final class DatabaseQueryTool implements AuthorizableToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    private const TOOL_NAME = 'db_query';

    private const TOOL_DESCRIPTION = 'EXECUTE a read-only SQL SELECT against the live DB and return real rows as JSON. Use for tables, row counts, schema, data. Invoke: never describe SQL, run it. SELECT only. Results over 8KB return a preview plus spill_path, a JSON Lines file readable with shell_exec head or grep; narrow with fewer columns, LIMIT, or OFFSET.';

    private const MAX_ROWS = 100;

    private const MAX_OUTPUT_BYTES = 8192;

    private const RESTRICTED_IDENTIFIERS = [
        'password', 'passwd', 'secret', 'private_key',
        'api_key', 'api_token', 'access_token', 'secret_key', 'auth_token',
    ];

    /**
     * Create a new DatabaseQueryTool instance.
     *
     * @param  \PDO  $pdo  PDO connection to query. Should be configured as read-only where possible.
     * @param  string  $spillDir  Directory for spilled results; empty uses the system temp dir.
     * @return void
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $spillDir = '',
    ) {}

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return self::TOOL_DESCRIPTION;
    }

    /**
     * JSON Schema describing the tool's `sql` and `params` inputs.
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
                    'description' => 'A SELECT SQL query to execute. Only SELECT is allowed.',
                ],
                'params' => [
                    'type' => 'array',
                    'description' => 'Optional positional or named parameters bound to the query.',
                    'items' => ['type' => ['string', 'integer', 'number', 'null']],
                ],
            ],
            'required' => ['sql'],
        ];
    }

    /**
     * Authorize the caller and validate the SQL as read-only.
     *
     * @param  array<string, mixed>  $input  Must contain 'sql'; optionally 'params'.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException If the statement is missing or not SELECT.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('query the database');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $sql = trim((string) ($input['sql'] ?? ''));

        if ($sql === '') {
            throw new ToolException('No SQL query provided.');
        }

        try {
            $safe = (new SqlReadOnlyGuard(SqlDialect::Generic))->validate($sql);
        } catch (SqlGuardException $e) {
            throw new ToolException($e->getMessage());
        }

        $this->assertNoRestrictedIdentifier($safe->sql());

        return [
            'input' => ['safe' => $safe, 'params' => (array) ($input['params'] ?? [])],
            'result' => null,
        ];
    }

    /**
     * Run the validated query.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When PDO preparation or execution fails.
     */
    protected function perform(array $input): array
    {
        /** @var SafeSql $safe */
        $safe = $input['safe'];

        return $this->runQuery($safe, (array) $input['params']);
    }

    /**
     * Accept the fetched rows unchanged.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     */
    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    /**
     * Convert the fetched rows into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $meta = ['mode' => 'query'];

        if (($execution['truncated'] ?? false) === true) {
            $meta['truncated'] = true;
            $meta['limit'] = self::MAX_ROWS;
        }

        return $this->success($execution, $meta);
    }

    /**
     * Reject queries referencing a credential identifier so secrets never reach the model.
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
     * Prepare and execute the validated query, fetching at most MAX_ROWS rows.
     *
     * @param  SafeSql  $safe  Validated SQL wrapping the exact string to execute.
     * @param  array<int|string, mixed>  $params  Positional or named parameters bound to the statement.
     * @return array<string, mixed> Rows payload produced by capRows().
     *
     * @throws ToolException When PDO preparation or execution fails.
     */
    private function runQuery(SafeSql $safe, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare($safe->sql());

            if ($stmt === false) {
                throw new ToolException('Failed to prepare SQL statement.');
            }

            $stmt->execute($params);

            return $this->capRows($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            Log::error('[phpClaw] DatabaseQueryTool: '.$e->getMessage());
            throw new ToolException('Database query failed.');
        }
    }

    /**
     * Cap rows at MAX_ROWS, keep as many inline as fit in MAX_OUTPUT_BYTES, and spill the rest to a file when anything was cut.
     *
     * @param  array<int, array<string, mixed>>  $rows  Rows returned by the SELECT.
     * @return array<string, mixed> Payload `{rows, count}`, plus `{total_rows, truncated, limit, spill_path?, spill_bytes?, hint}` when cut.
     */
    private function capRows(array $rows): array
    {
        $rowCapped = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $inline = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $bytes += strlen((string) json_encode($row)) + 1;

            if ($bytes > self::MAX_OUTPUT_BYTES && $inline !== []) {
                break;
            }

            $inline[] = $row;
        }

        $result = ['rows' => $inline, 'count' => count($inline)];

        if (! $rowCapped && count($inline) === count($rows)) {
            return $result;
        }

        $result['total_rows'] = count($rows);
        $result['truncated'] = true;
        $result['limit'] = self::MAX_ROWS;

        return $this->spill($rows, $result);
    }

    /**
     * Write every fetched row as JSON Lines to a temp file and point the model at it, falling back to a discard note when the write fails.
     *
     * @param  array<int, array<string, mixed>>  $rows  Every fetched row, up to MAX_ROWS.
     * @param  array<string, mixed>  $result  Payload built so far.
     * @return array<string, mixed> Payload with spill_path, spill_bytes and hint, or a discard note.
     */
    private function spill(array $rows, array $result): array
    {
        $lines = implode("\n", array_map(static fn (array $r): string => (string) json_encode($r), $rows))."\n";
        $dir = $this->spillDir !== '' ? $this->spillDir : sys_get_temp_dir();
        $path = is_dir($dir) && is_writable($dir) ? @tempnam($dir, 'phpclaw-db-') : false;

        if ($path !== false && @file_put_contents($path, $lines, LOCK_EX) !== false) {
            $result['spill_path'] = $path;
            $result['spill_bytes'] = strlen($lines);
            $result['hint'] = 'Preview only. Full result is JSON Lines at spill_path: use shell_exec head or grep on it, or narrow the query with fewer columns, a smaller LIMIT, or OFFSET.';

            return $result;
        }

        $result['hint'] = (count($rows) - count($result['rows'])).' more rows discarded. Narrow the query with fewer columns, a smaller LIMIT, or OFFSET.';

        return $result;
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
            tags: ['sql', 'select', 'query', 'rows', 'table', 'schema', 'count'],
            intents: ['query database', 'count rows', 'inspect schema'],
            examples: ['how many users are there'],
        );
    }
}
