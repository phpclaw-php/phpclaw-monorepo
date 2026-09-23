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
 * Report tool: revenue, orders, and sales analytics from ps_orders.
 */
final class PsReportTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 30;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const GROUP_BY_OPTIONS = ['day', 'week', 'month'];

    private const FILTERS = ['date_after', 'date_before', 'group_by'];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'date_after', 'date_before',
        'group_by', 'limit', 'offset', 'order_dir',
    ];

    private const AVAILABLE_COLUMNS = [
        'period', 'revenue', 'orders', 'avg_order_value',
        'new_customers', 'products_sold',
    ];

    private const DEFAULT_COLUMNS = [
        'period', 'revenue', 'orders', 'avg_order_value',
    ];

    /**
     * Create a new PsReportTool instance.
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
        return 'ps_report';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Generate PrestaShop revenue and order reports with flexible grouping.

AVAILABLE COLUMNS:
  period, revenue, orders, avg_order_value,
  new_customers, products_sold

CAPABILITIES:
  - Group by day, week, or month
  - Filter by date range (date_after, date_before)
  - Request specific columns or get defaults
  - Aggregate mode: total_revenue, total_orders, best_selling_products (top 5), revenue_by_month
  - Schema mode: discover available columns before querying

EXAMPLES:
  "Monthly revenue for 2026" → group_by: "month", date_after: "2026-01-01", date_before: "2026-12-31"
  "Daily orders this week" → group_by: "day", date_after: "2026-04-21"
  "Total revenue and best sellers" → aggregate: true
  "Revenue by month with customer counts" → columns: ["period","revenue","new_customers"], group_by: "month"
  "What columns exist?" → schema: true
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
                'group_by' => [
                    'type' => 'string',
                    'enum' => ['day', 'week', 'month'],
                    'description' => 'Grouping period. Default: day.',
                    'default' => 'day',
                ],
                'date_after' => [
                    'type' => 'string',
                    'description' => 'Start date (YYYY-MM-DD). Only orders on or after this date.',
                ],
                'date_before' => [
                    'type' => 'string',
                    'description' => 'End date (YYYY-MM-DD). Only orders on or before this date.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max periods to return (1-100, default 30).',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset.',
                    'default' => 0,
                ],
                'order_dir' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'description' => 'Sort direction for period. Default: DESC (newest first).',
                    'default' => 'DESC',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = summary stats only. Returns: total_revenue, total_orders, best_selling_products (top 5), revenue_by_month.',
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
            domains: ['commerce', 'analytics'],
            tags: ['report', 'reports', 'sales', 'revenue', 'earnings', 'turnover', 'total', 'totals', 'summary', 'analytics', 'stats', 'statistics', 'performance', 'best', 'top'],
            intents: ['sales report', 'show revenue', 'how are we performing', 'top sellers'],
            examples: ['show me this months sales report'],
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
        $forbidden = $this->guardCapability('read PrestaShop sales reports');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_report: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['periods'] ?? null)) {
            throw new ToolException('ps_report: the query returned an incomplete result.');
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
            ['periods' => $payload['periods']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['periods']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
                'truncated' => $payload['truncated'],
                'group_by' => $payload['group_by'],
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
     * Read one page of report periods, with a real total from the same WHERE clause.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the report query fails.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = min(max(0, (int) ($input['offset'] ?? self::DEFAULT_OFFSET)), self::MAX_OFFSET);
        $orderDir = strtoupper($input['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $groupBy = in_array($input['group_by'] ?? '', self::GROUP_BY_OPTIONS, true)
            ? (string) $input['group_by']
            : 'day';

        $columns = $this->resolveColumns($input['columns'] ?? []);
        $needsProductsSold = in_array('products_sold', $columns, true);

        $groupExpr = match ($groupBy) {
            'week' => 'DATE(DATE_SUB(o.date_add, INTERVAL WEEKDAY(o.date_add) DAY))',
            'month' => "DATE_FORMAT(o.date_add, '%Y-%m')",
            default => 'DATE(o.date_add)',
        };

        $selectParts = array_values(array_filter(array_map(
            fn (string $col): ?string => $this->selectExpression($col, $groupExpr, $needsProductsSold, $p),
            $columns,
        )));

        if ($selectParts === []) {
            $selectParts = ["{$groupExpr} AS period"];
        }

        [$where, $bindings] = $this->buildDateWhere($input);

        $from = $needsProductsSold
            ? "FROM `{$p}orders` o
                    LEFT JOIN `{$p}order_detail` od ON od.id_order = o.id_order
                    {$where}"
            : "FROM `{$p}orders` o
                    {$where}";

        $sql = 'SELECT '.implode(', ', $selectParts)."
                {$from}
                GROUP BY {$groupExpr}
                ORDER BY period {$orderDir}
                LIMIT {$limit} OFFSET {$offset}";

        $countSql = "SELECT COUNT(*) AS total FROM (
                         SELECT {$groupExpr} AS period
                         {$from}
                         GROUP BY {$groupExpr}
                     ) AS counted";

        try {
            $total = (int) ($this->db->query($countSql, $bindings)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsReportTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_report: database query failed.', previous: $e);
        }

        $capped = ToolOutputEncoder::cap($rows, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'periods' => $capped['rows'],
            'total' => $total,
            'group_by' => $groupBy,
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
     * Return the SELECT expression for one report column, or null when it has none.
     *
     * @param  string  $col  Requested column name.
     * @param  string  $groupExpr  SQL expression the rows are grouped by.
     * @param  bool  $joined  Whether order_detail is joined for this query.
     * @param  string  $p  Table prefix.
     * @return string|null
     */
    private function selectExpression(string $col, string $groupExpr, bool $joined, string $p): ?string
    {
        return match ($col) {
            'period' => "{$groupExpr} AS period",
            'revenue' => 'ROUND(SUM(o.total_paid_tax_incl), 2) AS revenue',
            'orders' => 'COUNT(*) AS orders',
            'avg_order_value' => 'ROUND(AVG(o.total_paid_tax_incl), 2) AS avg_order_value',
            'new_customers' => 'COUNT(DISTINCT o.id_customer) AS new_customers',
            'products_sold' => $joined
                ? 'COALESCE(SUM(od.product_quantity), 0) AS products_sold'
                : "(SELECT COALESCE(SUM(od2.product_quantity), 0) FROM `{$p}order_detail` od2 WHERE od2.id_order = o.id_order) AS products_sold",
            default => null,
        };
    }

    /**
     * Aggregate mode, totals and best sellers only, zero period rows.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the aggregate query fails.
     */
    private function aggregateData(array $input): array
    {
        $p = $this->tablePrefix;
        [$where, $bindings] = $this->buildDateWhere($input);

        $sqlTotals = "SELECT
                        ROUND(COALESCE(SUM(o.total_paid_tax_incl), 0), 2) AS total_revenue,
                        COUNT(*)                                           AS total_orders,
                        ROUND(AVG(o.total_paid_tax_incl), 2)               AS avg_order_value
                      FROM `{$p}orders` o
                      {$where}";

        $sqlMonthly = "SELECT
                         DATE_FORMAT(o.date_add, '%Y-%m') AS month,
                         ROUND(SUM(o.total_paid_tax_incl), 2) AS revenue,
                         COUNT(*) AS orders
                       FROM `{$p}orders` o
                       {$where}
                       GROUP BY DATE_FORMAT(o.date_add, '%Y-%m')
                       ORDER BY month DESC
                       LIMIT 12";

        $sqlBestSellers = "SELECT
                             od.product_name,
                             SUM(od.product_quantity) AS quantity_sold,
                             ROUND(SUM(od.total_price_tax_incl), 2) AS total_revenue
                           FROM `{$p}order_detail` od
                           INNER JOIN `{$p}orders` o ON o.id_order = od.id_order
                           {$where}
                           GROUP BY od.product_id, od.product_name
                           ORDER BY quantity_sold DESC
                           LIMIT 5";

        try {
            $totals = $this->db->query($sqlTotals, $bindings)->row;
            $monthly = $this->db->query($sqlMonthly, $bindings)->rows;
            $bestSellers = $this->db->query($sqlBestSellers, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsReportTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_report: database query failed.', previous: $e);
        }

        return [
            'total_revenue' => (float) ($totals['total_revenue'] ?? 0),
            'total_orders' => (int) ($totals['total_orders'] ?? 0),
            'avg_order_value' => (float) ($totals['avg_order_value'] ?? 0),
            'best_selling_products' => $bestSellers,
            'revenue_by_month' => $monthly,
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
                'period' => 'Grouped date period (day/week/month)',
                'revenue' => 'Total revenue including tax for the period',
                'orders' => 'Number of orders in the period',
                'avg_order_value' => 'Average order value for the period',
                'new_customers' => 'Distinct customers who ordered in the period',
                'products_sold' => 'Total quantity of products sold in the period',
            ],
            'group_by_options' => self::GROUP_BY_OPTIONS,
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
     * Build WHERE clause for date filters on valid orders.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildDateWhere(array $input): array
    {
        $conditions = ['o.valid = 1'];
        $bindings = [];

        if (isset($input['date_after']) && trim((string) $input['date_after']) !== '') {
            $conditions[] = 'DATE(o.date_add) >= ?';
            $bindings[] = trim((string) $input['date_after']);
        }

        if (isset($input['date_before']) && trim((string) $input['date_before']) !== '') {
            $conditions[] = 'DATE(o.date_add) <= ?';
            $bindings[] = trim((string) $input['date_before']);
        }

        $where = 'WHERE '.implode(' AND ', $conditions);

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

        if (! in_array('period', $valid, true)) {
            array_unshift($valid, 'period');
        }

        return array_values($valid);
    }
}
