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
 * Orders tool: read-only queries with dynamic column access and full filtering.
 */
final class PsOrderTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 25;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = [
        'search', 'state_id', 'customer_id',
        'date_after', 'date_before', 'min_total', 'max_total', 'valid_only',
    ];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'search', 'state_id', 'customer_id',
        'date_after', 'date_before', 'min_total', 'max_total', 'valid_only',
        'limit', 'offset',
    ];

    private const DEFAULT_COLUMNS = [
        'id', 'reference', 'customer_name', 'total_paid_tax_incl', 'state_name', 'date_add',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'reference', 'customer_name', 'total_paid_tax_incl', 'payment',
        'current_state', 'state_name', 'date_add', 'date_upd',
        'carrier_name', 'shipping_number', 'invoice_number',
    ];

    private const SENSITIVE_COLUMNS = ['customer_email'];

    /**
     * Create a new PsOrderTool instance.
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
        return 'ps_order';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect PrestaShop orders with status and payment data.

AVAILABLE COLUMNS:
  id, reference, customer_name, total_paid_tax_incl, payment, current_state,
  state_name, date_add, date_upd, carrier_name, shipping_number,
  invoice_number

SENSITIVE COLUMNS (never returned): customer_email

WHAT COUNTS AS AN ORDER:
  By default this tool counts EVERY order row, including cancelled orders, payment
  errors and orders still awaiting payment. PrestaShop marks an order valid when its
  current state is one that counts towards sales, and the back office uses that flag
  for its sales figures. Pass valid_only: true to count only those. Every response
  reports which basis it used in meta.valid_only.

CAPABILITIES:
  - Search by order reference or customer name
  - Filter by state, customer, date range, total amount
  - Request specific columns or get defaults (id, reference, customer_name, total_paid_tax_incl, state_name, date_add)
  - total_paid_tax_incl is the order total including tax, taken from the database column
    of the same name
  - Page with meta.next_offset; meta.total is the real count for the same filters
  - Aggregate mode: total orders, total_revenue, by_state counts, avg_order_value
  - Schema mode: discover available columns and filters

EXAMPLES:
  "List recent orders" → default query
  "How much revenue total?" → aggregate: true
  "Orders awaiting payment" → state_id: 1
  "Orders over $100" → min_total: 100
  "Orders from last week" → date_after: "2026-04-20"
  "Orders for customer 42" → customer_id: 42
  "Search order ABCDE" → search: "ABCDE"
  "Next page" → offset: 25
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
                    'description' => 'Columns to return. Use ["*"] for all available. Omit for defaults (id, reference, customer_name, total_paid_tax_incl, state_name, date_add).',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available columns and filter capabilities. No DB query.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total orders, total_revenue, by_state counts, avg_order_value.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against order reference or customer name.',
                ],
                'state_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by order state ID (e.g. 1 = Awaiting payment, 2 = Payment accepted, 5 = Delivered).',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by customer ID.',
                ],
                'date_after' => [
                    'type' => 'string',
                    'description' => 'Orders placed on or after this date (YYYY-MM-DD).',
                ],
                'date_before' => [
                    'type' => 'string',
                    'description' => 'Orders placed on or before this date (YYYY-MM-DD).',
                ],
                'min_total' => [
                    'type' => 'number',
                    'description' => 'Minimum order total (total_paid_tax_incl).',
                ],
                'valid_only' => [
                    'type' => 'boolean',
                    'description' => 'true = count only orders PrestaShop marks valid, meaning their current state counts towards sales. Default false, which counts every order row including cancelled, payment error and awaiting payment.',
                ],
                'max_total' => [
                    'type' => 'number',
                    'description' => 'Maximum order total (total_paid_tax_incl).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100). Default: 25.',
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
            domains: ['commerce', 'orders'],
            tags: ['order', 'orders', 'purchase', 'purchases', 'sale', 'sales', 'transaction', 'transactions', 'checkout', 'invoice', 'refund', 'reference', 'status', 'total', 'buyer', 'payment', 'delivered', 'shipped'],
            intents: ['list orders', 'show purchases', 'find a sale', 'what did customers buy'],
            examples: ['show me yesterdays purchases'],
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
        $forbidden = $this->guardCapability('read PrestaShop orders');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_order: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['orders'] ?? null)) {
            throw new ToolException('ps_order: the order query returned an incomplete result.');
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

        $validOnly = ! empty($input['valid_only']);

        if ($execution['type'] === 'schema') {
            return $this->success($payload, ['mode' => 'schema', 'database_query_performed' => false]);
        }

        if ($execution['type'] === 'aggregate') {
            return $this->success($payload, ['mode' => 'aggregate', 'valid_only' => $validOnly]);
        }

        return $this->success(
            ['orders' => $payload['orders']],
            [
                'mode' => 'query',
                'valid_only' => $validOnly,
                'total' => $payload['total'],
                'count' => count($payload['orders']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
                'withheld_columns' => self::SENSITIVE_COLUMNS,
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
            'sensitive_columns' => self::SENSITIVE_COLUMNS,
            'filter_capabilities' => self::FILTERS,
            'order_definition' => 'Counts every order row by default. valid_only: true restricts to orders whose current state counts towards sales, which is the basis the back office uses.',
            'valid_only_default' => false,
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

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
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
                    COUNT(*) AS total_orders,
                    ROUND(SUM(o.total_paid_tax_incl), 2) AS total_revenue,
                    ROUND(AVG(o.total_paid_tax_incl), 2) AS avg_order_value
                FROM `{$p}orders` o
                LEFT JOIN `{$p}customer` cu
                       ON cu.id_customer = o.id_customer
                WHERE {$where}";

        try {
            $row = $this->db->query($sql, $params)->row;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsOrderTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_order: database query failed.', previous: $e);
        }

        $stateSql = "SELECT osl.name AS state_name, COUNT(*) AS cnt
                     FROM `{$p}orders` o
                     LEFT JOIN `{$p}order_state_lang` osl
                            ON osl.id_order_state = o.current_state AND osl.id_lang = 1
                     LEFT JOIN `{$p}customer` cu
                            ON cu.id_customer = o.id_customer
                     WHERE {$where}
                     GROUP BY o.current_state, osl.name
                     ORDER BY cnt DESC";

        try {
            $stateRows = $this->db->query($stateSql, $params)->rows;
        } catch (\Throwable) {
            $stateRows = [];
        }

        $byState = [];

        foreach ($stateRows as $sr) {
            $byState[$sr['state_name'] ?? 'Unknown'] = (int) $sr['cnt'];
        }

        return [
            'stats' => [
                'total_orders' => (int) ($row['total_orders'] ?? 0),
                'total_revenue' => (float) ($row['total_revenue'] ?? 0),
                'avg_order_value' => (float) ($row['avg_order_value'] ?? 0),
                'by_state' => $byState,
            ],
        ];
    }

    /**
     * Read one page of orders, with a real total from the same WHERE clause.
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

        $countSql = "SELECT COUNT(DISTINCT o.id_order) AS total
                     FROM `{$p}orders` o
                     LEFT JOIN `{$p}customer` cu
                            ON cu.id_customer = o.id_customer
                     WHERE {$where}";

        $sql = "SELECT o.id_order, o.reference, o.id_customer,
                       CONCAT(cu.firstname, ' ', cu.lastname) AS customer_name,
                       o.total_paid_tax_incl,
                       o.payment, o.current_state,
                       osl.name AS state_name,
                       o.date_add, o.date_upd,
                       ca.name AS carrier_name,
                       oc.tracking_number AS shipping_number,
                       o.invoice_number
                FROM `{$p}orders` o
                LEFT JOIN `{$p}order_state_lang` osl
                       ON osl.id_order_state = o.current_state AND osl.id_lang = 1
                LEFT JOIN `{$p}customer` cu
                       ON cu.id_customer = o.id_customer
                LEFT JOIN `{$p}carrier` ca
                       ON ca.id_carrier = o.id_carrier
                LEFT JOIN `{$p}order_carrier` oc
                       ON oc.id_order = o.id_order
                WHERE {$where}
                GROUP BY o.id_order
                ORDER BY o.date_add DESC, o.id_order DESC
                LIMIT {$limit} OFFSET {$offset}";

        try {
            $total = (int) ($this->db->query($countSql, $params)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $params)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsOrderTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_order: database query failed.', previous: $e);
        }

        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->buildRow($row, $columns);
        }

        $capped = ToolOutputEncoder::cap($results, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'orders' => $capped['rows'],
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
     * Build a single order row with only the requested columns.
     *
     * @param  array<string, mixed>  $row  Raw database row.
     * @param  array<int, string>  $columns  Requested columns.
     * @return array<string, mixed>
     */
    private function buildRow(array $row, array $columns): array
    {
        $map = [
            'id' => fn () => (int) $row['id_order'],
            'reference' => fn () => $row['reference'] ?? '',
            'customer_name' => fn () => $row['customer_name'] ?? '',
            'total_paid_tax_incl' => fn () => (float) ($row['total_paid_tax_incl'] ?? 0),
            'payment' => fn () => $row['payment'] ?? '',
            'current_state' => fn () => (int) ($row['current_state'] ?? 0),
            'state_name' => fn () => $row['state_name'] ?? '',
            'date_add' => fn () => $row['date_add'] ?? '',
            'date_upd' => fn () => $row['date_upd'] ?? '',
            'carrier_name' => fn () => $row['carrier_name'] ?? '',
            'shipping_number' => fn () => $row['shipping_number'] ?? '',
            'invoice_number' => fn () => (int) ($row['invoice_number'] ?? 0),
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
            $where .= ' AND (o.reference LIKE ? OR CONCAT(cu.firstname, \' \', cu.lastname) LIKE ?)';
            $term = '%'.$input['search'].'%';
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['state_id'])) {
            $where .= ' AND o.current_state = ?';
            $params[] = (int) $input['state_id'];
        }

        if (isset($input['customer_id'])) {
            $where .= ' AND o.id_customer = ?';
            $params[] = (int) $input['customer_id'];
        }

        if (isset($input['date_after']) && (string) $input['date_after'] !== '') {
            $where .= ' AND DATE(o.date_add) >= ?';
            $params[] = $input['date_after'];
        }

        if (isset($input['date_before']) && (string) $input['date_before'] !== '') {
            $where .= ' AND DATE(o.date_add) <= ?';
            $params[] = $input['date_before'];
        }

        if (isset($input['min_total'])) {
            $where .= ' AND o.total_paid_tax_incl >= ?';
            $params[] = (float) $input['min_total'];
        }

        if (isset($input['max_total'])) {
            $where .= ' AND o.total_paid_tax_incl <= ?';
            $params[] = (float) $input['max_total'];
        }

        if (! empty($input['valid_only'])) {
            $where .= ' AND o.valid = 1';
        }
    }
}
