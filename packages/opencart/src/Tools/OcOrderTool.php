<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Orders tool: dynamic column access with full filtering.
 */
final class OcOrderTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const DEFAULT_COLUMNS = [
        'id', 'firstname', 'lastname', 'total', 'order_status', 'date_added',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'firstname', 'lastname', 'email', 'telephone', 'total',
        'currency_code', 'order_status', 'payment_method', 'shipping_method',
        'date_added', 'date_modified', 'store_name', 'comment',
    ];

    private const SENSITIVE_COLUMNS = ['email', 'telephone'];

    protected const ERROR_LABEL = 'order';

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_order';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect OpenCart orders with revenue and status data.

AVAILABLE COLUMNS:
  id, firstname, lastname, email, telephone, total, currency_code,
  order_status, payment_method, shipping_method, date_added,
  date_modified, store_name, comment

SENSITIVE COLUMNS (excluded from defaults): email, telephone

CAPABILITIES:
  - Search by customer name or email
  - Filter by order status, customer ID, date range, total range
  - Request specific columns or get defaults (id, firstname, lastname, total, order_status, date_added)
  - Aggregate mode: total orders, total_revenue, by_status counts, avg_order_value

EXAMPLES:
  "List recent orders" -> default query
  "How much revenue this month?" -> aggregate: true, date_after: "2026-04-01"
  "Pending orders" -> order_status_id: 1
  "Orders over $100" -> min_total: 100
  "Orders from customer 42" -> customer_id: 42
  "Search for John" -> search: "John"
  "Orders last week" -> date_after: "2026-04-20", date_before: "2026-04-27"
  "Show payment methods" -> columns: ["id", "total", "payment_method"]
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
                    'description' => 'Columns to return. Use ["*"] for all available. Omit for defaults (id, firstname, lastname, total, order_status, date_added).',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available columns and filter capabilities. No DB query.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total orders, total_revenue, by_status counts, avg_order_value.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against customer firstname, lastname, or email.',
                ],
                'order_status_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by order status ID (e.g. 1=Pending, 2=Processing, 5=Complete).',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Filter orders by customer ID.',
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
                    'description' => 'Minimum order total (inclusive).',
                ],
                'max_total' => [
                    'type' => 'number',
                    'description' => 'Maximum order total (inclusive).',
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
     * Authorise the caller and validate input before any query runs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the database connection is unavailable and a query is required.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('search and inspect orders');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if (! empty($input['schema'])) {
            return ['input' => ['schema' => true], 'result' => null];
        }

        if ($this->db === null) {
            throw new ToolException('oc_order: no database connection available.');
        }

        $limit = $this->clampLimit($input);
        $columns = $this->resolveColumns($input['columns'] ?? []);

        return [
            'input' => array_merge($input, ['limit' => $limit, 'columns' => $columns]),
            'result' => null,
        ];
    }

    /**
     * Run the planned query or return schema metadata.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => [
                'available_columns' => self::AVAILABLE_COLUMNS,
                'default_columns' => self::DEFAULT_COLUMNS,
                'sensitive_columns' => self::SENSITIVE_COLUMNS,
                'filter_capabilities' => [
                    'search', 'order_status_id', 'customer_id',
                    'date_after', 'date_before', 'min_total', 'max_total',
                ],
            ]];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['orders'] ?? null)) {
            throw new ToolException('oc_order: the query returned an incomplete result.');
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
            ['orders' => $payload['orders']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'shown' => $payload['shown'],
                'truncated' => $payload['truncated'],
                'columns_returned' => $payload['columns_returned'],
            ],
        );
    }

    /**
     * Gather aggregate statistics for orders matching the given filters.
     *
     * @param  array<string, mixed>  $input  Validated tool input.
     * @return array<string, mixed>
     *
     * @throws ToolException On infrastructure failure.
     */
    private function aggregateData(array $input): array
    {
        if ($this->db === null) {
            throw new ToolException('oc_order: no database connection available.');
        }

        $p = $this->tablePrefix;

        $where = 'o.order_status_id > 0';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $sql = "SELECT
                    COUNT(*) AS total,
                    ROUND(SUM(o.total), 2) AS total_revenue,
                    ROUND(AVG(o.total), 2) AS avg_order_value
                FROM `{$p}order` o
                WHERE {$where}";

        $row = $this->fetchOne($sql, $params);

        $statusParams = [];
        $statusWhere = 'o.order_status_id > 0';

        $this->applyWhereFilters($statusWhere, $statusParams, $input);

        $statusSql = "SELECT os.name AS status_name, COUNT(*) AS cnt
                      FROM `{$p}order` o
                      LEFT JOIN `{$p}order_status` os
                             ON os.order_status_id = o.order_status_id AND os.language_id = 1
                      WHERE {$statusWhere}
                      GROUP BY o.order_status_id, os.name
                      ORDER BY cnt DESC";

        try {
            $statusRows = $this->db->query($statusSql, $statusParams)->rows;
        } catch (\Throwable) {
            $statusRows = [];
        }

        $byStatus = [];

        foreach ($statusRows as $sr) {
            $byStatus[$sr['status_name'] ?? 'Unknown'] = (int) $sr['cnt'];
        }

        return [
            'stats' => [
                'total' => (int) ($row['total'] ?? 0),
                'total_revenue' => (float) ($row['total_revenue'] ?? 0),
                'avg_order_value' => (float) ($row['avg_order_value'] ?? 0),
                'by_status' => $byStatus,
            ],
        ];
    }

    /**
     * Execute a filtered order query with dynamic column selection and byte-capped output.
     *
     * @param  array<string, mixed>  $input  Validated tool input.
     * @return array<string, mixed>
     *
     * @throws ToolException On infrastructure failure.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = (int) $input['limit'];
        $columns = (array) $input['columns'];

        $where = 'o.order_status_id > 0';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $sql = "SELECT o.order_id, o.firstname, o.lastname, o.email,
                       o.telephone, o.total, o.currency_code,
                       os.name AS order_status,
                       o.payment_method, o.shipping_method,
                       o.date_added, o.date_modified,
                       o.store_name, o.comment
                FROM `{$p}order` o
                LEFT JOIN `{$p}order_status` os
                       ON os.order_status_id = o.order_status_id AND os.language_id = 1
                WHERE {$where}
                ORDER BY o.date_added DESC
                LIMIT {$limit}";

        $rows = $this->fetchRows($sql, $params);

        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->buildRow($row, $columns);
        }

        $kept = [];
        $bytes = 0;

        foreach ($results as $result) {
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $result;
            $bytes += strlen($encoded);
        }

        return [
            'orders' => $kept,
            'total' => count($results),
            'shown' => count($kept),
            'truncated' => count($kept) < count($results),
            'columns_returned' => $columns,
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
            'id' => fn () => (int) $row['order_id'],
            'firstname' => fn () => $row['firstname'] ?? '',
            'lastname' => fn () => $row['lastname'] ?? '',
            'email' => fn () => $row['email'] ?? '',
            'telephone' => fn () => $row['telephone'] ?? '',
            'total' => fn () => (float) ($row['total'] ?? 0),
            'currency_code' => fn () => $row['currency_code'] ?? '',
            'order_status' => fn () => $row['order_status'] ?? '',
            'payment_method' => fn () => $row['payment_method'] ?? '',
            'shipping_method' => fn () => $row['shipping_method'] ?? '',
            'date_added' => fn () => $row['date_added'] ?? '',
            'date_modified' => fn () => $row['date_modified'] ?? '',
            'store_name' => fn () => $row['store_name'] ?? '',
            'comment' => fn () => $row['comment'] ?? '',
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
     * @param  mixed  $requested  Column names from user input.
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
            fn ($c) => is_string($c) && in_array($c, self::AVAILABLE_COLUMNS, true)
        );

        if (! in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
        }

        return array_values($valid) ?: self::DEFAULT_COLUMNS;
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
            $where .= ' AND (o.firstname LIKE ? OR o.lastname LIKE ? OR o.email LIKE ?)';
            $term = '%'.$input['search'].'%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['order_status_id'])) {
            $where .= ' AND o.order_status_id = ?';
            $params[] = (int) $input['order_status_id'];
        }

        if (isset($input['customer_id'])) {
            $where .= ' AND o.customer_id = ?';
            $params[] = (int) $input['customer_id'];
        }

        if (isset($input['date_after']) && (string) $input['date_after'] !== '') {
            $where .= ' AND DATE(o.date_added) >= ?';
            $params[] = $input['date_after'];
        }

        if (isset($input['date_before']) && (string) $input['date_before'] !== '') {
            $where .= ' AND DATE(o.date_added) <= ?';
            $params[] = $input['date_before'];
        }

        if (isset($input['min_total'])) {
            $where .= ' AND o.total >= ?';
            $params[] = (float) $input['min_total'];
        }

        if (isset($input['max_total'])) {
            $where .= ' AND o.total <= ?';
            $params[] = (float) $input['max_total'];
        }
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
            tags: ['order', 'orders', 'purchase', 'purchases', 'sale', 'sales', 'transaction', 'transactions', 'checkout', 'invoice', 'refund', 'status', 'total', 'buyer', 'pending', 'complete', 'shipped'],
            intents: ['list orders', 'show purchases', 'find a sale', 'what did customers buy'],
            examples: ['show me yesterdays purchases'],
        );
    }
}
