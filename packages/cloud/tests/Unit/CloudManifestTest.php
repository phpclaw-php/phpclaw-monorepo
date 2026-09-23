<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudManifest;
use PHPUnit\Framework\TestCase;

final class CloudManifestTest extends TestCase
{
    public function test_from_array_populates_all_fields(): void
    {
        $manifest = CloudManifest::fromArray([
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'agent.after']],
            'guard_features' => ['guard_a' => ['priority' => 30]],
            'config' => ['max_events' => 5000],
        ]);

        $this->assertSame('active', $manifest->plan);
        $this->assertSame(['hook_a' => ['agent.before', 'agent.after']], $manifest->hookEvents);
        $this->assertSame(['guard_a' => ['priority' => 30]], $manifest->guardFeatures);
        $this->assertSame(['max_events' => 5000], $manifest->config);
    }

    public function test_from_array_uses_defaults_for_missing_keys(): void
    {
        $manifest = CloudManifest::fromArray([]);

        $this->assertSame('inactive', $manifest->plan);
        $this->assertSame([], $manifest->hookEvents);
        $this->assertSame([], $manifest->guardFeatures);
        $this->assertSame([], $manifest->config);
    }

    public function test_from_array_casts_plan_to_string(): void
    {
        $manifest = CloudManifest::fromArray(['plan' => 42]);
        $this->assertSame('42', $manifest->plan);
    }

    public function test_empty_returns_inactive_plan(): void
    {
        $manifest = CloudManifest::empty();
        $this->assertSame('inactive', $manifest->plan);
    }

    public function test_empty_returns_empty_hook_events(): void
    {
        $this->assertSame([], CloudManifest::empty()->hookEvents);
    }

    public function test_empty_returns_empty_guard_features(): void
    {
        $this->assertSame([], CloudManifest::empty()->guardFeatures);
    }

    public function test_empty_returns_empty_config(): void
    {
        $this->assertSame([], CloudManifest::empty()->config);
    }

    public function test_active_hook_events_returns_flat_event_list(): void
    {
        $manifest = CloudManifest::fromArray([
            'hook_events' => [
                'hook_a' => ['agent.before', 'agent.after'],
                'hook_b' => ['guard.blocked'],
            ],
        ]);

        $events = $manifest->activeHookEvents();

        $this->assertContains('agent.before', $events);
        $this->assertContains('agent.after', $events);
        $this->assertContains('guard.blocked', $events);
    }

    public function test_active_hook_events_excludes_disabled_feature(): void
    {
        $manifest = CloudManifest::fromArray([
            'hook_events' => [
                'hook_a' => ['agent.before', 'agent.after'],
                'hook_b' => ['guard.blocked'],
            ],
        ]);

        $events = $manifest->activeHookEvents(['hook_b']);

        $this->assertContains('agent.before', $events);
        $this->assertContains('agent.after', $events);
        $this->assertNotContains('guard.blocked', $events);
    }

    public function test_active_hook_events_excludes_multiple_disabled_features(): void
    {
        $manifest = CloudManifest::fromArray([
            'hook_events' => [
                'hook_a' => ['agent.before'],
                'hook_b' => ['guard.blocked'],
                'hook_c' => ['tool.before', 'tool.after'],
            ],
        ]);

        $events = $manifest->activeHookEvents(['hook_a', 'hook_c']);

        $this->assertSame(['guard.blocked'], $events);
    }

    public function test_active_hook_events_returns_empty_when_all_disabled(): void
    {
        $manifest = CloudManifest::fromArray([
            'hook_events' => ['hook_a' => ['agent.before']],
        ]);

        $this->assertSame([], $manifest->activeHookEvents(['hook_a']));
    }

    public function test_active_hook_events_returns_empty_for_empty_manifest(): void
    {
        $this->assertSame([], CloudManifest::empty()->activeHookEvents());
    }

    public function test_active_hook_events_uses_strict_feature_comparison(): void
    {
        $manifest = CloudManifest::fromArray([
            'hook_events' => ['hook_a' => ['agent.before']],
        ]);

        $events = $manifest->activeHookEvents(['0']);
        $this->assertContains('agent.before', $events);
    }

    public function test_active_guard_features_returns_all_when_none_disabled(): void
    {
        $manifest = CloudManifest::fromArray([
            'guard_features' => [
                'guard_a' => ['priority' => 30],
                'guard_b' => ['priority' => 50],
            ],
        ]);

        $features = $manifest->activeGuardFeatures();

        $this->assertArrayHasKey('guard_a', $features);
        $this->assertArrayHasKey('guard_b', $features);
    }

    public function test_active_guard_features_excludes_disabled(): void
    {
        $manifest = CloudManifest::fromArray([
            'guard_features' => [
                'guard_a' => ['priority' => 30],
                'guard_b' => ['priority' => 50],
            ],
        ]);

        $features = $manifest->activeGuardFeatures(['guard_b']);

        $this->assertArrayHasKey('guard_a', $features);
        $this->assertArrayNotHasKey('guard_b', $features);
    }

    public function test_active_guard_features_returns_empty_for_empty_manifest(): void
    {
        $this->assertSame([], CloudManifest::empty()->activeGuardFeatures());
    }

    public function test_active_guard_features_preserves_config_values(): void
    {
        $manifest = CloudManifest::fromArray([
            'guard_features' => ['guard_a' => ['priority' => 25]],
        ]);

        $features = $manifest->activeGuardFeatures();
        $this->assertSame(25, $features['guard_a']['priority']);
    }

    public function test_config_returns_value_for_existing_key(): void
    {
        $manifest = CloudManifest::fromArray(['config' => ['timeout' => 30]]);
        $this->assertSame(30, $manifest->config('timeout'));
    }

    public function test_config_returns_null_for_missing_key_by_default(): void
    {
        $manifest = CloudManifest::fromArray([]);
        $this->assertNull($manifest->config('nonexistent'));
    }

    public function test_config_returns_default_for_missing_key(): void
    {
        $manifest = CloudManifest::fromArray([]);
        $this->assertSame('fallback', $manifest->config('nonexistent', 'fallback'));
    }

    public function test_config_returns_custom_default_types(): void
    {
        $manifest = CloudManifest::fromArray([]);
        $this->assertSame([], $manifest->config('missing', []));
        $this->assertSame(false, $manifest->config('missing', false));
    }

    public function test_to_array_round_trips_via_from_array(): void
    {
        $data = [
            'plan' => 'active',
            'hook_events' => ['hook_a' => ['agent.before', 'agent.after']],
            'guard_features' => ['guard_a' => ['priority' => 30]],
            'config' => ['max_events' => 10000],
        ];

        $manifest = CloudManifest::fromArray($data);
        $this->assertSame($data, $manifest->toArray());
    }

    public function test_to_array_returns_all_four_keys(): void
    {
        $arr = CloudManifest::empty()->toArray();

        $this->assertArrayHasKey('plan', $arr);
        $this->assertArrayHasKey('hook_events', $arr);
        $this->assertArrayHasKey('guard_features', $arr);
        $this->assertArrayHasKey('config', $arr);
    }
}
