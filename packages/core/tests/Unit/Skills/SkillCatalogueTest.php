<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\PhpBestPracticesSkill;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        SkillCatalogue::reset();
        ComposerExtras::reset();
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillCatalogue::reset();
        ComposerExtras::reset();
        HookRegistry::reset();
    }

    public function test_built_in_skill_present(): void
    {
        $entry = SkillCatalogue::find('php_best_practices');
        $this->assertNotNull($entry);
        $this->assertSame(PhpBestPracticesSkill::class, $entry['class']);
    }

    public function test_register_adds_custom_skill(): void
    {
        SkillCatalogue::register('test-skill', PhpBestPracticesSkill::class, 'Test');
        $this->assertNotNull(SkillCatalogue::find('test-skill'));
        $this->assertSame('Test', SkillCatalogue::find('test-skill')['label']);
    }

    public function test_register_idempotent_overwrites_same_key(): void
    {
        SkillCatalogue::register('same', PhpBestPracticesSkill::class, 'First');
        SkillCatalogue::register('same', PhpBestPracticesSkill::class, 'Second');

        $matching = array_filter(SkillCatalogue::all(), fn ($e) => $e['key'] === 'same');
        $this->assertCount(1, $matching);
    }

    public function test_boot_registers_valid_entry(): void
    {
        ComposerExtras::withTestPayload([
            'alice/phpclaw-skills-seo' => [
                'skills' => [
                    ['class' => PhpBestPracticesSkill::class, 'key' => 'seo', 'label' => 'SEO'],
                ],
            ],
        ]);

        SkillCatalogue::boot();

        $entry = SkillCatalogue::find('seo');
        $this->assertNotNull($entry);
        $this->assertSame('SEO', $entry['label']);
    }

    public function test_boot_derives_key_from_class_when_missing(): void
    {
        ComposerExtras::withTestPayload([
            'alice/pkg' => [
                'skills' => [
                    ['class' => PhpBestPracticesSkill::class],
                ],
            ],
        ]);

        SkillCatalogue::boot();

        $this->assertNotNull(SkillCatalogue::find('php_best_practices'));
    }

    public function test_boot_skips_nonexistent_class(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['skills' => [['class' => 'Doesnt\\Exist']]],
        ]);
        SkillCatalogue::boot();
        $this->assertNull(SkillCatalogue::find('exist'));
    }

    public function test_boot_skips_class_not_implementing_skill_interface(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['skills' => [['class' => \stdClass::class, 'key' => 'std']]],
        ]);
        SkillCatalogue::boot();
        $this->assertNull(SkillCatalogue::find('std'));
    }

    public function test_reset_clears_customs_keeps_builtin(): void
    {
        SkillCatalogue::register('temp', PhpBestPracticesSkill::class);
        SkillCatalogue::reset();
        $this->assertNull(SkillCatalogue::find('temp'));
        $this->assertNotNull(SkillCatalogue::find('php_best_practices'));
    }

    public function test_activate_enabled_registers_skills_into_skill_registry(): void
    {
        SkillRegistry::reset();

        $instances = SkillCatalogue::activateEnabled(['php_best_practices']);

        $this->assertCount(1, $instances);
        $this->assertInstanceOf(PhpBestPracticesSkill::class, $instances[0]);
        $this->assertCount(1, SkillRegistry::all());

        SkillRegistry::reset();
    }

    public function test_activate_enabled_silently_skips_unknown_keys(): void
    {
        SkillRegistry::reset();

        $instances = SkillCatalogue::activateEnabled(['php_best_practices', 'does-not-exist']);

        $this->assertCount(1, $instances);
        SkillRegistry::reset();
    }

    public function test_activate_enabled_with_empty_array_does_nothing(): void
    {
        SkillRegistry::reset();

        $instances = SkillCatalogue::activateEnabled([]);

        $this->assertSame([], $instances);
        $this->assertSame([], SkillRegistry::all());
    }

    public function test_activate_enabled_skips_non_string_entries(): void
    {
        SkillRegistry::reset();

        /** @phpstan-ignore-next-line: deliberate bad-type input for defensive-branch coverage */
        $instances = SkillCatalogue::activateEnabled(['php_best_practices', 42, null, ['nested']]);

        $this->assertCount(1, $instances);
        $this->assertInstanceOf(PhpBestPracticesSkill::class, $instances[0]);
    }

    public function test_activate_enabled_skips_unknown_keys_silently(): void
    {
        SkillRegistry::reset();

        $instances = SkillCatalogue::activateEnabled(['ghost_skill', 'php_best_practices', 'another_ghost']);

        $this->assertCount(1, $instances);
        $this->assertInstanceOf(PhpBestPracticesSkill::class, $instances[0]);
    }

    public function test_activate_enabled_registers_into_global_skill_registry(): void
    {
        SkillRegistry::reset();

        SkillCatalogue::activateEnabled(['php_best_practices']);

        $registered = SkillRegistry::all();
        $this->assertCount(1, $registered);
        $this->assertInstanceOf(PhpBestPracticesSkill::class, $registered[0]);
    }

    public function test_activate_defaults_delegates_to_activate_enabled_with_all_keys(): void
    {
        SkillRegistry::reset();

        $instances = SkillCatalogue::activateDefaults();

        $this->assertNotEmpty($instances);
        foreach ($instances as $i) {
            $this->assertInstanceOf(SkillInterface::class, $i);
        }
    }

    public function test_activate_from_settings_is_a_no_op_that_registers_nothing(): void
    {
        SkillRegistry::reset();

        $result = SkillCatalogue::activateFromSettings(['anything' => ['php_best_practices']]);

        $this->assertSame([], $result);
        $this->assertSame([], SkillRegistry::all());
    }

    public function test_keys_returns_strings_only(): void
    {
        $keys = SkillCatalogue::keys();

        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertIsString($key);
        }
        $this->assertContains('php_best_practices', $keys);
    }

    public function test_reset_clears_custom_registrations_but_keeps_built_ins(): void
    {
        SkillCatalogue::register('custom-x', PhpBestPracticesSkill::class, 'Custom');
        $this->assertNotNull(SkillCatalogue::find('custom-x'));

        SkillCatalogue::reset();

        $this->assertNull(SkillCatalogue::find('custom-x'));
        $this->assertNotNull(SkillCatalogue::find('php_best_practices'));
    }

    public function test_register_fires_skill_registered_event(): void
    {
        $captured = [];
        HookRegistry::on(LifecycleEvent::SkillRegistered->value, function (array $ctx) use (&$captured): void {
            $captured[] = $ctx;
        });

        SkillCatalogue::register('test-skill', PhpBestPracticesSkill::class, 'Test Skill');

        $this->assertCount(1, $captured);
        $this->assertSame('test-skill', $captured[0]['key']);
        $this->assertSame(PhpBestPracticesSkill::class, $captured[0]['class']);
        $this->assertSame('Test Skill', $captured[0]['label']);
    }
}
