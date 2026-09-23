<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Manufacturer tool: list, search, and aggregate brands / manufacturers.
 */
final class OcManufacturerTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    protected const DEFAULT_LIMIT = 50;

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'image', 'sort_order', 'product_count',
    ];

    private const DEFAULT_COLUMNS = ['id', 'name', 'product_count'];

    protected const ERROR_LABEL = 'manufacturer';

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_manufacturer';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY OpenCart manufacturers (brands): list all brands, search by name,
get product counts per brand, and get aggregate statistics.

AVAILABLE COLUMNS:
  id, name, image, sort_order, product_count

DEFAULT COLUMNS: id, name, product_count

CAPABILITIES:
  - Search manufacturers by name (partial match)
  - Hide brands with 0 products
  - Request specific columns or get defaults
  - Aggregate mode: total manufacturers, avg products per manufacturer

EXAMPLES:
  "List all brands" -> {}
  "Search for Apple" -> {"search": "Apple"}
  "Brands with products" -> {"hide_empty": true}
  "Brand overview" -> {"mode": "aggregate"}
  "All columns" -> {"columns": ["*"]}
  "Show just names" -> {"columns": ["id", "name"]}

Invoke this tool; never guess manufacturer data.
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
                'mode' => [
                    'type' => 'string',
                    'description' => 'Operation mode. "list" = return rows (default). '
                                   .'"aggregate" = return manufacturer summary only. '
                                   .'"schema" = return available/default columns and filters.',
                    'enum' => ['list', 'aggregate', 'schema'],
                    'default' => 'list',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Columns to include in each row. Use ["*"] for all available. '
                                   .'Available: '.implode(', ', self::AVAILABLE_COLUMNS).'. '
                                   .'Default: '.implode(', ', self::DEFAULT_COLUMNS).'.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against manufacturer name (partial, case-insensitive).',
                ],
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'When true, exclude manufacturers with 0 products. Default: false.',
                    'default' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100). Default: 50.',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
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
     * Authorise the caller, resolve the mode, and clamp the limit for list requests.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the database is unavailable for a non-schema call.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('list and inspect OpenCart manufacturers');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $mode = (string) ($input['mode'] ?? 'list');
        $input['mode'] = $mode;

        if ($mode === 'schema') {
            return ['input' => $input, 'result' => null];
        }

        if ($this->db === null) {
            throw new ToolException('oc_manufacturer: no database connection available.');
        }

        if ($mode === 'list') {
            $input['limit'] = $this->clampLimit($input);
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the query, aggregate, or schema lookup selected by the validated input.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        $mode = (string) ($input['mode'] ?? 'list');

        if ($mode === 'schema') {
            return ['type' => 'schema', 'payload' => $this->schemaPayload()];
        }

        if ($mode === 'aggregate') {
            return ['type' => 'aggregate', 'payload' => $this->aggregatePayload()];
        }

        return ['type' => 'list', 'payload' => $this->listPayload($input)];
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
            throw new ToolException('oc_manufacturer: aggregate result is incomplete.');
        }

        if ($execution['type'] === 'list' && ! is_array($execution['payload']['manufacturers'] ?? null)) {
            throw new ToolException('oc_manufacturer: list result is incomplete.');
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
            ['manufacturers' => $payload['manufacturers'], 'columns_returned' => $payload['columns_returned']],
            [
                'mode' => 'list',
                'total' => $payload['total'],
                'truncated' => $payload['truncated'],
            ],
        );
    }

    /**
     * Return schema metadata without querying the database.
     *
     * @return array<string, mixed>
     */
    private function schemaPayload(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'filters' => ['search', 'hide_empty'],
            'modes' => ['list', 'aggregate', 'schema'],
        ];
    }

    /**
     * Execute the aggregate statistics query and return the stats payload.
     *
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function aggregatePayload(): array
    {
        $p = $this->tablePrefix;

        $sql = "SELECT
                    COUNT(DISTINCT m.manufacturer_id) AS total_manufacturers,
                    COUNT(p.product_id) AS total_products
                FROM `{$p}manufacturer` m
                LEFT JOIN `{$p}product` p ON p.manufacturer_id = m.manufacturer_id";

        $row = $this->fetchOne($sql, []);

        $totalMfrs = (int) ($row['total_manufacturers'] ?? 0);
        $totalProds = (int) ($row['total_products'] ?? 0);
        $avgPerMfr = $totalMfrs > 0 ? round($totalProds / $totalMfrs, 2) : 0.0;

        return [
            'stats' => [
                'total_manufacturers' => $totalMfrs,
                'total_products' => $totalProds,
                'avg_products_per_manufacturer' => $avgPerMfr,
            ],
        ];
    }

    /**
     * Execute a filtered manufacturer list query with dynamic column selection and byte-budget truncation.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function listPayload(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);
        $columns = $this->resolveColumns($input['columns'] ?? []);
        $params = [];

        $selectSql = $this->buildSelect($columns);
        $needsProductCount = in_array('product_count', $columns, true);
        $hideEmpty = ! empty($input['hide_empty']);

        $joins = '';
        if ($needsProductCount || $hideEmpty) {
            $joins = "LEFT JOIN `{$p}product` p ON p.manufacturer_id = m.manufacturer_id";
        }

        $sql = "SELECT {$selectSql}
                FROM `{$p}manufacturer` m
                {$joins}
                WHERE 1=1";

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $sql .= ' AND m.name LIKE ?';
            $params[] = '%'.trim((string) $input['search']).'%';
        }

        $sql .= ' GROUP BY m.manufacturer_id';

        if ($hideEmpty) {
            $sql .= ' HAVING COUNT(p.product_id) > 0';
        }

        $sql .= " ORDER BY m.sort_order ASC, m.name ASC LIMIT {$limit}";

        $rows = $this->fetchRows($sql, $params);

        $kept = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $row;
            $bytes += strlen($encoded);
        }

        return [
            'manufacturers' => $kept,
            'columns_returned' => $columns,
            'total' => count($rows),
            'truncated' => count($kept) < count($rows),
        ];
    }

    /**
     * Build the SELECT clause based on requested columns.
     *
     * @param  list<string>  $columns  Resolved column list.
     * @return string SQL select fragment.
     */
    private function buildSelect(array $columns): string
    {
        $map = [
            'id' => 'm.manufacturer_id AS id',
            'name' => 'm.name',
            'image' => 'm.image',
            'sort_order' => 'm.sort_order',
            'product_count' => 'COUNT(p.product_id) AS product_count',
        ];

        $parts = [];

        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'm.manufacturer_id AS id';
    }

    /**
     * Resolve which columns to return from input or fall back to defaults.
     *
     * @param  array<int, string>|mixed  $requested  Column names from user input.
     * @return list<string> Validated column list.
     */
    private function resolveColumns(mixed $requested): array
    {
        if (! is_array($requested) || $requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        if ($requested === ['*']) {
            return self::AVAILABLE_COLUMNS;
        }

        $valid = array_values(array_intersect($requested, self::AVAILABLE_COLUMNS));

        return $valid !== [] ? $valid : self::DEFAULT_COLUMNS;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['commerce', 'catalog'],
            tags: ['manufacturer', 'manufacturers', 'brand', 'brands', 'vendor', 'vendors', 'supplier', 'suppliers', 'maker', 'label'],
            intents: ['list manufacturers', 'show brands', 'which vendors do we stock'],
            examples: ['list the brands we carry'],
        );
    }
}
