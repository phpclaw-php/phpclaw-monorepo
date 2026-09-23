<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Hooks\HookCatalogue;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\SecurityAlertHook;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Memory\RedisMemory;
use PhpClaw\Skills\PhpBestPracticesSkill;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class CanonicalCoreClassesTest extends TestCase
{
    protected function tearDown(): void
    {
        HookRegistry::reset();
        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_hook_event_bridge_uses_canonical_core_class(): void
    {
        self::assertTrue(
            class_exists(HookEventBridge::class),
            'Canonical HookEventBridge must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\Magento\\Events\\HookEventBridge'),
            'Magento-local HookEventBridge duplicate must NOT exist (dead-code policy)',
        );

        $bridgeClass = new \ReflectionClass(HookEventBridge::class);
        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/Hooks/HookEventBridge.php');

        self::assertSame(
            $expectedPath,
            $bridgeClass->getFileName(),
            'HookEventBridge must load from vendor/phpclaw/phpclaw/src/Hooks/, not adapter src/',
        );
    }

    public function test_hook_event_bridge_accepts_closure_dispatch(): void
    {
        $captured = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $ctx) use (&$captured): void {
                $captured[] = ['phpclaw_'.str_replace('.', '_', $event), $ctx];
            },
            events: ['agent.before', 'tool.after'],
        );

        HookRegistry::reset();
        $bridge->register();

        HookRegistry::fire('agent.before', ['run_id' => 'r1']);
        HookRegistry::fire('tool.after', ['tool_name' => 't1']);

        self::assertCount(2, $captured);
        self::assertSame('phpclaw_agent_before', $captured[0][0]);
        self::assertSame('phpclaw_tool_after', $captured[1][0]);

        HookRegistry::reset();
    }

    public function test_hook_event_bridge_register_fires_lifecycle_events(): void
    {
        $dispatched = [];
        $bridge = new HookEventBridge(
            dispatcher: static function (string $event, array $_ctx) use (&$dispatched): void {
                $dispatched[] = $event;
            },
            events: ['agent.before', 'agent.after', 'provider.request'],
        );

        HookRegistry::reset();
        $bridge->register();

        HookRegistry::fire('agent.before', ['run_id' => 'x']);
        HookRegistry::fire('agent.after', ['run_id' => 'x']);
        HookRegistry::fire('provider.request', ['model' => 'm']);

        self::assertContains('agent.before', $dispatched);
        self::assertContains('agent.after', $dispatched);
        self::assertContains('provider.request', $dispatched);

        HookRegistry::reset();
    }

    public function test_privacy_aware_memory_uses_canonical_core_class(): void
    {
        self::assertTrue(
            class_exists(PrivacyAwareMemory::class),
            'Canonical PrivacyAwareMemory must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\Magento\\Memory\\PrivacyAwareMemory'),
            'Magento-local PrivacyAwareMemory duplicate must NOT exist (dead-code policy)',
        );

        $memClass = new \ReflectionClass(PrivacyAwareMemory::class);
        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/Memory/PrivacyAwareMemory.php');

        self::assertSame(
            $expectedPath,
            $memClass->getFileName(),
            'PrivacyAwareMemory must load from vendor/phpclaw/phpclaw/src/Memory/, not adapter src/',
        );
    }

    public function test_privacy_aware_memory_set_is_noop_when_store_messages_false(): void
    {
        $inner = new ArrayMemory;
        $memory = new PrivacyAwareMemory($inner, false);

        $memory->set('k', 'some content', 'conversations');

        self::assertNull($inner->get('k', 'conversations'));
    }

    public function test_privacy_aware_memory_set_writes_when_store_messages_true(): void
    {
        $inner = new ArrayMemory;
        $memory = new PrivacyAwareMemory($inner, true);

        $memory->set('k', 'some content', 'conversations');

        self::assertSame('some content', $inner->get('k', 'conversations'));
    }

    public function test_skills_activation_uses_canonical_catalogue(): void
    {
        self::assertTrue(
            class_exists(SkillCatalogue::class),
            'Canonical SkillCatalogue must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\Magento\\Skills\\SkillCatalogue'),
            'Magento-local SkillCatalogue duplicate must NOT exist (dead-code policy)',
        );

        $catClass = new \ReflectionClass(SkillCatalogue::class);
        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/Skills/SkillCatalogue.php');

        self::assertSame(
            $expectedPath,
            $catClass->getFileName(),
            'SkillCatalogue must load from vendor/phpclaw/phpclaw/src/Skills/, not adapter src/',
        );
    }

    public function test_skills_activation_with_empty_list_does_nothing(): void
    {
        SkillRegistry::reset();

        SkillCatalogue::activateEnabled([]);

        self::assertSame(0, SkillRegistry::count());
    }

    public function test_skills_activation_with_valid_keys_registers_to_registry(): void
    {
        SkillRegistry::reset();
        SkillCatalogue::reset();

        SkillCatalogue::register('mg-skill-direct', PhpBestPracticesSkill::class, 'MG Test Skill');

        SkillCatalogue::activateEnabled(['mg-skill-direct']);

        self::assertGreaterThan(0, SkillRegistry::count());

        SkillRegistry::reset();
        SkillCatalogue::reset();
    }

    public function test_bootstrap_autodiscovers_memory_driver_from_composer_extras(): void
    {
        ComposerExtras::withTestPayload([
            'fake/phpclaw-memory-mongo' => [
                'memory' => [
                    'mg-bootstrap-test' => [
                        'label' => 'MG Bootstrap Test Memory',
                        'class' => RedisMemory::class,
                    ],
                ],
            ],
        ]);
        MemoryCatalogue::reset();

        Bootstrap::boot();

        $entry = MemoryCatalogue::find('mg-bootstrap-test');
        self::assertNotNull($entry, 'Bootstrap::boot should have triggered MemoryCatalogue::boot');
        self::assertSame('MG Bootstrap Test Memory', $entry['label']);

        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_bootstrap_discovers_mixed_categories_with_validation(): void
    {
        ComposerExtras::withTestPayload([
            'mixed/mg-pkg' => [
                'memory' => [
                    'mg-mix-valid' => [
                        'label' => 'Mix Valid Memory',
                        'class' => RedisMemory::class,
                    ],
                    'mg-mix-no-cls' => [
                        'label' => 'No Class',
                        'class' => 'Nope\\NotReal',
                    ],
                    'mg-mix-wrong' => [
                        'label' => 'Wrong Interface',
                        'class' => \stdClass::class,
                    ],
                ],
                'hooks' => [
                    [
                        'event' => 'agent.error',
                        'class' => SecurityAlertHook::class,
                        'key' => 'mg-mix-hook-valid',
                    ],
                    [
                        'event' => 'tool.error',
                        'class' => 'Bad\\Hook\\Class',
                        'key' => 'mg-mix-hook-bad',
                    ],
                ],
            ],
        ]);
        MemoryCatalogue::reset();
        HookCatalogue::reset();

        Bootstrap::boot();

        self::assertNotNull(MemoryCatalogue::find('mg-mix-valid'));
        self::assertNull(MemoryCatalogue::find('mg-mix-no-cls'));
        self::assertNull(MemoryCatalogue::find('mg-mix-wrong'));

        self::assertNotNull(HookCatalogue::find('mg-mix-hook-valid'));
        self::assertNull(HookCatalogue::find('mg-mix-hook-bad'));

        MemoryCatalogue::reset();
        HookCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_autodiscovery_bootstrap_uses_canonical_core_class(): void
    {
        self::assertTrue(
            class_exists(Bootstrap::class),
            'Canonical Bootstrap must exist in core',
        );
        self::assertTrue(
            class_exists(ComposerExtras::class),
            'Canonical ComposerExtras must exist in core',
        );

        self::assertFalse(
            class_exists('PhpClaw\\Magento\\AutoDiscovery\\Bootstrap'),
            'Magento-local AutoDiscovery duplicate must NOT exist (dead-code policy)',
        );

        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/AutoDiscovery/Bootstrap.php');
        self::assertSame(
            $expectedPath,
            (new \ReflectionClass(Bootstrap::class))->getFileName(),
            'Bootstrap must load from vendor/phpclaw/phpclaw/src/AutoDiscovery/, not adapter src/',
        );
    }
}
