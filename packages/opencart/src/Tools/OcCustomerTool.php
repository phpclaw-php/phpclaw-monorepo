<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Customers tool: dynamic column access with full filtering.
 */
final class OcCustomerTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const DEFAULT_COLUMNS = [
        'id', 'firstname', 'lastname', 'date_added', 'status', 'total_orders',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'firstname', 'lastname', 'email', 'telephone',
        'customer_group', 'status', 'newsletter', 'date_added',
        'ip', 'total_orders', 'total_spent',
    ];

    private const BLOCKED_COLUMNS = [
        'password', 'salt', 'token', 'code', 'custom_field',
    ];

    private const SENSITIVE_COLUMNS = ['email', 'telephone', 'ip'];

    protected const ERROR_LABEL = 'customer';

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_customer';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect OpenCart customers with order history stats.

AVAILABLE COLUMNS:
  id, firstname, lastname, email, telephone, customer_group, status,
  newsletter, date_added, ip, total_orders, total_spent

BLOCKED COLUMNS (never returned): password, salt, token, code, custom_field
SENSITIVE COLUMNS (excluded from defaults): email, telephone, ip

CAPABILITIES:
  - Search by firstname, lastname, or email
  - Filter by customer group, status, newsletter opt-in, registration date range
  - Request specific columns or get defaults (id, firstname, lastname, date_added, status, total_orders)
  - Aggregate mode: total customers, active/inactive, newsletter subscribers, by_group counts

EXAMPLES:
  "List recent customers" -> default query
  "How many active customers?" -> aggregate: true
  "Newsletter subscribers" -> newsletter: 1
  "Customers in group 2" -> customer_group_id: 2
  "Search for John" -> search: "John"
  "Inactive customers" -> status: 0
  "Customers who joined this month" -> date_after: "2026-04-01"
  "Show email addresses" -> columns: ["id", "firstname", "lastname", "email"]
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
                    'description' => 'Columns to return. Use ["*"] for all available. Omit for defaults (id, firstname, lastname, date_added, status, total_orders).',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available columns and filter capabilities. No DB query.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total customers, active/inactive, newsletter subscribers, by_group counts.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against firstname, lastname, or email.',
                ],
                'customer_group_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by customer group ID.',
                ],
                'status' => [
                    'type' => 'integer',
                    'description' => '1 = active/approved only, 0 = disabled only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'newsletter' => [
                    'type' => 'integer',
                    'description' => '1 = subscribed to newsletter, 0 = not subscribed.',
                    'enum' => [0, 1],
                ],
                'date_after' => [
                    'type' => 'string',
                    'description' => 'Customers registered on or after this date (YYYY-MM-DD).',
                ],
                'date_before' => [
                    'type' => 'string',
                    'description' => 'Customers registered on or before this date (YYYY-MM-DD).',
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
     * Authorise the caller before any query runs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('search and inspect OpenCart customers');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the customer query, aggregate, or schema listing.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (! empty($input['schema'])) {
            return ['type' => 'schema', 'payload' => $this->schemaPayload()];
        }

        if ($this->db === null) {
            throw new ToolException('oc_customer: no database connection available.');
        }

        if (! empty($input['aggregate'])) {
            return ['type' => 'aggregate', 'payload' => $this->aggregatePayload($input)];
        }

        return ['type' => 'query', 'payload' => $this->queryPayload($input)];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['customers'] ?? null)) {
            throw new ToolException('oc_customer: the query returned an incomplete result.');
        }

        if ($execution['type'] === 'aggregate' && ! is_array($execution['payload']['stats'] ?? null)) {
            throw new ToolException('oc_customer: the aggregate query returned an incomplete result.');
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
                ['mode' => 'aggregate', 'total' => $payload['stats']['total'] ?? 0],
            );
        }

        return $this->success(
            ['customers' => $payload['customers'], 'columns_returned' => $payload['columns_returned']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'shown' => $payload['shown'],
                'truncated' => $payload['truncated'],
            ],
        );
    }

    /**
     * Return schema metadata without querying the database.
     *
     * @return array<string, mixed>
     */
    private function schemaPayload(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'blocked_columns' => self::BLOCKED_COLUMNS,
            'sensitive_columns' => self::SENSITIVE_COLUMNS,
            'filter_capabilities' => [
                'search', 'customer_group_id', 'status',
                'newsletter', 'date_after', 'date_before',
            ],
        ];
    }

    /**
     * Execute aggregate statistics queries and return the stats payload.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the primary query fails.
     */
    private function aggregatePayload(array $input): array
    {
        $p = $this->tablePrefix;

        $where = '1=1';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN c.status = 0 THEN 1 ELSE 0 END) AS inactive_count,
                    SUM(CASE WHEN c.newsletter = 1 THEN 1 ELSE 0 END) AS newsletter_subscribers
                FROM `{$p}customer` c
                WHERE {$where}";

        $row = $this->fetchOne($sql, $params);

        $groupParams = [];
        $groupWhere = '1=1';

        $this->applyWhereFilters($groupWhere, $groupParams, $input);

        $groupSql = "SELECT cgd.name AS group_name, COUNT(*) AS cnt
                     FROM `{$p}customer` c
                     LEFT JOIN `{$p}customer_group_description` cgd
                            ON cgd.customer_group_id = c.customer_group_id AND cgd.language_id = 1
                     WHERE {$groupWhere}
                     GROUP BY c.customer_group_id, cgd.name
                     ORDER BY cnt DESC";

        try {
            $groupRows = $this->db->query($groupSql, $groupParams)->rows;
        } catch (\Throwable) {
            $groupRows = [];
        }

        $byGroup = [];

        foreach ($groupRows as $gr) {
            $byGroup[$gr['group_name'] ?? 'Unknown'] = (int) $gr['cnt'];
        }

        return [
            'stats' => [
                'total' => (int) ($row['total'] ?? 0),
                'active_count' => (int) ($row['active_count'] ?? 0),
                'inactive_count' => (int) ($row['inactive_count'] ?? 0),
                'newsletter_subscribers' => (int) ($row['newsletter_subscribers'] ?? 0),
                'by_group' => $byGroup,
            ],
        ];
    }

    /**
     * Execute a filtered customer query with dynamic column selection.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function queryPayload(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = $this->clampLimit($input);
        $columns = $this->resolveColumns($input['columns'] ?? []);

        $where = '1=1';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $sql = "SELECT c.customer_id, c.firstname, c.lastname, c.email,
                       c.telephone, c.customer_group_id,
                       cgd.name AS customer_group,
                       c.status, c.newsletter, c.date_added, c.ip,
                       COUNT(DISTINCT o.order_id) AS total_orders,
                       COALESCE(ROUND(SUM(o.total), 2), 0) AS total_spent
                FROM `{$p}customer` c
                LEFT JOIN `{$p}customer_group_description` cgd
                       ON cgd.customer_group_id = c.customer_group_id AND cgd.language_id = 1
                LEFT JOIN `{$p}order` o
                       ON o.customer_id = c.customer_id AND o.order_status_id > 0
                WHERE {$where}
                GROUP BY c.customer_id, c.firstname, c.lastname, c.email,
                         c.telephone, c.customer_group_id, cgd.name,
                         c.status, c.newsletter, c.date_added, c.ip
                ORDER BY c.date_added DESC
                LIMIT {$limit}";

        $rows = $this->fetchRows($sql, $params);

        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->buildRow($row, $columns);
        }

        $total = count($results);
        $kept = [];
        $bytes = 0;

        foreach ($results as $customer) {
            $encoded = json_encode($customer, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $customer;
            $bytes += strlen($encoded);
        }

        return [
            'customers' => $kept,
            'columns_returned' => $columns,
            'total' => $total,
            'shown' => count($kept),
            'truncated' => count($kept) < $total,
        ];
    }

    /**
     * Build a single customer row with only the requested columns.
     *
     * @param  array<string, mixed>  $row  Raw database row.
     * @param  array<int, string>  $columns  Requested columns.
     * @return array<string, mixed>
     */
    private function buildRow(array $row, array $columns): array
    {
        $map = [
            'id' => fn () => (int) $row['customer_id'],
            'firstname' => fn () => $row['firstname'] ?? '',
            'lastname' => fn () => $row['lastname'] ?? '',
            'email' => fn () => $row['email'] ?? '',
            'telephone' => fn () => $row['telephone'] ?? '',
            'customer_group' => fn () => $row['customer_group'] ?? '',
            'status' => fn () => (int) ($row['status'] ?? 0),
            'newsletter' => fn () => (int) ($row['newsletter'] ?? 0),
            'date_added' => fn () => $row['date_added'] ?? '',
            'ip' => fn () => $row['ip'] ?? '',
            'total_orders' => fn () => (int) ($row['total_orders'] ?? 0),
            'total_spent' => fn () => (float) ($row['total_spent'] ?? 0),
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
     * @param  string  $where  WHERE clause (modified by reference).
     * @param  array<int, mixed>  $params  Positional `?` bind params (modified by reference).
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return void
     */
    private function applyWhereFilters(string &$where, array &$params, array $input): void
    {
        if (isset($input['search']) && (string) $input['search'] !== '') {
            $where .= ' AND (c.firstname LIKE ? OR c.lastname LIKE ? OR c.email LIKE ?)';
            $term = '%'.$input['search'].'%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['customer_group_id'])) {
            $where .= ' AND c.customer_group_id = ?';
            $params[] = (int) $input['customer_group_id'];
        }

        if (isset($input['status'])) {
            $where .= ' AND c.status = ?';
            $params[] = (int) $input['status'];
        }

        if (isset($input['newsletter'])) {
            $where .= ' AND c.newsletter = ?';
            $params[] = (int) $input['newsletter'];
        }

        if (isset($input['date_after']) && (string) $input['date_after'] !== '') {
            $where .= ' AND DATE(c.date_added) >= ?';
            $params[] = $input['date_after'];
        }

        if (isset($input['date_before']) && (string) $input['date_before'] !== '') {
            $where .= ' AND DATE(c.date_added) <= ?';
            $params[] = $input['date_before'];
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
            domains: ['commerce', 'customers'],
            tags: ['customer', 'customers', 'buyer', 'buyers', 'shopper', 'shoppers', 'client', 'clients', 'account', 'accounts', 'email', 'telephone', 'newsletter', 'approved', 'login', 'log', 'logged', 'sign', 'signin', 'signed', 'access', 'registered'],
            intents: ['list customers', 'find a buyer', 'look up a shopper'],
            examples: ['list the customers and their emails'],
        );
    }
}
