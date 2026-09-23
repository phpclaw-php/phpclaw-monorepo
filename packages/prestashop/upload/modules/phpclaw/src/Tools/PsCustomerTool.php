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
 * Customers tool: read-only queries with dynamic column access and full filtering.
 */
final class PsCustomerTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 25;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = [
        'search', 'group_id', 'active',
        'newsletter', 'date_after', 'date_before',
    ];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'search', 'group_id', 'active',
        'newsletter', 'date_after', 'date_before', 'limit', 'offset',
    ];

    private const DEFAULT_COLUMNS = [
        'id', 'firstname', 'lastname', 'date_add', 'active', 'total_orders',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'firstname', 'lastname', 'email', 'company', 'date_add',
        'newsletter', 'active', 'group_name', 'total_orders', 'total_spent',
    ];

    private const BLOCKED_COLUMNS = [
        'passwd', 'secure_key', 'reset_password_token',
    ];

    private const SENSITIVE_COLUMNS = ['email'];

    /**
     * Create a new PsCustomerTool instance.
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
        return 'ps_customer';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect PrestaShop customers with order history stats.

AVAILABLE COLUMNS:
  id, firstname, lastname, email, company, date_add, newsletter,
  active, group_name, total_orders, total_spent

BLOCKED COLUMNS (never returned): passwd, secure_key, reset_password_token
SENSITIVE COLUMNS (excluded from defaults): email

CAPABILITIES:
  - Search by firstname, lastname, or email
  - Filter by group, active status, newsletter subscription, registration date range
  - Request specific columns or get defaults (id, firstname, lastname, date_add, active, total_orders)
  - Aggregate mode: total customers, active/inactive, newsletter subscribers, by_group counts
  - Schema mode: discover available columns and filters

EXAMPLES:
  "List recent customers" → default query
  "How many active customers?" → aggregate: true
  "Newsletter subscribers" → newsletter: 1
  "Customers in group 3" → group_id: 3
  "Search for John" → search: "John"
  "Inactive customers" → active: 0
  "Customers who joined this month" → date_after: "2026-04-01"
  "Show email addresses" → columns: ["id", "firstname", "lastname", "email"]
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
                    'description' => 'Columns to return. Use ["*"] for all available. Omit for defaults (id, firstname, lastname, date_add, active, total_orders).',
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
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by customer group ID.',
                ],
                'active' => [
                    'type' => 'integer',
                    'description' => '1 = active only, 0 = inactive only. Omit for all.',
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
            domains: ['commerce', 'customers'],
            tags: ['customer', 'customers', 'buyer', 'buyers', 'shopper', 'shoppers', 'client', 'clients', 'account', 'accounts', 'email', 'newsletter', 'guest', 'address', 'login', 'log', 'logged', 'sign', 'signin', 'signed', 'access', 'registered'],
            intents: ['list customers', 'find a buyer', 'look up a shopper'],
            examples: ['list the customers and their emails'],
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
        $forbidden = $this->guardCapability('read PrestaShop customers');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_customer: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['customers'] ?? null)) {
            throw new ToolException('ps_customer: the customer query returned an incomplete result.');
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

        $meta = [
            'mode' => 'query',
            'total' => $payload['total'],
            'count' => count($payload['customers']),
            'limit' => $payload['limit'],
            'offset' => $payload['offset'],
            'has_more' => $payload['has_more'],
            'next_offset' => $payload['next_offset'],
            'columns_returned' => $payload['columns'],
            'truncated' => $payload['truncated'],
        ];

        $warnings = $payload['warnings'];
        $sensitive = array_values(array_intersect($payload['columns'], self::SENSITIVE_COLUMNS));

        if ($sensitive !== [] && $payload['customers'] !== []) {
            $meta['sensitive_fields_returned'] = $sensitive;

            $warnings[] = [
                'code' => 'SENSITIVE_DATA',
                'message' => 'The response contains personal data. Use it only for the requested '
                    .'purpose and do not repeat it in public output.',
            ];
        }

        return $this->success(['customers' => $payload['customers']], $meta, $warnings);
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
            'sensitive_columns' => self::SENSITIVE_COLUMNS,
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

        $where = 'c.deleted = 0';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.active = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN c.active = 0 THEN 1 ELSE 0 END) AS inactive_count,
                    SUM(CASE WHEN c.newsletter = 1 THEN 1 ELSE 0 END) AS newsletter_subscribers
                FROM `{$p}customer` c
                WHERE {$where}";

        try {
            $row = $this->db->query($sql, $params)->row;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsCustomerTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_customer: database query failed.', previous: $e);
        }

        $groupSql = "SELECT gl.name AS group_name, COUNT(*) AS cnt
                     FROM `{$p}customer` c
                     LEFT JOIN `{$p}group_lang` gl
                            ON gl.id_group = c.id_default_group AND gl.id_lang = 1
                     WHERE {$where}
                     GROUP BY c.id_default_group, gl.name
                     ORDER BY cnt DESC";

        try {
            $groupRows = $this->db->query($groupSql, $params)->rows;
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
     * Read one page of customers, with a real total from the same WHERE clause.
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

        $where = 'c.deleted = 0';
        $params = [];

        $this->applyWhereFilters($where, $params, $input);

        $countSql = "SELECT COUNT(DISTINCT c.id_customer) AS total
                     FROM `{$p}customer` c
                     WHERE {$where}";

        $sql = "SELECT c.id_customer, c.firstname, c.lastname, c.email,
                       c.company, c.date_add, c.newsletter, c.active,
                       gl.name AS group_name,
                       COUNT(DISTINCT o.id_order) AS total_orders,
                       COALESCE(ROUND(SUM(o.total_paid_tax_incl), 2), 0) AS total_spent
                FROM `{$p}customer` c
                LEFT JOIN `{$p}group_lang` gl
                       ON gl.id_group = c.id_default_group AND gl.id_lang = 1
                LEFT JOIN `{$p}orders` o
                       ON o.id_customer = c.id_customer
                WHERE {$where}
                GROUP BY c.id_customer, c.firstname, c.lastname, c.email,
                         c.company, c.date_add, c.newsletter, c.active, gl.name
                ORDER BY c.date_add DESC, c.id_customer DESC
                LIMIT {$limit} OFFSET {$offset}";

        try {
            $total = (int) ($this->db->query($countSql, $params)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $params)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsCustomerTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_customer: database query failed.', previous: $e);
        }

        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->buildRow($row, $columns);
        }

        $capped = ToolOutputEncoder::cap($results, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'customers' => $capped['rows'],
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
     * Build a single customer row with only the requested columns.
     *
     * @param  array<string, mixed>  $row  Raw database row.
     * @param  array<int, string>  $columns  Requested columns.
     * @return array<string, mixed>
     */
    private function buildRow(array $row, array $columns): array
    {
        $map = [
            'id' => fn () => (int) $row['id_customer'],
            'firstname' => fn () => $row['firstname'] ?? '',
            'lastname' => fn () => $row['lastname'] ?? '',
            'email' => fn () => $row['email'] ?? '',
            'company' => fn () => $row['company'] ?? '',
            'date_add' => fn () => $row['date_add'] ?? '',
            'newsletter' => fn () => (int) ($row['newsletter'] ?? 0),
            'active' => fn () => (int) ($row['active'] ?? 0),
            'group_name' => fn () => $row['group_name'] ?? '',
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
            $where .= ' AND (c.firstname LIKE ? OR c.lastname LIKE ? OR c.email LIKE ?)';
            $term = '%'.$input['search'].'%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['group_id'])) {
            $where .= ' AND c.id_default_group = ?';
            $params[] = (int) $input['group_id'];
        }

        if (isset($input['active'])) {
            $where .= ' AND c.active = ?';
            $params[] = (int) $input['active'];
        }

        if (isset($input['newsletter'])) {
            $where .= ' AND c.newsletter = ?';
            $params[] = (int) $input['newsletter'];
        }

        if (isset($input['date_after']) && (string) $input['date_after'] !== '') {
            $where .= ' AND DATE(c.date_add) >= ?';
            $params[] = $input['date_after'];
        }

        if (isset($input['date_before']) && (string) $input['date_before'] !== '') {
            $where .= ' AND DATE(c.date_add) <= ?';
            $params[] = $input['date_before'];
        }
    }
}
