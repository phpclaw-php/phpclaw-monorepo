<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WooCommerce\Tools\Concerns\ClassifiesProductFields;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Stock tool - read-only inventory view over products and variations.
 */
final class StockTool implements ToolInterface, ToolRoutingInterface
{
    use ClassifiesProductFields;
    use HasToolExecutionContract;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    private const MAX_OFFSET = 10000;

    private const MAX_SCAN = 1000;

    private const SCAN_PAGE = 100;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'woocommerce.stock.read';

    private const RISK_LEVEL = 'read';

    private const DEFAULT_COLUMNS = [
        'id', 'name', 'sku', 'stock_status', 'stock_qty', 'manage_stock', 'low_stock',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'sku', 'type', 'stock_status', 'stock_qty', 'manage_stock',
        'backorders', 'low_stock', 'price',
        'total_variation_stock', 'variation_count',
        'cost_of_goods', 'supplier', 'supplier_sku',
    ];

    private const EXPENSIVE_COLUMNS = [
        'total_variation_stock', 'variation_count',
    ];

    private const VALID_FILTERS = ['all', 'instock', 'outofstock', 'onbackorder', 'lowstock'];

    public const EXAMPLES = [
        [
            'prompt' => 'show me our current stock levels',
            'arguments' => [],
        ],
        [
            'prompt' => 'how much of the catalogue is out of stock?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'which products are running low on stock?',
            'arguments' => ['filter' => 'lowstock'],
        ],
    ];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'filter', 'limit', 'offset',
        'orderby', 'order',
    ];

    private const SORTABLE = [
        'stock_qty' => ['meta_value_num', '_stock'],
        'price' => ['meta_value_num', '_price'],
        'name' => ['title', null],
        'id' => ['ID', null],
    ];

    private const SORT_DIRECTIONS = ['ASC', 'DESC'];

    private $fetcher;

    /**
     * Bind the product fetcher, defaulting to wc_get_products().
     *
     * @param  callable(array<string,mixed>): mixed|null  $fetcher  Overrides wc_get_products() for testing.
     */
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher ?? static fn (array $args): mixed => wc_get_products($args);
    }

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wc_stock';
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
        return <<<'DESC'
Check WooCommerce stock levels. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true     Discover fields, filters and limits. No stock read.
  aggregate=true  Counts by stock status plus the low-stock threshold.
  default         Paginated inventory list; page with meta.next_offset.

FILTERS
  all, instock, outofstock, onbackorder, lowstock.
  lowstock means stock-managed, above zero, at or below the store threshold.

NEVER USE FOR
  Adjusting stock, editing products, or reading downloadable file URLs or
  licence keys. Those are refused, not silently omitted.

COMMERCIAL DATA
  cost_of_goods, supplier and supplier_sku are available on explicit request
  only, are never in the default field set, and add a SENSITIVE_DATA warning.

NOTES
  total_variation_stock and variation_count read a product's variations in one
  batched call and are excluded from ["*"].
  The lowstock scan is bounded; meta.scan_truncated reports when it stopped early.
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
                'columns' => [
                    'type' => 'array',
                    'description' => 'Fields to return. Omit for defaults. ["*"] returns all inexpensive fields.',
                    'items' => ['type' => 'string', 'enum' => [...self::AVAILABLE_COLUMNS, '*']],
                    'uniqueItems' => true,
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return field and filter metadata without reading stock.',
                    'default' => false,
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return stock counts by status. Row filters do not apply.',
                    'default' => false,
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Restrict to products in this stock condition.',
                    'enum' => self::VALID_FILTERS,
                    'default' => 'all',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Rows per page.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Rows to skip. Use meta.next_offset from the previous response.',
                    'minimum' => 0,
                    'maximum' => self::MAX_OFFSET,
                    'default' => 0,
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
        $forbidden = $this->guardCapability('read stock levels');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned stock read without handling model policy.
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

        if (($input['aggregate'] ?? false) === true) {
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
        if ($execution['type'] !== 'query') {
            return ['result' => null];
        }

        if (! is_array($execution['payload']['products'] ?? null)) {
            throw new ToolException('StockTool returned an incomplete stock result.');
        }

        $requested = $execution['payload']['columns'];

        foreach ($execution['payload']['products'] as $product) {
            foreach (array_keys($product) as $field) {
                if ($this->isBlockedProductField((string) $field)) {
                    throw new ToolException('StockTool attempted to return a blocked field.');
                }

                if (
                    in_array($field, self::sensitiveProductFields(), true)
                    && ! in_array($field, $requested, true)
                ) {
                    throw new ToolException('StockTool returned a sensitive field that was not requested.');
                }
            }
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
            return $this->success($payload, [
                'mode' => 'schema',
                'database_query_performed' => false,
            ]);
        }

        if ($execution['type'] === 'aggregate') {
            $warnings = [];

            foreach (['columns', 'limit', 'offset', 'filter'] as $argument) {
                if (array_key_exists($argument, $input)) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because aggregate mode counts every published product.',
                            $argument,
                        ),
                    ];
                }
            }

            return $this->success($payload, ['mode' => 'aggregate'], $warnings);
        }

        $meta = [
            'mode' => 'query',
            'total' => $payload['total'],
            'count' => count($payload['products']),
            'limit' => $payload['limit'],
            'offset' => $payload['offset'],
            'has_more' => $payload['has_more'],
            'next_offset' => $payload['next_offset'],
            'columns_returned' => $payload['columns'],
            'filter' => $payload['filter'],
            'low_stock_threshold' => $payload['threshold'],
            'source' => 'wc_product_api',
        ];

        $warnings = [];

        if ($payload['scan_truncated']) {
            $meta['scan_truncated'] = true;
            $meta['scanned'] = $payload['scanned'];

            $warnings[] = [
                'code' => 'SCAN_TRUNCATED',
                'message' => sprintf(
                    'The low-stock scan stopped after %d products. Narrow the request or raise the store threshold.',
                    self::MAX_SCAN,
                ),
            ];
        }

        if ($payload['sensitive'] !== []) {
            $meta['sensitive_fields_returned'] = $payload['sensitive'];

            $warnings[] = [
                'code' => 'SENSITIVE_DATA',
                'message' => 'The response contains commercial data. '
                    .'Use it only for the requested purpose and '
                    .'do not repeat it in public output.',
            ];
        }

        return $this->success(['products' => $payload['products']], $meta, $warnings);
    }

    /**
     * Collect one page of products for the requested stock condition.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the stock query fails.
     */
    private function queryData(array $input): array
    {
        $columns = $this->resolveColumns($input['columns'] ?? []);
        $limit = isset($input['limit']) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($input['offset']) ? (int) $input['offset'] : 0;
        $filter = (string) ($input['filter'] ?? 'all');
        $threshold = $this->lowStockThreshold();

        if ($filter === 'lowstock') {
            $scan = $this->scanLowStock($threshold, $limit, $offset);
            $products = $scan['products'];
            $total = $scan['total'];
            $truncated = $scan['truncated'];
            $scanned = $scan['scanned'];
        } else {
            $args = [
                'limit' => $limit,
                'offset' => $offset,
                'paginate' => true,
                'status' => 'publish',
                'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
            ];

            if (isset($input['orderby'])) {
                [$orderby, $metaKey] = self::SORTABLE[strtolower(trim((string) $input['orderby']))];

                $args['orderby'] = $orderby;
                $args['order'] = strtoupper(trim((string) ($input['order'] ?? 'ASC')));

                if ($metaKey !== null) {
                    $args['meta_key'] = $metaKey;
                }
            }

            if ($filter !== 'all') {
                $args['stock_status'] = $filter;
            }

            $page = $this->fetch($args);
            $products = $page['products'];
            $total = $page['total'];
            $truncated = false;
            $scanned = count($products);
        }

        $rows = [];

        foreach ($products as $product) {
            $rows[] = $this->buildRow($product, $columns, $threshold);
        }

        $hasMore = ($offset + count($rows)) < $total;

        return [
            'products' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($rows) : null,
            'columns' => $columns,
            'filter' => $filter,
            'threshold' => $threshold,
            'scan_truncated' => $truncated,
            'scanned' => $scanned,
            'sensitive' => array_values(array_intersect($columns, self::sensitiveProductFields())),
        ];
    }

    /**
     * Page through in-stock managed products to find low-stock ones, up to a hard cap.
     *
     * @param  int  $threshold  Quantity at or below which a product counts as low stock.
     * @param  int  $limit  Rows wanted on this page.
     * @param  int  $offset  Rows to skip.
     * @return array{products: array<int, mixed>, total: int, truncated: bool, scanned: int}
     *
     * @throws ToolException When a candidate page cannot be read.
     */
    private function scanLowStock(int $threshold, int $limit, int $offset): array
    {
        $matched = [];
        $scanned = 0;
        $page = 0;
        $truncated = false;

        while ($scanned < self::MAX_SCAN) {
            $batch = $this->fetch([
                'limit' => self::SCAN_PAGE,
                'offset' => $page * self::SCAN_PAGE,
                'paginate' => true,
                'status' => 'publish',
                'stock_status' => 'instock',
                'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
            ]);

            if ($batch['products'] === []) {
                break;
            }

            foreach ($batch['products'] as $product) {
                $scanned++;

                if ($this->isLowStock($product, $threshold)) {
                    $matched[] = $product;
                }
            }

            $page++;

            if (($page * self::SCAN_PAGE) >= $batch['total']) {
                break;
            }

            if ($scanned >= self::MAX_SCAN) {
                $truncated = true;

                break;
            }
        }

        return [
            'products' => array_slice($matched, $offset, $limit),
            'total' => count($matched),
            'truncated' => $truncated,
            'scanned' => $scanned,
        ];
    }

    /**
     * Count published products per stock status.
     *
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When a count query fails.
     */
    private function aggregateData(): array
    {
        $byStatus = [];
        $total = 0;

        foreach (['instock', 'outofstock', 'onbackorder'] as $status) {
            $count = $this->fetch([
                'limit' => 1,
                'paginate' => true,
                'status' => 'publish',
                'stock_status' => $status,
            ])['total'];

            $byStatus[$status] = $count;
            $total += $count;
        }

        return [
            'total_products' => $total,
            'by_stock_status' => $byStatus,
            'low_stock_threshold' => $this->lowStockThreshold(),
        ];
    }

    /**
     * Build field and filter metadata without reading stock.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'examples' => self::EXAMPLES,
            'default_columns' => self::DEFAULT_COLUMNS,
            'sensitive_columns' => self::sensitiveProductFields(),
            'expensive_columns' => self::EXPENSIVE_COLUMNS,
            'blocked_columns' => self::blockedProductFields(),
            'filters' => self::VALID_FILTERS,
            'sortable_fields' => array_keys(self::SORTABLE),
            'sort_directions' => self::SORT_DIRECTIONS,
            'modes' => ['schema', 'aggregate', 'query'],
            'pagination' => ['type' => 'offset', 'maximum_offset' => self::MAX_OFFSET],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
                'maximum_lowstock_scan' => self::MAX_SCAN,
            ],
            'low_stock_threshold' => $this->lowStockThreshold(),
            'woocommerce_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
        ];
    }

    /**
     * Call the product fetcher and normalise both the paginated and plain shapes.
     *
     * @param  array<string, mixed>  $args  wc_get_products() arguments.
     * @return array{products: array<int, mixed>, total: int}
     *
     * @throws ToolException When the stock query fails.
     */
    private function fetch(array $args): array
    {
        try {
            $result = ($this->fetcher)($args);
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('WC stock query failed', previous: $e);
        }

        if (is_object($result) && isset($result->products)) {
            return [
                'products' => (array) $result->products,
                'total' => (int) ($result->total ?? count((array) $result->products)),
            ];
        }

        $products = is_array($result) ? $result : [];

        return ['products' => $products, 'total' => count($products)];
    }

    /**
     * Map one product to the requested stock fields.
     *
     * @param  mixed  $product  A WC_Product or compatible object.
     * @param  array<int, string>  $columns  Resolved field list.
     * @param  int  $threshold  Low-stock threshold.
     * @return array<string, mixed> Stock row.
     */
    private function buildRow(mixed $product, array $columns, int $threshold): array
    {
        $get = static fn (string $method, mixed $fallback = ''): mixed => is_callable([$product, $method])
            ? $product->{$method}()
            : $fallback;

        $getters = [
            'id' => static fn (): int => (int) $get('get_id', 0),
            'name' => static fn (): string => (string) $get('get_name'),
            'sku' => static fn (): string => (string) ($get('get_sku') ?: 'n/a'),
            'type' => static fn (): string => (string) $get('get_type'),
            'stock_status' => static fn (): string => (string) $get('get_stock_status'),
            'stock_qty' => static fn (): mixed => $get('get_stock_quantity', null),
            'manage_stock' => static fn (): bool => (bool) $get('managing_stock', false),
            'backorders' => static fn (): string => (string) $get('get_backorders'),
            'price' => static fn (): string => (string) $get('get_price'),
            'low_stock' => fn (): bool => $this->isLowStock($product, $threshold),
            'variation_count' => static function () use ($get): int {
                $children = $get('get_children', []);

                return is_array($children) ? count($children) : 0;
            },
            'total_variation_stock' => fn (): mixed => $this->sumVariationStock($product, $this->fetcher),
        ];

        $row = [];

        foreach ($columns as $column) {
            if (isset($getters[$column])) {
                $row[$column] = $getters[$column]();

                continue;
            }

            if (in_array($column, self::sensitiveProductFields(), true)) {
                $row[$column] = $this->readSensitiveProductMeta($product, $column);
            }
        }

        return $row;
    }

    /**
     * Report whether a product is stock-managed and at or below the threshold.
     *
     * @param  mixed  $product  A WC_Product or compatible object.
     * @param  int  $threshold  Low-stock threshold.
     * @return bool
     */
    private function isLowStock(mixed $product, int $threshold): bool
    {
        if (! is_callable([$product, 'managing_stock']) || ! $product->managing_stock()) {
            return false;
        }

        $qty = is_callable([$product, 'get_stock_quantity']) ? $product->get_stock_quantity() : null;

        return $qty !== null && $qty > 0 && $qty <= $threshold;
    }

    /**
     * Read the store's configured low-stock threshold.
     *
     * @return int
     */
    private function lowStockThreshold(): int
    {
        return (int) get_option('woocommerce_notify_low_stock_amount', 2);
    }

    /**
     * Resolve requested fields to a validated list.
     *
     * @param  mixed  $requested  Field names from validated input.
     * @return array<int, string> Resolved field list.
     */
    private function resolveColumns(mixed $requested): array
    {
        if (! is_array($requested) || $requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        if (in_array('*', $requested, true)) {
            return array_values(array_diff(
                self::AVAILABLE_COLUMNS,
                self::EXPENSIVE_COLUMNS,
                self::sensitiveProductFields(),
            ));
        }

        return array_values(array_unique(array_map('strval', $requested)));
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

        foreach (['schema', 'aggregate'] as $flag) {
            if (array_key_exists($flag, $input) && ! is_bool($input[$flag])) {
                return $this->error('INVALID_ARGUMENT', sprintf('"%s" must be a boolean.', $flag));
            }
        }

        if (($input['schema'] ?? false) === true && ($input['aggregate'] ?? false) === true) {
            return $this->error('CONFLICTING_MODES', 'Set only one of "schema" or "aggregate".');
        }

        $paging = $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);

        if ($paging !== null) {
            return $paging;
        }

        if (array_key_exists('filter', $input)
            && (! is_string($input['filter']) || ! in_array($input['filter'], self::VALID_FILTERS, true))) {
            return $this->error(
                'INVALID_FILTER',
                '"filter" must be one of: '.implode(', ', self::VALID_FILTERS).'.',
                ['valid_filters' => self::VALID_FILTERS],
            );
        }

        if (array_key_exists('orderby', $input)
            && (! is_string($input['orderby'])
                || ! isset(self::SORTABLE[strtolower(trim($input['orderby']))]))) {
            return $this->error(
                'INVALID_ORDERBY',
                '"orderby" must be one of: '.implode(', ', array_keys(self::SORTABLE)).'.',
                ['sortable_fields' => array_keys(self::SORTABLE)],
            );
        }

        if (array_key_exists('order', $input)
            && (! is_string($input['order'])
                || ! in_array(strtoupper(trim($input['order'])), self::SORT_DIRECTIONS, true))) {
            return $this->error(
                'INVALID_ORDER',
                '"order" must be one of: '.implode(', ', self::SORT_DIRECTIONS).'.',
                ['sort_directions' => self::SORT_DIRECTIONS],
            );
        }

        return $this->validateColumns($input);
    }

    /**
     * Validate the columns argument against the available and blocked field lists.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validateColumns(array $input): ?string
    {
        if (! array_key_exists('columns', $input)) {
            return null;
        }

        if (! is_array($input['columns'])) {
            return $this->error('INVALID_COLUMNS', '"columns" must be an array of field names.');
        }

        foreach ($input['columns'] as $column) {
            if (! is_string($column)) {
                return $this->error('INVALID_COLUMNS', 'Every entry in "columns" must be a string.');
            }

            if ($this->isBlockedProductField($column)) {
                return $this->error(
                    'BLOCKED_COLUMN',
                    sprintf('Field "%s" is blocked and cannot be read by this tool.', $column),
                    [
                        'blocked_columns' => self::blockedProductFields(),
                        'blocked_prefixes' => self::blockedProductMetaPrefixes(),
                    ],
                );
            }

            if ($column === '*') {
                continue;
            }

            if (! in_array($column, self::AVAILABLE_COLUMNS, true)) {
                return $this->error(
                    'UNKNOWN_COLUMN',
                    sprintf('Field "%s" is not available from this tool.', $column),
                    ['available_columns' => self::AVAILABLE_COLUMNS],
                );
            }
        }

        return null;
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
            domains: ['commerce', 'inventory'],
            tags: ['stock', 'inventory', 'quantity', 'qty', 'available', 'backorder', 'instock', 'outofstock', 'low', 'levels', 'remaining'],
            intents: ['check stock', 'show inventory levels', 'what is running low'],
            examples: ['which products are low on stock'],
        );
    }
}
