<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Products tool: dynamic column access with full filtering.
 */
final class OcProductTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const DEFAULT_COLUMNS = [
        'id', 'name', 'model', 'price', 'quantity', 'status',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'model', 'sku', 'price', 'quantity', 'status',
        'manufacturer', 'category_name', 'date_added', 'date_modified',
        'weight', 'image', 'viewed', 'sort_order',
    ];

    private const BLOCKED_COLUMNS = ['description'];

    protected const ERROR_LABEL = 'product';

    private ?array $productColumnCache = null;

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_product';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect OpenCart products with stock and pricing data.

AVAILABLE COLUMNS:
  id, name, model, sku, price, quantity, status, manufacturer,
  category_name, date_added, date_modified, weight, image, viewed, sort_order

BLOCKED COLUMNS (never returned): description

CAPABILITIES:
  - Search by product name, model, or SKU
  - Filter by category, manufacturer, status, price range, out of stock
  - Request specific columns or get defaults (id, name, model, price, quantity, status)
  - Aggregate mode: total products, active/inactive count, out_of_stock count, avg_price

EXAMPLES:
  "List recent products" -> default query
  "How many products are active?" -> aggregate: true
  "Show out of stock items" -> out_of_stock: true
  "Products under $50" -> max_price: 50
  "Products in category 3" -> category_id: 3
  "Products by manufacturer 5" -> manufacturer_id: 5
  "Search for widget" -> search: "widget"
  "Show all columns" -> columns: ["*"]
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
                    'description' => 'Columns to return. Use ["*"] for all available. Omit for defaults (id, name, model, price, quantity, status).',
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
                    'description' => 'Search term matched against product name, model, or SKU.',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by category ID (via the product-to-category mapping table).',
                ],
                'manufacturer_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by manufacturer/brand ID.',
                ],
                'status' => [
                    'type' => 'integer',
                    'description' => '1 = enabled only, 0 = disabled only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'min_price' => [
                    'type' => 'number',
                    'description' => 'Minimum product price (inclusive).',
                ],
                'max_price' => [
                    'type' => 'number',
                    'description' => 'Maximum product price (inclusive).',
                ],
                'out_of_stock' => [
                    'type' => 'boolean',
                    'description' => 'true = only products with quantity = 0.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100). Default: 25.',
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
     * Authorise the caller, resolve the schema shortcut, and clamp the limit.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the database is unavailable for a non-schema call.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('search and view OpenCart products');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if (! empty($input['schema'])) {
            return ['input' => ['schema' => true], 'result' => null];
        }

        if ($this->db === null) {
            throw new ToolException('oc_product: no database connection available.');
        }

        $input['limit'] = $this->clampLimit($input);

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the query or schema lookup selected by the validated input.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        if (! empty($input['aggregate'])) {
            return ['type' => 'aggregate', 'payload' => $this->aggregateData($input)];
        }

        return ['type' => 'query', 'payload' => $this->queryData($input)];
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
            throw new ToolException('oc_product: aggregate result is incomplete.');
        }

        if ($execution['type'] === 'query' && ! is_array($execution['payload']['products'] ?? null)) {
            throw new ToolException('oc_product: query result is incomplete.');
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
            ['products' => $payload['products']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'columns_returned' => $payload['columns_returned'],
                'truncated' => $payload['truncated'],
            ],
        );
    }

    /**
     * Return static schema metadata without querying the database.
     *
     * @return array<string, mixed>
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'blocked_columns' => self::BLOCKED_COLUMNS,
            'filter_capabilities' => [
                'search', 'category_id', 'manufacturer_id',
                'status', 'min_price', 'max_price', 'out_of_stock',
            ],
        ];
    }

    /**
     * Run the aggregate statistics query for products.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
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
                    SUM(CASE WHEN p.status = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN p.status = 0 THEN 1 ELSE 0 END) AS inactive_count,
                    SUM(CASE WHEN p.quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                    ROUND(AVG(p.price), 2) AS avg_price
                FROM `{$p}product` p
                LEFT JOIN `{$p}product_description` pd
                       ON pd.product_id = p.product_id AND pd.language_id = 1
                WHERE {$where}";

        $row = $this->fetchOne($sql, $params);

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
     * Execute the product list query with dynamic column selection and byte-budget truncation.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);
        $columns = $this->resolveColumns($input['columns'] ?? []);
        $where = '1=1';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $viewedExpr = $this->hasProductColumn('viewed') ? 'p.viewed' : '0 AS viewed';

        $sql = "SELECT p.product_id, pd.name, p.model, p.sku, p.price,
                       p.quantity, p.status, p.manufacturer_id,
                       m.name AS manufacturer_name,
                       (SELECT cd2.name FROM `{$p}product_to_category` p2c2
                        LEFT JOIN `{$p}category_description` cd2 ON cd2.category_id = p2c2.category_id AND cd2.language_id = 1
                        WHERE p2c2.product_id = p.product_id LIMIT 1) AS category_name,
                       p.date_added, p.date_modified, p.weight,
                       p.image, {$viewedExpr}, p.sort_order
                FROM `{$p}product` p
                LEFT JOIN `{$p}product_description` pd
                       ON pd.product_id = p.product_id AND pd.language_id = 1
                LEFT JOIN `{$p}manufacturer` m
                       ON m.manufacturer_id = p.manufacturer_id
                WHERE {$where}
                ORDER BY p.date_added DESC
                LIMIT {$limit}";

        $rows = $this->fetchRows($sql, $params);
        $products = [];

        foreach ($rows as $row) {
            $products[] = $this->buildRow($row, $columns);
        }

        return $this->capProducts($products, $columns);
    }

    /**
     * Cap the product list at the output byte budget and report truncation.
     *
     * @param  list<array<string, mixed>>  $products  Built product rows.
     * @param  array<int, string>  $columns  Resolved column list.
     * @return array<string, mixed>
     */
    private function capProducts(array $products, array $columns): array
    {
        $kept = [];
        $bytes = 0;

        foreach ($products as $product) {
            $encoded = json_encode($product, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $product;
            $bytes += strlen($encoded);
        }

        return [
            'products' => $kept,
            'total' => count($products),
            'columns_returned' => $columns,
            'truncated' => count($kept) < count($products),
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
            'id' => fn () => (int) $row['product_id'],
            'name' => fn () => $row['name'] ?? '',
            'model' => fn () => $row['model'] ?? '',
            'sku' => fn () => $row['sku'] ?? '',
            'price' => fn () => (float) ($row['price'] ?? 0),
            'quantity' => fn () => (int) ($row['quantity'] ?? 0),
            'status' => fn () => (int) ($row['status'] ?? 0),
            'manufacturer' => fn () => $row['manufacturer_name'] ?? '',
            'category_name' => fn () => $row['category_name'] ?? '',
            'date_added' => fn () => $row['date_added'] ?? '',
            'date_modified' => fn () => $row['date_modified'] ?? '',
            'weight' => fn () => (float) ($row['weight'] ?? 0),
            'image' => fn () => $row['image'] ?? '',
            'viewed' => fn () => (int) ($row['viewed'] ?? 0),
            'sort_order' => fn () => (int) ($row['sort_order'] ?? 0),
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
     * Resolve requested columns to a safe validated list, excluding blocked columns.
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
                && ! in_array($c, self::BLOCKED_COLUMNS, true)
                && in_array($c, self::AVAILABLE_COLUMNS, true)
        );

        if (! in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
        }

        return array_values($valid) ?: self::DEFAULT_COLUMNS;
    }

    /**
     * Apply WHERE clause filters from tool input.
     *
     * @param  string  $where  WHERE clause fragment (modified by reference).
     * @param  array<int, mixed>  $params  Positional bind parameters (modified by reference).
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return void
     */
    private function applyWhereFilters(string &$where, array &$params, array $input): void
    {
        if (isset($input['search']) && (string) $input['search'] !== '') {
            $where .= ' AND (pd.name LIKE ? OR p.model LIKE ? OR p.sku LIKE ?)';
            $term = '%'.$input['search'].'%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['category_id'])) {
            $where .= " AND p.product_id IN (SELECT product_id FROM `{$this->tablePrefix}product_to_category` WHERE category_id = ?)";
            $params[] = (int) $input['category_id'];
        }

        if (isset($input['manufacturer_id'])) {
            $where .= ' AND p.manufacturer_id = ?';
            $params[] = (int) $input['manufacturer_id'];
        }

        if (isset($input['status'])) {
            $where .= ' AND p.status = ?';
            $params[] = (int) $input['status'];
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
            $where .= ' AND p.quantity = 0';
        }
    }

    /**
     * Check whether a column exists on the product table using a cached SHOW COLUMNS result.
     *
     * @param  string  $column  Column name to look up.
     * @return bool
     */
    private function hasProductColumn(string $column): bool
    {
        if ($this->db === null) {
            return false;
        }

        if ($this->productColumnCache === null) {
            $this->productColumnCache = [];

            try {
                foreach ($this->db->query("SHOW COLUMNS FROM `{$this->tablePrefix}product`")->rows as $row) {
                    $name = (string) ($row['Field'] ?? '');

                    if ($name !== '') {
                        $this->productColumnCache[$name] = true;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return isset($this->productColumnCache[$column]);
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
            tags: ['product', 'products', 'item', 'items', 'sku', 'model', 'price', 'prices', 'catalog', 'catalogue', 'listing', 'stock', 'quantity', 'inventory'],
            intents: ['list products', 'find an item', 'show the catalog', 'what do we sell'],
            examples: ['list the products and their prices'],
        );
    }
}
