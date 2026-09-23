<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Users tool, read-only user access.
 */
final class WpUserTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    private const DEFAULT_ORDERBY = 'registered';

    private const DEFAULT_ORDER = 'DESC';

    private const MAX_SEARCH_LENGTH = 255;

    private const MAX_OFFSET = 10000;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'wordpress.users.read';

    private const RISK_LEVEL = 'read';

    private const BLOCKED_COLUMNS = [
        'user_pass',
        'user_activation_key',
        'user_email_verified',
        'session_tokens',
        'spam',
        'deleted',
    ];

    private const SENSITIVE_COLUMNS = [
        'user_email',
    ];

    private const DEFAULT_COLUMNS = [
        'id',
        'display_name',
        'user_login',
        'role',
        'user_registered',
    ];

    private const AVAILABLE_COLUMNS = [
        'id',
        'display_name',
        'user_login',
        'user_nicename',
        'user_email',
        'user_url',
        'user_registered',
        'user_status',
        'role',
        'last_login',
        'post_count',
    ];

    private const EXPENSIVE_COLUMNS = [
        'last_login',
        'post_count',
    ];

    private const VALID_ORDERBY = [
        'registered',
        'display_name',
        'login',
        'ID',
        'post_count',
    ];

    private const ALLOWED_KEYS = [
        'columns',
        'schema',
        'aggregate',
        'role',
        'search',
        'user_id',
        'orderby',
        'order',
        'limit',
        'offset',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'who are the newest people to sign up?',
            'arguments' => ['orderby' => 'registered', 'order' => 'DESC'],
        ],
        [
            'prompt' => 'how many users do we have, and in what roles?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'who are the editors on this site?',
            'arguments' => ['role' => 'editor'],
        ],
    ];

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wp_users';
    }

    /**
     * Return the phpClaw policy capability required to expose or execute this tool.
     *
     * @return string
     */
    public function capability(): string
    {
        return self::PHPCLAW_CAPABILITY;
    }

    /**
     * Return the WordPress capability the runtime user must hold.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the risk classification used by higher-level policy layers.
     *
     * @return string
     */
    public function risk(): string
    {
        return self::RISK_LEVEL;
    }

    /**
     * Whether repeated execution has the same effect. This tool is read-only.
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
Search and inspect WordPress users. READ-ONLY.

MODES
  schema=true     Discover fields, roles, limits and capabilities. No query run.
  aggregate=true  Total users and counts by role. No user rows returned.
  default         Paginated user query; page with meta.next_offset.

NEVER USE FOR
  Creating, updating or deleting users; resetting passwords; reading password
  hashes or session secrets; changing roles or permissions. This tool cannot
  perform those operations.

NOTES
  user_email is personal data. Request it only when the task requires it.
  last_login and post_count need an extra lookup and are excluded from ["*"];
  request them by name.
  Call schema=true first if unsure which fields or roles exist.

EXAMPLES
  "who are the newest people to sign up?"        -> {"orderby":"registered","order":"DESC"}
  "how many users do we have, and in what roles?" -> {"aggregate":true}
  "who are the editors on this site?"            -> {"role":"editor"}
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
                    'description' => 'Fields to return. Omit for defaults. ["*"] returns all inexpensive fields.',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            ...self::AVAILABLE_COLUMNS,
                            '*',
                        ],
                    ],
                    'uniqueItems' => true,
                ],

                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return field, role and limit metadata without querying users.',
                    'default' => false,
                ],

                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return total users and counts by role. Filters do not apply in this mode.',
                    'default' => false,
                ],

                'role' => [
                    'type' => 'string',
                    'description' => 'Filter by a role registered on this site. See schema=true for the list.',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],

                'search' => [
                    'type' => 'string',
                    'description' => 'Partial match across user_login and display_name.',
                    'minLength' => 1,
                    'maxLength' => self::MAX_SEARCH_LENGTH,
                ],

                'orderby' => [
                    'type' => 'string',
                    'description' => 'Sort field.',
                    'enum' => self::VALID_ORDERBY,
                    'default' => self::DEFAULT_ORDERBY,
                ],

                'order' => [
                    'type' => 'string',
                    'description' => 'Sort direction.',
                    'enum' => ['DESC', 'ASC'],
                    'default' => self::DEFAULT_ORDER,
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
        if (! $this->runningInConsole() && ! $this->callerHasCapability(self::REQUIRED_CAPABILITY)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'FORBIDDEN',
                    sprintf(
                        'The current user lacks the "%s" WordPress capability required to read users.',
                        self::REQUIRED_CAPABILITY,
                    ),
                ),
            ];
        }

        $validationError = $this->validate($input);

        if ($validationError !== null) {
            return [
                'input' => $input,
                'result' => $validationError,
            ];
        }

        return [
            'input' => $input,
            'result' => null,
        ];
    }

    /**
     * Execute the planned WordPress operation without handling model policy.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return [
                'type' => 'schema',
                'payload' => $this->schemaData(),
            ];
        }

        if (($input['aggregate'] ?? false) === true) {
            return [
                'type' => 'aggregate',
                'payload' => $this->aggregateData(),
            ];
        }

        return [
            'type' => 'query',
            'payload' => $this->queryData($input),
        ];
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
    protected function verify(
        array $execution,
        array $input
    ): array {
        if (! isset($execution['type'])) {
            throw new ToolException(
                'Tool execution did not return an operation type.'
            );
        }

        if (! array_key_exists('payload', $execution)) {
            throw new ToolException(
                'Tool execution did not return a payload.'
            );
        }

        if (! is_array($execution['payload'])) {
            throw new ToolException(
                'Tool execution payload has an invalid structure.'
            );
        }

        if (
            $execution['type'] === 'query' &&
            ! isset($execution['payload']['users']) ||
            (
                $execution['type'] === 'query' &&
                ! is_array($execution['payload']['users'])
            )
        ) {
            throw new ToolException(
                'WordPress user query returned an invalid result.'
            );
        }

        return [
            'result' => null,
        ];
    }

    /**
     * Complete a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(
        array $execution,
        array $input
    ): string {
        $payload = $execution['payload'];

        if ($execution['type'] === 'schema') {
            return $this->encodeResult([
                'success' => true,
                'data' => $payload,
                'meta' => [
                    'mode' => 'schema',
                    'database_query_performed' => false,
                ],
                'warnings' => [],
            ]);
        }

        if ($execution['type'] === 'aggregate') {
            $warnings = [];

            foreach (
                [
                    'role',
                    'search',
                    'limit',
                    'offset',
                    'columns',
                    'orderby',
                    'order',
                ] as $argument
            ) {
                if (
                    array_key_exists(
                        $argument,
                        $input
                    )
                ) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because aggregate mode counts all users on this site.',
                            $argument
                        ),
                    ];
                }
            }

            return $this->encodeResult([
                'success' => true,
                'data' => $payload,
                'meta' => [
                    'mode' => 'aggregate',
                ],
                'warnings' => $warnings,
            ]);
        }

        $columns = $payload['columns'];
        $warnings = [];

        $sensitive = array_values(
            array_intersect(
                $columns,
                self::SENSITIVE_COLUMNS
            )
        );

        if ($sensitive !== []) {
            $payload['meta']['sensitive_fields_returned'] = $sensitive;

            $warnings[] = [
                'code' => 'SENSITIVE_DATA',
                'message' => 'The response contains personal data. '
                    .'Use it only for the requested purpose and '
                    .'do not repeat it in public output.',
            ];
        }

        return $this->encodeResult([
            'success' => true,
            'data' => [
                'users' => $payload['users'],
            ],
            'meta' => $payload['meta'],
            'warnings' => $warnings,
        ]);
    }

    /**
     * Execute and collect the paginated WordPress user query.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When WP_User_Query fails.
     */
    private function queryData(
        array $input
    ): array {
        $limit = isset($input['limit'])
                ? (int) $input['limit']
                : self::DEFAULT_LIMIT;

        $offset = isset($input['offset'])
                ? (int) $input['offset']
                : 0;

        $orderby = isset($input['orderby'])
                ? (string) $input['orderby']
                : self::DEFAULT_ORDERBY;

        $order = isset($input['order'])
                ? strtoupper(
                    (string) $input['order']
                )
                : self::DEFAULT_ORDER;

        $args = [
            'number' => $limit,
            'offset' => $offset,
            'orderby' => [
                $orderby => $order,
                'ID' => $order,
            ],
            'count_total' => true,
        ];

        if (isset($input['user_id'])) {
            $args['include'] = [(int) $input['user_id']];
        }

        $role = isset($input['role'])
                ? sanitize_key(
                    trim(
                        (string) $input['role']
                    )
                )
                : '';

        if ($role !== '') {
            $args['role'] = $role;
        }

        $search = isset($input['search'])
                ? trim(
                    sanitize_text_field(
                        (string) $input['search']
                    )
                )
                : '';

        if ($search !== '') {
            $args['search'] = '*'.$search.'*';

            $args['search_columns'] = [
                'user_login',
                'display_name',
            ];
        }

        try {
            $query = new \WP_User_Query(
                $args
            );

            $results = $query->get_results();

            $total = (int) $query->get_total();
        } catch (\Throwable $e) {
            $this->logExecutionError(
                $e
            );

            throw new ToolException(
                'WP_User_Query failed.',
                previous: $e
            );
        }

        $columns = $this->resolveColumns(
            $input['columns'] ?? []
        );

        $users = $this->mapUsers(
            $results,
            $columns
        );

        $returned = count($users);

        $hasMore = ($offset + $returned) < $total;

        return [
            'users' => $users,

            'columns' => $columns,

            'meta' => [
                'mode' => 'query',

                'total' => $total,

                'count' => $returned,

                'limit' => $limit,

                'offset' => $offset,

                'has_more' => $hasMore,

                'next_offset' => $hasMore
                        ? $offset + $returned
                        : null,

                'columns_returned' => $columns,

                'filters' => $this->buildFilterMetadata(
                    $input
                ),
            ],
        ];
    }

    /**
     * Map user records using only allowlisted fields and batched lookups.
     *
     * @param  array<int, mixed>  $users  Query results.
     * @param  array<int, string>  $columns  Resolved fields.
     * @return array<int, array<string, mixed>> Mapped user rows.
     *
     * @throws ToolException When a batched lookup fails.
     */
    private function mapUsers(
        array $users,
        array $columns
    ): array {
        $users = array_values(
            array_filter(
                $users,
                static fn ($user): bool => $user instanceof \WP_User
            )
        );

        if ($users === []) {
            return [];
        }

        $columnSet = array_fill_keys(
            $columns,
            true
        );

        $ids = array_values(
            array_map(
                static fn (\WP_User $user): int => (int) $user->ID,
                $users
            )
        );

        $lastLogins = [];

        if (
            isset(
                $columnSet['last_login']
            )
        ) {
            update_meta_cache(
                'user',
                $ids
            );

            foreach (
                $ids as $id
            ) {
                $value = get_user_meta(
                    $id,
                    'last_login',
                    true
                );

                $lastLogins[$id] = is_scalar($value) &&
                    (string) $value !== ''
                        ? (string) $value
                        : 'unknown';
            }
        }

        $postCounts = isset(
            $columnSet['post_count']
        )
                ? $this->batchPostCounts(
                    $ids
                )
                : [];

        $rows = [];

        foreach (
            $users as $user
        ) {
            $id = (int) $user->ID;

            $row = [];

            if (
                isset(
                    $columnSet['id']
                )
            ) {
                $row['id'] = $id;
            }

            if (
                isset(
                    $columnSet['display_name']
                )
            ) {
                $row['display_name'] = (string)
                        $user->display_name;
            }

            if (
                isset(
                    $columnSet['user_login']
                )
            ) {
                $row['user_login'] = (string)
                        $user->user_login;
            }

            if (
                isset(
                    $columnSet['user_nicename']
                )
            ) {
                $row['user_nicename'] = (string)
                        $user->user_nicename;
            }

            if (
                isset(
                    $columnSet['user_email']
                )
            ) {
                $row['user_email'] = (string)
                        $user->user_email;
            }

            if (
                isset(
                    $columnSet['user_url']
                )
            ) {
                $row['user_url'] = (string)
                        $user->user_url;
            }

            if (
                isset(
                    $columnSet['user_registered']
                )
            ) {
                $row['user_registered'] = (string)
                        $user->user_registered;
            }

            if (
                isset(
                    $columnSet['user_status']
                )
            ) {
                $row['user_status'] = (int)
                        $user->user_status;
            }

            if (
                isset(
                    $columnSet['role']
                )
            ) {
                $row['role'] = implode(
                    ', ',
                    array_map(
                        'strval',
                        (array)
                            $user->roles
                    )
                );
            }

            if (
                isset(
                    $columnSet['last_login']
                )
            ) {
                $row['last_login'] = $lastLogins[$id]
                    ?? 'unknown';
            }

            if (
                isset(
                    $columnSet['post_count']
                )
            ) {
                $row['post_count'] = (int) (
                    $postCounts[$id]
                    ?? 0
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Fetch user post counts in one batched WordPress call.
     *
     * @param  array<int, int>  $ids  User IDs.
     * @return array<int, int> User ID to post count map.
     *
     * @throws ToolException When the WordPress lookup fails.
     */
    private function batchPostCounts(
        array $ids
    ): array {
        if ($ids === []) {
            return [];
        }

        try {
            $counts = count_many_users_posts(
                $ids,
                'post',
                false
            );
        } catch (\Throwable $e) {
            $this->logExecutionError(
                $e
            );

            throw new ToolException(
                'Unable to calculate user post counts.',
                previous: $e
            );
        }

        $result = array_fill_keys(
            $ids,
            0
        );

        foreach (
            (array) $counts as $userId => $count
        ) {
            $result[(int) $userId] = (int) $count;
        }

        return $result;
    }

    /**
     * Calculate aggregate user counts through WordPress's native API.
     *
     * @return array<string, mixed> Aggregate result.
     *
     * @throws ToolException When the aggregate cannot be calculated.
     */
    private function aggregateData(): array
    {
        try {
            $counts = count_users();
        } catch (\Throwable $e) {
            $this->logExecutionError(
                $e
            );

            throw new ToolException(
                'Unable to calculate WordPress user aggregates.',
                previous: $e
            );
        }

        if (
            ! is_array($counts) ||
            ! isset(
                $counts['total_users']
            )
        ) {
            throw new ToolException(
                'Unable to calculate WordPress user aggregates.'
            );
        }

        $registeredRoles = $this->registeredRoles();

        $roleCounts = [];

        foreach (
            (array) (
                $counts['avail_roles']
                ?? []
            ) as $slug => $count
        ) {
            if (
                ! is_string($slug) ||
                $slug === ''
            ) {
                continue;
            }

            $roleCounts[$slug] = [
                'label' => $registeredRoles[$slug]
                    ?? $slug,

                'count' => (int) $count,
            ];
        }

        ksort(
            $roleCounts
        );

        return [
            'total_users' => (int) $counts['total_users'],

            'by_role' => $roleCounts,
        ];
    }

    /**
     * Build field, role and limit metadata without querying user records.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'examples' => self::EXAMPLES,

            'default_columns' => self::DEFAULT_COLUMNS,

            'expensive_columns' => self::EXPENSIVE_COLUMNS,

            'blocked_columns' => self::BLOCKED_COLUMNS,

            'sensitive_columns' => self::SENSITIVE_COLUMNS,

            'column_descriptions' => [
                'id' => 'WordPress user ID.',

                'display_name' => 'Public display name.',

                'user_login' => 'Login username.',

                'user_nicename' => 'URL-friendly form of the username.',

                'user_email' => 'Email address. Sensitive personal data.',

                'user_url' => 'Website URL associated with the user.',

                'user_registered' => 'Registration timestamp.',

                'user_status' => 'Account status flag; 0 normally means active.',

                'role' => 'Assigned role or roles.',

                'last_login' => 'Last login from user meta, or "unknown". Extra batched lookup.',

                'post_count' => 'Posts attributed to the user. Extra batched lookup.',
            ],

            'filters' => [
                'role',
                'search',
                'user_id',
                'orderby',
                'order',
                'limit',
                'offset',
            ],

            'modes' => [
                'schema',
                'aggregate',
                'query',
            ],

            'pagination' => [
                'type' => 'offset',

                'maximum_offset' => self::MAX_OFFSET,
            ],

            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,

                'maximum_limit' => self::MAX_LIMIT,

                'maximum_search_length' => self::MAX_SEARCH_LENGTH,
            ],

            'registered_roles' => array_keys(
                $this->registeredRoles()
            ),

            'wordpress_capability' => self::REQUIRED_CAPABILITY,

            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,

            'risk' => self::RISK_LEVEL,

            'idempotent' => true,
        ];
    }

    /**
     * Resolve requested fields into a deterministic safe selection.
     *
     * @param  mixed  $requested  Requested columns.
     * @return array<int, string> Resolved columns.
     */
    private function resolveColumns(
        mixed $requested
    ): array {
        if (
            ! is_array($requested) ||
            $requested === []
        ) {
            return self::DEFAULT_COLUMNS;
        }

        if ($requested === ['*']) {
            return array_values(
                array_diff(
                    self::AVAILABLE_COLUMNS,
                    self::EXPENSIVE_COLUMNS
                )
            );
        }

        $resolved = [];

        foreach (
            $requested as $column
        ) {
            if (
                is_string($column) &&
                $column !== '*' &&
                ! in_array(
                    $column,
                    $resolved,
                    true
                )
            ) {
                $resolved[] = $column;
            }
        }

        if (
            ! in_array(
                'id',
                $resolved,
                true
            )
        ) {
            array_unshift(
                $resolved,
                'id'
            );
        }

        return $resolved;
    }

    /**
     * Return roles registered by the current WordPress installation.
     *
     * @return array<string, string> Role slug to label map.
     */
    private function registeredRoles(): array
    {
        if (
            ! function_exists(
                'wp_roles'
            )
        ) {
            return [];
        }

        $roles = wp_roles()->get_names();

        return is_array($roles)
            ? $roles
            : [];
    }

    /**
     * Build non-sensitive metadata describing applied filters.
     *
     * @param  array<string, mixed>  $input  Validated input.
     * @return array<string, mixed> Filter metadata.
     */
    private function buildFilterMetadata(
        array $input
    ): array {
        $filters = [];

        if (
            isset($input['role']) &&
            is_string($input['role']) &&
            trim($input['role']) !== ''
        ) {
            $filters['role'] = sanitize_key(
                trim($input['role'])
            );
        }

        if (
            isset($input['search']) &&
            is_string($input['search']) &&
            trim($input['search']) !== ''
        ) {
            $filters['search_applied'] = true;
        }

        return $filters;
    }

    /**
     * Validate runtime input against the tool contract.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return string|null Encoded error result or null when valid.
     */
    private function validate(
        array $input
    ): ?string {
        foreach (
            array_keys($input) as $key
        ) {
            if (
                ! is_string($key) ||
                ! in_array(
                    $key,
                    self::ALLOWED_KEYS,
                    true
                )
            ) {
                return $this->error(
                    'UNKNOWN_ARGUMENT',
                    sprintf(
                        'Unknown argument "%s".',
                        (string) $key
                    ),
                    [
                        'accepted_arguments' => self::ALLOWED_KEYS,
                    ]
                );
            }
        }

        foreach (
            ['schema', 'aggregate'] as $flag
        ) {
            if (
                isset($input[$flag]) &&
                ! is_bool(
                    $input[$flag]
                )
            ) {
                return $this->error(
                    'INVALID_ARGUMENT',
                    sprintf(
                        'The "%s" argument must be a boolean (true or false, not a string).',
                        $flag
                    )
                );
            }
        }

        if (
            array_key_exists('user_id', $input) &&
            (
                ! is_int($input['user_id']) ||
                $input['user_id'] < 1
            )
        ) {
            return $this->error(
                'INVALID_ARGUMENT',
                'The "user_id" argument must be a positive integer.'
            );
        }

        if (
            ($input['schema'] ?? false) === true &&
            ($input['aggregate'] ?? false) === true
        ) {
            return $this->error(
                'CONFLICTING_MODES',
                'Enable either "schema" or "aggregate", not both.'
            );
        }

        if (
            isset($input['columns'])
        ) {
            $columnError = $this->validateColumns(
                $input['columns']
            );

            if ($columnError !== null) {
                return $columnError;
            }
        }

        if (
            isset($input['role'])
        ) {
            if (
                ! is_string($input['role']) ||
                trim($input['role']) === ''
            ) {
                return $this->error(
                    'INVALID_ROLE',
                    'The "role" argument must be a non-empty string.'
                );
            }

            if (
                mb_strlen(
                    trim($input['role'])
                ) > 100
            ) {
                return $this->error(
                    'ROLE_TOO_LONG',
                    'The "role" argument cannot exceed 100 characters.'
                );
            }

            $role = sanitize_key(
                trim($input['role'])
            );

            $roles = $this->registeredRoles();

            if (
                ! isset(
                    $roles[$role]
                )
            ) {
                return $this->error(
                    'UNKNOWN_ROLE',
                    sprintf(
                        'Role "%s" is not registered on this WordPress site.',
                        trim($input['role'])
                    ),
                    [
                        'valid_roles' => array_keys($roles),
                    ]
                );
            }
        }

        if (
            isset($input['search'])
        ) {
            if (
                ! is_string($input['search']) ||
                trim($input['search']) === ''
            ) {
                return $this->error(
                    'INVALID_SEARCH',
                    'The "search" argument must be a non-empty string.'
                );
            }

            if (
                mb_strlen(
                    trim($input['search'])
                ) > self::MAX_SEARCH_LENGTH
            ) {
                return $this->error(
                    'SEARCH_TOO_LONG',
                    sprintf(
                        'The "search" argument cannot exceed %d characters.',
                        self::MAX_SEARCH_LENGTH
                    )
                );
            }
        }

        if (
            isset($input['orderby']) &&
            (
                ! is_string($input['orderby']) ||
                ! in_array(
                    $input['orderby'],
                    self::VALID_ORDERBY,
                    true
                )
            )
        ) {
            return $this->error(
                'INVALID_ORDERBY',
                'The "orderby" value is not supported.',
                [
                    'valid_orderby' => self::VALID_ORDERBY,
                ]
            );
        }

        if (
            isset($input['order']) &&
            (
                ! is_string($input['order']) ||
                ! in_array(
                    strtoupper(
                        $input['order']
                    ),
                    ['ASC', 'DESC'],
                    true
                )
            )
        ) {
            return $this->error(
                'INVALID_ORDER',
                'The "order" value must be ASC or DESC.'
            );
        }

        if (
            isset($input['limit']) &&
            (
                ! is_int($input['limit']) ||
                $input['limit'] < 1 ||
                $input['limit'] > self::MAX_LIMIT
            )
        ) {
            return $this->error(
                'INVALID_LIMIT',
                sprintf(
                    'The "limit" value must be an integer between 1 and %d.',
                    self::MAX_LIMIT
                )
            );
        }

        if (
            isset($input['offset']) &&
            (
                ! is_int($input['offset']) ||
                $input['offset'] < 0 ||
                $input['offset'] > self::MAX_OFFSET
            )
        ) {
            return $this->error(
                'INVALID_OFFSET',
                sprintf(
                    'The "offset" value must be an integer between 0 and %d.',
                    self::MAX_OFFSET
                )
            );
        }

        return null;
    }

    /**
     * Validate requested fields against the tool's security boundaries.
     *
     * @param  mixed  $columns  Requested columns.
     * @return string|null Encoded error result or null when valid.
     */
    private function validateColumns(
        mixed $columns
    ): ?string {
        if (
            ! is_array($columns)
        ) {
            return $this->error(
                'INVALID_COLUMNS',
                'The "columns" argument must be an array of strings.'
            );
        }

        if ($columns === []) {
            return null;
        }

        if (
            in_array(
                '*',
                $columns,
                true
            ) &&
            count($columns) > 1
        ) {
            return $this->error(
                'INVALID_COLUMNS',
                'Use ["*"] on its own, or list the fields you need explicitly.'
            );
        }

        foreach (
            $columns as $column
        ) {
            if (! is_string($column)) {
                return $this->error(
                    'INVALID_COLUMNS',
                    'Every requested column must be a string.'
                );
            }

            if ($column === '*') {
                continue;
            }

            if (
                in_array(
                    $column,
                    self::BLOCKED_COLUMNS,
                    true
                )
            ) {
                return $this->error(
                    'BLOCKED_COLUMN',
                    sprintf(
                        'Column "%s" contains credential or security data and can never be returned.',
                        $column
                    )
                );
            }

            if (
                ! in_array(
                    $column,
                    self::AVAILABLE_COLUMNS,
                    true
                )
            ) {
                return $this->error(
                    'UNKNOWN_COLUMN',
                    sprintf(
                        'Column "%s" is not available from this tool.',
                        $column
                    ),
                    [
                        'available_columns' => self::AVAILABLE_COLUMNS,
                    ]
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
            domains: ['users', 'accounts'],
            tags: ['user', 'users', 'account', 'accounts', 'member', 'members', 'author', 'authors', 'subscriber', 'editor', 'administrator', 'admin', 'role', 'roles', 'login', 'email', 'registered'],
            intents: ['list users', 'find account', 'who are the authors', 'show administrators'],
            examples: ['list the users and their roles'],
        );
    }
}
