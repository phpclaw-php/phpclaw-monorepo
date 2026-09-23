<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that runs sales aggregate reports against the sales_order table.
 */
// non-final: Magento interceptor required
class MagentoReportTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const VALID_METRICS = ['revenue', 'orders', 'avg_order_value', 'top_products'];

    private const SOLD_STATUSES_SQL = "'complete', 'processing'";

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    /**
     * Bind the resource connection, identity resolver, and ACL service.
     *
     * @param  ResourceConnection  $resourceConnection  Magento DB connection provider.
     * @param  IdentityResolver  $identityResolver  Resolver reporting the area and the acting admin identity.
     * @param  AuthorizationInterface  $acl  Magento authorization service.
     * @return void
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        private readonly IdentityResolver $identityResolver,
        private readonly AuthorizationInterface $acl,
    ) {
        parent::__construct($resourceConnection);
    }

    /**
     * Return the ACL resource a caller must hold to run sales reports.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
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
            tags: ['report', 'reports', 'sales', 'revenue', 'earnings', 'total', 'totals', 'summary', 'analytics', 'stats', 'statistics', 'performance', 'best', 'top', 'bestseller'],
            intents: ['sales report', 'show revenue', 'how are we performing', 'top sellers'],
            examples: ['show me this months sales report'],
        );
    }

    /**
     * Return the identity resolver the contract trait reads the area from.
     *
     * @return IdentityResolver
     */
    protected function identity(): IdentityResolver
    {
        return $this->identityResolver;
    }

    /**
     * Return the ACL service the contract trait checks capabilities against.
     *
     * @return AuthorizationInterface
     */
    protected function authorization(): AuthorizationInterface
    {
        return $this->acl;
    }

    /**
     * Returns the tool identifier used in agent tool dispatch.
     *
     * @return string
     */
    public function name(): string
    {
        return 'magento_report';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'RUN a Magento sales report for a date range. metric options: revenue (total sales), orders (count + status breakdown), avg_order_value, top_products (bestselling SKUs). from and to are required (YYYY-MM-DD). Invoke it, never estimate sales data.';
    }

    /**
     * Returns the JSON Schema object describing accepted inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['from', 'to', 'metric'],
            'properties' => [
                'from' => [
                    'type' => 'string',
                    'description' => 'Report start date, inclusive (YYYY-MM-DD).',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Report end date, inclusive (YYYY-MM-DD).',
                ],
                'metric' => [
                    'type' => 'string',
                    'description' => 'Metric to calculate: revenue, orders, avg_order_value, top_products.',
                    'enum' => self::VALID_METRICS,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows for top_products (1-50, default 20).',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
        ];
    }

    /**
     * Guard the caller, reject unknown arguments, and validate the date range and metric.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('run Magento sales reports');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['from', 'to', 'metric', 'limit']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));
        $metric = trim((string) ($input['metric'] ?? ''));

        if ($from === '' || $to === '') {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', "magento_report: 'from' and 'to' dates are required."),
            ];
        }

        if (! preg_match(self::DATE_PATTERN, $from)) {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', "magento_report: 'from' must be YYYY-MM-DD, got '{$from}'."),
            ];
        }

        if (! preg_match(self::DATE_PATTERN, $to)) {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', "magento_report: 'to' must be YYYY-MM-DD, got '{$to}'."),
            ];
        }

        if (! in_array($metric, self::VALID_METRICS, true)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'INVALID_ARGUMENT',
                    "magento_report: invalid metric '{$metric}'. Valid: ".implode(', ', self::VALID_METRICS),
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Expand the date range to datetime bounds and dispatch to the requested metric.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));
        $metric = trim((string) ($input['metric'] ?? ''));

        $fromDt = $from.' 00:00:00';
        $toDt = $to.' 23:59:59';
        $period = ['from' => $fromDt, 'to' => $toDt];

        return match ($metric) {
            'revenue' => $this->revenue($fromDt, $toDt, $period),
            'orders' => $this->orders($fromDt, $toDt, $period),
            'avg_order_value' => $this->avgOrderValue($fromDt, $toDt, $period),
            'top_products' => $this->topProducts($fromDt, $toDt, $period, $input),
            default => throw new ToolException(sprintf('magento_report: unvalidated metric "%s" reached perform().', $metric)),
        };
    }

    /**
     * Confirm the execution result contains the required data key for the metric.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the result is structurally incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        $mode = (string) ($execution['mode'] ?? '');

        if (in_array($mode, ['revenue', 'avg_order_value', 'top_products'], true) && ! is_array($execution['data'] ?? null)) {
            throw new ToolException("magento_report: incomplete {$mode} result.");
        }

        if ($mode === 'orders' && ! is_array($execution['by_status'] ?? null)) {
            throw new ToolException('magento_report: incomplete orders result.');
        }

        return ['result' => null];
    }

    /**
     * Apply the byte cap and build the public response envelope for the completed metric.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return match ($execution['mode']) {
            'revenue' => $this->completeRevenue($execution),
            'orders' => $this->completeOrders($execution),
            'avg_order_value' => $this->completeAvgOrderValue($execution),
            'top_products' => $this->completeTopProducts($execution),
            default => throw new ToolException(sprintf('magento_report: unknown mode "%s" reached complete().', (string) $execution['mode'])),
        };
    }

    /**
     * Aggregate total revenue and order count grouped by base_currency_code for sold orders.
     *
     * @param  string  $fromDt  Datetime lower bound (e.g. "2024-01-01 00:00:00").
     * @param  string  $toDt  Datetime upper bound (e.g. "2024-01-31 23:59:59").
     * @param  array{from: string, to: string}  $period  Period descriptor for the meta envelope.
     * @return array<string, mixed>
     */
    private function revenue(string $fromDt, string $toDt, array $period): array
    {
        $conn = $this->resourceConnection->getConnection();
        $tOrder = $this->resourceConnection->getTableName('sales_order');

        $rows = $conn->fetchAll(
            "SELECT COALESCE(SUM(grand_total), 0) AS revenue,
                    COUNT(*)                       AS order_count,
                    base_currency_code
             FROM {$tOrder}
             WHERE status     IN (".self::SOLD_STATUSES_SQL.')
               AND created_at >= ?
               AND created_at <= ?
             GROUP BY base_currency_code
             ORDER BY revenue DESC
             LIMIT 10',
            [$fromDt, $toDt],
        );

        return ['mode' => 'revenue', 'period' => $period, 'data' => $rows];
    }

    /**
     * Return order count and per-status breakdown for all statuses in the period.
     *
     * @param  string  $fromDt  Datetime lower bound.
     * @param  string  $toDt  Datetime upper bound.
     * @param  array{from: string, to: string}  $period  Period descriptor for the meta envelope.
     * @return array<string, mixed>
     */
    private function orders(string $fromDt, string $toDt, array $period): array
    {
        $conn = $this->resourceConnection->getConnection();
        $tOrder = $this->resourceConnection->getTableName('sales_order');

        $breakdown = $conn->fetchAll(
            "SELECT status, COUNT(*) AS count, COALESCE(SUM(grand_total), 0) AS total
             FROM {$tOrder}
             WHERE created_at >= ?
               AND created_at <= ?
             GROUP BY status
             ORDER BY count DESC",
            [$fromDt, $toDt],
        );

        $totalOrders = (int) array_sum(array_column($breakdown, 'count'));

        return [
            'mode' => 'orders',
            'period' => $period,
            'total_orders' => $totalOrders,
            'by_status' => $breakdown,
        ];
    }

    /**
     * Calculate average grand_total grouped by base_currency_code for sold orders.
     *
     * @param  string  $fromDt  Datetime lower bound.
     * @param  string  $toDt  Datetime upper bound.
     * @param  array{from: string, to: string}  $period  Period descriptor for the meta envelope.
     * @return array<string, mixed>
     */
    private function avgOrderValue(string $fromDt, string $toDt, array $period): array
    {
        $conn = $this->resourceConnection->getConnection();
        $tOrder = $this->resourceConnection->getTableName('sales_order');

        $rows = $conn->fetchAll(
            "SELECT COALESCE(AVG(grand_total), 0) AS avg_order_value,
                    COUNT(*)                       AS order_count,
                    base_currency_code
             FROM {$tOrder}
             WHERE status     IN (".self::SOLD_STATUSES_SQL.')
               AND created_at >= ?
               AND created_at <= ?
             GROUP BY base_currency_code
             ORDER BY avg_order_value DESC
             LIMIT 10',
            [$fromDt, $toDt],
        );

        return ['mode' => 'avg_order_value', 'period' => $period, 'data' => $rows];
    }

    /**
     * Rank bestselling SKUs by qty_ordered for sold orders in the period.
     *
     * @param  string  $fromDt  Datetime lower bound.
     * @param  string  $toDt  Datetime upper bound.
     * @param  array{from: string, to: string}  $period  Period descriptor for the meta envelope.
     * @param  array<string, mixed>  $input  Runtime input used to extract the row limit.
     * @return array<string, mixed>
     */
    private function topProducts(string $fromDt, string $toDt, array $period, array $input): array
    {
        $limit = isset($input['limit']) ? max(1, min((int) $input['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;
        $conn = $this->resourceConnection->getConnection();
        $tOrder = $this->resourceConnection->getTableName('sales_order');
        $tOrderItem = $this->resourceConnection->getTableName('sales_order_item');

        $rows = $conn->fetchAll(
            "SELECT oi.sku, oi.name,
                    SUM(oi.qty_ordered) AS qty_sold,
                    SUM(oi.row_total)   AS revenue
             FROM {$tOrderItem} oi
             JOIN {$tOrder} o ON o.entity_id = oi.order_id
             WHERE o.status      IN (".self::SOLD_STATUSES_SQL.')
               AND o.created_at  >= ?
               AND o.created_at  <= ?
               AND oi.parent_item_id IS NULL
             GROUP BY oi.sku, oi.name
             ORDER BY qty_sold DESC
             LIMIT '.(int) $limit,
            [$fromDt, $toDt],
        );

        return ['mode' => 'top_products', 'period' => $period, 'data' => $rows, 'limit' => $limit];
    }

    /**
     * Build the revenue metric response envelope with byte-capped rows.
     *
     * @param  array<string, mixed>  $execution  Verified revenue execution result.
     * @return string JSON-encoded response envelope.
     */
    private function completeRevenue(array $execution): string
    {
        $capped = $this->capRows($execution['data']);

        return $this->success(
            ['data' => $capped['rows']],
            [
                'metric' => 'revenue',
                'period' => $execution['period'],
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }

    /**
     * Build the orders metric response envelope with byte-capped status breakdown.
     *
     * @param  array<string, mixed>  $execution  Verified orders execution result.
     * @return string JSON-encoded response envelope.
     */
    private function completeOrders(array $execution): string
    {
        $capped = $this->capRows($execution['by_status']);

        return $this->success(
            ['total_orders' => $execution['total_orders'], 'by_status' => $capped['rows']],
            [
                'metric' => 'orders',
                'period' => $execution['period'],
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }

    /**
     * Build the avg_order_value metric response envelope with byte-capped rows.
     *
     * @param  array<string, mixed>  $execution  Verified avg_order_value execution result.
     * @return string JSON-encoded response envelope.
     */
    private function completeAvgOrderValue(array $execution): string
    {
        $capped = $this->capRows($execution['data']);

        return $this->success(
            ['data' => $capped['rows']],
            [
                'metric' => 'avg_order_value',
                'period' => $execution['period'],
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }

    /**
     * Build the top_products metric response envelope with byte-capped rows.
     *
     * @param  array<string, mixed>  $execution  Verified top_products execution result.
     * @return string JSON-encoded response envelope.
     */
    private function completeTopProducts(array $execution): string
    {
        $capped = $this->capRows($execution['data']);

        return $this->success(
            ['data' => $capped['rows']],
            [
                'metric' => 'top_products',
                'period' => $execution['period'],
                'limit' => $execution['limit'],
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }
}
