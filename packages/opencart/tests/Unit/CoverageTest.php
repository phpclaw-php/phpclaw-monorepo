<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Hooks\HookCatalogue;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\SecurityAlertHook;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Memory\RedisMemory;
use PhpClaw\Skills\SkillResolver;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_hook_event_bridge_canonical_feature_fires_closure_on_hook_event(): void
    {
        $fired = false;
        $bridge = new HookEventBridge(
            static function (string $_event, array $_ctx) use (&$fired): void {
                $fired = true;
            },
        );
        $bridge->register();

        HookRegistry::fire('agent.before', ['message' => 'hi']);

        self::assertTrue($fired, 'Closure dispatch must fire when HookRegistry emits agent.before.');
    }

    public function test_hook_event_bridge_mixed_entries_closure_only_fires_for_registered_events(): void
    {
        $events = [];
        $bridge = new HookEventBridge(
            static function (string $event, array $_ctx) use (&$events): void {
                $events[] = $event;
            },
        );
        $bridge->register();

        HookRegistry::fire('agent.before', []);
        HookRegistry::fire('tool.after', []);

        self::assertContains('agent.before', $events);
        self::assertContains('tool.after', $events);
    }

    public function test_hook_event_bridge_uses_canonical_not_local(): void
    {
        self::assertTrue(class_exists(HookEventBridge::class));

        $ref = new \ReflectionClass(HookEventBridge::class);
        self::assertSame('PhpClaw\\Hooks', $ref->getNamespaceName());

        self::assertFalse(
            class_exists('PhpClaw\\OpenCart\\Events\\HookEventBridge', false),
            'Local HookEventBridge must be deleted.',
        );
    }

    public function test_skill_resolver_canonical_feature_tags_optional(): void
    {
        $skills = SkillResolver::resolve([[
            'name' => 'tagless-skill',
            'description' => 'No tags field',
            'content' => 'OpenCart best practices.',
        ]]);

        self::assertCount(1, $skills);
        self::assertSame('tagless-skill', $skills[0]->name());
    }

    public function test_skill_resolver_mixed_entries_valid_plus_invalid_resolves_only_valid(): void
    {
        $entries = [
            ['name' => 'valid',   'description' => 'OK',  'content' => 'good'],
            ['file' => '/nonexistent/file.md'],
            ['class' => 'App\\Skills\\DoesNotExist'],
            ['name' => 'another', 'description' => 'OK2', 'content' => 'good2'],
        ];

        $skills = SkillResolver::resolve($entries);

        self::assertCount(2, $skills);
        self::assertSame('valid', $skills[0]->name());
        self::assertSame('another', $skills[1]->name());
    }

    public function test_skill_resolver_uses_canonical_not_local_inline_logic(): void
    {
        self::assertTrue(class_exists(SkillResolver::class));

        $ref = new \ReflectionClass(SkillResolver::class);
        self::assertSame('PhpClaw\\Skills', $ref->getNamespaceName());

        $method = $ref->getMethod('resolve');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
    }

    public function test_bootstrap_autodiscovers_memory_driver_from_composer_extras(): void
    {
        ComposerExtras::withTestPayload([
            'fake/phpclaw-memory-mongo' => [
                'memory' => [
                    'oc-bootstrap-test' => [
                        'label' => 'OC Bootstrap Test Memory',
                        'class' => RedisMemory::class,
                    ],
                ],
            ],
        ]);
        MemoryCatalogue::reset();

        Bootstrap::boot();

        $entry = MemoryCatalogue::find('oc-bootstrap-test');
        self::assertNotNull($entry, 'Bootstrap::boot() must pick up ComposerExtras entries.');
        self::assertSame('OC Bootstrap Test Memory', $entry['label']);

        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_bootstrap_discovers_mixed_categories_with_validation(): void
    {
        ComposerExtras::withTestPayload([
            'mixed/pkg' => [
                'memory' => [
                    'mix-valid' => ['label' => 'Mix Valid',    'class' => RedisMemory::class],
                    'mix-no-cls' => ['label' => 'No Class',     'class' => 'Nope\\NotReal'],
                    'mix-wrong' => ['label' => 'Wrong Iface',  'class' => \stdClass::class],
                ],
                'hooks' => [
                    ['event' => 'agent.error', 'class' => SecurityAlertHook::class, 'key' => 'mix-hook-valid'],
                    ['event' => 'tool.error',  'class' => 'Bad\\Hook\\Class',                      'key' => 'mix-hook-bad'],
                ],
            ],
        ]);
        MemoryCatalogue::reset();
        HookCatalogue::reset();

        Bootstrap::boot();

        self::assertNotNull(MemoryCatalogue::find('mix-valid'));
        self::assertNull(MemoryCatalogue::find('mix-no-cls'));
        self::assertNull(MemoryCatalogue::find('mix-wrong'));

        MemoryCatalogue::reset();
        HookCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_autodiscovery_bootstrap_uses_canonical_core_class(): void
    {
        self::assertTrue(class_exists(Bootstrap::class));
        self::assertTrue(class_exists(ComposerExtras::class));

        self::assertFalse(
            class_exists('PhpClaw\\OpenCart\\AutoDiscovery\\Bootstrap'),
            'OC-local AutoDiscovery duplicate must not exist.',
        );

        $expectedPath = realpath(__DIR__.'/../../vendor/phpclaw/phpclaw/src/AutoDiscovery/Bootstrap.php');
        self::assertSame(
            $expectedPath,
            (new \ReflectionClass(Bootstrap::class))->getFileName(),
        );
    }
}
