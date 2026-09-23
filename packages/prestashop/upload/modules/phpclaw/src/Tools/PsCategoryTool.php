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
 * Categories tool: read-only listing, search, and aggregation of product categories.
 */
final class PsCategoryTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'description', 'active', 'parent_id',
        'parent_name', 'depth', 'position', 'product_count',
        'date_add', 'date_upd',
    ];

    private const DEFAULT_COLUMNS = ['id', 'name', 'active', 'parent_name', 'product_count'];

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = ['search', 'parent_id', 'active', 'hide_empty'];

    private const MODES = ['list', 'aggregate', 'schema'];

    private const ALLOWED_KEYS = [
        'mode', 'columns', 'search', 'parent_id', 'active', 'hide_empty', 'limit', 'offset',
    ];

    /**
     * Create a new PsCategoryTool instance.
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
        return 'ps_category';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY PrestaShop product categories: browse hierarchy, filter by parent/active,
search by name, count products per category, and get aggregate statistics.

AVAILABLE COLUMNS:
  id, name, description, active, parent_id, parent_name, depth,
  position, product_count, date_add, date_upd

DEFAULT COLUMNS: id, name, active, parent_name, product_count

CAPABILITIES:
  - Search categories by name (partial match)
  - Filter by parent_id, active status, or hide empty categories
  - Request specific columns or get defaults
  - Page with meta.next_offset; meta.total is the real count for the same filters
  - Aggregate mode: total categories, active/inactive, empty count, max_depth
  - Schema mode: discover available columns and filters

EXAMPLES:
  "List all active categories" → {"active": 1}
  "Categories under parent 2" → {"parent_id": 2}
  "Find empty categories" → {"hide_empty": true, "columns": ["id","name","product_count"]}
  "Search for clothing" → {"search": "clothing"}
  "Category tree depth" → {"mode": "aggregate"}
  "What columns exist?" → {"mode": "schema"}

Invoke. Never guess category data.
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
                                   .'"aggregate" = return summary statistics only. '
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
                    'description' => 'Search term matched against category name (partial, case-insensitive).',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by parent category ID (e.g. 2 = root in most installs).',
                ],
                'active' => [
                    'type' => 'integer',
                    'description' => '1 = active only, 0 = inactive only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'When true, exclude categories with 0 products. Default: false.',
                    'default' => false,
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
            domains: ['commerce', 'catalog'],
            tags: ['category', 'categories', 'department', 'departments', 'collection', 'collections', 'section', 'parent', 'child', 'tree', 'nleft', 'nright'],
            intents: ['list categories', 'show departments', 'what collections exist'],
            examples: ['list the product categories'],
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
        $forbidden = $this->guardCapability('read PrestaShop product categories');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if ((string) ($input['mode'] ?? 'list') !== 'schema' && $this->db === null) {
            throw new ToolException('ps_category: no database connection available.');
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['categories'] ?? null)) {
            throw new ToolException('ps_category: the category query returned an incomplete result.');
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
            ['categories' => $payload['categories']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['categories']),
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
        $params = [];

        $where = $this->buildWhere($input, $params);

        $sql = "SELECT
                    COUNT(*) AS total_categories,
                    SUM(CASE WHEN c.active = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN c.active = 0 THEN 1 ELSE 0 END) AS inactive_count,
                    MAX(c.level_depth) AS max_depth
                FROM `{$p}category` c
                LEFT JOIN `{$p}category_lang` cl
                       ON cl.id_category = c.id_category AND cl.id_lang = 1
                {$where}";

        $row = $this->fetchOne($sql, $params);

        $emptyParams = $params;
        $emptySql = "SELECT COUNT(*) AS empty_count FROM (
                         SELECT c.id_category
                         FROM `{$p}category` c
                         LEFT JOIN `{$p}category_lang` cl
                                ON cl.id_category = c.id_category AND cl.id_lang = 1
                         LEFT JOIN `{$p}category_product` cp
                                ON cp.id_category = c.id_category
                         {$where}
                         GROUP BY c.id_category
                         HAVING COUNT(cp.id_product) = 0
                     ) AS sub";

        try {
            $emptyRow = $this->db->query($emptySql, $emptyParams)->row;
            $emptyCount = (int) ($emptyRow['empty_count'] ?? 0);
        } catch (\Throwable) {
            $emptyCount = 0;
        }

        return [
            'total_categories' => (int) ($row['total_categories'] ?? 0),
            'active_count' => (int) ($row['active_count'] ?? 0),
            'inactive_count' => (int) ($row['inactive_count'] ?? 0),
            'empty_categories' => $emptyCount,
            'max_depth' => (int) ($row['max_depth'] ?? 0),
        ];
    }

    /**
     * Read one page of categories, with a real total from the same WHERE clause.
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
        $params = [];

        $where = $this->buildWhere($input, $params);
        $hideEmpty = ! empty($input['hide_empty']);

        $joins = '';

        if (in_array('parent_name', $columns, true)) {
            $joins .= " LEFT JOIN `{$p}category` pc ON pc.id_category = c.id_parent";
            $joins .= " LEFT JOIN `{$p}category_lang` pcl ON pcl.id_category = pc.id_category AND pcl.id_lang = 1";
        }

        if (in_array('product_count', $columns, true) || $hideEmpty) {
            $joins .= " LEFT JOIN `{$p}category_product` cp ON cp.id_category = c.id_category";
        }

        $base = "FROM `{$p}category` c
                LEFT JOIN `{$p}category_lang` cl
                       ON cl.id_category = c.id_category AND cl.id_lang = 1
                {$joins}
                {$where}
                GROUP BY c.id_category";

        $having = $hideEmpty ? ' HAVING COUNT(cp.id_product) > 0' : '';

        $total = (int) ($this->fetchOne(
            "SELECT COUNT(*) AS total FROM (SELECT c.id_category {$base}{$having}) AS counted",
            $params,
        )['total'] ?? 0);

        $sql = "SELECT {$this->buildSelect($columns)}
                {$base}{$having}
                ORDER BY c.position ASC, c.id_category ASC
                LIMIT {$limit} OFFSET {$offset}";

        $capped = ToolOutputEncoder::cap($this->fetchAll($sql, $params), self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'categories' => $capped['rows'],
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
     * Build the SELECT clause based on requested columns.
     *
     * @param  list<string>  $columns  Resolved column list.
     * @return string SQL select fragment.
     */
    private function buildSelect(array $columns): string
    {
        $map = [
            'id' => 'c.id_category AS id',
            'name' => 'cl.name',
            'description' => 'SUBSTRING(cl.description, 1, 200) AS description',
            'active' => 'c.active',
            'parent_id' => 'c.id_parent AS parent_id',
            'parent_name' => 'pcl.name AS parent_name',
            'depth' => 'c.level_depth AS depth',
            'position' => 'c.position',
            'product_count' => 'COUNT(cp.id_product) AS product_count',
            'date_add' => 'c.date_add',
            'date_upd' => 'c.date_upd',
        ];

        $parts = [];
        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'c.id_category AS id';
    }

    /**
     * Build the WHERE clause from input filters.
     *
     * @param  array<string, mixed>  $input  Tool input.
     * @param  list<mixed>  $params  Bind parameters (modified by reference).
     * @return string SQL WHERE clause including the WHERE keyword.
     */
    private function buildWhere(array $input, array &$params): string
    {
        $conditions = ['1=1'];

        if (isset($input['parent_id'])) {
            $conditions[] = 'c.id_parent = ?';
            $params[] = (int) $input['parent_id'];
        }

        if (isset($input['active'])) {
            $conditions[] = 'c.active = ?';
            $params[] = (int) $input['active'];
        }

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $conditions[] = 'cl.name LIKE ?';
            $params[] = '%'.trim((string) $input['search']).'%';
        }

        return 'WHERE '.implode(' AND ', $conditions);
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
            \PrestaShopLogger::addLog('phpClaw PsCategoryTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_category: database query failed.', previous: $e);
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
            \PrestaShopLogger::addLog('phpClaw PsCategoryTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_category: database query failed.', previous: $e);
        }
    }
}
