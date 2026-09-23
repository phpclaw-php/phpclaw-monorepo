<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Reviews tool: query and inspect product reviews with aggregate statistics.
 */
final class OcReviewTool extends AbstractOpenCartTool
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const AVAILABLE_COLUMNS = [
        'id', 'product_name', 'author', 'text', 'rating',
        'status', 'date_added', 'date_modified',
    ];

    private const DEFAULT_COLUMNS = ['id', 'product_name', 'author', 'rating', 'status', 'date_added'];

    protected const ERROR_LABEL = 'review';

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'oc_review';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
QUERY OpenCart product reviews: list reviews by product, rating, or status,
search by author or content, filter by date range, and get aggregate statistics.
BLOCKED: review moderation (approve/disable); use the admin panel.

AVAILABLE COLUMNS:
  id, product_name, author, text, rating, status, date_added, date_modified

DEFAULT COLUMNS: id, product_name, author, rating, status, date_added

CAPABILITIES:
  - Filter by product_id, rating (1-5), or status (approved/pending)
  - Search by author name or review text (partial, case-insensitive)
  - Date range filtering (date_after, date_before)
  - Request specific columns or get defaults
  - Aggregate mode: total reviews, avg_rating, by-rating counts, pending count

EXAMPLES:
  "All pending reviews" -> {"status": 0}
  "Reviews for product 42" -> {"product_id": 42}
  "5-star reviews" -> {"rating": 5}
  "Reviews this month" -> {"date_after": "2026-04-01"}
  "Search for 'broken'" -> {"search": "broken"}
  "Review statistics" -> {"mode": "aggregate"}
  "Show full text" -> {"columns": ["id", "product_name", "author", "text", "rating"]}

Invoke this tool; never guess review data.
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
                                   .'"aggregate" = return review summary only. '
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
                    'description' => 'Search term matched against author name or review text (partial, case-insensitive).',
                ],
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Filter reviews for a specific product ID.',
                ],
                'rating' => [
                    'type' => 'integer',
                    'description' => 'Filter by exact star rating (1-5).',
                    'minimum' => 1,
                    'maximum' => 5,
                ],
                'status' => [
                    'type' => 'integer',
                    'description' => '1 = approved only, 0 = pending only. Omit for all.',
                    'enum' => [0, 1],
                ],
                'date_after' => [
                    'type' => 'string',
                    'description' => 'Only reviews added on or after this date (YYYY-MM-DD).',
                ],
                'date_before' => [
                    'type' => 'string',
                    'description' => 'Only reviews added on or before this date (YYYY-MM-DD).',
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
     * Authorise the caller, normalise the mode, and clamp the limit for non-schema calls.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the database is unavailable for a non-schema call.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('search and inspect OpenCart product reviews');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $mode = (string) ($input['mode'] ?? 'list');
        $input['mode'] = $mode;

        if ($mode === 'schema') {
            return ['input' => $input, 'result' => null];
        }

        if ($this->db === null) {
            throw new ToolException('oc_review: no database connection available.');
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
        if ($execution['type'] === 'aggregate' && ! is_array($execution['payload']['stats'] ?? null)) {
            throw new ToolException('oc_review: aggregate result is incomplete.');
        }

        if ($execution['type'] === 'list' && ! is_array($execution['payload']['reviews'] ?? null)) {
            throw new ToolException('oc_review: query result is incomplete.');
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
            ['reviews' => $payload['reviews'], 'columns_returned' => $payload['columns_returned']],
            [
                'mode' => 'list',
                'total' => $payload['total'],
                'shown' => $payload['shown'],
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
            'filters' => ['search', 'product_id', 'rating', 'status', 'date_after', 'date_before'],
            'modes' => ['list', 'aggregate', 'schema'],
            'notes' => 'Review moderation (approve/disable) is BLOCKED. Use the admin panel.',
        ];
    }

    /**
     * Execute the aggregate statistics query and return the stats payload.
     *
     * @param  array<string, mixed>  $input  Tool input (filters still apply).
     * @return array<string, mixed>
     *
     * @throws ToolException If the query fails.
     */
    private function aggregatePayload(array $input): array
    {
        $p = $this->tablePrefix;
        $params = [];
        $where = $this->buildWhere($input, $params);

        $sql = "SELECT
                    COUNT(*) AS total_reviews,
                    ROUND(AVG(r.rating), 2) AS avg_rating,
                    SUM(CASE WHEN r.status = 0 THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN r.rating = 1 THEN 1 ELSE 0 END) AS rating_1,
                    SUM(CASE WHEN r.rating = 2 THEN 1 ELSE 0 END) AS rating_2,
                    SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) AS rating_3,
                    SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) AS rating_4,
                    SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) AS rating_5
                FROM `{$p}review` r
                LEFT JOIN `{$p}product_description` pd
                       ON pd.product_id = r.product_id AND pd.language_id = 1
                {$where}";

        $row = $this->fetchOne($sql, $params);

        return [
            'stats' => [
                'total_reviews' => (int) ($row['total_reviews'] ?? 0),
                'avg_rating' => (float) ($row['avg_rating'] ?? 0),
                'pending_count' => (int) ($row['pending_count'] ?? 0),
                'by_rating' => [
                    '1' => (int) ($row['rating_1'] ?? 0),
                    '2' => (int) ($row['rating_2'] ?? 0),
                    '3' => (int) ($row['rating_3'] ?? 0),
                    '4' => (int) ($row['rating_4'] ?? 0),
                    '5' => (int) ($row['rating_5'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Execute a filtered review list query with dynamic column selection and byte-budget truncation.
     *
     * @param  array<string, mixed>  $input  Tool input.
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
        $joins = '';

        if (in_array('product_name', $columns, true)) {
            $joins = "LEFT JOIN `{$p}product_description` pd
                             ON pd.product_id = r.product_id AND pd.language_id = 1";
        }

        $sql = "SELECT {$selectSql}
                FROM `{$p}review` r
                {$joins}
                {$where}
                ORDER BY r.date_added DESC LIMIT {$limit}";

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
            'reviews' => $kept,
            'total' => $total,
            'shown' => count($kept),
            'columns_returned' => $columns,
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
            'id' => 'r.review_id AS id',
            'product_name' => 'pd.name AS product_name',
            'author' => 'r.author',
            'text' => 'SUBSTRING(r.text, 1, 500) AS text',
            'rating' => 'r.rating',
            'status' => 'r.status',
            'date_added' => 'r.date_added',
            'date_modified' => 'r.date_modified',
        ];

        $parts = [];

        foreach ($columns as $col) {
            if (isset($map[$col])) {
                $parts[] = $map[$col];
            }
        }

        return implode(', ', $parts) ?: 'r.review_id AS id';
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

        if (isset($input['product_id'])) {
            $conditions[] = 'r.product_id = ?';
            $params[] = (int) $input['product_id'];
        }

        if (isset($input['status'])) {
            $conditions[] = 'r.status = ?';
            $params[] = (int) $input['status'];
        }

        if (isset($input['rating'])) {
            $conditions[] = 'r.rating = ?';
            $params[] = max(1, min(5, (int) $input['rating']));
        }

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $term = '%'.trim((string) $input['search']).'%';
            $conditions[] = '(r.author LIKE ? OR r.text LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($input['date_after']) && trim((string) $input['date_after']) !== '') {
            $conditions[] = 'r.date_added >= ?';
            $params[] = trim((string) $input['date_after']).' 00:00:00';
        }

        if (isset($input['date_before']) && trim((string) $input['date_before']) !== '') {
            $conditions[] = 'r.date_added <= ?';
            $params[] = trim((string) $input['date_before']).' 23:59:59';
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
            domains: ['commerce', 'reviews'],
            tags: ['review', 'reviews', 'rating', 'ratings', 'star', 'stars', 'feedback', 'testimonial', 'comment', 'approved', 'pending', 'author'],
            intents: ['list reviews', 'show ratings', 'what feedback did we get'],
            examples: ['show the product reviews and their ratings'],
        );
    }
}
