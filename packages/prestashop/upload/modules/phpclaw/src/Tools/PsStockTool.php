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
 * Stock tool: query product stock availability and inventory levels.
 */
final class PsStockTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = ['search', 'product_id', 'out_of_stock', 'low_stock', 'low_stock_threshold'];

    private const MODES = ['list', 'aggregate', 'schema'];

    private const ALLOWED_KEYS = ['mode', 'columns', 'search', 'product_id', 'out_of_stock', 'low_stock', 'low_stock_threshold', 'limit', 'offset'];

    private const AVAILABLE_COLUMNS = [
        'id', 'product_name', 'reference', 'quantity',
        'physical_quantity', 'reserved_quantity',
        'location', 'date_add',
    ];

    private const DEFAULT_COLUMNS = ['id', 'product_name', 'reference', 'quantity'];

    /**
     * Create a new PsStockTool instance.
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
        return 'ps_stock';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY PrestaShop stock availability: check quantity per product, find out-of-stock
and low-stock items, and get inventory aggregate statistics.

AVAILABLE COLUMNS:
  id, product_name, reference, quantity, physical_quantity,
  reserved_quantity, location, date_add

DEFAULT COLUMNS: id, product_name, reference, quantity

CAPABILITIES:
  - Search by product name or reference (partial match)
  - Filter by product_id, out_of_stock, or low_stock threshold
  - Request specific columns or get defaults
  - Aggregate mode: total SKUs, out_of_stock count, low_stock count, total_units
  - Schema mode: discover available columns and filters

EXAMPLES:
  "All stock levels" → {}
  "Out-of-stock products" → {"out_of_stock": true}
  "Low stock (qty < 5)" → {"low_stock": true}
  "Custom threshold" → {"low_stock": true, "low_stock_threshold": 10}
  "Specific product" → {"product_id": 42, "columns": ["id","product_name","quantity","physical_quantity"]}
  "Inventory overview" → {"mode": "aggregate"}
  "What columns exist?" → {"mode": "schema"}

Invoke. Never guess stock data.
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
                                   .'"aggregate" = return inventory summary only. '
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
                    'description' => 'Search term matched against product name or reference (partial, case-insensitive).',
                ],
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by specific product ID.',
                ],
                'out_of_stock' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only products with quantity <= 0.',
                    'default' => false,
                ],
                'low_stock' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only products with quantity below the low-stock threshold (default 5). '
                                   .'Use low_stock_threshold to change the cutoff.',
                    'default' => false,
                ],
                'low_stock_threshold' => [
                    'type' => 'integer',
                    'description' => 'Quantity threshold for low_stock filter. Default: 5.',
                    'default' => 5,
                    'minimum' => 1,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100). Default: 50.',
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
            domains: ['commerce', 'inventory'],
            tags: ['stock', 'inventory', 'quantity', 'qty', 'available', 'warehouse', 'low', 'levels', 'remaining', 'outofstock', 'instock', 'movement'],
            intents: ['check stock', 'show inventory levels', 'what is running low'],
            examples: ['which products are low on stock'],
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
        $forbidden = $this->guardCapability('read PrestaShop stock levels');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if ((string) ($input['mode'] ?? 'list') !== 'schema' && $this->db === null) {
            throw new ToolException('ps_stock: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['stock'] ?? null)) {
            throw new ToolException('ps_stock: the query returned an incomplete result.');
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
            ['stock' => $payload['stock']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['stock']),
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
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException If the query fails.
     */
    private function aggregateData(array $input): array
    {
        $p = $this->tablePrefix;
        $threshold = max(1, (int) ($input['low_stock_threshold'] ?? 5));

        $sql = "SELECT
                    COUNT(DISTINCT sa.id_product) AS total_skus,
                    SUM(CASE WHEN sa.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                    SUM(CASE WHEN sa.quantity > 0 AND sa.quantity < ? THEN 1 ELSE 0 END) AS low_stock_count,
                    SUM(sa.quantity) AS total_units
                FROM `{$p}stock_available` sa
                WHERE sa.id_product_attribute = 0";

        $row = $this->fetchOne($sql, [$threshold]);

        return [
            'total_skus' => (int) ($row['total_skus'] ?? 0),
            'out_of_stock_count' => (int) ($row['out_of_stock_count'] ?? 0),
            'low_stock_count' => (int) ($row['low_stock_count'] ?? 0),
            'low_stock_threshold' => $threshold,
            'total_units' => (int) ($row['total_units'] ?? 0),
        ];
    }

    /**
     * Read one page of stock records, with a real total from the same WHERE clause.
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

        [$joins, $where, $params] = $this->buildClauses($input, $columns);

        $total = (int) ($this->fetchOne(
            "SELECT COUNT(*) AS total FROM `{$p}stock_available` sa {$joins} WHERE {$where}",
            $params,
        )['total'] ?? 0);

        $sql = "SELECT {$this->buildSelect($columns)}
                FROM `{$p}stock_available` sa
                {$joins}
                WHERE {$where}
                ORDER BY sa.quantity ASC, sa.id_stock_available ASC
                LIMIT {$limit} OFFSET {$offset}";

        $capped = ToolOutputEncoder::cap($this->fetchAll($sql, $params), self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'stock' => $capped['rows'],
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
        $threshold = max(1, (int) ($input['low_stock_threshold'] ?? 5));

        $searchTerm = isset($input['search']) && trim((string) $input['search']) !== '';
        $needsLang = in_array('product_name', $columns, true) || $searchTerm;
        $needsRef = in_array('reference', $columns, true) || $searchTerm;

        $joins = '';

        if ($needsLang) {
            $joins .= " LEFT JOIN `{$p}product_lang` pl ON pl.id_product = sa.id_product AND pl.id_lang = 1";
        }

        if ($needsRef) {
            $joins .= " LEFT JOIN `{$p}product` p ON p.id_product = sa.id_product";
        }

        $where = 'sa.id_product_attribute = 0';

        if (isset($input['product_id'])) {
            $where .= ' AND sa.id_product = ?';
            $params[] = (int) $input['product_id'];
        }

        if ($searchTerm) {
            $term = '%'.trim((string) $input['search']).'%';
            $where .= ' AND (pl.name LIKE ? OR p.reference LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        if (! empty($input['out_of_stock'])) {
            $where .= ' AND sa.quantity <= 0';
        } elseif (! empty($input['low_stock'])) {
            $where .= ' AND sa.quantity > 0 AND sa.quantity < ?';
            $params[] = $threshold;
        }

        return [$joins, $where, $params];
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
            'id' => 'sa.id_product AS id',
            'product_name' => 'pl.name AS product_name',
            'reference' => 'p.reference',
            'quantity' => 'sa.quantity',
            'physical_quantity' => 'sa.physical_quantity',
            'reserved_quantity' => 'sa.reserved_quantity',
            'location' => 'sa.location',
            'date_add' => 'sa.date_add',
        ];

        $parts = [];
        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'sa.id_product AS id';
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
            \PrestaShopLogger::addLog('phpClaw PsStockTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_stock: database query failed.', previous: $e);
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
            \PrestaShopLogger::addLog('phpClaw PsStockTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_stock: database query failed.', previous: $e);
        }
    }
}
