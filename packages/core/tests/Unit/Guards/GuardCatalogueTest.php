<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Guards\GuardCatalogue;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\PiiDetectionGuard;
use PHPUnit\Framework\TestCase;

final class GuardCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        GuardCatalogue::reset();
        ComposerExtras::reset();
    }

    protected function tearDown(): void
    {
        GuardCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_built_in_pii_present(): void
    {
        $entry = GuardCatalogue::find('pii_detection');
        $this->assertNotNull($entry);
        $this->assertSame(PiiDetectionGuard::class, $entry['class']);
        $this->assertSame(30, $entry['priority']);
        $this->assertTrue($entry['enabled_by_default']);
    }

    public function test_default_enabled_keys_includes_pii(): void
    {
        $this->assertContains('pii_detection', GuardCatalogue::defaultEnabledKeys());
    }

    public function test_register_adds_custom_guard(): void
    {
        GuardCatalogue::register('profanity', PiiDetectionGuard::class, 60, false, 'Profanity');
        $entry = GuardCatalogue::find('profanity');
        $this->assertNotNull($entry);
        $this->assertSame(60, $entry['priority']);
        $this->assertSame('Profanity', $entry['label']);
    }

    public function test_boot_registers_valid_entry(): void
    {
        ComposerExtras::withTestPayload([
            'alice/phpclaw-guard-foo' => [
                'guards' => [
                    ['class' => PiiDetectionGuard::class, 'key' => 'foo', 'priority' => 40, 'enabled_by_default' => true],
                ],
            ],
        ]);

        GuardCatalogue::boot();

        $entry = GuardCatalogue::find('foo');
        $this->assertNotNull($entry);
        $this->assertSame(40, $entry['priority']);
        $this->assertTrue($entry['enabled_by_default']);
    }

    public function test_boot_default_priority_when_missing(): void
    {
        ComposerExtras::withTestPayload([
            'alice/pkg' => [
                'guards' => [
                    ['class' => PiiDetectionGuard::class, 'key' => 'no_pri'],
                ],
            ],
        ]);

        GuardCatalogue::boot();

        $this->assertSame(GuardCatalogue::DEFAULT_ENTRY_PRIORITY, GuardCatalogue::find('no_pri')['priority']);
    }

    public function test_boot_derives_key_from_class_when_missing(): void
    {
        ComposerExtras::withTestPayload([
            'alice/pkg' => ['guards' => [['class' => PiiDetectionGuard::class]]],
        ]);

        GuardCatalogue::boot();

        $matches = array_filter(GuardCatalogue::all(), fn ($e) => $e['key'] === 'pii_detection');
        $this->assertGreaterThanOrEqual(1, count($matches));
    }

    public function test_boot_skips_nonexistent_class(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['guards' => [['class' => 'Doesnt\\Exist']]],
        ]);
        $before = count(GuardCatalogue::all());
        GuardCatalogue::boot();
        $this->assertCount($before, GuardCatalogue::all());
    }

    public function test_boot_skips_class_not_implementing_guard_interface(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['guards' => [['class' => \stdClass::class, 'key' => 'bad']]],
        ]);
        GuardCatalogue::boot();
        $this->assertNull(GuardCatalogue::find('bad'));
    }

    public function test_reset_clears_customs_keeps_builtin(): void
    {
        GuardCatalogue::register('temp', PiiDetectionGuard::class);
        GuardCatalogue::reset();
        $this->assertNull(GuardCatalogue::find('temp'));
        $this->assertNotNull(GuardCatalogue::find('pii_detection'));
    }

    public function test_activate_enabled_registers_guard_into_guard_registry(): void
    {
        GuardRegistry::reset();

        $instances = GuardCatalogue::activateEnabled(['pii_detection']);

        $this->assertCount(1, $instances);
        $this->assertGreaterThanOrEqual(1, GuardRegistry::count());

        GuardRegistry::reset();
    }

    public function test_activate_enabled_skips_a_class_already_present_in_guard_registry(): void
    {
        GuardRegistry::reset();
        GuardRegistry::register(new PiiDetectionGuard);

        $instances = GuardCatalogue::activateEnabled(['pii_detection']);

        $this->assertCount(0, $instances, 'Already-registered class must not be re-instantiated or re-registered.');
        $this->assertSame(1, GuardRegistry::count());

        GuardRegistry::reset();
    }

    public function test_activate_defaults_registers_pii_detection(): void
    {
        GuardRegistry::reset();

        $instances = GuardCatalogue::activateDefaults();

        $this->assertGreaterThanOrEqual(1, count($instances));

        GuardRegistry::reset();
    }

    public function test_activate_enabled_silently_skips_unknown_keys(): void
    {
        GuardRegistry::reset();

        $instances = GuardCatalogue::activateEnabled(['pii_detection', 'no-such-guard']);

        $this->assertCount(1, $instances);
        GuardRegistry::reset();
    }
}
