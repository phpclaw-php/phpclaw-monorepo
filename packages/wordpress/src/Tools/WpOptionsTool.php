<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Options tool - read-only access to the options table.
 */
final class WpOptionsTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    private const MAX_OFFSET = 10000;

    private const MAX_SEARCH_LENGTH = 255;

    private const MAX_OPTION_NAME_LENGTH = 191;

    private const MAX_OPTION_NAMES = 50;

    private const MAX_VALUE_LENGTH = 500;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'wordpress.options.read';

    private const RISK_LEVEL = 'read';

    private const BLOCKED_SUBSTRINGS = [
        'password',
        'passwd',
        'secret',
        'auth_key',
        'auth_salt',
        'nonce_key',
        'nonce_salt',
        'logged_in_key',
        'logged_in_salt',
        'secure_auth_key',
        'secure_auth_salt',
        'api_key',
        'private_key',
        'token',
        'credential',
    ];

    private const WITHHELD_KEYS = [
        'password', 'passwd', 'pwd', 'pass', 'secret', 'auth_key', 'auth_salt',
        'nonce_key', 'nonce_salt', 'logged_in_key', 'logged_in_salt',
        'secure_auth_key', 'secure_auth_salt', 'api_key', 'apikey', 'private_key',
        'token', 'credential', 'salt', 'passphrase', 'bearer',
    ];

    private const REDACTION = '***withheld***';

    private const BLOCKED_EXACT = [
        'phpclaw_settings',
        'mailserver_pass',
    ];

    private const ALLOWED_KEYS = [
        'schema',
        'aggregate',
        'option_name',
        'search',
        'autoload_only',
        'include_values',
        'limit',
        'offset',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what is this site called?',
            'arguments' => ['option_name' => 'blogname'],
        ],
        [
            'prompt' => 'how many settings does this site store?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'find the settings to do with the blog',
            'arguments' => ['search' => 'blog'],
        ],
    ];

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wp_option';
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
Read WordPress options. READ-ONLY. Requires the "phpclaw_use_chat" capability.

MODES
  schema=true     Discover parameters, limits and the blocked list. No query run.
  aggregate=true  Option counts and total size. No option rows returned.
  option_name     Exact lookup of one option, or several when given a list.
  search          Partial match across option names; page with meta.next_offset.

NEVER USE FOR
  Creating, updating or deleting options; reading credentials, API keys, salts,
  auth tokens or the phpClaw settings record. This tool cannot perform those
  operations and blocked names are refused, not silently omitted.

NOTES
  Option values are truncated at 500 characters.
  Call schema=true first if unsure which parameters exist.
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
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return parameter, limit and blocked-name metadata without querying options.',
                    'default' => false,
                ],

                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return option counts and total size. Search filters do not apply in this mode.',
                    'default' => false,
                ],

                'option_name' => [
                    'description' => 'Exact option name, or a list of names, retrieved via get_option().',
                    'oneOf' => [
                        [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => self::MAX_OPTION_NAME_LENGTH,
                        ],
                        [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'maxLength' => self::MAX_OPTION_NAME_LENGTH,
                            ],
                            'minItems' => 1,
                            'maxItems' => self::MAX_OPTION_NAMES,
                            'uniqueItems' => true,
                        ],
                    ],
                ],

                'search' => [
                    'type' => 'string',
                    'description' => 'Partial match against option names.',
                    'minLength' => 1,
                    'maxLength' => self::MAX_SEARCH_LENGTH,
                ],

                'include_values' => [
                    'type' => 'boolean',
                    'description' => 'true = also return each option\'s value in a listing or search. '
                        .'Values can hold plugin credentials, and one search can return many at once. '
                        .'A single named option always returns its value without this flag.',
                    'default' => false,
                ],
                'autoload_only' => [
                    'type' => 'boolean',
                    'description' => 'Restrict to options WordPress currently autoloads. Applies to search and aggregate.',
                    'default' => false,
                ],

                'limit' => [
                    'type' => 'integer',
                    'description' => 'Rows per page for search results.',
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
                        'The current user lacks the "%s" WordPress capability required to read options.',
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
                'payload' => $this->aggregateData($input),
            ];
        }

        if (isset($input['option_name'])) {
            return [
                'type' => 'lookup',
                'payload' => $this->lookupData($input),
            ];
        }

        return [
            'type' => 'query',
            'payload' => $this->searchData($input),
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
    protected function verify(array $execution, array $input): array
    {
        if ($execution['type'] === 'schema') {
            return ['result' => null];
        }

        if ($execution['type'] === 'aggregate') {
            if (! isset($execution['payload']['total_count'])) {
                throw new ToolException('WpOptionsTool returned an incomplete aggregate result.');
            }

            return ['result' => null];
        }

        if (
            ! isset($execution['payload']['options'])
            || ! is_array($execution['payload']['options'])
        ) {
            throw new ToolException('WpOptionsTool returned an incomplete option result.');
        }

        foreach ($execution['payload']['options'] as $option) {
            if ($this->isBlocked((string) ($option['option_name'] ?? ''))) {
                throw new ToolException('WpOptionsTool attempted to return a blocked option.');
            }
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

            foreach (['option_name', 'search', 'limit', 'offset'] as $argument) {
                if (array_key_exists($argument, $input)) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because aggregate mode counts all options on this site.',
                            $argument,
                        ),
                    ];
                }
            }

            return $this->encodeResult([
                'success' => true,
                'data' => [
                    'total_count' => $payload['total_count'],
                    'autoloaded_count' => $payload['autoloaded_count'],
                    'total_size_bytes' => $payload['total_size_bytes'],
                ],
                'meta' => [
                    'mode' => 'aggregate',
                    'autoload_filter' => $payload['autoload_filter'],
                ],
                'warnings' => $warnings,
            ]);
        }

        $warnings = [];

        if ($payload['blocked'] !== []) {
            $warnings[] = [
                'code' => 'BLOCKED_OPTION',
                'message' => sprintf(
                    '%d option(s) were withheld because their names are blocked: %s.',
                    count($payload['blocked']),
                    implode(', ', $payload['blocked']),
                ),
            ];
        }

        $warnings[] = [
            'code' => 'VALUE_SCRUBBING_IS_PARTIAL',
            'message' => 'Values are filtered by key name at every depth, and credentials embedded '
                .'in a URL are removed. That is a blocklist and it has two limits, both real: a '
                .'credential stored under a key nobody listed, such as one named foo or a vendor\'s '
                .'own word, is returned in full; and a scalar credential under an unremarkable '
                .'option name, such as myplugin_config holding a raw token, is returned in full. Do '
                .'not treat these values as scrubbed.',
        ];

        if ($execution['type'] === 'lookup') {
            return $this->encodeResult([
                'success' => true,
                'data' => [
                    'options' => $payload['options'],
                ],
                'meta' => [
                    'mode' => 'lookup',
                    'count' => count($payload['options']),
                    'requested' => $payload['requested'],
                ],
                'warnings' => $warnings,
            ]);
        }

        return $this->encodeResult([
            'success' => true,
            'data' => [
                'options' => $payload['options'],
            ],
            'meta' => [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['options']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'autoload_filter' => $payload['autoload_filter'],
            ],
            'warnings' => $warnings,
        ]);
    }

    /**
     * Look up one or more options by exact name.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Lookup result data.
     */
    private function lookupData(array $input): array
    {
        $names = is_array($input['option_name'])
            ? array_values($input['option_name'])
            : [$input['option_name']];

        $names = array_values(array_unique(array_map(
            static fn ($name): string => trim((string) $name),
            $names,
        )));

        if (function_exists('wp_prime_option_caches')) {
            wp_prime_option_caches($names);
        }

        $options = [];
        $blocked = [];

        foreach ($names as $name) {
            if ($this->isBlocked($name)) {
                $blocked[] = $name;

                continue;
            }

            $raw = get_option($name, false);
            $found = $raw !== false;

            $options[] = [
                'option_name' => $name,
                'value' => $this->stringifyValue($found ? $this->scrubValue($raw, $name) : ''),
                'found' => $found,
            ];
        }

        return [
            'options' => $options,
            'blocked' => $blocked,
            'requested' => count($names),
        ];
    }

    /**
     * Search option names and collect the matching page of rows.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Search result data.
     *
     * @throws ToolException When the options query fails.
     */
    private function searchData(array $input): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            throw new ToolException('WpOptionsTool: $wpdb is not available.');
        }

        $limit = isset($input['limit']) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($input['offset']) ? (int) $input['offset'] : 0;
        $autoloadOnly = ($input['autoload_only'] ?? false) === true;
        $includeValues = ($input['include_values'] ?? false) === true;
        $search = '%'.$wpdb->esc_like(trim((string) ($input['search'] ?? ''))).'%';

        $where = 'WHERE option_name LIKE %s';
        $args = [$search];

        if ($autoloadOnly) {
            $autoloadValues = $this->autoloadValues();
            $where .= ' AND autoload IN ('.implode(', ', array_fill(0, count($autoloadValues), '%s')).')';
            $args = [...$args, ...$autoloadValues];
        }

        $total = (int) $this->runQuery(
            static fn (): mixed => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} {$where}",
                    ...$args,
                ),
            ),
            'options count query failed',
        );

        $rows = $this->runQuery(
            static fn (): mixed => $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value, autoload FROM {$wpdb->options} {$where}
                     ORDER BY option_name ASC LIMIT %d OFFSET %d",
                    ...[...$args, $limit, $offset],
                ),
                ARRAY_A,
            ),
            'options search query failed',
        );

        $options = [];
        $blocked = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = (string) ($row['option_name'] ?? '');

            if ($this->isBlocked($name)) {
                $blocked[] = $name;

                continue;
            }

            $raw = $this->unserialiseStored((string) ($row['option_value'] ?? ''));
            $shape = $this->describeValue($raw);

            $entry = [
                'option_name' => $name,
                'value_type' => $shape['type'],
                'value_size' => $shape['size'],
                'autoload' => (string) ($row['autoload'] ?? 'no'),
            ];

            if ($includeValues) {
                $entry['value'] = $this->stringifyValue($this->scrubValue($raw, $name));
            }

            $options[] = $entry;
        }

        $scanned = is_array($rows) ? count($rows) : 0;
        $hasMore = ($offset + $scanned) < $total;

        return [
            'options' => $options,
            'blocked' => $blocked,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $scanned : null,
            'autoload_filter' => $autoloadOnly,
        ];
    }

    /**
     * Collect option counts and total stored size.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When an aggregate query fails.
     */
    private function aggregateData(array $input): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            throw new ToolException('WpOptionsTool: $wpdb is not available.');
        }

        $autoloadOnly = ($input['autoload_only'] ?? false) === true;
        $autoloadValues = $this->autoloadValues();
        $placeholders = implode(', ', array_fill(0, count($autoloadValues), '%s'));

        $totalRow = $this->runQuery(
            static fn (): mixed => $autoloadOnly
                ? $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT COUNT(*) AS total_count, SUM(LENGTH(option_value)) AS total_size
                         FROM %i WHERE autoload IN ({$placeholders})",
                        $wpdb->options,
                        ...$autoloadValues,
                    ),
                )
                : $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT COUNT(*) AS total_count, SUM(LENGTH(option_value)) AS total_size FROM %i',
                        $wpdb->options,
                    ),
                ),
            'aggregate query failed',
        );

        $autoloadRow = $this->runQuery(
            static fn (): mixed => $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS autoload_count FROM %i WHERE autoload IN ({$placeholders})",
                    $wpdb->options,
                    ...$autoloadValues,
                ),
            ),
            'autoload count query failed',
        );

        return [
            'total_count' => (int) ($totalRow->total_count ?? 0),
            'autoloaded_count' => (int) ($autoloadRow->autoload_count ?? 0),
            'total_size_bytes' => (int) ($totalRow->total_size ?? 0),
            'autoload_filter' => $autoloadOnly,
        ];
    }

    /**
     * Build parameter, limit and blocked-name metadata without querying options.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'parameters' => self::ALLOWED_KEYS,
            'examples' => self::EXAMPLES,
            'modes' => ['schema', 'aggregate', 'lookup', 'query'],
            'blocked_substrings' => self::BLOCKED_SUBSTRINGS,
            'blocked_exact' => self::BLOCKED_EXACT,
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
                'maximum_offset' => self::MAX_OFFSET,
                'maximum_search_length' => self::MAX_SEARCH_LENGTH,
                'maximum_option_names' => self::MAX_OPTION_NAMES,
                'maximum_value_length' => self::MAX_VALUE_LENGTH,
            ],
            'pagination' => [
                'type' => 'offset',
                'applies_to' => 'search',
            ],
            'wordpress_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
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
        foreach (array_keys($input) as $key) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                return $this->error(
                    'UNKNOWN_ARGUMENT',
                    sprintf('Unknown argument "%s".', (string) $key),
                    ['accepted_arguments' => self::ALLOWED_KEYS],
                );
            }
        }

        foreach (['schema', 'aggregate', 'autoload_only'] as $flag) {
            if (array_key_exists($flag, $input) && ! is_bool($input[$flag])) {
                return $this->error(
                    'INVALID_ARGUMENT',
                    sprintf('"%s" must be a boolean.', $flag),
                );
            }
        }

        if (($input['schema'] ?? false) === true && ($input['aggregate'] ?? false) === true) {
            return $this->error(
                'CONFLICTING_MODES',
                'Set only one of "schema" or "aggregate".',
            );
        }

        if (array_key_exists('option_name', $input) && array_key_exists('search', $input)) {
            return $this->error(
                'CONFLICTING_MODES',
                'Set only one of "option_name" or "search".',
            );
        }

        $nameError = $this->validateOptionNames($input);

        if ($nameError !== null) {
            return $nameError;
        }

        if (array_key_exists('search', $input)) {
            if (! is_string($input['search']) || trim($input['search']) === '') {
                return $this->error(
                    'INVALID_SEARCH',
                    '"search" must be a non-empty string.',
                );
            }

            if (mb_strlen($input['search']) > self::MAX_SEARCH_LENGTH) {
                return $this->error(
                    'SEARCH_TOO_LONG',
                    sprintf('"search" may not exceed %d characters.', self::MAX_SEARCH_LENGTH),
                );
            }
        }

        if (array_key_exists('limit', $input)) {
            if (
                ! is_int($input['limit'])
                || $input['limit'] < 1
                || $input['limit'] > self::MAX_LIMIT
            ) {
                return $this->error(
                    'INVALID_LIMIT',
                    sprintf('"limit" must be an integer between 1 and %d.', self::MAX_LIMIT),
                );
            }
        }

        if (array_key_exists('offset', $input)) {
            if (
                ! is_int($input['offset'])
                || $input['offset'] < 0
                || $input['offset'] > self::MAX_OFFSET
            ) {
                return $this->error(
                    'INVALID_OFFSET',
                    sprintf('"offset" must be an integer between 0 and %d.', self::MAX_OFFSET),
                );
            }
        }

        $isSchema = ($input['schema'] ?? false) === true;
        $isAggregate = ($input['aggregate'] ?? false) === true;

        if (
            ! $isSchema
            && ! $isAggregate
            && ! array_key_exists('option_name', $input)
            && ! array_key_exists('search', $input)
        ) {
            return $this->error(
                'INVALID_ARGUMENT',
                'Provide at least one of: schema, aggregate, option_name, or search.',
                ['accepted_arguments' => self::ALLOWED_KEYS],
            );
        }

        return null;
    }

    /**
     * Validate the option_name argument in both its string and list forms.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validateOptionNames(array $input): ?string
    {
        if (! array_key_exists('option_name', $input)) {
            return null;
        }

        $names = $input['option_name'];

        if (is_string($names)) {
            $names = [$names];
        }

        if (! is_array($names) || $names === []) {
            return $this->error(
                'INVALID_ARGUMENT',
                '"option_name" must be a non-empty string or a non-empty list of strings.',
            );
        }

        if (count($names) > self::MAX_OPTION_NAMES) {
            return $this->error(
                'INVALID_ARGUMENT',
                sprintf('"option_name" may not contain more than %d names.', self::MAX_OPTION_NAMES),
            );
        }

        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '') {
                return $this->error(
                    'INVALID_ARGUMENT',
                    'Every entry in "option_name" must be a non-empty string.',
                );
            }

            if (mb_strlen($name) > self::MAX_OPTION_NAME_LENGTH) {
                return $this->error(
                    'INVALID_ARGUMENT',
                    sprintf(
                        'Option name "%s" exceeds the %d character maximum.',
                        $name,
                        self::MAX_OPTION_NAME_LENGTH,
                    ),
                );
            }

            if ($this->isBlocked(trim($name))) {
                return $this->error(
                    'BLOCKED_OPTION',
                    sprintf('Option "%s" is blocked and cannot be read by this tool.', trim($name)),
                    ['blocked_exact' => self::BLOCKED_EXACT, 'blocked_substrings' => self::BLOCKED_SUBSTRINGS],
                );
            }
        }

        return null;
    }

    /**
     * Run a database closure and convert a driver-level failure into an exception.
     *
     * @param  callable():mixed  $query  The database call to run.
     * @param  string  $context  Message describing the failing query.
     * @return mixed The query result.
     *
     * @throws ToolException When the database reports an error.
     */
    private function runQuery(callable $query, string $context): mixed
    {
        $this->clearDatabaseError();

        try {
            $result = $query();
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException(
                sprintf('WpOptionsTool: %s', $context),
                previous: $e,
            );
        }

        $lastError = $this->lastDatabaseError();

        if ($lastError !== '') {
            $failure = new \RuntimeException($lastError);

            $this->logExecutionError($failure);

            throw new ToolException(
                sprintf('WpOptionsTool: %s', $context),
                previous: $failure,
            );
        }

        return $result;
    }

    /**
     * Clear any database error left over from an earlier statement.
     *
     * @return void
     */
    private function clearDatabaseError(): void
    {
        global $wpdb;

        if (isset($wpdb) && property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
    }

    /**
     * Read the error reported by the most recent database statement.
     *
     * @return string The driver error message, or an empty string when clean.
     */
    private function lastDatabaseError(): string
    {
        global $wpdb;

        return isset($wpdb) && property_exists($wpdb, 'last_error')
            ? (string) $wpdb->last_error
            : '';
    }

    /**
     * Return the autoload column values WordPress core currently treats as autoloaded.
     *
     * @return string[]
     */
    private function autoloadValues(): array
    {
        return function_exists('wp_autoload_values_to_autoload')
            ? wp_autoload_values_to_autoload()
            : ['yes', 'on'];
    }

    /**
     * Convert an option value of any type into a string for transport.
     *
     * @param  mixed  $value  The raw option value.
     * @return string The stringified, truncated value.
     */
    private function stringifyValue(mixed $value): string
    {
        $output = is_scalar($value)
            ? (string) $value
            : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return $this->truncate($output);
    }

    /**
     * Return a value with credential-shaped keys withheld at every depth.
     *
     * @param  mixed  $value  The raw option value.
     * @param  string  $optionName  The option's name, which is the only key a scalar has.
     * @return mixed The value with withheld keys replaced and URL credentials stripped.
     */
    private function scrubValue(mixed $value, string $optionName): mixed
    {
        if (! is_array($value)) {
            if ($this->isWithheldKey($optionName)) {
                return self::REDACTION;
            }

            return is_string($value) ? $this->stripUrlCredentials($value) : $value;
        }

        $out = [];

        foreach ($value as $key => $child) {
            if (is_string($key) && $this->isWithheldKey($key)) {
                $out[$key] = self::REDACTION;

                continue;
            }

            $out[$key] = $this->scrubValue($child, is_string($key) ? $key : $optionName);
        }

        return $out;
    }

    /**
     * Whether a key name marks a credential.
     *
     * @param  string  $key  Option name or array key.
     * @return bool
     */
    private function isWithheldKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::WITHHELD_KEYS as $withheld) {
            if (str_contains($lower, $withheld)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove an embedded username and password from a URL value. Deliberately narrow:
     * one shape, scheme://user:pass@host, which carried a value past a key-name filter.
     *
     * @param  string  $value  Option value.
     * @return string The value with any URL credentials removed.
     */
    private function stripUrlCredentials(string $value): string
    {
        return (string) preg_replace_callback(
            '#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s:@]+@#i',
            static fn (array $m): string => $m[1].self::REDACTION.'@',
            $value,
        );
    }

    /**
     * Turn a stored option value back into the shape WordPress would hand a caller, with
     * a class-refusing fallback so an absent maybe_unserialize() cannot degrade the scrub.
     *
     * @param  string  $stored  The raw option_value column.
     * @return mixed The value as WordPress would return it.
     */
    private function unserialiseStored(string $stored): mixed
    {
        if (function_exists('maybe_unserialize')) {
            return maybe_unserialize($stored);
        }

        if (preg_match('/^[aOs]:[0-9]+:/', $stored) !== 1 && $stored !== 'b:0;' && $stored !== 'b:1;') {
            return $stored;
        }

        $decoded = @unserialize($stored, ['allowed_classes' => false]);

        return $decoded === false && $stored !== 'b:0;' ? $stored : $decoded;
    }

    /**
     * Describe a value without returning it, for the listing and search paths, where one
     * call can disclose many options at once. Values come back only when the caller asks.
     *
     * @param  mixed  $value  The raw option value.
     * @return array{type: string, size: int} Shape and size of the value.
     */
    private function describeValue(mixed $value): array
    {
        $encoded = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return [
            'type' => is_array($value) ? 'array' : gettype($value),
            'size' => strlen($encoded),
        ];
    }

    /**
     * Truncate a value to the transport maximum.
     *
     * @param  string  $value  The value to truncate.
     * @return string The truncated value.
     */
    private function truncate(string $value): string
    {
        return strlen($value) > self::MAX_VALUE_LENGTH
            ? substr($value, 0, self::MAX_VALUE_LENGTH).'...[truncated]'
            : $value;
    }

    /**
     * Check whether the given option name is blocked by substring or exact match.
     *
     * @param  string  $optionName  The option name to check.
     * @return bool
     */
    private function isBlocked(string $optionName): bool
    {
        $lower = strtolower($optionName);

        foreach (self::BLOCKED_EXACT as $blocked) {
            if ($lower === strtolower($blocked)) {
                return true;
            }
        }

        foreach (self::BLOCKED_SUBSTRINGS as $substring) {
            if (str_contains($lower, $substring)) {
                return true;
            }
        }

        return false;
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
            domains: ['settings', 'configuration'],
            tags: ['option', 'options', 'setting', 'settings', 'config', 'configuration', 'siteurl', 'home', 'blogname', 'permalink', 'timezone', 'value'],
            intents: ['read option', 'show setting', 'what is the site url'],
            examples: ['what is the value of the siteurl option'],
        );
    }
}
