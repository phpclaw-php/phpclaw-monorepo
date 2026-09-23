<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

/**
 * Cloud subscription configuration: describes which hook events and guard features the active subscription has enabled, plus arbitrary cloud config.
 */
final class CloudManifest
{
    public const PLAN_INACTIVE = 'inactive';

    private const KEY_PLAN = 'plan';

    private const KEY_HOOK_EVENTS = 'hook_events';

    private const KEY_GUARD_FEATURES = 'guard_features';

    private const KEY_CONFIG = 'config';

    private const CONFIG_CUSTOM_EVENTS = 'custom_events';

    /**
     * Build a CloudManifest value object from already-resolved fields.
     *
     * @param  string  $plan  Subscription plan name (e.g. 'pro', 'inactive').
     * @param  array<string, list<string>>  $hookEvents  Feature → event name list mapping.
     * @param  array<string, array{priority:int}>  $guardFeatures  Feature → priority config mapping.
     * @param  array<string, mixed>  $config  Arbitrary cloud configuration values.
     * @return void
     */
    public function __construct(
        public readonly string $plan,
        public readonly array $hookEvents,
        public readonly array $guardFeatures,
        public readonly array $config,
    ) {}

    /**
     * Construct an empty or inactive manifest, used as the no-network fallback.
     *
     * @return self Manifest with plan=inactive and empty hook/guard/config arrays.
     */
    public static function empty(): self
    {
        return new self(
            plan: self::PLAN_INACTIVE,
            hookEvents: [],
            guardFeatures: [],
            config: [],
        );
    }

    /**
     * Construct an instance from a decoded JSON array (cloud manifest endpoint shape).
     *
     * @param  array<string, mixed>  $data  Decoded payload from GET /v1/manifest (or the on-disk cache).
     * @return self CloudManifest built from the data with defensive casts.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            plan: (string) ($data[self::KEY_PLAN] ?? self::PLAN_INACTIVE),
            hookEvents: (array) ($data[self::KEY_HOOK_EVENTS] ?? []),
            guardFeatures: (array) ($data[self::KEY_GUARD_FEATURES] ?? []),
            config: (array) ($data[self::KEY_CONFIG] ?? []),
        );
    }

    /**
     * Return the flat list of lifecycle events that should fire, after applying the caller-supplied disable list.
     *
     * @param  list<string>  $disable  Feature names to exclude from activation.
     * @return list<string> Flattened list of enabled event names.
     */
    public function activeHookEvents(array $disable = []): array
    {
        $events = [];

        foreach ($this->hookEvents as $feature => $featureEvents) {
            if (in_array($feature, $disable, strict: true)) {
                continue;
            }

            array_push($events, ...$featureEvents);
        }

        return $events;
    }

    /**
     * Return the guard-feature config map after applying the disable list.
     *
     * @param  list<string>  $disable  Feature names to exclude from activation.
     * @return array<string, array{priority: int}> Feature → priority config for guards that survived the disable filter.
     */
    public function activeGuardFeatures(array $disable = []): array
    {
        return array_filter(
            $this->guardFeatures,
            static fn (string $feature): bool => ! in_array($feature, $disable, strict: true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Look up a config value by key, returning $default when missing.
     *
     * @param  string  $key  Config key to look up.
     * @param  mixed  $default  Value returned when the key is not present.
     * @return mixed The config value, or $default when missing.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Whether the wildcard CloudWebhookHook listener should be installed.
     *
     * @return bool True when the manifest's config.custom_events flag is truthy.
     */
    public function customEventsEnabled(): bool
    {
        return (bool) ($this->config[self::CONFIG_CUSTOM_EVENTS] ?? false);
    }

    /**
     * Whether this is the empty / fallback manifest.
     *
     * @return bool True when plan equals PLAN_INACTIVE.
     */
    public function isInactive(): bool
    {
        return $this->plan === self::PLAN_INACTIVE;
    }

    /**
     * Snapshot the manifest as the wire-format array (mirror of fromArray()).
     *
     * @return array<string, mixed> Map of plan / hook_events / guard_features / config.
     */
    public function toArray(): array
    {
        return [
            self::KEY_PLAN => $this->plan,
            self::KEY_HOOK_EVENTS => $this->hookEvents,
            self::KEY_GUARD_FEATURES => $this->guardFeatures,
            self::KEY_CONFIG => $this->config,
        ];
    }
}
