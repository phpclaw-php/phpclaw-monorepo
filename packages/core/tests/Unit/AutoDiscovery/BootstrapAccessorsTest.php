<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureDefaultTool;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureGuard;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureHook;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureMemory;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureNoArgTool;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureProvider;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureSkill;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureTool;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureWildcardHook;
use PhpClaw\Tools\ToolCatalogue;
use PHPUnit\Framework\TestCase;

final class BootstrapAccessorsTest extends TestCase
{
    protected function setUp(): void
    {
        $this->injectDiscoveryState([
            'tools' => [
                FixtureTool::class => ['name' => 'fixture_tool',        'default' => false, 'needsConfig' => []],
                FixtureDefaultTool::class => ['name' => 'fixture_default_tool', 'default' => true,  'needsConfig' => ['workspaceRoot' => 'string']],
                FixtureNoArgTool::class => ['name' => 'fixture_no_arg_tool',  'default' => true,  'needsConfig' => []],
            ],
            'providers' => [
                FixtureProvider::class => ['name' => 'fixture_provider', 'defaultModel' => 'fixture-model-v1'],
            ],
            'memory' => [
                FixtureMemory::class => ['driver' => 'fixture_driver'],
            ],
            'skills' => [
                FixtureSkill::class => ['name' => 'fixture_skill', 'keywords' => ['fix', 'test']],
            ],
            'hooks' => [
                FixtureHook::class => [
                    ['event' => 'agent.before', 'priority' => 10],
                    ['event' => 'agent.after',  'priority' => 200],
                ],
                FixtureWildcardHook::class => [
                    ['event' => '*', 'priority' => 50],
                ],
            ],
            'guards' => [
                FixtureGuard::class => ['priority' => 25],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DiscoveryCache::reset();
    }

    public function test_tools_returns_name_to_class_map(): void
    {
        $tools = Bootstrap::tools();

        $this->assertSame(FixtureTool::class, $tools['fixture_tool']);
        $this->assertSame(FixtureDefaultTool::class, $tools['fixture_default_tool']);
        $this->assertSame(FixtureNoArgTool::class, $tools['fixture_no_arg_tool']);
    }

    public function test_providers_returns_name_to_class_map(): void
    {
        $providers = Bootstrap::providers();

        $this->assertSame(FixtureProvider::class, $providers['fixture_provider']);
    }

    public function test_memory_returns_driver_to_class_map(): void
    {
        $memory = Bootstrap::memory();

        $this->assertSame(FixtureMemory::class, $memory['fixture_driver']);
    }

    public function test_skills_returns_name_to_class_map(): void
    {
        $skills = Bootstrap::skills();

        $this->assertSame(FixtureSkill::class, $skills['fixture_skill']);
    }

    public function test_hooks_groups_by_event_with_priority_sort(): void
    {
        $hooks = Bootstrap::hooks();

        $this->assertSame([FixtureHook::class], $hooks['agent.before']);
        $this->assertSame([FixtureHook::class], $hooks['agent.after']);
        $this->assertSame([FixtureWildcardHook::class], $hooks['*']);
    }

    public function test_hooks_with_multiple_listeners_are_priority_sorted(): void
    {
        $this->injectDiscoveryState([
            'tools' => [], 'providers' => [], 'memory' => [], 'skills' => [],
            'hooks' => [
                FixtureHook::class => [['event' => 'agent.before', 'priority' => 50]],
                FixtureWildcardHook::class => [['event' => 'agent.before', 'priority' => 10]],
            ],
            'guards' => [],
        ]);

        $listeners = Bootstrap::hooks()['agent.before'];

        $this->assertSame(
            [FixtureWildcardHook::class, FixtureHook::class],
            $listeners,
        );
    }

    public function test_guards_returns_priority_sorted_list_of_class_priority_pairs(): void
    {
        $guards = Bootstrap::guards();

        $this->assertSame([['class' => FixtureGuard::class, 'priority' => 25]], $guards);
    }

    public function test_guards_are_returned_in_ascending_priority(): void
    {
        $this->injectDiscoveryState([
            'tools' => [], 'providers' => [], 'memory' => [], 'skills' => [], 'hooks' => [],
            'guards' => [
                'A' => ['priority' => 50],
                'B' => ['priority' => 10],
                'C' => ['priority' => 30],
            ],
        ]);

        $priorities = array_column(Bootstrap::guards(), 'priority');

        $this->assertSame([10, 30, 50], $priorities);
    }

    public function test_instantiate_core_tools_only_includes_default_tagged_entries(): void
    {
        $tools = ToolCatalogue::instantiateDefaults(['workspaceRoot' => '/tmp/x']);

        $this->assertCount(2, $tools);
        $classes = array_map(static fn ($t) => $t::class, $tools);
        $this->assertContains(FixtureDefaultTool::class, $classes);
        $this->assertContains(FixtureNoArgTool::class, $classes);
        $this->assertNotContains(FixtureTool::class, $classes);
    }

    public function test_instantiate_core_tools_passes_needs_config_values_to_constructor(): void
    {
        $tools = ToolCatalogue::instantiateDefaults(['workspaceRoot' => '/some/path']);

        $defaultTool = null;
        foreach ($tools as $t) {
            if ($t instanceof FixtureDefaultTool) {
                $defaultTool = $t;
                break;
            }
        }

        $this->assertNotNull($defaultTool);
        $this->assertSame('/some/path', $defaultTool->workspaceRoot);
    }

    public function test_instantiate_core_tools_no_arg_class_constructs_cleanly(): void
    {
        $tools = ToolCatalogue::instantiateDefaults([]);

        $noArg = null;
        foreach ($tools as $t) {
            if ($t instanceof FixtureNoArgTool) {
                $noArg = $t;
                break;
            }
        }

        $this->assertInstanceOf(FixtureNoArgTool::class, $noArg);
    }

    public function test_instantiate_core_tools_skips_missing_classes(): void
    {
        $this->injectDiscoveryState([
            'tools' => [
                'PhpClaw\\Does\\Not\\Exist' => ['name' => 'ghost', 'default' => true, 'needsConfig' => []],
            ],
            'providers' => [], 'memory' => [], 'skills' => [], 'hooks' => [], 'guards' => [],
        ]);

        $this->assertSame([], ToolCatalogue::instantiateDefaults([]));
    }

    private function injectDiscoveryState(array $state): void
    {
        $reflection = new \ReflectionClass(DiscoveryCache::class);
        $prop = $reflection->getProperty('memoryCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $state);
    }
}
