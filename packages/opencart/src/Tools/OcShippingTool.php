<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Shipping tool: list and inspect shipping methods with full filtering.
 */
final class OcShippingTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    protected const DEFAULT_LIMIT = 50;

    protected const ERROR_LABEL = 'shipping';

    private const AVAILABLE_COLUMNS = [
        'id',
        'name',
        'geo_zone',
        'cost',
        'tax_class',
        'sort_order',
        'status',
    ];

    private const DEFAULT_COLUMNS = [
        'id',
        'name',
        'geo_zone',
        'cost',
        'status',
    ];

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_shipping';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect OpenCart shipping methods.

AVAILABLE COLUMNS:
  id, name, geo_zone, cost, tax_class, sort_order, status

DEFAULT COLUMNS (returned when columns param is omitted):
  id, name, geo_zone, cost, status

CAPABILITIES:
  - List all installed shipping extensions with config details
  - Filter by status: enabled, disabled, or all
  - Filter by geo_zone_id (numeric)
  - Search by shipping method name (partial match, case-insensitive)
  - Dynamic column selection via columns param
  - Aggregate mode: total methods, enabled/disabled counts

EXAMPLES:
  "List enabled shipping methods"     -> status: "enabled"
  "Show disabled shipping"            -> status: "disabled"
  "Shipping for geo zone 1"          -> geo_zone_id: 1
  "Is flat rate configured?"         -> search: "flat"
  "Shipping costs and tax classes"   -> columns: ["name", "cost", "tax_class"]
  "How many shipping methods?"       -> aggregate: true
  "All details for every method"     -> columns: ["*"]
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
                    'description' => 'Columns to return. Use ["*"] for all. Omit for defaults (id, name, geo_zone, cost, status).',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available/default columns only. No database query.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total_methods, enabled, disabled counts.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter: all, enabled, disabled. Default: all.',
                    'default' => 'all',
                    'enum' => ['all', 'enabled', 'disabled'],
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Partial match in shipping method name (case-insensitive).',
                ],
                'geo_zone_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by geo zone ID.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows (1-100, default 50).',
                    'default' => self::DEFAULT_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset.',
                    'default' => 0,
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
     * Authorise the caller, resolve the schema shortcut, and normalise paging inputs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the database is unavailable for a non-schema call.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('list and inspect OpenCart shipping methods');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if (! empty($input['schema'])) {
            return ['input' => ['schema' => true], 'result' => null];
        }

        if ($this->db === null) {
            throw new ToolException('oc_shipping: no database connection available.');
        }

        $input['limit'] = $this->clampLimit($input);
        $input['offset'] = max(0, (int) ($input['offset'] ?? 0));

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the schema lookup, aggregate count, or filtered method list.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => $this->schemaPayload()];
        }

        if (! empty($input['aggregate'])) {
            return ['type' => 'aggregate', 'payload' => $this->aggregatePayload($input)];
        }

        return ['type' => 'query', 'payload' => $this->queryPayload($input)];
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
        if ($execution['type'] === 'aggregate' && ! is_array($execution['payload']['stats'] ?? null)) {
            throw new ToolException('oc_shipping: aggregate result is incomplete.');
        }

        if ($execution['type'] === 'query' && ! is_array($execution['payload']['shipping_methods'] ?? null)) {
            throw new ToolException('oc_shipping: query result is incomplete.');
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
        $payload = $execution['payload'];

        if ($execution['type'] === 'schema') {
            return $this->success($payload, ['mode' => 'schema']);
        }

        if ($execution['type'] === 'aggregate') {
            return $this->success(
                ['stats' => $payload['stats']],
                ['mode' => 'aggregate'],
            );
        }

        return $this->success(
            [
                'shipping_methods' => $payload['shipping_methods'],
                'columns_returned' => $payload['columns_returned'],
            ],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'shown' => $payload['shown'],
                'has_more' => $payload['has_more'],
                'truncated' => $payload['truncated'],
            ],
        );
    }

    /**
     * Return static schema metadata without querying the database.
     *
     * @return array<string, mixed>
     */
    private function schemaPayload(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'status_filters' => ['all', 'enabled', 'disabled'],
            'filter_capabilities' => ['search', 'status', 'geo_zone_id', 'columns', 'aggregate', 'schema', 'limit', 'offset'],
        ];
    }

    /**
     * Count enabled and disabled shipping methods, optionally filtered by a search term.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException On infrastructure failure.
     */
    private function aggregatePayload(array $input): array
    {
        $methods = $this->loadShippingMethods();
        $search = strtolower(trim((string) ($input['search'] ?? '')));

        $enabled = 0;
        $disabled = 0;

        foreach ($methods as $method) {
            $name = strtolower((string) ($method['name'] ?? ''));

            if ($search !== '' && ! str_contains($name, $search)) {
                continue;
            }

            if ((int) ($method['status'] ?? 0) === 1) {
                $enabled++;
            } else {
                $disabled++;
            }
        }

        return [
            'stats' => [
                'total_methods' => $enabled + $disabled,
                'enabled' => $enabled,
                'disabled' => $disabled,
            ],
        ];
    }

    /**
     * Load, filter, page, and byte-cap the shipping method list.
     *
     * @param  array<string, mixed>  $input  Validated input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException On infrastructure failure.
     */
    private function queryPayload(array $input): array
    {
        $status = (string) ($input['status'] ?? 'all');
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $geoZoneId = isset($input['geo_zone_id']) ? (int) $input['geo_zone_id'] : null;
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);
        $offset = (int) ($input['offset'] ?? 0);
        $columns = $this->resolveColumns($input['columns'] ?? []);

        $methods = $this->loadShippingMethods();
        $allFiltered = [];

        foreach ($methods as $method) {
            $isEnabled = (int) ($method['status'] ?? 0) === 1;

            if ($status === 'enabled' && ! $isEnabled) {
                continue;
            }

            if ($status === 'disabled' && $isEnabled) {
                continue;
            }

            $name = strtolower((string) ($method['name'] ?? ''));

            if ($search !== '' && ! str_contains($name, $search)) {
                continue;
            }

            if ($geoZoneId !== null && (int) ($method['geo_zone_id'] ?? 0) !== $geoZoneId) {
                continue;
            }

            $allFiltered[] = $this->buildRow($method, $columns);
        }

        $total = count($allFiltered);
        $paged = array_slice($allFiltered, $offset, $limit);
        $hasMore = ($offset + count($paged)) < $total;

        return $this->capMethods($paged, $columns, $total, $hasMore);
    }

    /**
     * Apply the byte budget to a paged method list and report truncation.
     *
     * @param  list<array<string, mixed>>  $paged  Paged shipping method rows.
     * @param  array<int, string>  $columns  Resolved column list.
     * @param  int  $total  Total filtered method count before paging.
     * @param  bool  $hasMore  Whether more pages exist beyond the current one.
     * @return array<string, mixed>
     */
    private function capMethods(array $paged, array $columns, int $total, bool $hasMore): array
    {
        $kept = [];
        $bytes = 0;

        foreach ($paged as $method) {
            $encoded = json_encode($method, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $method;
            $bytes += strlen($encoded);
        }

        return [
            'shipping_methods' => $kept,
            'columns_returned' => $columns,
            'total' => $total,
            'shown' => count($kept),
            'has_more' => $hasMore,
            'truncated' => count($kept) < count($paged),
        ];
    }

    /**
     * Load all shipping extensions with their settings from the database.
     *
     * @return array<int, array<string, mixed>> Shipping methods with config data.
     *
     * @throws ToolException On query failure.
     */
    private function loadShippingMethods(): array
    {
        $p = $this->tablePrefix;

        $extSql = "SELECT extension_id, `code`
                    FROM `{$p}extension`
                    WHERE `type` = 'shipping'
                    ORDER BY `code` ASC
                    LIMIT ".self::MAX_LIMIT;

        $extensions = $this->fetchRows($extSql, []);

        $geoZones = $this->loadGeoZones();
        $methods = [];

        foreach ($extensions as $ext) {
            $code = (string) $ext['code'];
            $config = $this->loadMethodConfig($code);

            $geoZoneId = (int) ($config['geo_zone_id'] ?? 0);
            $geoZoneName = $geoZones[$geoZoneId] ?? '';

            $methods[] = [
                'id' => (int) $ext['extension_id'],
                'name' => $this->formatMethodName($code),
                'code' => $code,
                'geo_zone' => $geoZoneName,
                'geo_zone_id' => $geoZoneId,
                'cost' => (string) ($config['cost'] ?? '0.00'),
                'tax_class' => (int) ($config['tax_class_id'] ?? 0),
                'sort_order' => (int) ($config['sort_order'] ?? 0),
                'status' => (int) ($config['status'] ?? 0),
            ];
        }

        return $methods;
    }

    /**
     * Load shipping method configuration from the setting table for the given extension code.
     *
     * @param  string  $code  Shipping extension code (e.g. "flat").
     * @return array<string, string> Configuration key-value pairs with the code prefix stripped.
     */
    private function loadMethodConfig(string $code): array
    {
        $p = $this->tablePrefix;
        $sql = "SELECT `key`, `value`
                FROM `{$p}setting`
                WHERE `code` = ? OR `code` = ?
                LIMIT 50";

        $rows = $this->fetchRows($sql, ["shipping_{$code}", $code]);

        $config = [];

        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $value = (string) $row['value'];

            $shortKey = preg_replace('/^(shipping_)?'.preg_quote($code, '/').'_/', '', $key);

            if ($shortKey !== '' && $shortKey !== $key) {
                $config[$shortKey] = $value;
            }
        }

        return $config;
    }

    /**
     * Load all geo zones keyed by ID.
     *
     * @return array<int, string> Geo zone names keyed by geo_zone_id.
     */
    private function loadGeoZones(): array
    {
        $p = $this->tablePrefix;
        $sql = "SELECT geo_zone_id, name FROM `{$p}geo_zone` LIMIT 200";
        $rows = $this->fetchRows($sql, []);

        $zones = [];

        foreach ($rows as $row) {
            $zones[(int) $row['geo_zone_id']] = (string) $row['name'];
        }

        return $zones;
    }

    /**
     * Build a single shipping method row with only the requested columns.
     *
     * @param  array<string, mixed>  $method  Raw method data.
     * @param  array<int, string>  $columns  Columns to include.
     * @return array<string, mixed>
     */
    private function buildRow(array $method, array $columns): array
    {
        $allFields = [
            'id' => (int) $method['id'],
            'name' => (string) $method['name'],
            'geo_zone' => (string) $method['geo_zone'],
            'cost' => (string) $method['cost'],
            'tax_class' => (int) $method['tax_class'],
            'sort_order' => (int) $method['sort_order'],
            'status' => (int) $method['status'] === 1 ? 'enabled' : 'disabled',
        ];

        $row = [];

        foreach ($columns as $col) {
            if (array_key_exists($col, $allFields)) {
                $row[$col] = $allFields[$col];
            }
        }

        return $row;
    }

    /**
     * Format a shipping extension code into a human-readable name.
     *
     * @param  string  $code  Extension code (e.g. "flat", "free", "weight").
     * @return string Human-readable name (e.g. "Flat Rate", "Free Shipping", "Weight Based").
     */
    private function formatMethodName(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    /**
     * Resolve requested columns to a safe validated list.
     *
     * @param  mixed  $requested  Column names from user input.
     * @return array<int, string> Validated column list.
     */
    private function resolveColumns(mixed $requested): array
    {
        if (! is_array($requested)) {
            return self::DEFAULT_COLUMNS;
        }

        if ($requested === ['*']) {
            return self::AVAILABLE_COLUMNS;
        }

        if ($requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        $valid = array_filter(
            $requested,
            fn ($c) => is_string($c) && in_array($c, self::AVAILABLE_COLUMNS, true),
        );

        return array_values($valid) ?: self::DEFAULT_COLUMNS;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['commerce', 'shipping'],
            tags: ['shipping', 'delivery', 'zone', 'zones', 'geo', 'method', 'methods', 'rate', 'rates', 'carrier', 'postage', 'region', 'country', 'flat', 'weight'],
            intents: ['list shipping methods', 'show delivery options', 'what are the shipping rates'],
            examples: ['list the shipping methods and rates'],
        );
    }
}
