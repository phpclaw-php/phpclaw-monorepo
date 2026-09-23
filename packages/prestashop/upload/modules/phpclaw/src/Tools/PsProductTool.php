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
 * Products tool: read-only queries with dynamic column access and full filtering.
 */
final class PsProductTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 25;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = [
        'search', 'category_id', 'manufacturer_id',
        'active', 'min_price', 'max_price', 'out_of_stock',
    ];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'search', 'category_id', 'manufacturer_id',
        'active', 'min_price', 'max_price', 'out_of_stock', 'limit', 'offset',
    ];

    private const DEFAULT_COLUMNS = [
        'id', 'name', 'reference', 'price', 'quantity', 'active',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'reference', 'price', 'quantity',
        'active', 'manufacturer_name', 'category_name', 'date_add',
        'date_upd', 'weight', 'ean13', 'upc', 'description_short',
    ];

    private const BLOCKED_COLUMNS = ['description', 'wholesale_price'];

    /**
     * Create a new PsProductTool instance.
     *
     * @param  PsDbInterface|null  $db  Native PrestaShop DB handle.
     * @param  string  $tablePrefix  Table prefix (default 'ps_').
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
        return 'ps_product';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect PrestaShop products with stock and pricing data.

AVAILABLE COLUMNS:
  id, name, reference, price, quantity, active,
  manufacturer_name, category_name, date_add, date_upd, weight,
  ean13, upc, description_short

BLOCKED COLUMNS (refused by name, never returned): description, wholesale_price
  Product cost is not readable through this tool. Asking for it returns REFUSED_COLUMN.

CAPABILITIES:
  - Search by product name or reference (SKU)
  - Filter by category, manufacturer, active status, price range, out of stock
  - Request specific columns or get defaults (id, name, reference, price, quantity, active)
  - Aggregate mode: total products, active/inactive count, out_of_stock count, avg_price
  - Schema mode: discover available columns and filters

EXAMPLES:
  "List recent products" → default query
  "List 4 products" → limit: 4
  "How many products are active?" → aggregate: true
  "Show out of stock items" → out_of_stock: true
  "Products under $50" → max_price: 50
  "Products in category 3" → category_id: 3
  "Search for widget" → search: "widget"
  "Show all columns" → columns: ["*"]
  "What columns can I query?" → schema: true
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
                    'description' => 'Columns to return. Use ["*"] for all available. Omit for defaults (id, name, reference, price, quantity, active).',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available columns and filter capabilities. No DB query.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total products, active/inactive count, out_of_stock count, avg_price.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against product name or reference (SKU).',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by default category ID.',
                ],
                'manufacturer_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by manufacturer/brand ID.',
                ],
                'active' => [
                    'type' => 'integer',
                    'description' => '1 = active only, 0 = inactive only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'min_price' => [
                    'type' => 'number',
                    'description' => 'Minimum product price (tax excluded).',
                ],
                'max_price' => [
                    'type' => 'number',
                    'description' => 'Maximum product price (tax excluded).',
                ],
                'out_of_stock' => [
                    'type' => 'boolean',
                    'description' => 'true = only products with quantity = 0.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100). Default: 25. When the user asks for a specific number of products (e.g. "list 4 products", "top 5"), set this to that number.',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Rows to skip for pagination. Page with meta.next_offset.',
                    'default' => self::DEFAULT_OFFSET,
                    'minimum' => 0,
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
            tags: ['product', 'products', 'item', 'items', 'reference', 'price', 'prices', 'catalog', 'catalogue', 'listing', 'combination', 'combinations', 'attribute', 'active'],
            intents: ['list products', 'find an item', 'show the catalog', 'what do we sell'],
            examples: ['list the products and their prices'],
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
        $forbidden = $this->guardCapability('read PrestaShop products');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_product: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['products'] ?? null)) {
            throw new ToolException('ps_product: the product query returned an incomplete result.');
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
            ['products' => $payload['products']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['products']),
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
     * Schema discovery data, no database query.
     *
     * @return array<string, mixed> Schema discovery data.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'blocked_columns' => self::BLOCKED_COLUMNS,
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

        $refused = $this->rejectBlockedColumns($input);

        if ($refused !== null) {
            return $refused;
        }

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
    }

    /**
     * Refuse a by-name request for a blocked column, so the blocklist is a control and not a label.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when no blocked column was named.
     */
    private function rejectBlockedColumns(array $input): ?string
    {
        if (! isset($input['columns']) || ! is_array($input['columns'])) {
            return null;
        }

        $refused = array_values(array_intersect($input['columns'], self::BLOCKED_COLUMNS));

        if ($refused === []) {
            return null;
        }

        return $this->error(
            'REFUSED_COLUMN',
            'ps_product: the column(s) '.implode(', ', $refused).' cannot be returned by this tool.',
            [
                'refused_columns' => $refused,
                'available_columns' => self::AVAILABLE_COLUMNS,
            ],
        );
    }

    /**
     * Aggregate mode, counts and stats only, zero row data.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException If the query fails.
     */
    private function aggregateData(array $input): array
    {
        $p = $this->tablePrefix;

        $where = '1=1';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN p.active = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN p.active = 0 THEN 1 ELSE 0 END) AS inactive_count,
                    SUM(CASE WHEN COALESCE(sa.quantity, 0) = 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                    ROUND(AVG(p.price), 2) AS avg_price
                FROM `{$p}product` p
                LEFT JOIN `{$p}product_lang` pl
                       ON pl.id_product = p.id_product AND pl.id_lang = 1
                LEFT JOIN (
                    SELECT id_product, SUM(quantity) AS quantity
                    FROM `{$p}stock_available`
                    WHERE id_product_attribute = 0
                    GROUP BY id_product
                ) sa ON sa.id_product = p.id_product
                WHERE {$where}";

        try {
            $row = $this->db->query($sql, $params)->row;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsProductTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_product: database query failed.', previous: $e);
        }

        return [
            'stats' => [
                'total' => (int) ($row['total'] ?? 0),
                'active_count' => (int) ($row['active_count'] ?? 0),
                'inactive_count' => (int) ($row['inactive_count'] ?? 0),
                'out_of_stock_count' => (int) ($row['out_of_stock_count'] ?? 0),
                'avg_price' => (float) ($row['avg_price'] ?? 0),
            ],
        ];
    }

    /**
     * Read one page of products, with a real total from the same WHERE clause.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException If the query fails.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = min(max(0, (int) ($input['offset'] ?? self::DEFAULT_OFFSET)), self::MAX_OFFSET);
        $columns = $this->resolveColumns($input['columns'] ?? []);

        $where = '1=1';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $from = "FROM `{$p}product` p
                LEFT JOIN `{$p}product_lang` pl
                       ON pl.id_product = p.id_product AND pl.id_lang = 1
                LEFT JOIN `{$p}manufacturer` m
                       ON m.id_manufacturer = p.id_manufacturer
                LEFT JOIN `{$p}category_lang` cl
                       ON cl.id_category = p.id_category_default AND cl.id_lang = 1
                LEFT JOIN (
                    SELECT id_product, SUM(quantity) AS quantity
                    FROM `{$p}stock_available`
                    WHERE id_product_attribute = 0
                    GROUP BY id_product
                ) sa ON sa.id_product = p.id_product
                WHERE {$where}";

        $sql = "SELECT p.id_product, pl.name, p.reference, p.price,
                       COALESCE(sa.quantity, 0) AS quantity, p.active,
                       m.name AS manufacturer_name,
                       cl.name AS category_name,
                       p.date_add, p.date_upd, p.weight, p.ean13, p.upc,
                       SUBSTRING(pl.description_short, 1, 300) AS description_short
                {$from}
                ORDER BY p.date_add DESC, p.id_product DESC
                LIMIT {$limit} OFFSET {$offset}";

        try {
            $total = (int) ($this->db->query("SELECT COUNT(DISTINCT p.id_product) AS total {$from}", $params)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $params)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsProductTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_product: database query failed.', previous: $e);
        }

        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->buildRow($row, $columns);
        }

        $capped = ToolOutputEncoder::cap($results, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'products' => $capped['rows'],
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
     * Build a single product row with only the requested columns.
     *
     * @param  array<string, mixed>  $row  Raw database row.
     * @param  array<int, string>  $columns  Requested columns.
     * @return array<string, mixed>
     */
    private function buildRow(array $row, array $columns): array
    {
        $map = [
            'id' => fn () => (int) $row['id_product'],
            'name' => fn () => $row['name'] ?? '',
            'reference' => fn () => $row['reference'] ?? '',
            'price' => fn () => (float) ($row['price'] ?? 0),
            'quantity' => fn () => (int) ($row['quantity'] ?? 0),
            'active' => fn () => (int) ($row['active'] ?? 0),
            'manufacturer_name' => fn () => $row['manufacturer_name'] ?? '',
            'category_name' => fn () => $row['category_name'] ?? '',
            'date_add' => fn () => $row['date_add'] ?? '',
            'date_upd' => fn () => $row['date_upd'] ?? '',
            'weight' => fn () => (float) ($row['weight'] ?? 0),
            'ean13' => fn () => $row['ean13'] ?? '',
            'upc' => fn () => $row['upc'] ?? '',
            'description_short' => fn () => strip_tags((string) ($row['description_short'] ?? '')),
        ];

        $result = [];

        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $result[$col] = $map[$col]();
            }
        }

        return $result;
    }

    /**
     * Resolve requested columns to a safe validated list.
     *
     * @param  array<int, string>|mixed  $requested  Column names from user input.
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
            fn ($c) => is_string($c)
                && in_array($c, self::AVAILABLE_COLUMNS, true)
        );

        if (! in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
        }

        return array_values($valid);
    }

    /**
     * Apply WHERE clause filters from tool input.
     *
     * @param  string  $where  WHERE clause (modified by reference).
     * @param  array<int, mixed>  $params  Positional bind params (modified by reference).
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return void
     */
    private function applyWhereFilters(string &$where, array &$params, array $input): void
    {
        if (isset($input['search']) && (string) $input['search'] !== '') {
            $where .= ' AND (pl.name LIKE ? OR p.reference LIKE ?)';
            $term = '%'.$input['search'].'%';
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['category_id'])) {
            $where .= ' AND p.id_category_default = ?';
            $params[] = (int) $input['category_id'];
        }

        if (isset($input['manufacturer_id'])) {
            $where .= ' AND p.id_manufacturer = ?';
            $params[] = (int) $input['manufacturer_id'];
        }

        if (isset($input['active'])) {
            $where .= ' AND p.active = ?';
            $params[] = (int) $input['active'];
        }

        if (isset($input['min_price'])) {
            $where .= ' AND p.price >= ?';
            $params[] = (float) $input['min_price'];
        }

        if (isset($input['max_price'])) {
            $where .= ' AND p.price <= ?';
            $params[] = (float) $input['max_price'];
        }

        if (! empty($input['out_of_stock'])) {
            $where .= ' AND COALESCE(sa.quantity, 0) = 0';
        }
    }
}
