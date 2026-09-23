<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Categories tool: list, search, and aggregate product categories.
 */
final class OcCategoryTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    protected const DEFAULT_LIMIT = 50;

    protected const ERROR_LABEL = 'category';

    private const AVAILABLE_COLUMNS = [
        'id', 'name', 'description', 'parent_id', 'parent_name',
        'status', 'sort_order', 'product_count',
        'date_added', 'date_modified',
    ];

    private const DEFAULT_COLUMNS = ['id', 'name', 'status', 'parent_name', 'product_count'];

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_category';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY OpenCart product categories: browse hierarchy, filter by parent/status,
search by name, count products per category, and get aggregate statistics.

AVAILABLE COLUMNS:
  id, name, description, parent_id, parent_name, status, sort_order,
  product_count, date_added, date_modified

DEFAULT COLUMNS: id, name, status, parent_name, product_count

CAPABILITIES:
  - Search categories by name (partial match)
  - Filter by parent_id, status (enabled/disabled), or hide empty categories
  - Request specific columns or get defaults
  - Aggregate mode: total categories, active/inactive, empty count, max_depth

EXAMPLES:
  "List all enabled categories" -> {"status": 1}
  "Top-level categories" -> {"parent_id": 0}
  "Subcategories of 20" -> {"parent_id": 20}
  "Find empty categories" -> {"hide_empty": true}
  "Search for electronics" -> {"search": "electronics"}
  "Category overview" -> {"mode": "aggregate"}
  "Show id and name only" -> {"columns": ["id", "name"]}
  "All columns" -> {"columns": ["*"]}

Invoke this tool; never guess category data.
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
                    'description' => 'Search term matched against category name (partial, case-insensitive).',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by parent category ID (0 = top-level categories).',
                ],
                'status' => [
                    'type' => 'integer',
                    'description' => '1 = enabled only, 0 = disabled only. Omit for all.',
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
     * Authorise the caller, validate mode, and clamp the limit.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the database is unavailable for a non-schema call.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('search and view OpenCart product categories');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $mode = (string) ($input['mode'] ?? 'list');

        if ($mode !== 'schema' && $this->db === null) {
            throw new ToolException('oc_category: no database connection available.');
        }

        $input['limit'] = $this->clampLimit($input);

        return ['input' => $input, 'result' => null];
    }

    /**
     * Run the query, aggregate, or schema lookup selected by the validated input.
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

        if ($mode === 'aggregate') {
            return ['type' => 'aggregate', 'payload' => $this->aggregatePayload($input)];
        }

        return ['type' => 'list', 'payload' => $this->listPayload($input)];
    }

    /**
     * Assert the execution result is usable before it becomes the final envelope.
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
            throw new ToolException('oc_category: aggregate result is incomplete.');
        }

        if ($execution['type'] === 'list' && ! is_array($execution['payload']['categories'] ?? null)) {
            throw new ToolException('oc_category: list result is incomplete.');
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
            ['categories' => $payload['categories']],
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
            'filters' => ['search', 'parent_id', 'status', 'hide_empty'],
            'modes' => ['list', 'aggregate', 'schema'],
        ];
    }

    /**
     * Run aggregate statistics queries and return the stats payload.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the primary aggregate query fails.
     */
    private function aggregatePayload(array $input): array
    {
        $p = $this->tablePrefix;
        $params = [];
        $where = $this->buildWhere($input, $params);

        $sql = "SELECT
                    COUNT(*) AS total_categories,
                    SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN c.status = 0 THEN 1 ELSE 0 END) AS inactive_count
                FROM `{$p}category` c
                LEFT JOIN `{$p}category_description` cd
                       ON cd.category_id = c.category_id AND cd.language_id = 1
                {$where}";

        $row = $this->fetchOne($sql, $params);

        $emptyCount = 0;

        try {
            $emptyParams = $params;
            $emptySql = "SELECT COUNT(*) AS empty_count FROM (
                             SELECT c.category_id
                             FROM `{$p}category` c
                             LEFT JOIN `{$p}category_description` cd
                                    ON cd.category_id = c.category_id AND cd.language_id = 1
                             LEFT JOIN `{$p}product_to_category` ptc
                                    ON ptc.category_id = c.category_id
                             {$where}
                             GROUP BY c.category_id
                             HAVING COUNT(ptc.product_id) = 0
                         ) AS sub";
            $emptyRow = $this->db->query($emptySql, $emptyParams)->row;
            $emptyCount = (int) ($emptyRow['empty_count'] ?? 0);
        } catch (\Throwable) {
            $emptyCount = 0;
        }

        $maxDepth = 0;

        try {
            $recursiveSql = "WITH RECURSIVE cat_tree AS (
                              SELECT category_id, parent_id, 0 AS depth
                              FROM `{$p}category`
                              WHERE parent_id = 0
                              UNION ALL
                              SELECT c3.category_id, c3.parent_id, ct.depth + 1
                              FROM `{$p}category` c3
                              INNER JOIN cat_tree ct ON ct.category_id = c3.parent_id
                          )
                          SELECT MAX(depth) AS max_depth FROM cat_tree";
            $depthRow = $this->db->query($recursiveSql)->row;
            $maxDepth = (int) ($depthRow['max_depth'] ?? 0);
        } catch (\Throwable) {
            $maxDepth = 0;
        }

        return [
            'stats' => [
                'total_categories' => (int) ($row['total_categories'] ?? 0),
                'active_count' => (int) ($row['active_count'] ?? 0),
                'inactive_count' => (int) ($row['inactive_count'] ?? 0),
                'empty_categories' => $emptyCount,
                'max_depth' => $maxDepth,
            ],
        ];
    }

    /**
     * Execute a filtered category list query with dynamic column selection and byte-budget truncation.
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function listPayload(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);
        $columns = $this->resolveColumns($input);
        $params = [];
        $where = $this->buildWhere($input, $params);
        $selectSql = $this->buildSelect($columns);
        $needsProductCount = in_array('product_count', $columns, true);
        $needsParentName = in_array('parent_name', $columns, true);
        $hideEmpty = ! empty($input['hide_empty']);
        $joins = '';

        if ($needsParentName) {
            $joins .= " LEFT JOIN `{$p}category_description` pcd
                               ON pcd.category_id = c.parent_id AND pcd.language_id = 1";
        }

        if ($needsProductCount || $hideEmpty) {
            $joins .= " LEFT JOIN `{$p}product_to_category` ptc
                               ON ptc.category_id = c.category_id";
        }

        $sql = "SELECT {$selectSql}
                FROM `{$p}category` c
                LEFT JOIN `{$p}category_description` cd
                       ON cd.category_id = c.category_id AND cd.language_id = 1
                {$joins}
                {$where}
                GROUP BY c.category_id";

        if ($hideEmpty) {
            $sql .= ' HAVING COUNT(ptc.product_id) > 0';
        }

        $sql .= " ORDER BY c.parent_id ASC, c.sort_order ASC LIMIT {$limit}";

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
            'categories' => $kept,
            'columns_returned' => $columns,
            'total' => $total,
            'shown' => count($kept),
            'truncated' => count($kept) < $total,
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
            'id' => 'c.category_id AS id',
            'name' => 'cd.name',
            'description' => 'SUBSTRING(cd.description, 1, 200) AS description',
            'parent_id' => 'c.parent_id',
            'parent_name' => 'pcd.name AS parent_name',
            'status' => 'c.status',
            'sort_order' => 'c.sort_order',
            'product_count' => 'COUNT(ptc.product_id) AS product_count',
            'date_added' => 'c.date_added',
            'date_modified' => 'c.date_modified',
        ];

        $parts = [];

        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'c.category_id AS id';
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
            $conditions[] = 'c.parent_id = ?';
            $params[] = (int) $input['parent_id'];
        }

        if (isset($input['status'])) {
            $conditions[] = 'c.status = ?';
            $params[] = (int) $input['status'];
        }

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $conditions[] = 'cd.name LIKE ?';
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
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['commerce', 'catalog'],
            tags: ['category', 'categories', 'department', 'departments', 'collection', 'collections', 'section', 'parent', 'child', 'tree', 'path'],
            intents: ['list categories', 'show departments', 'what collections exist'],
            examples: ['list the product categories'],
        );
    }
}
