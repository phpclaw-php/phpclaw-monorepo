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
 * Products tool - read-only product access with selectable fields.
 */
final class ProductTool implements ToolInterface, ToolRoutingInterface
{
    use ClassifiesProductFields;
    use HasToolExecutionContract;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    private const MAX_OFFSET = 10000;

    private const MAX_SEARCH_LENGTH = 255;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'woocommerce.products.read';

    private const RISK_LEVEL = 'read';

    private const DEFAULT_COLUMNS = [
        'id', 'name', 'sku', 'stock_status', 'stock_qty', 'price',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'sku', 'type', 'status', 'catalog_visibility',
        'price', 'regular_price', 'sale_price', 'on_sale',
        'stock_status', 'stock_qty', 'manage_stock', 'backorders',
        'date_created', 'permalink', 'average_rating',
        'variation_count', 'total_variation_stock', 'review_count',
        'cost_of_goods', 'supplier', 'supplier_sku',
    ];

    private const EXPENSIVE_COLUMNS = [
        'variation_count', 'total_variation_stock', 'review_count',
    ];

    private const VALID_STOCK_STATUSES = ['all', 'instock', 'outofstock', 'onbackorder'];

    public const EXAMPLES = [
        [
            'prompt' => 'show me the products we sell',
            'arguments' => [],
        ],
        [
            'prompt' => 'how many products are in stock?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'which products are out of stock?',
            'arguments' => ['stock_status' => 'outofstock'],
        ],
    ];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'stock_status', 'search', 'limit', 'offset',
        'orderby', 'order',
    ];

    private const SORTABLE = [
        'price' => ['meta_value_num', '_price'],
        'stock_qty' => ['meta_value_num', '_stock'],
        'name' => ['title', null],
        'title' => ['title', null],
        'date' => ['date', null],
        'date_created' => ['date', null],
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
        return 'wc_get_products';
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
Read WooCommerce products. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true     Discover fields, stock statuses and limits. No product read.
  aggregate=true  Product counts by stock status. No product rows returned.
  default         Paginated product list; page with meta.next_offset.

NEVER USE FOR
  Creating, editing, pricing or deleting products; reading downloadable file
  URLs or ids, licence keys or gateway credentials. Those are refused, not
  silently omitted, because a download URL grants access on its own.

PERSONAL AND COMMERCIAL DATA
  cost_of_goods, supplier and supplier_sku are available on explicit request
  only, are never in the default field set, and add a SENSITIVE_DATA warning.

NOTES
  variation_count, total_variation_stock and review_count each cost an extra
  read per product and are excluded from ["*"].
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
                    'description' => 'Return field and stock-status metadata without reading products.',
                    'default' => false,
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return product counts by stock status. Row filters do not apply.',
                    'default' => false,
                ],
                'stock_status' => [
                    'type' => 'string',
                    'description' => 'Restrict to products with this stock status.',
                    'enum' => self::VALID_STOCK_STATUSES,
                    'default' => 'all',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Partial match against product name and SKU.',
                    'minLength' => 1,
                    'maxLength' => self::MAX_SEARCH_LENGTH,
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
        $forbidden = $this->guardCapability('read products');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned product read without handling model policy.
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
            throw new ToolException('ProductTool returned an incomplete product result.');
        }

        $requested = $execution['payload']['columns'];

        foreach ($execution['payload']['products'] as $product) {
            foreach (array_keys($product) as $field) {
                if ($this->isBlockedProductField((string) $field)) {
                    throw new ToolException('ProductTool attempted to return a blocked field.');
                }

                if (
                    in_array($field, self::sensitiveProductFields(), true)
                    && ! in_array($field, $requested, true)
                ) {
                    throw new ToolException('ProductTool returned a sensitive field that was not requested.');
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

            foreach (['columns', 'limit', 'offset', 'stock_status', 'search'] as $argument) {
                if (array_key_exists($argument, $input)) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because aggregate mode counts every product.',
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
            'source' => 'wc_product_api',
        ];

        $warnings = [];

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
     * Fetch one page of products through the WooCommerce product API.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the product query fails.
     */
    private function queryData(array $input): array
    {
        $columns = $this->resolveColumns($input['columns'] ?? []);
        $limit = isset($input['limit']) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($input['offset']) ? (int) $input['offset'] : 0;

        $args = [
            'limit' => $limit,
            'offset' => $offset,
            'paginate' => true,
            'status' => 'publish',
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ];

        if (isset($input['orderby'])) {
            [$orderby, $metaKey] = self::SORTABLE[strtolower(trim((string) $input['orderby']))];

            $args['orderby'] = $orderby;
            $args['order'] = strtoupper(trim((string) ($input['order'] ?? 'ASC')));

            if ($metaKey !== null) {
                $args['meta_key'] = $metaKey;
            }
        }

        if (($input['stock_status'] ?? 'all') !== 'all') {
            $args['stock_status'] = (string) $input['stock_status'];
        }

        if (isset($input['search'])) {
            $args['s'] = trim((string) $input['search']);
        }

        $result = $this->fetch($args);

        $rows = [];

        foreach ($result['products'] as $product) {
            $rows[] = $this->buildRow($product, $columns);
        }

        $total = $result['total'];
        $hasMore = ($offset + count($rows)) < $total;

        return [
            'products' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($rows) : null,
            'columns' => $columns,
            'sensitive' => array_values(array_intersect($columns, self::sensitiveProductFields())),
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

        foreach (self::VALID_STOCK_STATUSES as $status) {
            if ($status === 'all') {
                continue;
            }

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
        ];
    }

    /**
     * Build field and stock-status metadata without reading products.
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
            'stock_statuses' => self::VALID_STOCK_STATUSES,
            'filters' => ['stock_status', 'search', 'limit', 'offset', 'orderby', 'order'],
            'sortable_fields' => array_keys(self::SORTABLE),
            'sort_directions' => self::SORT_DIRECTIONS,
            'modes' => ['schema', 'aggregate', 'query'],
            'pagination' => ['type' => 'offset', 'maximum_offset' => self::MAX_OFFSET],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
                'maximum_search_length' => self::MAX_SEARCH_LENGTH,
            ],
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
     * @throws ToolException When the product query fails.
     */
    private function fetch(array $args): array
    {
        try {
            $result = ($this->fetcher)($args);
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('wc_get_products failed', previous: $e);
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
     * Map one product object to the requested fields.
     *
     * @param  mixed  $product  A WC_Product or compatible object.
     * @param  array<int, string>  $columns  Resolved field list.
     * @return array<string, mixed> Product row.
     */
    private function buildRow(mixed $product, array $columns): array
    {
        $get = static fn (string $method, mixed $fallback = ''): mixed => is_callable([$product, $method])
            ? $product->{$method}()
            : $fallback;

        $getters = [
            'id' => static fn (): int => (int) $get('get_id', 0),
            'name' => static fn (): string => (string) $get('get_name'),
            'sku' => static fn (): string => (string) $get('get_sku'),
            'type' => static fn (): string => (string) $get('get_type'),
            'status' => static fn (): string => (string) $get('get_status'),
            'catalog_visibility' => static fn (): string => (string) $get('get_catalog_visibility'),
            'price' => static fn (): string => (string) $get('get_price'),
            'regular_price' => static fn (): string => (string) $get('get_regular_price'),
            'sale_price' => static fn (): string => (string) $get('get_sale_price'),
            'on_sale' => static fn (): bool => (bool) $get('is_on_sale', false),
            'stock_status' => static fn (): string => (string) $get('get_stock_status'),
            'stock_qty' => static fn (): mixed => $get('get_stock_quantity', null),
            'manage_stock' => static fn (): bool => (bool) $get('get_manage_stock', false),
            'backorders' => static fn (): string => (string) $get('get_backorders'),
            'permalink' => static fn (): string => (string) $get('get_permalink'),
            'average_rating' => static fn (): string => (string) $get('get_average_rating'),
            'review_count' => static fn (): int => (int) $get('get_review_count', 0),
            'date_created' => static function () use ($get): string {
                $date = $get('get_date_created', null);

                return is_object($date) && is_callable([$date, 'date']) ? (string) $date->date('Y-m-d H:i:s') : '';
            },
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

        if (array_key_exists('stock_status', $input)
            && (! is_string($input['stock_status'])
                || ! in_array($input['stock_status'], self::VALID_STOCK_STATUSES, true))) {
            return $this->error(
                'INVALID_STOCK_STATUS',
                '"stock_status" must be one of: '.implode(', ', self::VALID_STOCK_STATUSES).'.',
                ['valid_stock_statuses' => self::VALID_STOCK_STATUSES],
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

        if (array_key_exists('search', $input)) {
            if (! is_string($input['search']) || trim($input['search']) === '') {
                return $this->error('INVALID_SEARCH', '"search" must be a non-empty string.');
            }

            if (mb_strlen($input['search']) > self::MAX_SEARCH_LENGTH) {
                return $this->error(
                    'SEARCH_TOO_LONG',
                    sprintf('"search" may not exceed %d characters.', self::MAX_SEARCH_LENGTH),
                );
            }
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
            domains: ['commerce', 'catalog'],
            tags: ['product', 'products', 'item', 'items', 'sku', 'price', 'prices', 'catalog', 'catalogue', 'listing', 'variation', 'variations', 'title'],
            intents: ['list products', 'find an item', 'show the catalog', 'what do we sell'],
            examples: ['list the products and their prices'],
        );
    }
}
