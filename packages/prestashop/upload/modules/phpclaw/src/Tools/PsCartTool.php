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
 * Cart tool: read-only query of shopping carts with abandonment detection.
 */
final class PsCartTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 25;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const AVAILABLE_COLUMNS = [
        'id', 'customer_name', 'customer_email', 'total_products',
        'total', 'date_add', 'date_upd',
        'carrier_name', 'currency',
    ];

    private const DEFAULT_COLUMNS = ['id', 'customer_name', 'total', 'date_add'];

    private const SENSITIVE_COLUMNS = ['customer_email'];

    private const FILTERS = ['search', 'abandoned_only', 'date_after', 'date_before', 'min_total'];

    private const MODES = ['list', 'aggregate', 'schema'];

    private const ALLOWED_KEYS = [
        'mode', 'columns', 'search', 'abandoned_only',
        'date_after', 'date_before', 'min_total', 'limit', 'offset',
    ];

    /**
     * Create a new PsCartTool instance.
     *
     * @param  PsDbInterface|null  $db  Native PrestaShop DB handle.
     * @param  string  $tablePrefix  PrestaShop table prefix (default 'ps_').
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
        return 'ps_cart';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY PrestaShop shopping carts: list all carts, detect abandoned carts (no order),
filter by date range and total, search by customer name, and get aggregate statistics.

AVAILABLE COLUMNS:
  id, customer_name, customer_email, total_products,
  total, date_add, date_upd, carrier_name, currency

DEFAULT COLUMNS: id, customer_name, total, date_add
SENSITIVE COLUMNS (excluded from defaults): customer_email

CAPABILITIES:
  - Search carts by customer name (partial match)
  - Filter abandoned carts (no matching order), date range, minimum total
  - Request specific columns or get defaults
  - Page with meta.next_offset; meta.total is the real count for the same filters
  - Aggregate mode: total carts, abandoned count, avg_cart_value, total_value
  - Schema mode: discover available columns and filters

EXAMPLES:
  "All carts" → {}
  "Abandoned carts" → {"abandoned_only": true}
  "Carts over $50" → {"min_total": 50}
  "Carts this week" → {"date_after": "2026-04-21"}
  "Search for John" → {"search": "John"}
  "Include email" → {"columns": ["id","customer_name","customer_email","total","date_add"]}
  "Next page" → {"offset": 25}
  "Cart overview" → {"mode": "aggregate"}
  "What columns exist?" → {"mode": "schema"}

Invoke. Never guess cart data.
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
                'mode' => [
                    'type' => 'string',
                    'description' => 'Operation mode. "list" = return rows (default). '
                                   .'"aggregate" = return cart summary only. '
                                   .'"schema" = return available/default columns and filters.',
                    'enum' => self::MODES,
                    'default' => 'list',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Columns to include in each row. Use ["*"] for all available. '
                                   .'Available: '.implode(', ', self::AVAILABLE_COLUMNS).'. '
                                   .'Default: '.implode(', ', self::DEFAULT_COLUMNS).'. '
                                   .'Sensitive (must request explicitly): '.implode(', ', self::SENSITIVE_COLUMNS).'.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against customer first/last name (partial, case-insensitive).',
                ],
                'abandoned_only' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only carts with no matching order (abandoned).',
                    'default' => false,
                ],
                'date_after' => [
                    'type' => 'string',
                    'description' => 'Carts created on or after this date (YYYY-MM-DD).',
                ],
                'date_before' => [
                    'type' => 'string',
                    'description' => 'Carts created on or before this date (YYYY-MM-DD).',
                ],
                'min_total' => [
                    'type' => 'number',
                    'description' => 'Only return carts where the computed products total >= this value.',
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
            domains: ['commerce', 'carts'],
            tags: ['cart', 'carts', 'basket', 'baskets', 'trolley', 'abandoned', 'abandon', 'pending', 'unconverted', 'session', 'guest'],
            intents: ['list carts', 'show abandoned baskets', 'which carts never converted'],
            examples: ['show me the abandoned carts'],
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
        $forbidden = $this->guardCapability('read PrestaShop shopping carts');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if ((string) ($input['mode'] ?? 'list') !== 'schema' && $this->db === null) {
            throw new ToolException('ps_cart: no database connection available.');
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
        $mode = (string) ($input['mode'] ?? 'list');

        if ($mode === 'schema') {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        if ($mode === 'aggregate') {
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['carts'] ?? null)) {
            throw new ToolException('ps_cart: the cart query returned an incomplete result.');
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
            'count' => count($payload['carts']),
            'limit' => $payload['limit'],
            'offset' => $payload['offset'],
            'has_more' => $payload['has_more'],
            'next_offset' => $payload['next_offset'],
            'columns_returned' => $payload['columns'],
            'truncated' => $payload['truncated'],
        ];

        $warnings = $payload['warnings'];
        $sensitive = array_values(array_intersect($payload['columns'], self::SENSITIVE_COLUMNS));

        if ($sensitive !== [] && $payload['carts'] !== []) {
            $meta['sensitive_fields_returned'] = $sensitive;

            $warnings[] = [
                'code' => 'SENSITIVE_DATA',
                'message' => 'The response contains personal data. Use it only for the requested '
                    .'purpose and do not repeat it in public output.',
            ];
        }

        return $this->success(['carts' => $payload['carts']], $meta, $warnings);
    }

    /**
     * Read one page of carts, with a real total from the same WHERE clause.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the cart query fails.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = min(max(0, (int) ($input['offset'] ?? self::DEFAULT_OFFSET)), self::MAX_OFFSET);
        $columns = $this->resolveColumns($input);

        [$joins, $where, $params] = $this->buildClauses($input, $columns);

        $total = (int) ($this->fetchOne(
            "SELECT COUNT(DISTINCT ct.id_cart) AS total FROM `{$p}cart` ct {$joins} WHERE {$where}",
            $params,
        )['total'] ?? 0);

        $sql = "SELECT {$this->buildSelect($columns)}
                FROM `{$p}cart` ct
                {$joins}
                WHERE {$where}
                GROUP BY ct.id_cart
                ORDER BY ct.date_add DESC, ct.id_cart DESC
                LIMIT {$limit} OFFSET {$offset}";

        $capped = ToolOutputEncoder::cap($this->fetchAll($sql, $params), self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'carts' => $capped['rows'],
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
     * Aggregate mode, counts and stats only, zero row data.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the aggregate query fails.
     */
    private function aggregateData(array $input): array
    {
        $p = $this->tablePrefix;
        $params = [];
        $dateWhere = $this->buildDateWhere($input, $params);

        $sql = 'SELECT
                    COUNT(*) AS total_carts,
                    SUM(CASE WHEN o.id_order IS NULL THEN 1 ELSE 0 END) AS abandoned_count,
                    ROUND(AVG('.self::cartTotalSql($p).'), 2) AS avg_cart_value,
                    ROUND(SUM('.self::cartTotalSql($p)."), 2) AS total_value
                FROM `{$p}cart` ct
                LEFT JOIN `{$p}orders` o ON o.id_cart = ct.id_cart
                WHERE ct.id_customer > 0
                {$dateWhere}";

        $row = $this->fetchOne($sql, $params);

        return [
            'total_carts' => (int) ($row['total_carts'] ?? 0),
            'abandoned_count' => (int) ($row['abandoned_count'] ?? 0),
            'avg_cart_value' => (float) ($row['avg_cart_value'] ?? 0),
            'total_value' => (float) ($row['total_value'] ?? 0),
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
            'sensitive_columns' => self::SENSITIVE_COLUMNS,
            'filters' => self::FILTERS,
            'modes' => self::MODES,
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

        if (array_key_exists('mode', $input) && ! in_array((string) $input['mode'], self::MODES, true)) {
            return $this->error(
                'INVALID_ARGUMENT',
                sprintf('"mode" must be one of: %s.', implode(', ', self::MODES)),
                ['accepted_modes' => self::MODES],
            );
        }

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
    }

    /**
     * Build the joins, WHERE clause and bindings shared by the count and the page.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @param  list<string>  $columns  Resolved column list.
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function buildClauses(array $input, array $columns): array
    {
        $p = $this->tablePrefix;
        $params = [];

        $needsCustomer = in_array('customer_name', $columns, true)
                      || in_array('customer_email', $columns, true);
        $searchTerm = isset($input['search']) && trim((string) $input['search']) !== '';

        $joins = '';

        if ($needsCustomer || $searchTerm) {
            $joins .= " LEFT JOIN `{$p}customer` cu ON cu.id_customer = ct.id_customer";
        }

        if (in_array('carrier_name', $columns, true)) {
            $joins .= " LEFT JOIN `{$p}carrier` ca ON ca.id_carrier = ct.id_carrier";
        }

        if (in_array('currency', $columns, true)) {
            $joins .= " LEFT JOIN `{$p}currency` cur ON cur.id_currency = ct.id_currency";
        }

        $joins .= " LEFT JOIN `{$p}orders` o ON o.id_cart = ct.id_cart";

        $where = 'ct.id_customer > 0';

        if (! empty($input['abandoned_only'])) {
            $where .= ' AND o.id_order IS NULL';
        }

        $where .= $this->buildDateWhere($input, $params);

        if (isset($input['min_total'])) {
            $where .= ' AND '.self::cartTotalSql($p).' >= ?';
            $params[] = (float) $input['min_total'];
        }

        if ($searchTerm) {
            $term = '%'.trim((string) $input['search']).'%';
            $where .= ' AND (cu.firstname LIKE ? OR cu.lastname LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        return [$joins, $where, $params];
    }

    /**
     * SQL for a cart total; PrestaShop stores no cart total, it is summed from cart_product.
     *
     * @param  string  $prefix  Table prefix.
     * @return string Correlated subquery yielding the products total for ct.id_cart.
     */
    private static function cartTotalSql(string $prefix): string
    {
        return '(SELECT ROUND(COALESCE(SUM(cp.quantity * pss.price), 0), 2)'
            ." FROM `{$prefix}cart_product` cp"
            ." JOIN `{$prefix}product_shop` pss"
            .' ON pss.id_product = cp.id_product AND pss.id_shop = ct.id_shop'
            .' WHERE cp.id_cart = ct.id_cart)';
    }

    /**
     * Build the SELECT clause based on requested columns.
     *
     * @param  list<string>  $columns  Resolved column list.
     * @return string SQL select fragment.
     */
    private function buildSelect(array $columns): string
    {
        $map = [
            'id' => 'ct.id_cart AS id',
            'customer_name' => "CONCAT(cu.firstname, ' ', cu.lastname) AS customer_name",
            'customer_email' => 'cu.email AS customer_email',
            'total_products' => self::cartTotalSql($this->tablePrefix).' AS total_products',
            'total' => self::cartTotalSql($this->tablePrefix).' AS total',
            'date_add' => 'ct.date_add',
            'date_upd' => 'ct.date_upd',
            'carrier_name' => 'ca.name AS carrier_name',
            'currency' => 'cur.iso_code AS currency',
        ];

        $parts = [];

        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'ct.id_cart AS id';
    }

    /**
     * Build date-range WHERE fragments.
     *
     * @param  array<string, mixed>  $input  Tool input.
     * @param  list<mixed>  $params  Bind parameters (modified by reference).
     * @return string SQL fragment (starts with AND if non-empty).
     */
    private function buildDateWhere(array $input, array &$params): string
    {
        $sql = '';

        if (isset($input['date_after']) && (string) $input['date_after'] !== '') {
            $sql .= ' AND DATE(ct.date_add) >= ?';
            $params[] = (string) $input['date_after'];
        }

        if (isset($input['date_before']) && (string) $input['date_before'] !== '') {
            $sql .= ' AND DATE(ct.date_add) <= ?';
            $params[] = (string) $input['date_before'];
        }

        return $sql;
    }

    /**
     * Resolve which columns to use from input or fall back to defaults.
     *
     * @param  array<string, mixed>  $input  Tool input.
     * @return list<string> Validated column list.
     */
    private function resolveColumns(array $input): array
    {
        if (! isset($input['columns']) || ! is_array($input['columns']) || $input['columns'] === []) {
            return self::DEFAULT_COLUMNS;
        }

        if ($input['columns'] === ['*']) {
            return self::AVAILABLE_COLUMNS;
        }

        $valid = array_intersect($input['columns'], self::AVAILABLE_COLUMNS);

        return $valid !== [] ? array_values($valid) : self::DEFAULT_COLUMNS;
    }

    /**
     * Execute a query and return a single row.
     *
     * @param  string  $sql  SQL statement.
     * @param  list<mixed>  $params  Bind parameters.
     * @return array<string, mixed> Single row or empty array.
     *
     * @throws ToolException If the query fails.
     */
    private function fetchOne(string $sql, array $params): array
    {
        try {
            return $this->db->query($sql, $params)->row;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsCartTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_cart: database query failed.', previous: $e);
        }
    }

    /**
     * Execute a query and return every row.
     *
     * @param  string  $sql  SQL statement.
     * @param  list<mixed>  $params  Bind parameters.
     * @return array<int, array<string, mixed>> Result rows.
     *
     * @throws ToolException If the query fails.
     */
    private function fetchAll(string $sql, array $params): array
    {
        try {
            return $this->db->query($sql, $params)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsCartTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_cart: database query failed.', previous: $e);
        }
    }
}
