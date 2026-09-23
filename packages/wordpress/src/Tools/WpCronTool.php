<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Cron tool - read-only access to scheduled events.
 */
final class WpCronTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    private const MAX_OFFSET = 10000;

    private const MAX_SEARCH_LENGTH = 255;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'wordpress.cron.read';

    private const RISK_LEVEL = 'read';

    private const AVAILABLE_COLUMNS = [
        'hook', 'next_run', 'schedule', 'interval', 'args', 'is_overdue',
    ];

    private const DEFAULT_COLUMNS = [
        'hook', 'next_run', 'schedule', 'is_overdue',
    ];

    private const ALLOWED_KEYS = [
        'columns',
        'schema',
        'aggregate',
        'hook',
        'overdue_only',
        'search',
        'limit',
        'offset',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what scheduled tasks are set up on this site?',
            'arguments' => [],
        ],
        [
            'prompt' => 'how many scheduled jobs are there?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'which scheduled tasks belong to WordPress core?',
            'arguments' => ['search' => 'wp_'],
        ],
    ];

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wp_cron';
    }

    /**
     * Return the phpClaw capability identifier this tool exercises.
     *
     * @return string
     */
    public function capability(): string
    {
        return self::PHPCLAW_CAPABILITY;
    }

    /**
     * Return the WordPress capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the risk classification for this tool.
     *
     * @return string
     */
    public function risk(): string
    {
        return self::RISK_LEVEL;
    }

    /**
     * Report whether repeated identical calls produce the same result.
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * Return worked example prompts for this tool.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
List WordPress scheduled cron events. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true     Discover columns, schedules and limits. No cron read.
  aggregate=true  Totals, overdue count, next event and schedule breakdown.
  default         Paginated event list; page with meta.next_offset.

NEVER USE FOR
  Scheduling, rescheduling, unscheduling or running cron events. This tool
  cannot perform those operations.

NOTES
  Columns: hook, next_run, schedule, interval, args, is_overdue. ["*"] returns all.
  Schedules: hourly, twicedaily, daily, weekly, one-time.
  next_run is UTC. Call schema=true if unsure which columns exist.
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
            'additionalProperties' => false,
            'properties' => [
                'columns' => [
                    'type' => 'array',
                    'description' => 'Columns to return. Omit for defaults. ["*"] returns all columns.',
                    'items' => [
                        'type' => 'string',
                        'enum' => [...self::AVAILABLE_COLUMNS, '*'],
                    ],
                    'uniqueItems' => true,
                ],

                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return column and schedule metadata without reading cron.',
                    'default' => false,
                ],

                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return counts and the next event. Row filters still apply.',
                    'default' => false,
                ],

                'hook' => [
                    'type' => 'string',
                    'description' => 'Filter by exact hook name.',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],

                'overdue_only' => [
                    'type' => 'boolean',
                    'description' => 'Return only events whose next_run is in the past.',
                    'default' => false,
                ],

                'search' => [
                    'type' => 'string',
                    'description' => 'Partial, case-insensitive match against hook names.',
                    'minLength' => 1,
                    'maxLength' => self::MAX_SEARCH_LENGTH,
                ],

                'limit' => [
                    'type' => 'integer',
                    'description' => 'Rows per page.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],

                'offset' => [
                    'type' => 'integer',
                    'description' => 'Rows to skip. Use meta.next_offset from the previous response.',
                    'minimum' => 0,
                    'maximum' => self::MAX_OFFSET,
                    'default' => 0,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Plan the execution by enforcing authorization, validating input, and determining the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read scheduled events');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned cron read without handling model policy.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        $events = $this->collectEvents($input);

        if (($input['aggregate'] ?? false) === true) {
            return ['type' => 'aggregate', 'payload' => $this->aggregateData($events)];
        }

        return ['type' => 'query', 'payload' => $this->queryData($events, $input)];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['events'] ?? null)) {
            throw new ToolException('WpCronTool returned an incomplete event result.');
        }

        if ($execution['type'] === 'aggregate' && ! isset($execution['payload']['total_events'])) {
            throw new ToolException('WpCronTool returned an incomplete aggregate result.');
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
            return $this->success($payload, [
                'mode' => 'schema',
                'database_query_performed' => false,
            ]);
        }

        if ($execution['type'] === 'aggregate') {
            $warnings = [];

            foreach (['columns', 'limit', 'offset'] as $argument) {
                if (array_key_exists($argument, $input)) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because aggregate mode returns counts only.',
                            $argument,
                        ),
                    ];
                }
            }

            return $this->success($payload, ['mode' => 'aggregate'], $warnings);
        }

        return $this->success(
            ['events' => $payload['events']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['events']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
            ],
        );
    }

    /**
     * Project and page the collected events.
     *
     * @param  array<int, array<string, mixed>>  $events  Filtered event rows.
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     */
    private function queryData(array $events, array $input): array
    {
        $limit = isset($input['limit']) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($input['offset']) ? (int) $input['offset'] : 0;
        $columns = $this->resolveColumns($input['columns'] ?? []);

        usort($events, static fn (array $a, array $b): int => strcmp($a['next_run'], $b['next_run']));

        $total = count($events);
        $page = array_slice($events, $offset, $limit);

        $projected = array_map(
            static fn (array $event): array => array_intersect_key($event, array_flip($columns)),
            $page,
        );

        $hasMore = ($offset + count($projected)) < $total;

        return [
            'events' => $projected,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($projected) : null,
            'columns' => $columns,
        ];
    }

    /**
     * Summarise the collected events.
     *
     * @param  array<int, array<string, mixed>>  $events  Filtered event rows.
     * @return array<string, mixed> Aggregate result data.
     */
    private function aggregateData(array $events): array
    {
        $overdueCount = 0;
        $nextEvent = null;
        $bySchedule = [];

        foreach ($events as $event) {
            if ($event['is_overdue']) {
                $overdueCount++;
            }

            $schedule = $event['schedule'];
            $bySchedule[$schedule] = ($bySchedule[$schedule] ?? 0) + 1;

            if ($event['is_overdue']) {
                continue;
            }

            if ($nextEvent === null || $event['next_run'] < $nextEvent['next_run']) {
                $nextEvent = [
                    'hook' => $event['hook'],
                    'next_run' => $event['next_run'],
                    'schedule' => $event['schedule'],
                ];
            }
        }

        arsort($bySchedule);

        return [
            'total_events' => count($events),
            'overdue_count' => $overdueCount,
            'next_event' => $nextEvent,
            'by_schedule' => $bySchedule,
        ];
    }

    /**
     * Build column and schedule metadata without reading cron.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'examples' => self::EXAMPLES,
            'default_columns' => self::DEFAULT_COLUMNS,
            'column_descriptions' => [
                'hook' => 'The action hook name.',
                'next_run' => 'Scheduled run time, UTC, as Y-m-d H:i:s.',
                'schedule' => 'Recurrence name, or "one-time" for a single-fire event.',
                'interval' => 'Seconds between recurrences; 0 for one-time.',
                'args' => 'Arguments passed to the hook callback.',
                'is_overdue' => 'True when next_run is in the past.',
            ],
            'schedule_names' => ['hourly', 'twicedaily', 'daily', 'weekly', 'one-time'],
            'filters' => ['hook', 'overdue_only', 'search', 'limit', 'offset'],
            'modes' => ['schema', 'aggregate', 'query'],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
                'maximum_offset' => self::MAX_OFFSET,
                'maximum_search_length' => self::MAX_SEARCH_LENGTH,
            ],
            'wordpress_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
        ];
    }

    /**
     * Collect cron events from WordPress, applying the hook, search and overdue filters.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<int, array<string, mixed>> Flat list of event rows with all columns.
     */
    private function collectEvents(array $input): array
    {
        $crons = _get_cron_array();

        if (! is_array($crons)) {
            return [];
        }

        $hookFilter = isset($input['hook']) ? trim((string) $input['hook']) : '';
        $searchFilter = isset($input['search']) ? strtolower(trim((string) $input['search'])) : '';
        $overdueOnly = ($input['overdue_only'] ?? false) === true;
        $now = time();
        $events = [];

        foreach ($crons as $timestamp => $hooks) {
            if (! is_array($hooks)) {
                continue;
            }

            foreach ($hooks as $hookName => $entries) {
                if (! $this->hookMatchesFilters((string) $hookName, $hookFilter, $searchFilter)) {
                    continue;
                }

                foreach ((array) $entries as $data) {
                    $row = $this->buildEventRow(
                        (string) $hookName,
                        (int) $timestamp,
                        (array) $data,
                        $now,
                        $overdueOnly,
                    );

                    if ($row !== null) {
                        $events[] = $row;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * Apply the optional hook-equality and case-insensitive search filters.
     *
     * @param  string  $hookName  The event's hook name.
     * @param  string  $hookFilter  Empty for no filter, otherwise must equal $hookName exactly.
     * @param  string  $searchFilter  Empty for no filter, otherwise a lowercased substring match.
     * @return bool
     */
    private function hookMatchesFilters(string $hookName, string $hookFilter, string $searchFilter): bool
    {
        if ($hookFilter !== '' && $hookName !== $hookFilter) {
            return false;
        }

        if ($searchFilter !== '' && ! str_contains(strtolower($hookName), $searchFilter)) {
            return false;
        }

        return true;
    }

    /**
     * Shape one cron-event row, applying the overdue-only filter.
     *
     * @param  string  $hookName  Hook the event fires on.
     * @param  int  $timestamp  Scheduled run timestamp, Unix.
     * @param  array<string, mixed>  $data  Raw entry from _get_cron_array().
     * @param  int  $now  Current timestamp, Unix.
     * @param  bool  $overdueOnly  When true, drop events whose timestamp is in the future.
     * @return array<string, mixed>|null Row data, or null when filtered out.
     */
    private function buildEventRow(
        string $hookName,
        int $timestamp,
        array $data,
        int $now,
        bool $overdueOnly,
    ): ?array {
        $isOverdue = $timestamp < $now;

        if ($overdueOnly && ! $isOverdue) {
            return null;
        }

        $scheduleName = $data['schedule'] ?? false;

        return [
            'hook' => $hookName,
            'next_run' => gmdate('Y-m-d H:i:s', $timestamp),
            'schedule' => $scheduleName !== false && $scheduleName !== '' ? (string) $scheduleName : 'one-time',
            'interval' => (int) ($data['interval'] ?? 0),
            'args' => $data['args'] ?? [],
            'is_overdue' => $isOverdue,
        ];
    }

    /**
     * Resolve requested columns to a validated list.
     *
     * @param  mixed  $requested  Column names from validated input.
     * @return array<int, string> Validated column list.
     */
    private function resolveColumns(mixed $requested): array
    {
        if (! is_array($requested) || $requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        if (in_array('*', $requested, true)) {
            return self::AVAILABLE_COLUMNS;
        }

        return array_values(array_unique(array_map('strval', $requested)));
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

        foreach (['schema', 'aggregate', 'overdue_only'] as $flag) {
            if (array_key_exists($flag, $input) && ! is_bool($input[$flag])) {
                return $this->error('INVALID_ARGUMENT', sprintf('"%s" must be a boolean.', $flag));
            }
        }

        if (($input['schema'] ?? false) === true && ($input['aggregate'] ?? false) === true) {
            return $this->error('CONFLICTING_MODES', 'Set only one of "schema" or "aggregate".');
        }

        $paging = $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);

        if ($paging !== null) {
            return $paging;
        }

        foreach (['hook', 'search'] as $text) {
            if (! array_key_exists($text, $input)) {
                continue;
            }

            if (! is_string($input[$text]) || trim($input[$text]) === '') {
                return $this->error(
                    $text === 'search' ? 'INVALID_SEARCH' : 'INVALID_ARGUMENT',
                    sprintf('"%s" must be a non-empty string.', $text),
                );
            }

            if (mb_strlen($input[$text]) > self::MAX_SEARCH_LENGTH) {
                return $this->error(
                    $text === 'search' ? 'SEARCH_TOO_LONG' : 'INVALID_ARGUMENT',
                    sprintf('"%s" may not exceed %d characters.', $text, self::MAX_SEARCH_LENGTH),
                );
            }
        }

        return $this->validateColumns($input);
    }

    /**
     * Validate the columns argument against the available column list.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validateColumns(array $input): ?string
    {
        if (! array_key_exists('columns', $input)) {
            return null;
        }

        if (! is_array($input['columns'])) {
            return $this->error('INVALID_COLUMNS', '"columns" must be an array of column names.');
        }

        foreach ($input['columns'] as $column) {
            if (! is_string($column)) {
                return $this->error('INVALID_COLUMNS', 'Every entry in "columns" must be a string.');
            }

            if ($column === '*') {
                continue;
            }

            if (! in_array($column, self::AVAILABLE_COLUMNS, true)) {
                return $this->error(
                    'UNKNOWN_COLUMN',
                    sprintf('Column "%s" is not available from this tool.', $column),
                    ['available_columns' => self::AVAILABLE_COLUMNS],
                );
            }
        }

        return null;
    }

    /**
     * Whether this tool may be offered to the model. WordPress evaluates the caller's capability when the tool runs, so every tool stays eligible for routing.
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
            domains: ['scheduling', 'system'],
            tags: ['cron', 'schedule', 'scheduled', 'event', 'events', 'job', 'jobs', 'task', 'tasks', 'hourly', 'daily', 'recurrence', 'wpcron'],
            intents: ['list cron events', 'show scheduled jobs', 'what runs daily'],
            examples: ['list the scheduled cron events'],
        );
    }
}
