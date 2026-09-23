<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Configuration tool: read-only access to store configuration values, withholding
 * credentials across the JSON, serialised, URL-embedded and bulk-listing paths.
 */
final class PsConfigTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 30;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = ['search', 'name'];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'search', 'name', 'include_values',
        'limit', 'offset', 'order_by', 'order_dir',
    ];

    private const AVAILABLE_COLUMNS = [
        'name', 'value', 'id_shop', 'id_shop_group', 'date_add', 'date_upd',
    ];

    private const DEFAULT_COLUMNS = [
        'name', 'value', 'id_shop', 'id_shop_group',
    ];

    private const BLOCKED_PATTERNS = [
        'PASSWD', 'SECRET', 'KEY', 'SALT', 'TOKEN', 'COOKIE', 'PHPCLAW',
        'PWD', 'CRED', 'BEARER', 'LICENSE', 'PRIVATE', 'CERT', 'DSN',
        'PASSPHRASE', 'NONCE', 'HASH',
    ];

    private const WITHHELD_KEYS = [
        'password', 'passwd', 'pwd', 'pass', 'secret', 'api_key', 'apikey',
        'private_key', 'token', 'credential', 'salt', 'passphrase', 'bearer',
        'auth_key', 'nonce', 'certificate',
    ];

    private const REDACTION = '***withheld***';

    /**
     * Create a new PsConfigTool instance.
     *
     * @param  PsDbInterface|null  $db  Native PrestaShop DB handle.
     * @param  string  $tablePrefix  PrestaShop table prefix.
     */
    public function __construct(
        private readonly ?PsDbInterface $db,
        private readonly string $tablePrefix = 'ps_',
    ) {}

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'ps_config';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Read PrestaShop configuration values (read-only, sensitive keys blocked).

AVAILABLE COLUMNS:
  name, value, date_add, date_upd

BLOCKED (never returned, hardcoded security):
  Any config key containing: PASSWD, SECRET, KEY, SALT, TOKEN, COOKIE, PHPCLAW
  (case-insensitive match)

CAPABILITIES:
  - Search by key name (partial match)
  - Filter by exact key name
  - Request specific columns or get defaults
  - Aggregate mode: total config keys, grouped by PS_ prefix
  - Schema mode: discover available columns before querying

EXAMPLES:
  "What is the shop name?" → name: "PS_SHOP_NAME"
  "Show all currency settings" → search: "CURRENCY"
  "List email config" → search: "PS_MAIL"
  "How many config keys exist?" → aggregate: true
  "What columns exist?" → schema: true
  "Show all PS_SHOP_ settings" → search: "PS_SHOP"
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
            'properties' => [
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Columns to return. Any of: '.
                        implode(', ', self::AVAILABLE_COLUMNS).
                        '. Omit for defaults. Use ["*"] for all.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Partial match search in config key name (case-insensitive). E.g. "CURRENCY" or "PS_SHOP".',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Exact config key name. E.g. "PS_SHOP_NAME".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100, default 30).',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset.',
                    'default' => 0,
                ],
                'order_by' => [
                    'type' => 'string',
                    'description' => 'Sort column. Default: name.',
                    'default' => 'name',
                ],
                'order_dir' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'description' => 'Sort direction. Default: ASC.',
                    'default' => 'ASC',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total config keys, grouped by PS_ prefix.',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available columns. No DB query.',
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
            domains: ['settings', 'configuration'],
            tags: ['config', 'configuration', 'setting', 'settings', 'option', 'options', 'parameter', 'parameters', 'value', 'shop', 'global'],
            intents: ['read a configuration value', 'show settings', 'what is this option set to'],
            examples: ['what is the shop name configured as'],
        );
    }

    /**
     * Plan the execution: authorise first, then validate, before any query runs.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the store database is unreachable.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read PrestaShop configuration keys');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_config: no database connection available.');
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Execute the planned read.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (! empty($input['schema'])) {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        if (! empty($input['aggregate'])) {
            return ['type' => 'aggregate', 'payload' => $this->aggregateData()];
        }

        return ['type' => 'query', 'payload' => $this->queryData($input)];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['configs'] ?? null)) {
            throw new ToolException('ps_config: the query returned an incomplete result.');
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
            return $this->success($payload, ['mode' => 'schema', 'database_query_performed' => false]);
        }

        if ($execution['type'] === 'aggregate') {
            return $this->success($payload, ['mode' => 'aggregate']);
        }

        return $this->success(
            ['configs' => $payload['configs']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['configs']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
                'truncated' => $payload['truncated'],
                'values_included' => $payload['values_included'],
                'blocked_patterns' => self::BLOCKED_PATTERNS,
                'names_with_multiple_scopes' => $payload['scoped'],
            ],
            $payload['warnings'],
        );
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

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
    }

    /**
     * Read one page of configuration rows, with a real total from the same WHERE clause.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the configuration query fails.
     */
    private function queryData(array $input): array
    {
        $table = '`'.$this->tablePrefix.'configuration`';
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = min(max(0, (int) ($input['offset'] ?? self::DEFAULT_OFFSET)), self::MAX_OFFSET);
        $orderBy = $this->safeColumn($input['order_by'] ?? 'name');
        $orderDir = strtoupper($input['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $includeValues = ($input['include_values'] ?? false) === true;
        $isNamedLookup = isset($input['name']) && trim((string) $input['name']) !== '';
        $columns = $this->resolveColumns($input['columns'] ?? []);
        $select = implode(', ', array_map(fn (string $c) => "c.{$c}", $columns));

        [$where, $bindings] = $this->buildWhere($input);

        $sql = "SELECT {$select} FROM {$table} c {$where} ORDER BY c.{$orderBy} {$orderDir}, c.id_configuration ASC LIMIT {$limit} OFFSET {$offset}";

        try {
            $total = (int) ($this->db->query("SELECT COUNT(*) AS total FROM {$table} c {$where}", $bindings)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsConfigTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_config: database query failed.', previous: $e);
        }

        $rows = array_map(function (array $row) use ($isNamedLookup, $includeValues): array {
            if (! array_key_exists('value', $row)) {
                return $row;
            }

            $stored = (string) $row['value'];

            if ($isNamedLookup || $includeValues) {
                $row['value'] = $this->scrubValue((string) ($row['name'] ?? ''), $stored);

                return $row;
            }

            unset($row['value']);

            return $row + $this->describeValue($stored);
        }, $rows);

        $names = array_count_values(array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $rows));
        $scoped = array_keys(array_filter($names, static fn (int $n): bool => $n > 1));

        $capped = ToolOutputEncoder::cap($rows, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'configs' => $capped['rows'],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $capped['shown'] : null,
            'columns' => $columns,
            'values_included' => $isNamedLookup || $includeValues,
            'scoped' => $scoped,
            'truncated' => $capped['truncated'],
            'warnings' => $this->queryWarnings($capped, $scoped),
        ];
    }

    /**
     * Build the warnings a configuration page carries, including the scrubbing limits.
     *
     * @param  array{rows: array<int, mixed>, total: int, shown: int, truncated: bool}  $capped  A cap() result.
     * @param  array<int, string>  $scoped  Names that appear under more than one shop scope.
     * @return array<int, array{code: string, message: string}>
     */
    private function queryWarnings(array $capped, array $scoped): array
    {
        $warnings = ToolOutputEncoder::warnings($capped);

        $warnings[] = [
            'code' => 'PARTIAL_REDACTION',
            'message' => 'Configuration names containing any of blocked_patterns are refused outright. '
                .'Values that are returned have credential-shaped keys withheld at every depth, and credentials '
                .'embedded in a URL removed. That is a blocklist and it has limits: a credential under a key '
                .'nobody listed is returned in full, and so is a credential held as plain text under a clean '
                ."name, which is this table's most common shape. Do not treat these values as scrubbed.",
        ];

        if ($scoped !== []) {
            $warnings[] = [
                'code' => 'MULTIPLE_SHOP_SCOPES',
                'message' => 'These names have more than one row because ps_configuration is scoped '
                    .'per shop. Different values under one name are scoping, not a contradiction to resolve. '
                    .'id_shop and id_shop_group say which row applies where.',
            ];
        }

        return $warnings;
    }

    /**
     * Aggregate mode, counts and grouping only, zero row data.
     *
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the aggregate query fails.
     */
    private function aggregateData(): array
    {
        $table = '`'.$this->tablePrefix.'configuration`';
        $blockedWhere = $this->blockedWhere();

        $sqlTotal = "SELECT COUNT(*) AS total FROM {$table} c WHERE 1=1 {$blockedWhere}";

        $sqlGroups = "SELECT
                        SUBSTRING_INDEX(c.name, '_', 2) AS prefix_group,
                        COUNT(*) AS count
                      FROM {$table} c
                      WHERE 1=1 {$blockedWhere}
                      GROUP BY SUBSTRING_INDEX(c.name, '_', 2)
                      ORDER BY count DESC
                      LIMIT 20";

        try {
            $totalRow = $this->db->query($sqlTotal)->row;
            $total = (int) ($totalRow['total'] ?? 0);
            $groups = $this->db->query($sqlGroups)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsConfigTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_config: database query failed.', previous: $e);
        }

        return [
            'total' => $total,
            'by_group' => $groups,
        ];
    }

    /**
     * Schema discovery data, no database query.
     *
     * @return array<string, mixed> Schema discovery data.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'blocked_patterns' => self::BLOCKED_PATTERNS,
            'column_descriptions' => [
                'name' => 'Configuration key name (e.g. PS_SHOP_NAME)',
                'value' => 'Configuration value (string)',
                'date_add' => 'Date the config key was created',
                'date_upd' => 'Date the config key was last updated',
            ],
            'filter_capabilities' => self::FILTERS,
            'modes' => ['schema', 'aggregate', 'query'],
            'pagination' => ['type' => 'offset'],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
            ],
            'security_notice' => 'Keys containing PASSWD, SECRET, KEY, SALT, TOKEN, COOKIE, or PHPCLAW are automatically blocked and never returned.',
        ];
    }

    /**
     * Build WHERE clause with blocked-pattern exclusions and input filters.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(array $input): array
    {
        $conditions = [];
        $bindings = [];

        if (isset($input['name']) && trim((string) $input['name']) !== '') {
            $conditions[] = 'c.name = ?';
            $bindings[] = trim((string) $input['name']);
        }

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $conditions[] = 'c.name LIKE ?';
            $bindings[] = '%'.trim((string) $input['search']).'%';
        }

        $blockedSql = $this->blockedWhere();

        $where = 'WHERE 1=1';
        if ($conditions !== []) {
            $where .= ' AND '.implode(' AND ', $conditions);
        }
        $where .= $blockedSql;

        return [$where, $bindings];
    }

    /**
     * Decode a stored value, scrub it, and re-encode it in the form it arrived in.
     *
     * @param  string  $name  The configuration name, which is a scalar value's only key.
     * @param  string  $stored  The raw value column.
     * @return string The value with credential-shaped keys withheld.
     */
    private function scrubValue(string $name, string $stored): string
    {
        $trimmed = trim($stored);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return (string) json_encode($this->filterStructure($decoded, $name), JSON_UNESCAPED_UNICODE);
            }
        }

        if (preg_match('/^[aO]:[0-9]+:/', $trimmed) === 1) {
            $decoded = @unserialize($trimmed, ['allowed_classes' => false]);

            if (is_array($decoded)) {
                return serialize($this->filterStructure($decoded, $name));
            }
        }

        if ($this->isWithheldKey($name)) {
            return self::REDACTION;
        }

        return $this->stripUrlCredentials($stored);
    }

    /**
     * Walk a decoded structure, withholding credential-shaped keys at every depth.
     *
     * @param  array<int|string, mixed>  $data  Decoded value.
     * @param  string  $fallbackKey  Key to attribute a scalar to when its own key is not a string.
     * @return array<int|string, mixed> The filtered structure.
     */
    private function filterStructure(array $data, string $fallbackKey): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isWithheldKey($key)) {
                $out[$key] = self::REDACTION;

                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->filterStructure($value, is_string($key) ? $key : $fallbackKey);

                continue;
            }

            $childKey = is_string($key) ? $key : $fallbackKey;

            if (is_string($value)) {
                $out[$key] = $this->isWithheldKey($childKey)
                    ? self::REDACTION
                    : $this->stripUrlCredentials($value);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Whether a key name marks a credential.
     *
     * @param  string  $key  Configuration name or structure key.
     * @return bool
     */
    private function isWithheldKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::WITHHELD_KEYS as $withheld) {
            if (str_contains($lower, $withheld)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove an embedded username and password from a URL value.
     *
     * @param  string  $value  Configuration value.
     * @return string The value with any URL credentials removed.
     */
    private function stripUrlCredentials(string $value): string
    {
        return (string) preg_replace_callback(
            '#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s:@]+@#i',
            static fn (array $m): string => $m[1].self::REDACTION.'@',
            $value,
        );
    }

    /**
     * Describe a value without returning it, for the listing and search paths.
     *
     * @param  string  $stored  The raw value column.
     * @return array{value_type: string, value_size: int}
     */
    private function describeValue(string $stored): array
    {
        $trimmed = trim($stored);
        $type = 'string';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && is_array(json_decode($trimmed, true))) {
            $type = 'json';
        } elseif (preg_match('/^[aO]:[0-9]+:/', $trimmed) === 1) {
            $type = 'serialised';
        }

        return ['value_type' => $type, 'value_size' => strlen($stored)];
    }

    /**
     * Generate SQL conditions to exclude blocked key patterns.
     *
     * @return string SQL fragment with AND conditions.
     */
    private function blockedWhere(): string
    {
        $parts = [];
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            $parts[] = " AND UPPER(c.name) NOT LIKE '%".strtoupper($pattern)."%'";
        }

        return implode('', $parts);
    }

    /**
     * Resolve requested columns to a safe validated list.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function resolveColumns(array $requested): array
    {
        if ($requested === ['*']) {
            return self::AVAILABLE_COLUMNS;
        }
        if ($requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        $valid = array_filter(
            $requested,
            fn (string $c) => in_array($c, self::AVAILABLE_COLUMNS, true)
        );

        if (! in_array('name', $valid, true)) {
            array_unshift($valid, 'name');
        }

        return array_values($valid);
    }

    /**
     * Validate a column name for use in ORDER BY.
     *
     * @param  string  $col
     * @return string
     */
    private function safeColumn(string $col): string
    {
        return in_array($col, self::AVAILABLE_COLUMNS, true) ? $col : 'name';
    }
}
