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
 * Manufacturer tool: read-only inspection of brands and manufacturers.
 */
final class PsManufacturerTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = ['search', 'active', 'hide_empty'];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'search', 'active', 'hide_empty',
        'limit', 'offset', 'order_by', 'order_dir',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'description', 'active',
        'date_add', 'date_upd', 'product_count',
    ];

    private const DEFAULT_COLUMNS = [
        'id', 'name', 'active', 'product_count',
    ];

    private const COLUMN_MAP = [
        'id' => 'm.id_manufacturer',
        'name' => 'm.name',
        'description' => 'ml.description',
        'active' => 'm.active',
        'date_add' => 'm.date_add',
        'date_upd' => 'm.date_upd',
    ];

    /**
     * Create a new PsManufacturerTool instance.
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
        return 'ps_manufacturer';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect PrestaShop manufacturers/brands.

AVAILABLE COLUMNS:
  id, name, description, active,
  date_add, date_upd, product_count

CAPABILITIES:
  - Search by manufacturer name (partial match)
  - Filter by active/inactive status
  - Hide manufacturers with zero products (hide_empty)
  - Include product counts per manufacturer
  - Request specific columns or get all available columns
  - Aggregate mode: total manufacturers, active/inactive, avg products per manufacturer
  - Schema mode: discover available columns before querying

EXAMPLES:
  "List all brands" → (no params)
  "Find brand Nike" → search: "Nike"
  "Active brands with products" → active: true, hide_empty: true
  "How many brands do we have?" → aggregate: true
  "Show all brand details" → columns: ["*"]
  "What columns exist?" → schema: true
  "Brands sorted by product count" → order_by: "product_count", order_dir: "DESC"
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
                    'description' => 'Partial match search on manufacturer name (case-insensitive).',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'true = active only, false = inactive only. Omit for all.',
                ],
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'true = exclude manufacturers with zero products.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100, default 50).',
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
                    'description' => 'true = stats only. Returns: total, active, inactive, avg_products_per_manufacturer.',
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
            domains: ['commerce', 'catalog'],
            tags: ['manufacturer', 'manufacturers', 'brand', 'brands', 'vendor', 'vendors', 'supplier', 'suppliers', 'maker', 'label'],
            intents: ['list manufacturers', 'show brands', 'which vendors do we stock'],
            examples: ['list the brands we carry'],
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
        $forbidden = $this->guardCapability('read PrestaShop manufacturers');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_manufacturer: no database connection available.');
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
            return ['type' => 'aggregate', 'payload' => $this->aggregateData($input)];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['manufacturers'] ?? null)) {
            throw new ToolException('ps_manufacturer: the query returned an incomplete result.');
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
            ['manufacturers' => $payload['manufacturers']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['manufacturers']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
                'truncated' => $payload['truncated'],
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
     * Read one page of manufacturers, with a real total from the same WHERE clause.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the manufacturer query fails.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = min(max(0, (int) ($input['offset'] ?? self::DEFAULT_OFFSET)), self::MAX_OFFSET);
        $orderBy = $this->safeColumn($input['order_by'] ?? 'name');
        $orderDir = strtoupper($input['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $columns = $this->resolveColumns($input['columns'] ?? []);
        $needProductCount = in_array('product_count', $columns, true);

        $selectParts = [];

        foreach ($columns as $col) {
            if ($col === 'product_count') {
                $selectParts[] = 'COUNT(p.id_product) AS product_count';

                continue;
            }

            $selectParts[] = self::COLUMN_MAP[$col].' AS '.$col;
        }

        $select = implode(', ', $selectParts);

        $joins = "LEFT JOIN `{$p}manufacturer_lang` ml
                         ON ml.id_manufacturer = m.id_manufacturer AND ml.id_lang = 1";

        if ($needProductCount) {
            $joins .= "\nLEFT JOIN `{$p}product` p ON p.id_manufacturer = m.id_manufacturer";
        }

        [$where, $bindings] = $this->buildWhere($input);

        $groupBy = $needProductCount
            ? 'GROUP BY m.id_manufacturer, m.name, m.active, m.date_add, m.date_upd, ml.description'
            : '';

        $having = '';

        if ($needProductCount && ! empty($input['hide_empty'])) {
            $having = 'HAVING product_count > 0';
        }

        $orderSql = $orderBy === 'product_count'
            ? "product_count {$orderDir}"
            : self::COLUMN_MAP[$orderBy]." {$orderDir}";

        $from = "FROM `{$p}manufacturer` m
                {$joins}
                {$where}";

        $countSql = $needProductCount
            ? "SELECT COUNT(*) AS total FROM (
                   SELECT m.id_manufacturer, COUNT(p.id_product) AS product_count
                   {$from}
                   GROUP BY m.id_manufacturer
                   {$having}
               ) AS counted"
            : "SELECT COUNT(*) AS total {$from}";

        $sql = "SELECT {$select}
                {$from}
                {$groupBy}
                {$having}
                ORDER BY {$orderSql}, m.id_manufacturer ASC
                LIMIT {$limit} OFFSET {$offset}";

        try {
            $total = (int) ($this->db->query($countSql, $bindings)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsManufacturerTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_manufacturer: database query failed.', previous: $e);
        }

        $rows = array_map(static function (array $row): array {
            if (isset($row['active'])) {
                $row['active'] = (bool) $row['active'];
            }

            if (isset($row['product_count'])) {
                $row['product_count'] = (int) $row['product_count'];
            }

            return $row;
        }, $rows);

        $capped = ToolOutputEncoder::cap($rows, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'manufacturers' => $capped['rows'],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $capped['shown'] : null,
            'columns' => $columns,
            'truncated' => $capped['truncated'],
            'warnings' => ToolOutputEncoder::warnings($capped),
        ];
    }

    /**
     * Aggregate mode, counts and stats only, zero row data.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the aggregate query fails.
     */
    private function aggregateData(array $input): array
    {
        $p = $this->tablePrefix;
        [$where, $bindings] = $this->buildWhere($input);

        $sqlCounts = "SELECT
                        COUNT(*)              AS total,
                        SUM(m.active = 1)     AS active,
                        SUM(m.active = 0)     AS inactive
                      FROM `{$p}manufacturer` m
                      LEFT JOIN `{$p}manufacturer_lang` ml
                             ON ml.id_manufacturer = m.id_manufacturer AND ml.id_lang = 1
                      {$where}";

        $sqlAvg = "SELECT
                     ROUND(AVG(pc.cnt), 2) AS avg_products_per_manufacturer
                   FROM (
                     SELECT m.id_manufacturer, COUNT(p.id_product) AS cnt
                     FROM `{$p}manufacturer` m
                     LEFT JOIN `{$p}product` p ON p.id_manufacturer = m.id_manufacturer
                     LEFT JOIN `{$p}manufacturer_lang` ml
                            ON ml.id_manufacturer = m.id_manufacturer AND ml.id_lang = 1
                     {$where}
                     GROUP BY m.id_manufacturer
                   ) pc";

        try {
            $stats = $this->db->query($sqlCounts, $bindings)->row;
            $avgRow = $this->db->query($sqlAvg, $bindings)->row;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsManufacturerTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_manufacturer: database query failed.', previous: $e);
        }

        return [
            'stats' => array_map('intval', $stats),
            'avg_products_per_manufacturer' => (float) ($avgRow['avg_products_per_manufacturer'] ?? 0),
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
            'column_descriptions' => [
                'id' => 'Manufacturer ID (integer)',
                'name' => 'Manufacturer/brand name',
                'description' => 'Manufacturer description text (from lang table)',
                'active' => 'true = enabled, false = disabled',
                'date_add' => 'Date the manufacturer was created',
                'date_upd' => 'Date the manufacturer was last updated',
                'product_count' => 'Number of products linked to this manufacturer',
            ],
            'filter_capabilities' => self::FILTERS,
            'modes' => ['schema', 'aggregate', 'query'],
            'pagination' => ['type' => 'offset'],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
            ],
        ];
    }

    /**
     * Build WHERE clause and positional bindings from input filters.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(array $input): array
    {
        $conditions = [];
        $bindings = [];

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $conditions[] = 'm.name LIKE ?';
            $bindings[] = '%'.trim((string) $input['search']).'%';
        }

        if (isset($input['active'])) {
            $conditions[] = 'm.active = ?';
            $bindings[] = $input['active'] ? 1 : 0;
        }

        $where = $conditions !== [] ? 'WHERE '.implode(' AND ', $conditions) : '';

        return [$where, $bindings];
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

        if (! in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
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
