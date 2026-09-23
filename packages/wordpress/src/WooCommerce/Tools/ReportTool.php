<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Reports tool - read-only sales aggregates over a bounded date range.
 */
final class ReportTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const MAX_RANGE_DAYS = 366;

    private const DEFAULT_PERIOD = 'this_month';

    private const MAX_ORDERS_FALLBACK = 500;

    private const DEFAULT_TOP_PRODUCTS = 5;

    private const MAX_TOP_PRODUCTS = 20;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'woocommerce.reports.read';

    private const RISK_LEVEL = 'read';

    private const COUNTED_STATUSES = ['wc-completed', 'wc-processing'];

    private const VALID_PERIODS = [
        'today', 'this_week', 'this_month', 'last_month', 'this_year', 'custom',
    ];

    private const ALLOWED_KEYS = [
        'period', 'date_from', 'date_to', 'top_products', 'schema',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'how did sales go this month?',
            'arguments' => [],
        ],
        [
            'prompt' => 'what date ranges can you report on?',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'what are our top 3 selling products?',
            'arguments' => ['top_products' => 3],
        ],
    ];

    private $orderFetcher;

    /**
     * Bind the order fetcher used by the fallback aggregate path, or null for the default.
     *
     * @param  callable(array<string,mixed>): mixed|null  $orderFetcher  Overrides wc_get_orders() for testing.
     */
    public function __construct(?callable $orderFetcher = null)
    {
        $this->orderFetcher = $orderFetcher;
    }

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wc_report';
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
WooCommerce sales report: revenue, order count, average order value and top
sellers over a date range. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true   Discover periods, limits and the range maximum. No report run.
  default       Run the report for the requested period.

PERIODS
  today, this_week, this_month, last_month, this_year, custom.
  Default is this_month. custom takes date_from and date_to as YYYY-MM-DD.

NEVER USE FOR
  Editing orders or refunds. This tool only reads aggregates.

NOTES
  A range longer than 366 days is clamped to the maximum and reported with a
  RANGE_CLAMPED warning. meta.date_from and meta.date_to are always the range
  actually applied, not the range asked for.
  Money values carry meta.currency; never read a figure without it.
  Only completed and processing orders count toward revenue.
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
                'period' => [
                    'type' => 'string',
                    'description' => 'Report period. Use custom with date_from and date_to for an explicit range.',
                    'enum' => self::VALID_PERIODS,
                    'default' => self::DEFAULT_PERIOD,
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Range start as YYYY-MM-DD. Only used when period is custom.',
                    'pattern' => '^\d{4}-\d{2}-\d{2}$',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Range end as YYYY-MM-DD. Only used when period is custom.',
                    'pattern' => '^\d{4}-\d{2}-\d{2}$',
                ],
                'top_products' => [
                    'type' => 'integer',
                    'description' => 'How many best sellers to return.',
                    'minimum' => 1,
                    'maximum' => self::MAX_TOP_PRODUCTS,
                    'default' => self::DEFAULT_TOP_PRODUCTS,
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return period and limit metadata without running a report.',
                    'default' => false,
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
        $forbidden = $this->guardCapability('read sales reports');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned report without handling model policy.
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

        $range = $this->resolveRange($input);
        $top = isset($input['top_products']) ? (int) $input['top_products'] : self::DEFAULT_TOP_PRODUCTS;

        $analytics = $this->analyticsReport($range, $top);

        if ($analytics !== null) {
            return ['type' => 'report', 'payload' => $analytics + ['range' => $range]];
        }

        return ['type' => 'report', 'payload' => $this->orderApiReport($range, $top) + ['range' => $range]];
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
        if ($execution['type'] === 'schema') {
            return ['result' => null];
        }

        foreach (['total_revenue', 'order_count', 'top_products'] as $key) {
            if (! array_key_exists($key, $execution['payload'])) {
                throw new ToolException('ReportTool returned an incomplete report result.');
            }
        }

        if ($execution['payload']['order_count'] < 0 || $execution['payload']['total_revenue'] < 0) {
            throw new ToolException('ReportTool produced a negative aggregate.');
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

        $range = $payload['range'];
        $orderCount = (int) $payload['order_count'];
        $revenue = round((float) $payload['total_revenue'], 2);

        $warnings = [];

        if ($range['clamped']) {
            $warnings[] = [
                'code' => 'RANGE_CLAMPED',
                'message' => sprintf(
                    'The requested range spanned %d days and was clamped to the %d day maximum. '
                    .'The report covers %s to %s.',
                    $range['requested_days'],
                    self::MAX_RANGE_DAYS,
                    $range['from'],
                    $range['to'],
                ),
            ];
        }

        if (($payload['truncated'] ?? false) === true) {
            $warnings[] = [
                'code' => 'RESULT_TRUNCATED',
                'message' => sprintf(
                    'More than %d orders fall in this range and only the first %d were aggregated. '
                    .'The figures are a lower bound. Narrow the range for an exact total.',
                    self::MAX_ORDERS_FALLBACK,
                    self::MAX_ORDERS_FALLBACK,
                ),
            ];
        }

        if (($payload['source'] ?? '') === 'wc_orders_api') {
            $warnings[] = [
                'code' => 'ANALYTICS_UNAVAILABLE',
                'message' => 'WooCommerce Analytics has no rows for this range, so the report was '
                    .'aggregated from the order API instead. Figures are correct but the query is '
                    .'more expensive.',
            ];
        }

        return $this->success(
            [
                'total_revenue' => $revenue,
                'order_count' => $orderCount,
                'avg_order_value' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0.0,
                'top_products' => $payload['top_products'],
                'unique_products' => (int) ($payload['unique_products'] ?? count($payload['top_products'])),
            ],
            [
                'mode' => 'report',
                'period' => $range['period'],
                'date_from' => $range['from'],
                'date_to' => $range['to'],
                'range_days' => $range['days'],
                'range_clamped' => $range['clamped'],
                'currency' => $this->currency(),
                'counted_statuses' => self::COUNTED_STATUSES,
                'source' => $payload['source'],
            ],
            $warnings,
        );
    }

    /**
     * Aggregate from the WooCommerce Analytics lookup tables.
     *
     * @param  array<string, mixed>  $range  Resolved date range.
     * @param  int  $top  How many best sellers to return.
     * @return array<string, mixed>|null Report data, or null when Analytics has no rows for the range.
     */
    private function analyticsReport(array $range, int $top): ?array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return null;
        }

        $statsTable = $wpdb->prefix.'wc_order_stats';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $statsTable)) !== $statsTable) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count(self::COUNTED_STATUSES), '%s'));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS order_count, COALESCE(SUM(total_sales), 0) AS revenue
             FROM {$statsTable}
             WHERE date_created >= %s AND date_created <= %s AND status IN ({$placeholders})",
            $range['from'].' 00:00:00',
            $range['to'].' 23:59:59',
            ...self::COUNTED_STATUSES,
        ));

        if ($row === null) {
            return null;
        }

        $orderCount = (int) ($row->order_count ?? 0);

        if ($orderCount === 0) {
            return null;
        }

        return [
            'total_revenue' => (float) ($row->revenue ?? 0),
            'order_count' => $orderCount,
            'top_products' => $this->analyticsTopProducts($range, $top),
            'unique_products' => $this->analyticsUniqueProducts($range),
            'source' => 'wc_order_stats',
            'truncated' => false,
        ];
    }

    /**
     * Read best sellers from the Analytics product lookup table.
     *
     * @param  array<string, mixed>  $range  Resolved date range.
     * @param  int  $top  How many best sellers to return.
     * @return array<int, array<string, mixed>>
     */
    private function analyticsTopProducts(array $range, int $top): array
    {
        global $wpdb;

        $lookup = $wpdb->prefix.'wc_order_product_lookup';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, SUM(product_qty) AS qty
             FROM {$lookup}
             WHERE date_created >= %s AND date_created <= %s
             GROUP BY product_id
             ORDER BY qty DESC, product_id ASC
             LIMIT %d",
            $range['from'].' 00:00:00',
            $range['to'].' 23:59:59',
            $top,
        ));

        $products = [];

        foreach ((array) $rows as $row) {
            $id = (int) ($row->product_id ?? 0);
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;

            $products[] = [
                'product_id' => $id,
                'name' => is_object($product) && is_callable([$product, 'get_name'])
                    ? (string) $product->get_name()
                    : (string) $id,
                'quantity_sold' => (int) ($row->qty ?? 0),
            ];
        }

        return $products;
    }

    /**
     * Count distinct products sold in the range from the Analytics lookup table.
     *
     * @param  array<string, mixed>  $range  Resolved date range.
     * @return int
     */
    private function analyticsUniqueProducts(array $range): int
    {
        global $wpdb;

        $lookup = $wpdb->prefix.'wc_order_product_lookup';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT product_id) FROM {$lookup} WHERE date_created >= %s AND date_created <= %s",
            $range['from'].' 00:00:00',
            $range['to'].' 23:59:59',
        ));
    }

    /**
     * Aggregate from the order API when Analytics has nothing for the range.
     *
     * @param  array<string, mixed>  $range  Resolved date range.
     * @param  int  $top  How many best sellers to return.
     * @return array<string, mixed> Report data.
     *
     * @throws ToolException When the order query fails.
     */
    private function orderApiReport(array $range, int $top): array
    {
        $args = [
            'status' => self::COUNTED_STATUSES,
            'date_created' => $range['from'].'...'.$range['to'],
            'limit' => self::MAX_ORDERS_FALLBACK + 1,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ];

        try {
            $fetcher = $this->orderFetcher ?? static fn (array $a): mixed => wc_get_orders($a);
            $orders = $fetcher($args);
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('WC report query failed', previous: $e);
        }

        $orders = is_array($orders) ? $orders : [];
        $truncated = count($orders) > self::MAX_ORDERS_FALLBACK;

        if ($truncated) {
            $orders = array_slice($orders, 0, self::MAX_ORDERS_FALLBACK);
        }

        $revenue = 0.0;
        $sales = [];

        foreach ($orders as $order) {
            if (! is_callable([$order, 'get_total'])) {
                continue;
            }

            $revenue += (float) $order->get_total();

            if (! is_callable([$order, 'get_items'])) {
                continue;
            }

            foreach ((array) $order->get_items() as $item) {
                if (! is_callable([$item, 'get_name'])) {
                    continue;
                }

                $name = (string) $item->get_name();
                $qty = is_callable([$item, 'get_quantity']) ? (int) $item->get_quantity() : 0;
                $id = is_callable([$item, 'get_product_id']) ? (int) $item->get_product_id() : 0;

                $key = $id > 0 ? (string) $id : $name;

                $sales[$key] = [
                    'product_id' => $id,
                    'name' => $name,
                    'quantity_sold' => ($sales[$key]['quantity_sold'] ?? 0) + $qty,
                ];
            }
        }

        uasort(
            $sales,
            static fn (array $a, array $b): int => [$b['quantity_sold'], $a['product_id']]
                <=> [$a['quantity_sold'], $b['product_id']],
        );

        return [
            'total_revenue' => $revenue,
            'order_count' => count($orders),
            'top_products' => array_values(array_slice($sales, 0, $top)),
            'unique_products' => count($sales),
            'source' => 'wc_orders_api',
            'truncated' => $truncated,
        ];
    }

    /**
     * Build period and limit metadata without running a report.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'periods' => self::VALID_PERIODS,
            'default_period' => self::DEFAULT_PERIOD,
            'examples' => self::EXAMPLES,
            'counted_statuses' => self::COUNTED_STATUSES,
            'currency' => $this->currency(),
            'modes' => ['schema', 'report'],
            'limits' => [
                'maximum_range_days' => self::MAX_RANGE_DAYS,
                'default_top_products' => self::DEFAULT_TOP_PRODUCTS,
                'maximum_top_products' => self::MAX_TOP_PRODUCTS,
                'maximum_orders_when_analytics_unavailable' => self::MAX_ORDERS_FALLBACK,
            ],
            'sources' => ['wc_order_stats', 'wc_orders_api'],
            'woocommerce_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
        ];
    }

    /**
     * Resolve the requested period into a bounded date range.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Range with from, to, days, clamped and period.
     */
    private function resolveRange(array $input): array
    {
        $period = (string) ($input['period'] ?? self::DEFAULT_PERIOD);
        $today = $this->today();

        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'this_week' => [$this->formatDate('monday this week'), $today],
            'this_month' => [$this->formatDate('first day of this month'), $today],
            'last_month' => [
                $this->formatDate('first day of last month'),
                $this->formatDate('last day of last month'),
            ],
            'this_year' => [$this->formatDate('first day of january this year'), $today],
            'custom' => [
                (string) ($input['date_from'] ?? $this->formatDate('first day of this month')),
                (string) ($input['date_to'] ?? $today),
            ],
            default => [$this->formatDate('first day of this month'), $today],
        };

        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }

        $requestedDays = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        $clamped = false;

        if ($requestedDays > self::MAX_RANGE_DAYS) {
            $from = gmdate('Y-m-d', strtotime($to) - ((self::MAX_RANGE_DAYS - 1) * 86400));
            $clamped = true;
        }

        $days = (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1;

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'requested_days' => $requestedDays,
            'clamped' => $clamped,
        ];
    }

    /**
     * Return today's date in the site timezone.
     *
     * @return string
     */
    private function today(): string
    {
        return function_exists('current_time') ? (string) current_time('Y-m-d') : gmdate('Y-m-d');
    }

    /**
     * Format a relative date expression in the site timezone.
     *
     * @param  string  $expression  A strtotime expression.
     * @return string
     */
    private function formatDate(string $expression): string
    {
        $timestamp = strtotime($expression);

        if ($timestamp === false) {
            return $this->today();
        }

        return function_exists('wp_date')
            ? (string) wp_date('Y-m-d', $timestamp)
            : gmdate('Y-m-d', $timestamp);
    }

    /**
     * Return the store currency code.
     *
     * @return string
     */
    private function currency(): string
    {
        return function_exists('get_woocommerce_currency')
            ? (string) get_woocommerce_currency()
            : '';
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

        if (array_key_exists('schema', $input) && ! is_bool($input['schema'])) {
            return $this->error('INVALID_ARGUMENT', '"schema" must be a boolean.');
        }

        if (array_key_exists('period', $input)
            && (! is_string($input['period']) || ! in_array($input['period'], self::VALID_PERIODS, true))) {
            return $this->error(
                'INVALID_PERIOD',
                '"period" must be one of: '.implode(', ', self::VALID_PERIODS).'.',
                ['valid_periods' => self::VALID_PERIODS],
            );
        }

        foreach (['date_from', 'date_to'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if (! is_string($input[$key]) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$key]) !== 1) {
                return $this->error(
                    'INVALID_DATE',
                    sprintf('"%s" must be a date in YYYY-MM-DD form.', $key),
                );
            }

            if (strtotime($input[$key]) === false) {
                return $this->error('INVALID_DATE', sprintf('"%s" is not a real date.', $key));
            }
        }

        if (array_key_exists('top_products', $input)) {
            if (
                ! is_int($input['top_products'])
                || $input['top_products'] < 1
                || $input['top_products'] > self::MAX_TOP_PRODUCTS
            ) {
                return $this->error(
                    'INVALID_ARGUMENT',
                    sprintf('"top_products" must be an integer between 1 and %d.', self::MAX_TOP_PRODUCTS),
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
            domains: ['commerce', 'analytics'],
            tags: ['report', 'reports', 'sales', 'revenue', 'earnings', 'total', 'totals', 'summary', 'analytics', 'stats', 'statistics', 'performance', 'best', 'top'],
            intents: ['sales report', 'show revenue', 'how are we performing', 'top sellers'],
            examples: ['show me this months sales report'],
        );
    }
}
