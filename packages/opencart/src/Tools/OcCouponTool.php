<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Coupon tool: query discount coupons with schema, aggregate, and filter support.
 */
final class OcCouponTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'code', 'discount', 'type',
        'total', 'date_start', 'date_end',
        'uses_total', 'uses_customer', 'status',
    ];

    private const DEFAULT_COLUMNS = ['id', 'name', 'discount', 'type', 'status', 'date_end'];

    private const SENSITIVE_COLUMNS = ['code'];

    protected const ERROR_LABEL = 'coupon';

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_coupon';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY OpenCart coupons: list active/expired coupons, check usage, filter by
code or status, and get aggregate statistics.

SENSITIVE COLUMNS (excluded from defaults): code

AVAILABLE COLUMNS:
  id, name, code, discount, type, total, date_start, date_end,
  uses_total, uses_customer, status

DEFAULT COLUMNS: id, name, discount, type, status, date_end

CAPABILITIES:
  - Search coupons by name or code (partial match)
  - Filter by status (active/inactive) or expired
  - Request specific columns or get defaults
  - Aggregate mode: total coupons, active/expired/upcoming, total_redemptions

EXAMPLES:
  "All active coupons" -> {"status": 1}
  "Expired coupons" -> {"expired": true}
  "Search by code" -> {"search": "SUMMER"}
  "How many coupons?" -> {"mode": "aggregate"}
  "Full details" -> {"columns": ["*"]}

Invoke this tool; never guess coupon data.
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
                    'enum' => ['list', 'aggregate', 'schema'],
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
                    'description' => 'Search term matched against coupon name or code (partial, case-insensitive).',
                ],
                'status' => [
                    'type' => 'integer',
                    'description' => '1 = active only, 0 = inactive only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'expired' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only coupons whose date_end is in the past.',
                    'default' => false,
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
        $forbidden = $this->guardCapability('query and view OpenCart coupons');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the coupon query, aggregate, or schema listing.
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
            return ['type' => 'schema', 'payload' => $this->schemaPayload()];
        }

        if ($this->db === null) {
            throw new ToolException('oc_coupon: no database connection available.');
        }

        if ($mode === 'aggregate') {
            return ['type' => 'aggregate', 'payload' => $this->aggregatePayload()];
        }

        return ['type' => 'list', 'payload' => $this->listPayload($input)];
    }

    /**
     * Assert the execution result is usable before completing.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['type'] === 'aggregate' && ! is_array($execution['payload']['stats'] ?? null)) {
            throw new ToolException('oc_coupon: aggregate query returned an incomplete result.');
        }

        if ($execution['type'] === 'list' && ! is_array($execution['payload']['coupons'] ?? null)) {
            throw new ToolException('oc_coupon: list query returned an incomplete result.');
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
            ['coupons' => $payload['coupons']],
            [
                'mode' => 'list',
                'total' => $payload['total'],
                'shown' => $payload['shown'],
                'columns_returned' => $payload['columns_returned'],
                'truncated' => $payload['truncated'],
            ],
        );
    }

    /**
     * Return static schema metadata without querying the database.
     *
     * @return array<string, mixed>
     */
    private function schemaPayload(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'sensitive_columns' => self::SENSITIVE_COLUMNS,
            'filters' => ['search', 'status', 'expired'],
            'modes' => ['list', 'aggregate', 'schema'],
        ];
    }

    /**
     * Run the aggregate coupon statistics queries and return the stats payload.
     *
     * @return array<string, mixed>
     *
     * @throws ToolException If a query fails.
     */
    private function aggregatePayload(): array
    {
        $p = $this->tablePrefix;

        $sql = "SELECT
                    COUNT(*) AS total_coupons,
                    SUM(CASE WHEN c.status = 1 AND (c.date_end = '0000-00-00' OR c.date_end >= NOW()) THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN c.date_end != '0000-00-00' AND c.date_end < NOW() THEN 1 ELSE 0 END) AS expired_count,
                    SUM(CASE WHEN c.status = 1 AND c.date_start > NOW() THEN 1 ELSE 0 END) AS upcoming_count
                FROM `{$p}coupon` c";

        $row = $this->fetchOne($sql, []);

        $redemptionSql = "SELECT COUNT(*) AS total_redemptions FROM `{$p}coupon_history`";
        $redemptionRow = $this->fetchOne($redemptionSql, []);

        return [
            'stats' => [
                'total_coupons' => (int) ($row['total_coupons'] ?? 0),
                'active_count' => (int) ($row['active_count'] ?? 0),
                'expired_count' => (int) ($row['expired_count'] ?? 0),
                'upcoming_count' => (int) ($row['upcoming_count'] ?? 0),
                'total_redemptions' => (int) ($redemptionRow['total_redemptions'] ?? 0),
            ],
        ];
    }

    /**
     * Execute a filtered coupon list query with dynamic column selection and byte-budget truncation.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function listPayload(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = $this->clampLimit($input);
        $columns = $this->resolveColumns($input['columns'] ?? []);
        $params = [];

        $selectSql = $this->buildSelect($columns);

        $sql = "SELECT {$selectSql}
                FROM `{$p}coupon` c
                WHERE 1=1";

        if (isset($input['status'])) {
            $sql .= ' AND c.status = ?';
            $params[] = (int) $input['status'];
        }

        if (! empty($input['expired'])) {
            $sql .= " AND c.date_end != '0000-00-00' AND c.date_end < NOW()";
        }

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $term = '%'.trim((string) $input['search']).'%';
            $sql .= ' AND (c.name LIKE ? OR c.code LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY c.date_end DESC LIMIT {$limit}";

        $rows = $this->fetchRows($sql, $params);
        $total = count($rows);
        $kept = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::MAX_OUTPUT_BYTES) {
                break;
            }

            $kept[] = $row;
            $bytes += strlen($encoded);
        }

        return [
            'coupons' => $kept,
            'columns_returned' => $columns,
            'total' => $total,
            'shown' => count($kept),
            'truncated' => count($kept) < $total,
        ];
    }

    /**
     * Build the SELECT clause based on requested columns.
     *
     * @param  array<int, string>  $columns  Resolved column list.
     * @return string SQL select fragment.
     */
    private function buildSelect(array $columns): string
    {
        $map = [
            'id' => 'c.coupon_id AS id',
            'name' => 'c.name',
            'code' => 'c.code',
            'discount' => 'c.discount',
            'type' => "CASE WHEN c.type = 'P' THEN 'percentage' ELSE 'fixed' END AS type",
            'total' => 'c.total',
            'date_start' => 'c.date_start',
            'date_end' => 'c.date_end',
            'uses_total' => 'c.uses_total',
            'uses_customer' => 'c.uses_customer',
            'status' => 'c.status',
        ];

        $parts = [];

        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'c.coupon_id AS id';
    }

    /**
     * Resolve requested columns to a validated list.
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
            fn ($c) => is_string($c) && in_array($c, self::AVAILABLE_COLUMNS, true),
        );

        if (! in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
        }

        return array_values($valid) ?: self::DEFAULT_COLUMNS;
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
            tags: ['coupon', 'coupons', 'discount', 'discounts', 'promo', 'promotion', 'promotions', 'voucher', 'vouchers', 'code', 'codes', 'percent', 'expired', 'uses'],
            intents: ['list coupons', 'show discount codes', 'which promos are active'],
            examples: ['list the active discount codes'],
        );
    }
}
