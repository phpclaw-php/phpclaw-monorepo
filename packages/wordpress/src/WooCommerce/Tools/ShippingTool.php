<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Shipping tool - read-only shipping zone and method configuration.
 */
final class ShippingTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'woocommerce.shipping.read';

    private const RISK_LEVEL = 'read';

    private const MAX_LOCATIONS_PER_ZONE = 200;

    private const METHOD_SETTINGS_ALLOWLIST = [
        'flat_rate' => ['title', 'tax_status', 'cost'],
        'free_shipping' => ['title', 'requires', 'min_amount', 'ignore_discounts'],
        'local_pickup' => ['title', 'tax_status', 'cost'],
    ];

    private const CREDENTIAL_KEY_PATTERNS = [
        'key', 'secret', 'token', 'password', 'passwd', 'account',
        'auth', 'credential', 'api', 'licence', 'license', 'signature',
    ];

    private const ALLOWED_KEYS = [
        'zone_id', 'schema', 'include_settings',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what shipping zones do we have?',
            'arguments' => [],
        ],
        [
            'prompt' => 'what shipping information can you look up?',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'what does shipping cost in zone 1?',
            'arguments' => ['zone_id' => 1, 'include_settings' => true],
        ],
    ];

    private $zoneFetcher;

    /**
     * Bind the shipping zone fetcher, defaulting to WC_Shipping_Zones plus rest-of-world.
     *
     * @param  callable(): array<int, array<string, mixed>>|null  $zoneFetcher  Overrides WC_Shipping_Zones for testing.
     */
    public function __construct(?callable $zoneFetcher = null)
    {
        $this->zoneFetcher = $zoneFetcher ?? static function (): array {
            $zones = \WC_Shipping_Zones::get_zones();
            $restOfWorld = new \WC_Shipping_Zone(0);

            return array_merge([[
                'id' => 0,
                'zone_name' => $restOfWorld->get_zone_name(),
                'zone_locations' => [],
                'shipping_methods' => $restOfWorld->get_shipping_methods(),
            ]], array_values($zones));
        };
    }

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wc_shipping';
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
Read WooCommerce shipping zones and methods. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true   Discover fields, allowlisted settings keys and limits.
  default       List shipping zones with their locations and methods.

NEVER USE FOR
  Editing zones, adding or configuring shipping methods, or reading carrier
  credentials. Carrier integrations store API keys, account numbers and auth
  tokens in method settings; those are never returned.

SETTINGS
  include_settings adds a small allowlist of configuration values per method
  type: flat_rate and local_pickup give title, tax_status and cost; free_shipping
  gives title, requires, min_amount and ignore_discounts. Any other method type
  returns id, title and enabled with no settings, rather than a guess.

NOTES
  Zone id 0 is the "rest of the world" fallback zone.
  Zone locations are store configuration, not customer data.
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
                'zone_id' => [
                    'type' => 'integer',
                    'description' => 'Return only this zone. 0 is the rest-of-the-world fallback zone.',
                    'minimum' => 0,
                ],
                'include_settings' => [
                    'type' => 'boolean',
                    'description' => 'Include the allowlisted configuration values for each method.',
                    'default' => false,
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return field and allowlist metadata without reading zones.',
                    'default' => false,
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
        $forbidden = $this->guardCapability('read shipping configuration');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned shipping read without handling model policy.
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

        return ['type' => 'query', 'payload' => $this->zonesData($input)];
    }

    /**
     * Verify the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete or unsafe.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['type'] !== 'query') {
            return ['result' => null];
        }

        if (! is_array($execution['payload']['zones'] ?? null)) {
            throw new ToolException('ShippingTool returned an incomplete zone result.');
        }

        foreach ($execution['payload']['zones'] as $zone) {
            foreach ((array) ($zone['methods'] ?? []) as $method) {
                $allowed = self::METHOD_SETTINGS_ALLOWLIST[$method['method_id'] ?? ''] ?? [];

                foreach (array_keys((array) ($method['settings'] ?? [])) as $key) {
                    if (! in_array($key, $allowed, true)) {
                        throw new ToolException('ShippingTool attempted to return an unlisted settings key.');
                    }

                    if ($this->isCredentialShaped((string) $key)) {
                        throw new ToolException('ShippingTool attempted to return a credential-shaped settings key.');
                    }
                }
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
            return $this->success($payload, [
                'mode' => 'schema',
                'database_query_performed' => false,
            ]);
        }

        $warnings = [];

        if ($payload['unclassified_methods'] !== []) {
            $warnings[] = [
                'code' => 'SETTINGS_OMITTED',
                'message' => sprintf(
                    'Settings were omitted for %s because these method types are not in the '
                    .'allowlist. Third-party carrier methods can hold credentials, so their '
                    .'settings are never returned.',
                    implode(', ', $payload['unclassified_methods']),
                ),
            ];
        }

        return $this->success(
            ['zones' => $payload['zones']],
            [
                'mode' => 'query',
                'count' => count($payload['zones']),
                'total' => $payload['total'],
                'settings_included' => $payload['settings_included'],
                'allowlisted_method_types' => array_keys(self::METHOD_SETTINGS_ALLOWLIST),
                'unclassified_methods' => $payload['unclassified_methods'],
            ],
            $warnings,
        );
    }

    /**
     * Collect shipping zones with their locations and methods.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Zone result data.
     *
     * @throws ToolException When the zone read fails.
     */
    private function zonesData(array $input): array
    {
        try {
            $zones = ($this->zoneFetcher)();
        } catch (\Throwable $e) {
            $this->logExecutionError($e);

            throw new ToolException('WC shipping zone read failed', previous: $e);
        }

        $zones = is_array($zones) ? $zones : [];
        $wantedId = array_key_exists('zone_id', $input) ? (int) $input['zone_id'] : null;
        $includeSettings = ($input['include_settings'] ?? false) === true;

        $rows = [];
        $unclassified = [];

        foreach ($zones as $zone) {
            $id = (int) ($zone['id'] ?? $zone['zone_id'] ?? 0);

            if ($wantedId !== null && $id !== $wantedId) {
                continue;
            }

            $methods = [];

            foreach ((array) ($zone['shipping_methods'] ?? []) as $method) {
                $built = $this->buildMethod($method, $includeSettings);

                if ($built['settings_omitted'] === true) {
                    $unclassified[] = $built['method_id'];
                }

                unset($built['settings_omitted']);
                $methods[] = $built;
            }

            $rows[] = [
                'id' => $id,
                'name' => (string) ($zone['zone_name'] ?? ''),
                'locations' => $this->buildLocations($zone),
                'methods' => $methods,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [
            'zones' => $rows,
            'total' => count($rows),
            'settings_included' => $includeSettings,
            'unclassified_methods' => array_values(array_unique($unclassified)),
        ];
    }

    /**
     * Map one shipping method, building settings outward from the allowlist.
     *
     * @param  mixed  $method  A WC_Shipping_Method or compatible object.
     * @param  bool  $includeSettings  Whether allowlisted settings were requested.
     * @return array<string, mixed> Method row.
     */
    private function buildMethod(mixed $method, bool $includeSettings): array
    {
        $methodId = (string) ($method->id ?? '');

        $row = [
            'method_id' => $methodId,
            'instance_id' => (int) ($method->instance_id ?? 0),
            'title' => is_callable([$method, 'get_title']) ? (string) $method->get_title() : '',
            'enabled' => is_callable([$method, 'is_enabled']) ? (bool) $method->is_enabled() : false,
            'settings_omitted' => false,
        ];

        if (! $includeSettings) {
            return $row;
        }

        if (! array_key_exists($methodId, self::METHOD_SETTINGS_ALLOWLIST)) {
            $row['settings_omitted'] = true;

            return $row;
        }

        $settings = [];

        foreach (self::METHOD_SETTINGS_ALLOWLIST[$methodId] as $key) {
            if ($this->isCredentialShaped($key)) {
                continue;
            }

            $value = is_callable([$method, 'get_option']) ? $method->get_option($key, null) : null;

            if ($value === null) {
                continue;
            }

            $settings[$key] = is_scalar($value) ? (string) $value : '';
        }

        $row['settings'] = $settings;

        return $row;
    }

    /**
     * Map a zone's locations, which are store configuration rather than customer data.
     *
     * @param  array<string, mixed>  $zone  Raw zone data.
     * @return array<int, array<string, string>> Location rows.
     */
    private function buildLocations(array $zone): array
    {
        $locations = [];

        foreach ((array) ($zone['zone_locations'] ?? []) as $location) {
            if (count($locations) >= self::MAX_LOCATIONS_PER_ZONE) {
                break;
            }

            $locations[] = [
                'type' => (string) ($location->type ?? ''),
                'code' => (string) ($location->code ?? ''),
            ];
        }

        return $locations;
    }

    /**
     * Report whether a settings key looks like it holds a credential.
     *
     * @param  string  $key  Settings key.
     * @return bool
     */
    private function isCredentialShaped(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::CREDENTIAL_KEY_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build field and allowlist metadata without reading zones.
     *
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(): array
    {
        return [
            'examples' => self::EXAMPLES,
            'zone_fields' => ['id', 'name', 'locations', 'methods'],
            'method_fields' => ['method_id', 'instance_id', 'title', 'enabled', 'settings'],
            'location_fields' => ['type', 'code'],
            'allowlisted_settings' => self::METHOD_SETTINGS_ALLOWLIST,
            'credential_key_patterns' => self::CREDENTIAL_KEY_PATTERNS,
            'sensitive_columns' => [],
            'modes' => ['schema', 'query'],
            'filters' => ['zone_id', 'include_settings'],
            'limits' => ['maximum_locations_per_zone' => self::MAX_LOCATIONS_PER_ZONE],
            'woocommerce_capability' => self::REQUIRED_CAPABILITY,
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
        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return $unknown;
        }

        foreach (['schema', 'include_settings'] as $flag) {
            if (array_key_exists($flag, $input) && ! is_bool($input[$flag])) {
                return $this->error('INVALID_ARGUMENT', sprintf('"%s" must be a boolean.', $flag));
            }
        }

        if (array_key_exists('zone_id', $input)
            && (! is_int($input['zone_id']) || $input['zone_id'] < 0)) {
            return $this->error('INVALID_ARGUMENT', '"zone_id" must be an integer of 0 or more.');
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
            domains: ['commerce', 'shipping'],
            tags: ['shipping', 'delivery', 'zone', 'zones', 'method', 'methods', 'rate', 'rates', 'carrier', 'postage', 'region', 'flat'],
            intents: ['list shipping zones', 'show delivery methods', 'what are the shipping rates'],
            examples: ['list the shipping zones and their methods'],
        );
    }
}
