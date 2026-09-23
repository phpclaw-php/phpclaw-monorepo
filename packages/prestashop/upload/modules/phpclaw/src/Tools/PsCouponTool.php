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
 * Coupon tool: read-only query of cart rules (vouchers / discount codes).
 */
final class PsCouponTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 25;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = ['search', 'active', 'expired', 'free_shipping'];

    private const MODES = ['list', 'aggregate', 'schema'];

    private const ALLOWED_KEYS = ['mode', 'columns', 'search', 'active', 'expired', 'free_shipping', 'limit', 'offset'];

    private const AVAILABLE_COLUMNS = [
        'id', 'code', 'name', 'description',
        'reduction_percent', 'reduction_amount',
        'quantity', 'quantity_per_user',
        'date_from', 'date_to',
        'active', 'minimum_amount', 'free_shipping',
    ];

    private const DEFAULT_COLUMNS = [
        'id', 'code', 'name', 'reduction_percent',
        'reduction_amount', 'active', 'date_to',
    ];

    /**
     * Create a new PsCouponTool instance.
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
        return 'ps_coupon';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY PrestaShop cart rules / vouchers: list discount codes, filter active/expired/
free-shipping, search by code or name, and get aggregate statistics.

AVAILABLE COLUMNS:
  id, code, name, description, reduction_percent, reduction_amount,
  quantity, quantity_per_user, date_from, date_to, active,
  minimum_amount, free_shipping

DEFAULT COLUMNS: id, code, name, reduction_percent, reduction_amount, active, date_to

CAPABILITIES:
  - Search coupons by code or name (partial match)
  - Filter by active status, expired, or free_shipping
  - Request specific columns or get defaults
  - Page with meta.next_offset; meta.total is the real count for the same filters
  - Aggregate mode: total coupons, active/expired/upcoming counts, free_shipping count
  - Schema mode: discover available columns and filters

EXAMPLES:
  "All active coupons" → {"active": 1}
  "Expired coupons" → {"expired": true}
  "Free shipping rules" → {"free_shipping": true}
  "Search by code" → {"search": "SUMMER"}
  "Full details" → {"columns": ["*"]}
  "Coupon overview" → {"mode": "aggregate"}
  "What columns exist?" → {"mode": "schema"}

Invoke. Never guess coupon data.
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
                                   .'"aggregate" = return coupon summary only. '
                                   .'"schema" = return available/default columns and filters.',
                    'enum' => self::MODES,
                    'default' => 'list',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Columns to include in each row. Use ["*"] for all available. '
                                   .'Available: '.implode(', ', self::AVAILABLE_COLUMNS).'. '
                                   .'Default: '.implode(', ', self::DEFAULT_COLUMNS).'.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term matched against coupon code or name (partial, case-insensitive).',
                ],
                'active' => [
                    'type' => 'integer',
                    'description' => '1 = active only, 0 = inactive only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'expired' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only rules whose date_to is in the past.',
                    'default' => false,
                ],
                'free_shipping' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only rules that grant free shipping.',
                    'default' => false,
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
            domains: ['commerce', 'promotions'],
            tags: ['coupon', 'coupons', 'discount', 'discounts', 'promo', 'promotion', 'promotions', 'voucher', 'vouchers', 'rule', 'rules', 'code', 'codes', 'percent', 'expired', 'quantity'],
            intents: ['list coupons', 'show discount codes', 'which promos are active'],
            examples: ['list the active discount codes'],
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
        $forbidden = $this->guardCapability('read PrestaShop cart rules and vouchers');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if ((string) ($input['mode'] ?? 'list') !== 'schema' && $this->db === null) {
            throw new ToolException('ps_coupon: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['coupons'] ?? null)) {
            throw new ToolException('ps_coupon: the query returned an incomplete result.');
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
            ['coupons' => $payload['coupons']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['coupons']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
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
     * Aggregate mode, counts and stats only, zero row data.
     *
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException If the query fails.
     */
    private function aggregateData(): array
    {
        $p = $this->tablePrefix;

        $sql = "SELECT
                    COUNT(*) AS total_coupons,
                    SUM(CASE WHEN cr.active = 1 AND cr.date_to >= NOW() THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN cr.date_to < NOW() THEN 1 ELSE 0 END) AS expired_count,
                    SUM(CASE WHEN cr.active = 1 AND cr.date_from > NOW() THEN 1 ELSE 0 END) AS upcoming_count,
                    SUM(CASE WHEN cr.free_shipping = 1 THEN 1 ELSE 0 END) AS free_shipping_count
                FROM `{$p}cart_rule` cr";

        $row = $this->fetchOne($sql, []);

        return [
            'total_coupons' => (int) ($row['total_coupons'] ?? 0),
            'active_count' => (int) ($row['active_count'] ?? 0),
            'expired_count' => (int) ($row['expired_count'] ?? 0),
            'upcoming_count' => (int) ($row['upcoming_count'] ?? 0),
            'free_shipping_count' => (int) ($row['free_shipping_count'] ?? 0),
        ];
    }

    /**
     * Read one page of coupons, with a real total from the same WHERE clause.
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
        $columns = $this->resolveColumns($input);

        [$langJoin, $where, $params] = $this->buildClauses($input, $columns);

        $total = (int) ($this->fetchOne(
            "SELECT COUNT(DISTINCT cr.id_cart_rule) AS total
             FROM `{$p}cart_rule` cr
             {$langJoin}
             WHERE {$where}",
            $params,
        )['total'] ?? 0);

        $sql = "SELECT {$this->buildSelect($columns)}
                FROM `{$p}cart_rule` cr
                {$langJoin}
                WHERE {$where}
                ORDER BY cr.date_add DESC, cr.id_cart_rule DESC
                LIMIT {$limit} OFFSET {$offset}";

        $capped = ToolOutputEncoder::cap($this->fetchAll($sql, $params), self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'coupons' => $capped['rows'],
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
     * Build the language join, WHERE clause and bindings shared by the count and the page.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @param  list<string>  $columns  Resolved column list.
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function buildClauses(array $input, array $columns): array
    {
        $p = $this->tablePrefix;
        $params = [];

        $needsLang = in_array('name', $columns, true) || in_array('description', $columns, true);
        $searchNeedsLang = isset($input['search']) && trim((string) $input['search']) !== '';

        $langJoin = '';

        if ($needsLang || $searchNeedsLang) {
            $langJoin = "LEFT JOIN `{$p}cart_rule_lang` crl
                                ON crl.id_cart_rule = cr.id_cart_rule AND crl.id_lang = 1";
        }

        $where = '1=1';

        if (isset($input['active'])) {
            $where .= ' AND cr.active = ?';
            $params[] = (int) $input['active'];
        }

        if (! empty($input['expired'])) {
            $where .= ' AND cr.date_to < NOW()';
        }

        if (! empty($input['free_shipping'])) {
            $where .= ' AND cr.free_shipping = 1';
        }

        if ($searchNeedsLang) {
            $term = '%'.trim((string) $input['search']).'%';
            $where .= ' AND (cr.code LIKE ? OR crl.name LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        return [$langJoin, $where, $params];
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
            'id' => 'cr.id_cart_rule AS id',
            'code' => 'cr.code',
            'name' => 'crl.name',
            'description' => 'SUBSTRING(crl.name, 1, 200) AS description',
            'reduction_percent' => 'cr.reduction_percent',
            'reduction_amount' => 'cr.reduction_amount',
            'quantity' => 'cr.quantity',
            'quantity_per_user' => 'cr.quantity_per_user',
            'date_from' => 'cr.date_from',
            'date_to' => 'cr.date_to',
            'active' => 'cr.active',
            'minimum_amount' => 'cr.minimum_amount',
            'free_shipping' => 'cr.free_shipping',
        ];

        $parts = [];
        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'cr.id_cart_rule AS id';
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
            \PrestaShopLogger::addLog('phpClaw PsCouponTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_coupon: database query failed.', previous: $e);
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
            \PrestaShopLogger::addLog('phpClaw PsCouponTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_coupon: database query failed.', previous: $e);
        }
    }
}
