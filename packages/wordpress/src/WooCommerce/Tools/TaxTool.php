<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Tax tool - read-only tax configuration and rates.
 */
final class TaxTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    private const MAX_OFFSET = 100000;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'woocommerce.tax.read';

    private const RISK_LEVEL = 'read';

    private const DEFAULT_COLUMNS = [
        'id', 'class', 'country', 'state', 'rate', 'name', 'priority',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'class', 'country', 'state', 'postcode_count', 'city_count',
        'rate', 'name', 'priority', 'compound', 'shipping', 'order',
    ];

    private const EXPENSIVE_COLUMNS = [
        'postcode_count', 'city_count',
    ];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'tax_class', 'country', 'limit', 'offset',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what tax rates do we charge in the UK?',
            'arguments' => ['country' => 'GB'],
        ],
        [
            'prompt' => 'how many tax rates do we have, and where?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'list our reduced rate tax rates',
            'arguments' => ['tax_class' => 'reduced-rate'],
        ],
    ];

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wc_tax';
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
Read WooCommerce tax configuration and rates. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true     Discover fields, classes and limits. No rate read.
  aggregate=true  Store tax settings, rate totals and coverage by country.
  default         Paginated rate list; page with meta.next_offset.

NEVER USE FOR
  Editing rates, classes or tax settings, or calculating tax for an order.

NOTES
  A store using ZIP-level rates can hold tens of thousands of rows, so rates are
  always paged and filters are applied in the query rather than afterwards.
  postcode_count and city_count each need an extra lookup and are excluded
  from ["*"].
  Rates are ordered by priority then id, so paging is stable.

EXAMPLES
  "what tax rates do we charge in the UK?"     -> {"country":"GB"}
  "how many tax rates do we have, and where?"  -> {"aggregate":true}
  "list our reduced rate tax rates"            -> {"tax_class":"reduced-rate"}
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
                    'items' => ['type' => 'string', 'enum' => [...self::AVAILABLE_COLUMNS, '*']],
                    'uniqueItems' => true,
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return field and class metadata without reading rates.',
                    'default' => false,
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return store tax settings and rate totals rather than rate rows.',
                    'default' => false,
                ],
                'tax_class' => [
                    'type' => 'string',
                    'description' => 'Restrict to one tax class. Use "standard" for the default class.',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],
                'country' => [
                    'type' => 'string',
                    'description' => 'Restrict to a two-letter country code.',
                    'minLength' => 2,
                    'maxLength' => 2,
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
        $forbidden = $this->guardCapability('read tax configuration');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned tax read without handling model policy.
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

        if (($input['aggregate'] ?? false) === true) {
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['rates'] ?? null)) {
            throw new ToolException('TaxTool returned an incomplete rate result.');
        }

        if ($execution['type'] === 'aggregate' && ! isset($execution['payload']['total_rates'])) {
            throw new ToolException('TaxTool returned an incomplete aggregate result.');
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
                            '"%s" was ignored because aggregate mode returns totals only.',
                            $argument,
                        ),
                    ];
                }
            }

            return $this->success($payload, ['mode' => 'aggregate'], $warnings);
        }

        return $this->success(
            ['rates' => $payload['rates']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['rates']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
                'tax_enabled' => $payload['tax_enabled'],
                'filters' => $payload['filters'],
            ],
        );
    }

    /**
     * Read one page of tax rates, with the filters and count applied in the query.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When a rate query fails.
     */
    private function queryData(array $input): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            throw new ToolException('TaxTool: $wpdb is not available.');
        }

        $columns = $this->resolveColumns($input['columns'] ?? []);
        $limit = isset($input['limit']) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($input['offset']) ? (int) $input['offset'] : 0;

        [$where, $args] = $this->filterClause($input);
        $table = $wpdb->prefix.'woocommerce_tax_rates';

        $total = (int) $this->runQuery(
            static fn (): mixed => $args === []
                ? $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}")
                : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", ...$args)),
            'tax rate count failed',
        );

        $rows = $this->runQuery(
            static fn (): mixed => $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY tax_rate_priority ASC, tax_rate_id ASC LIMIT %d OFFSET %d",
                ...[...$args, $limit, $offset],
            )),
            'tax rate query failed',
        );

        $rows = is_array($rows) ? $rows : [];
        $rates = $this->mapRates($rows, $columns);
        $hasMore = ($offset + count($rates)) < $total;

        return [
            'rates' => $rates,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($rates) : null,
            'columns' => $columns,
            'tax_enabled' => $this->taxEnabled(),
            'filters' => [
                'tax_class' => isset($input['tax_class'])
                    ? $this->normaliseTaxClass((string) $input['tax_class'])
                    : null,
                'country' => isset($input['country']) ? strtoupper((string) $input['country']) : null,
            ],
        ];
    }

    /**
     * Summarise tax configuration and rate coverage without listing rates.
     *
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When a count query fails.
     */
    private function aggregateData(): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            throw new ToolException('TaxTool: $wpdb is not available.');
        }

        $table = $wpdb->prefix.'woocommerce_tax_rates';
        $locations = $wpdb->prefix.'woocommerce_tax_rate_locations';

        $total = (int) $this->runQuery(
            static fn (): mixed => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'tax rate count failed',
        );

        $byCountry = $this->runQuery(
            static fn (): mixed => $wpdb->get_results(
                "SELECT tax_rate_country AS country, COUNT(*) AS rate_count
                 FROM {$table} GROUP BY tax_rate_country ORDER BY rate_count DESC, country ASC LIMIT 50",
            ),
            'tax coverage query failed',
        );

        $locationCount = (int) $this->runQuery(
            static fn (): mixed => $wpdb->get_var("SELECT COUNT(*) FROM {$locations}"),
            'tax location count failed',
        );

        $coverage = [];

        foreach ((array) $byCountry as $row) {
            $coverage[(string) ($row->country ?: '*')] = (int) ($row->rate_count ?? 0);
        }

        return [
            'tax_enabled' => $this->taxEnabled(),
            'prices_include_tax' => $this->option('woocommerce_prices_include_tax', 'no') === 'yes',
            'display_in_shop' => (string) $this->option('woocommerce_tax_display_shop', 'excl'),
            'tax_classes' => $this->taxClasses(),
            'total_rates' => $total,
            'total_rate_locations' => $locationCount,
            'rates_by_country' => $coverage,
        ];
    }

    /**
     * Build field and class metadata without reading rates.
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
            'sensitive_columns' => [],
            'blocked_columns' => [],
            'tax_classes' => $this->taxClasses(),
            'filters' => ['tax_class', 'country', 'limit', 'offset'],
            'modes' => ['schema', 'aggregate', 'query'],
            'pagination' => ['type' => 'offset', 'maximum_offset' => self::MAX_OFFSET],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
            ],
            'woocommerce_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
        ];
    }

    /**
     * Build the filter clause and its bindings for the rate query.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function filterClause(array $input): array
    {
        $clauses = [];
        $args = [];

        if (isset($input['tax_class'])) {
            $slug = $this->normaliseTaxClass((string) $input['tax_class']);
            $clauses[] = 'tax_rate_class = %s';
            $args[] = $slug === 'standard' ? '' : $slug;
        }

        if (isset($input['country'])) {
            $clauses[] = 'tax_rate_country = %s';
            $args[] = strtoupper((string) $input['country']);
        }

        return [$clauses === [] ? '' : 'WHERE '.implode(' AND ', $clauses), $args];
    }

    /**
     * Map rate rows to the requested fields, batching the location counts.
     *
     * @param  array<int, mixed>  $rows  Raw rate rows.
     * @param  array<int, string>  $columns  Resolved field list.
     * @return array<int, array<string, mixed>> Rate rows.
     *
     * @throws ToolException When the location lookup fails.
     */
    private function mapRates(array $rows, array $columns): array
    {
        $wantsLocations = array_intersect($columns, self::EXPENSIVE_COLUMNS) !== [];
        $postcodes = [];
        $cities = [];

        if ($wantsLocations && $rows !== []) {
            global $wpdb;

            $ids = array_map(static fn ($r): int => (int) ($r->tax_rate_id ?? 0), $rows);
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
            $locations = $wpdb->prefix.'woocommerce_tax_rate_locations';

            $counts = $this->runQuery(
                static fn (): mixed => $wpdb->get_results($wpdb->prepare(
                    "SELECT tax_rate_id, location_type, COUNT(*) AS c FROM {$locations}
                     WHERE tax_rate_id IN ({$placeholders}) GROUP BY tax_rate_id, location_type",
                    ...$ids,
                )),
                'tax location lookup failed',
            );

            foreach ((array) $counts as $row) {
                $id = (int) ($row->tax_rate_id ?? 0);

                if ((string) ($row->location_type ?? '') === 'city') {
                    $cities[$id] = (int) ($row->c ?? 0);

                    continue;
                }

                $postcodes[$id] = (int) ($row->c ?? 0);
            }
        }

        $rates = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $rates[] = $this->buildRow($row, $columns, $postcodes, $cities);
            }
        }

        return $rates;
    }

    /**
     * Map one rate row to the requested fields.
     *
     * @param  object  $row  Raw rate row.
     * @param  array<int, string>  $columns  Resolved field list.
     * @param  array<int, int>  $postcodes  Rate id to postcode count.
     * @param  array<int, int>  $cities  Rate id to city count.
     * @return array<string, mixed> Rate row.
     */
    private function buildRow(object $row, array $columns, array $postcodes, array $cities): array
    {
        $id = (int) ($row->tax_rate_id ?? 0);

        $getters = [
            'id' => static fn (): int => $id,
            'class' => static fn (): string => (string) ($row->tax_rate_class ?: 'standard'),
            'country' => static fn (): string => (string) ($row->tax_rate_country ?: '*'),
            'state' => static fn (): string => (string) ($row->tax_rate_state ?: '*'),
            'rate' => static fn (): string => (string) $row->tax_rate.'%',
            'name' => static fn (): string => (string) ($row->tax_rate_name ?? ''),
            'priority' => static fn (): int => (int) ($row->tax_rate_priority ?? 0),
            'compound' => static fn (): bool => (bool) ($row->tax_rate_compound ?? false),
            'shipping' => static fn (): bool => (bool) ($row->tax_rate_shipping ?? false),
            'order' => static fn (): int => (int) ($row->tax_rate_order ?? 0),
            'postcode_count' => static fn (): int => $postcodes[$id] ?? 0,
            'city_count' => static fn (): int => $cities[$id] ?? 0,
        ];

        $rate = [];

        foreach ($columns as $column) {
            if (isset($getters[$column])) {
                $rate[$column] = $getters[$column]();
            }
        }

        return $rate;
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
        global $wpdb;

        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }

        try {
            $result = $query();
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException(sprintf('TaxTool: %s', $context), previous: $e);
        }

        $lastError = property_exists($wpdb, 'last_error') ? (string) $wpdb->last_error : '';

        if ($lastError !== '') {
            $failure = new \RuntimeException($lastError);

            $this->logExecutionError($failure);

            throw new ToolException(sprintf('TaxTool: %s', $context), previous: $failure);
        }

        return $result;
    }

    /**
     * Return the store's tax class slugs, standard first.
     *
     * @return array<int, string>
     */
    private function taxClasses(): array
    {
        if (! class_exists('\WC_Tax')) {
            return ['standard'];
        }

        $slugs = is_callable(['\WC_Tax', 'get_tax_class_slugs'])
            ? (array) \WC_Tax::get_tax_class_slugs()
            : array_map(
                static fn ($class): string => sanitize_title((string) $class),
                (array) \WC_Tax::get_tax_classes(),
            );

        return array_values(array_merge(['standard'], array_map('strval', $slugs)));
    }

    /**
     * Normalise a tax class argument to the slug the rate table stores.
     *
     * @param  string  $class  Tax class as supplied by the model.
     * @return string The slug, or "standard" for the default class.
     */
    private function normaliseTaxClass(string $class): string
    {
        $class = trim($class);

        return strtolower($class) === 'standard' ? 'standard' : sanitize_title($class);
    }

    /**
     * Report whether tax calculation is switched on.
     *
     * @return bool
     */
    private function taxEnabled(): bool
    {
        return $this->option('woocommerce_calc_taxes', 'no') === 'yes';
    }

    /**
     * Read a WordPress option with a default.
     *
     * @param  string  $name  Option name.
     * @param  mixed  $default  Value when the option is absent.
     * @return mixed
     */
    private function option(string $name, mixed $default): mixed
    {
        return function_exists('get_option') ? get_option($name, $default) : $default;
    }

    /**
     * Resolve requested fields to a validated list.
     *
     * @param  mixed  $requested  Field names from validated input.
     * @return array<int, string> Resolved field list.
     */
    private function resolveColumns(mixed $requested): array
    {
        if (! is_array($requested) || $requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        if (in_array('*', $requested, true)) {
            return array_values(array_diff(self::AVAILABLE_COLUMNS, self::EXPENSIVE_COLUMNS));
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

        foreach (['schema', 'aggregate'] as $flag) {
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

        if (array_key_exists('country', $input)) {
            if (! is_string($input['country']) || preg_match('/^[A-Za-z]{2}$/', $input['country']) !== 1) {
                return $this->error(
                    'INVALID_COUNTRY',
                    '"country" must be a two-letter country code.',
                );
            }
        }

        if (array_key_exists('tax_class', $input)) {
            if (! is_string($input['tax_class']) || trim($input['tax_class']) === '') {
                return $this->error('INVALID_ARGUMENT', '"tax_class" must be a non-empty string.');
            }

            $known = $this->taxClasses();

            if (! in_array($this->normaliseTaxClass($input['tax_class']), $known, true)) {
                return $this->error(
                    'UNKNOWN_TAX_CLASS',
                    sprintf('Tax class "%s" is not registered on this store.', $input['tax_class']),
                    ['valid_tax_classes' => $this->taxClasses()],
                );
            }
        }

        return $this->validateColumns($input);
    }

    /**
     * Validate the columns argument against the available field list.
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
            return $this->error('INVALID_COLUMNS', '"columns" must be an array of field names.');
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
                    sprintf('Field "%s" is not available from this tool.', $column),
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
            domains: ['commerce', 'tax'],
            tags: ['tax', 'taxes', 'vat', 'gst', 'rate', 'rates', 'class', 'classes', 'taxable', 'exempt', 'country', 'state'],
            intents: ['list tax rates', 'show vat settings', 'how is tax configured'],
            examples: ['list the tax rates'],
        );
    }
}
