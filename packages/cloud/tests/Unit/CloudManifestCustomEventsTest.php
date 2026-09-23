<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudManifest;
use PHPUnit\Framework\TestCase;

final class CloudManifestCustomEventsTest extends TestCase
{
    public function test_custom_events_enabled_defaults_to_false_for_empty_manifest(): void
    {
        $m = CloudManifest::empty();

        $this->assertFalse($m->customEventsEnabled());
    }

    public function test_custom_events_enabled_false_when_config_key_absent(): void
    {
        $m = new CloudManifest(plan: 'pro', hookEvents: [], guardFeatures: [], config: []);

        $this->assertFalse($m->customEventsEnabled());
    }

    public function test_custom_events_enabled_true_when_config_flag_set(): void
    {
        $m = new CloudManifest(
            plan: 'pro',
            hookEvents: [],
            guardFeatures: [],
            config: ['custom_events' => true],
        );

        $this->assertTrue($m->customEventsEnabled());
    }

    public function test_custom_events_enabled_false_when_config_flag_falsy(): void
    {
        $m = new CloudManifest(
            plan: 'pro',
            hookEvents: [],
            guardFeatures: [],
            config: ['custom_events' => 0],
        );

        $this->assertFalse($m->customEventsEnabled());
    }

    public function test_custom_events_enabled_survives_round_trip_through_from_array(): void
    {
        $m = CloudManifest::fromArray([
            'plan' => 'pro',
            'hook_events' => [],
            'guard_features' => [],
            'config' => ['custom_events' => true],
        ]);

        $this->assertTrue($m->customEventsEnabled());
    }
}
