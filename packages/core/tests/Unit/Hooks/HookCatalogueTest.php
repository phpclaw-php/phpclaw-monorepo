<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Hooks\HookCatalogue;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\SecurityAlertHook;
use PHPUnit\Framework\TestCase;

final class HookCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        HookCatalogue::reset();
        ComposerExtras::reset();
    }

    protected function tearDown(): void
    {
        HookCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_built_in_security_alert_present(): void
    {
        $entry = HookCatalogue::find('security_alert');
        $this->assertNotNull($entry);
        $this->assertSame(SecurityAlertHook::class, $entry['class']);
        $this->assertSame('guard.blocked', $entry['event']);
    }

    public function test_default_enabled_keys_includes_security_alert(): void
    {
        $this->assertContains('security_alert', HookCatalogue::defaultEnabledKeys());
    }

    public function test_register_adds_custom_hook(): void
    {
        HookCatalogue::register('mine', 'agent.error', SecurityAlertHook::class, 75, false, 'Mine');
        $entry = HookCatalogue::find('mine');
        $this->assertNotNull($entry);
        $this->assertSame('agent.error', $entry['event']);
        $this->assertSame(75, $entry['priority']);
        $this->assertFalse($entry['enabled_by_default']);
        $this->assertSame('Mine', $entry['label']);
    }

    public function test_boot_registers_valid_entry(): void
    {
        ComposerExtras::withTestPayload([
            'alice/phpclaw-hook-jira' => [
                'hooks' => [
                    [
                        'event' => 'agent.error',
                        'class' => SecurityAlertHook::class,
                        'key' => 'jira',
                        'priority' => 80,
                        'enabled_by_default' => false,
                    ],
                ],
            ],
        ]);

        HookCatalogue::boot();

        $entry = HookCatalogue::find('jira');
        $this->assertNotNull($entry);
        $this->assertSame(80, $entry['priority']);
    }

    public function test_boot_default_priority_when_missing(): void
    {
        ComposerExtras::withTestPayload([
            'alice/pkg' => [
                'hooks' => [
                    ['event' => 'agent.before', 'class' => SecurityAlertHook::class, 'key' => 'no_pri'],
                ],
            ],
        ]);

        HookCatalogue::boot();

        $this->assertSame(HookCatalogue::DEFAULT_PRIORITY, HookCatalogue::find('no_pri')['priority']);
    }

    public function test_boot_skips_missing_event(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['hooks' => [['class' => SecurityAlertHook::class, 'key' => 'broken']]],
        ]);
        HookCatalogue::boot();
        $this->assertNull(HookCatalogue::find('broken'));
    }

    public function test_boot_skips_nonexistent_class(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['hooks' => [['event' => 'a.b', 'class' => 'Doesnt\\Exist']]],
        ]);
        HookCatalogue::boot();
        $this->assertCount(0, array_filter(HookCatalogue::all(), fn ($e) => $e['key'] !== 'security_alert'));
    }

    public function test_boot_skips_class_not_implementing_hook_interface(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['hooks' => [['event' => 'a.b', 'class' => \stdClass::class, 'key' => 'bad']]],
        ]);
        HookCatalogue::boot();
        $this->assertNull(HookCatalogue::find('bad'));
    }

    public function test_reset_clears_customs_keeps_builtin(): void
    {
        HookCatalogue::register('temp', 'agent.before', SecurityAlertHook::class);
        HookCatalogue::reset();
        $this->assertNull(HookCatalogue::find('temp'));
        $this->assertNotNull(HookCatalogue::find('security_alert'));
    }

    public function test_activate_enabled_registers_hook_into_hook_registry(): void
    {
        HookRegistry::reset();

        $instances = HookCatalogue::activateEnabled(['security_alert']);

        $this->assertCount(1, $instances);
        $this->assertSame(1, HookRegistry::count('guard.blocked'));

        HookRegistry::reset();
    }

    public function test_activate_defaults_registers_security_alert(): void
    {
        HookRegistry::reset();

        $instances = HookCatalogue::activateDefaults();

        $this->assertGreaterThanOrEqual(1, count($instances));
        $this->assertGreaterThanOrEqual(1, HookRegistry::count('guard.blocked'));

        HookRegistry::reset();
    }

    public function test_activate_enabled_silently_skips_unknown_keys(): void
    {
        HookRegistry::reset();

        $instances = HookCatalogue::activateEnabled(['security_alert', 'no-such-hook']);

        $this->assertCount(1, $instances);
        HookRegistry::reset();
    }
}
