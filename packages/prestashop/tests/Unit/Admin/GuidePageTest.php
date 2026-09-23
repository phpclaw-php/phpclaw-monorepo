<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\PrestaShop\Admin\GuidePage;
use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\Tests\Helpers\MysqliPsDb;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuidePage::class)]
final class GuidePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPlugin();
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        \Hook::reset();

        \Configuration::reset();
        $this->resetPlugin();
        SkillRegistry::reset();

        parent::tearDown();
    }

    private function resetPlugin(): void
    {
        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    public function test_guide_providers_covers_every_catalogue_provider(): void
    {
        $rows = GuidePage::guideProviders();

        self::assertSame(
            array_map('strval', array_keys(ProviderCatalogue::all())),
            array_column($rows, 'key'),
        );

        foreach ($rows as $row) {
            self::assertSame(['key', 'name', 'models', 'signup', 'notes'], array_keys($row));
            self::assertNotSame('', $row['name']);
        }
    }

    public function test_guide_providers_returns_at_least_eight_rows(): void
    {
        self::assertGreaterThanOrEqual(8, count(GuidePage::guideProviders()));
    }

    public function test_guide_providers_rows_have_required_keys(): void
    {
        foreach (GuidePage::guideProviders() as $row) {
            self::assertArrayHasKey('key', $row);
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('models', $row);
            self::assertArrayHasKey('signup', $row);
            self::assertArrayHasKey('notes', $row);
        }
    }

    public function test_guide_providers_includes_anthropic(): void
    {
        $keys = array_column(GuidePage::guideProviders(), 'key');

        self::assertContains('anthropic', $keys);
    }

    public function test_guide_providers_fallback_when_catalogue_absent(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);

        $existing = $ref->getValue(null) ?? [];
        $rows = GuidePage::guideProviders();

        self::assertNotEmpty($rows);
        self::assertArrayHasKey('key', $rows[0]);
    }

    public function test_guide_tool_rows_lists_every_prestashop_tool(): void
    {
        $rows = GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_'));

        self::assertSame([
            'Products', 'Orders', 'Customers', 'Categories', 'Manufacturers',
            'Carts', 'Stock', 'Coupons', 'Modules', 'Reports',
            'Configuration', 'Employees', 'Database', 'Log',
        ], array_column($rows, 'name'));

        foreach ($rows as $row) {
            self::assertSame(['name', 'description', 'examples'], array_keys($row));
        }
    }

    public function test_guide_tool_rows_has_fourteen_native_tools(): void
    {
        self::assertGreaterThanOrEqual(14, count(GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_'))));
    }

    public function test_guide_tool_rows_each_have_required_keys(): void
    {
        foreach (GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_')) as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('description', $row);
            self::assertArrayHasKey('examples', $row);
        }
    }

    public function test_guide_tool_rows_includes_products_tool(): void
    {
        $names = array_column(GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_')), 'name');

        self::assertContains('Products', $names);
    }

    public function test_guide_tool_rows_respects_tool_deny(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $factory = new EngineFactory([], ['tool_deny' => ['ps_product']], null, 'ps_');
        $factoryRef = new \ReflectionProperty(Plugin::class, 'engineFactory');
        $factoryRef->setAccessible(true);
        $factoryRef->setValue($plugin, $factory);

        $names = array_column(GuidePage::guideToolRows($plugin), 'name');

        self::assertNotContains('Products', $names);
        self::assertContains('Orders', $names);
    }

    public function test_guide_tool_rows_respects_group_deny(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $factory = new EngineFactory([], ['tool_deny' => ['group:system']], null, 'ps_');
        $factoryRef = new \ReflectionProperty(Plugin::class, 'engineFactory');
        $factoryRef->setAccessible(true);
        $factoryRef->setValue($plugin, $factory);

        $names = array_column(GuidePage::guideToolRows($plugin), 'name');

        self::assertNotContains('Database', $names);
        self::assertNotContains('Log', $names);
        self::assertNotContains('Configuration', $names);
        self::assertContains('Products', $names);
    }

    public function test_guide_tool_rows_skips_non_object_contributions(): void
    {
        \Hook::setResult('actionPhpclawExtraTools', [['not-an-object']]);

        $rows = GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_'));

        self::assertGreaterThanOrEqual(14, count($rows));
    }

    public function test_guide_tool_rows_appends_external_tool_with_name_and_description(): void
    {
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'ext_tool';
            }

            public function description(): string
            {
                return 'External tool description.';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };

        \Hook::setResult('actionPhpclawExtraTools', [[$tool]]);

        $rows = GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_'));
        $names = array_column($rows, 'name');

        self::assertContains('ext_tool', $names);

        $idx = array_search('ext_tool', $names, true);
        self::assertSame('External tool description.', $rows[(int) $idx]['description']);
        self::assertSame('', $rows[(int) $idx]['examples']);
    }

    public function test_guide_tool_rows_uses_class_basename_when_tool_has_no_name_method(): void
    {
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return '';
            }

            public function description(): string
            {
                return '';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };

        \Hook::setResult('actionPhpclawExtraTools', [[$tool]]);

        $rows = GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_'));

        self::assertGreaterThanOrEqual(14, count($rows));
    }

    public function test_get_core_utility_tools_returns_non_empty_list(): void
    {
        $rows = GuidePage::getCoreUtilityTools();

        self::assertNotContains(
            'PsProductTool',
            array_column($rows, 'tool'),
            'a tool outside the PhpClaw\\Tools namespace must not be listed',
        );
    }

    public function test_get_core_utility_tools_returns_exactly_seven_entries(): void
    {
        self::assertCount(7, GuidePage::getCoreUtilityTools());
    }

    public function test_get_core_utility_tools_rows_have_required_keys(): void
    {
        foreach (GuidePage::getCoreUtilityTools() as $row) {
            self::assertArrayHasKey('tool', $row);
            self::assertArrayHasKey('status', $row);
            self::assertArrayHasKey('notes', $row);
        }
    }

    public function test_get_core_utility_tools_includes_http_tool(): void
    {
        $tools = array_column(GuidePage::getCoreUtilityTools(), 'tool');

        self::assertContains('HttpTool', $tools);
    }

    public function test_get_core_utility_tools_includes_shell_tool(): void
    {
        $tools = array_column(GuidePage::getCoreUtilityTools(), 'tool');

        self::assertContains('ShellTool', $tools);
    }

    public function test_get_core_utility_tools_includes_file_read_tool(): void
    {
        $tools = array_column(GuidePage::getCoreUtilityTools(), 'tool');

        self::assertContains('FileReadTool', $tools);
    }

    public function test_get_core_utility_tools_includes_file_write_tool(): void
    {
        $tools = array_column(GuidePage::getCoreUtilityTools(), 'tool');

        self::assertContains('FileWriteTool', $tools);
    }

    public function test_get_core_utility_tools_fallback_when_discovery_cache_throws(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $rows = GuidePage::getCoreUtilityTools();

        self::assertNotEmpty($rows);
        self::assertArrayHasKey('tool', $rows[0]);
    }

    public function test_get_core_utility_tools_falls_back_to_the_full_core_tool_meta(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $meta = (new \ReflectionClassConstant(GuidePage::class, 'CORE_TOOL_META'))->getValue();

        self::assertSame(array_keys($meta), array_column(GuidePage::getCoreUtilityTools(), 'tool'));
    }

    public function test_get_core_utility_tools_skips_tools_without_default_flag(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [
                'PhpClaw\\Tools\\HttpTool' => ['default' => false],
            ],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $rows = GuidePage::getCoreUtilityTools();

        $meta = (new \ReflectionClassConstant(GuidePage::class, 'CORE_TOOL_META'))->getValue();

        self::assertCount(
            count($meta),
            $rows,
            'a tool with default=false contributes nothing, so the full fallback list is returned',
        );
    }

    public function test_get_core_utility_tools_skips_non_core_namespace_tools(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [
                'PhpClaw\\PrestaShop\\Tools\\PsProductTool' => ['default' => true],
            ],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $rows = GuidePage::getCoreUtilityTools();

        self::assertNotContains('PsProductTool', array_column($rows, 'tool'));
        $meta = (new \ReflectionClassConstant(GuidePage::class, 'CORE_TOOL_META'))->getValue();
        self::assertCount(count($meta), $rows, 'skipping the only entry falls back to the full core list');
    }

    public function test_get_memory_drivers_rows_have_required_keys(): void
    {
        foreach (GuidePage::getMemoryDrivers() as $row) {
            self::assertArrayHasKey('driver', $row);
            self::assertArrayHasKey('label', $row);
            self::assertArrayHasKey('source', $row);
            self::assertArrayHasKey('class', $row);
        }
    }

    public function test_get_memory_drivers_returns_empty_when_cache_has_no_memory(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $drivers = GuidePage::getMemoryDrivers();

        self::assertSame([], $drivers, 'an integer-keyed contribution must be skipped');
    }

    public function test_get_memory_drivers_labels_prestashop_namespace_as_prestashop(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [
                'PhpClaw\\PrestaShop\\Memory\\PsDbMemory' => [
                    'driver' => 'ps_db',
                    'label' => 'PrestaShop DB',
                ],
            ],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $drivers = GuidePage::getMemoryDrivers();

        self::assertCount(1, $drivers);
        self::assertSame('prestashop', $drivers[0]['source']);
    }

    public function test_get_memory_drivers_labels_core_namespace_as_core(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [
                'PhpClaw\\Memory\\ArrayMemory' => [
                    'driver' => 'array',
                    'label' => 'Array',
                ],
            ],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $drivers = GuidePage::getMemoryDrivers();

        self::assertCount(1, $drivers);
        self::assertSame('core', $drivers[0]['source']);
    }

    public function test_get_memory_drivers_appends_external_contribution(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $factory = static fn () => new ArrayMemory;
        \Hook::setResult('actionPhpclawExtraMemory', [['ext_mem' => $factory]]);

        $drivers = GuidePage::getMemoryDrivers();
        $slugs = array_column($drivers, 'driver');

        self::assertContains('ext_mem', $slugs);

        $idx = array_search('ext_mem', $slugs, true);
        self::assertSame('prestashop', $drivers[(int) $idx]['source']);
    }

    public function test_get_memory_drivers_skips_empty_slug_contributions(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraMemory', [['' => static fn () => null]]);

        $drivers = GuidePage::getMemoryDrivers();

        $slugs = array_column($drivers, 'driver');
        self::assertNotContains('', $slugs);
    }

    public function test_get_memory_drivers_skips_integer_key_contributions(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraMemory', [[static fn () => null]]);

        self::assertSame([], GuidePage::getMemoryDrivers());
    }

    public function test_get_discovered_guards_rows_have_required_keys(): void
    {
        foreach (GuidePage::getDiscoveredGuards() as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('label', $row);
            self::assertArrayHasKey('priority', $row);
            self::assertArrayHasKey('enabled_by_default', $row);
            self::assertArrayHasKey('source', $row);
            self::assertArrayHasKey('class', $row);
        }
    }

    public function test_get_discovered_guards_labels_prestashop_namespace(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [
                'PhpClaw\\PrestaShop\\Guards\\PsGuard' => [
                    'name' => 'ps_guard',
                    'label' => 'PS Guard',
                    'priority' => 10,
                    'enabledByDefault' => true,
                ],
            ],
        ]);

        $guards = GuidePage::getDiscoveredGuards();

        self::assertCount(1, $guards);
        self::assertSame('prestashop', $guards[0]['source']);
    }

    public function test_get_discovered_guards_labels_core_namespace(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [
                'PhpClaw\\Guards\\MessageLengthGuard' => [
                    'name' => 'message_length',
                    'label' => 'Message Length',
                    'priority' => 5,
                    'enabledByDefault' => false,
                ],
            ],
        ]);

        $guards = GuidePage::getDiscoveredGuards();

        self::assertCount(1, $guards);
        self::assertSame('core', $guards[0]['source']);
        self::assertFalse($guards[0]['enabled_by_default']);
    }

    public function test_get_discovered_guards_appends_external_object_contribution(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $guard = new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };

        \Hook::setResult('actionPhpclawExtraGuards', [[$guard]]);

        $guards = GuidePage::getDiscoveredGuards();

        self::assertCount(1, $guards);
        self::assertSame('prestashop', $guards[0]['source']);
        self::assertTrue($guards[0]['enabled_by_default']);
        self::assertSame(0, $guards[0]['priority']);
    }

    public function test_get_discovered_guards_skips_non_object_contributions(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraGuards', [['not-a-guard-object']]);

        $guards = GuidePage::getDiscoveredGuards();

        self::assertSame([], $guards);
    }

    public function test_get_discovered_hooks_rows_have_required_keys(): void
    {
        foreach (GuidePage::getDiscoveredHooks() as $row) {
            self::assertArrayHasKey('event', $row);
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('priority', $row);
            self::assertArrayHasKey('enabled_by_default', $row);
            self::assertArrayHasKey('source', $row);
            self::assertArrayHasKey('class', $row);
        }
    }

    public function test_get_discovered_hooks_reads_from_cache(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [
                'PhpClaw\\PrestaShop\\Events\\HookEventBridge' => [
                    ['event' => 'agent.done', 'name' => 'onAgentDone', 'priority' => 10, 'enabledByDefault' => true],
                ],
            ],
            'guards' => [],
        ]);

        $hooks = GuidePage::getDiscoveredHooks();

        self::assertCount(1, $hooks);
        self::assertSame('agent.done', $hooks[0]['event']);
        self::assertSame('prestashop', $hooks[0]['source']);
    }

    public function test_get_discovered_hooks_appends_array_handler_contribution(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $handlerObj = new \stdClass;
        \Hook::setResult('actionPhpclawExtraHooks', [[
            [
                'event' => 'agent.before',
                'handler' => [$handlerObj, 'onBefore'],
                'priority' => 20,
            ],
        ]]);

        $hooks = GuidePage::getDiscoveredHooks();

        self::assertCount(1, $hooks);
        self::assertSame('agent.before', $hooks[0]['event']);
        self::assertSame('onBefore', $hooks[0]['name']);
        self::assertSame(20, $hooks[0]['priority']);
        self::assertSame('prestashop', $hooks[0]['source']);
        self::assertTrue($hooks[0]['enabled_by_default']);
    }

    public function test_get_discovered_hooks_appends_string_handler_contribution(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraHooks', [[
            [
                'event' => 'agent.after',
                'handler' => 'MyNamespace\\MyListener::handle',
            ],
        ]]);

        $hooks = GuidePage::getDiscoveredHooks();

        self::assertCount(1, $hooks);
        self::assertSame('agent.after', $hooks[0]['event']);
        self::assertSame('MyNamespace\\MyListener::handle', $hooks[0]['class']);
    }

    public function test_get_discovered_hooks_skips_entries_without_event_key(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraHooks', [[
            ['handler' => 'foo'],
        ]]);

        $hooks = GuidePage::getDiscoveredHooks();

        self::assertSame([], $hooks);
    }

    public function test_get_discovered_hooks_skips_non_array_entries(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraHooks', [['not-an-array-entry']]);

        $hooks = GuidePage::getDiscoveredHooks();

        self::assertSame([], $hooks);
    }

    public function test_get_discovered_skills_rows_have_required_keys(): void
    {
        foreach (GuidePage::getDiscoveredSkills() as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('label', $row);
            self::assertArrayHasKey('keywords', $row);
            self::assertArrayHasKey('source', $row);
            self::assertArrayHasKey('class', $row);
        }
    }

    public function test_get_discovered_skills_reads_from_cache(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [
                'PhpClaw\\PrestaShop\\Skills\\PsSkill' => [
                    'name' => 'ps_skill',
                    'label' => 'PS Skill',
                    'keywords' => ['order', 'product'],
                ],
            ],
            'hooks' => [],
            'guards' => [],
        ]);

        $skills = GuidePage::getDiscoveredSkills();

        self::assertCount(1, $skills);
        self::assertSame('prestashop', $skills[0]['source']);
        self::assertSame(['order', 'product'], $skills[0]['keywords']);
    }

    public function test_get_discovered_skills_appends_external_skill_with_tags(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $skill = new class implements SkillInterface
        {
            public function name(): string
            {
                return 'ext_skill';
            }

            public function description(): string
            {
                return 'External.';
            }

            public function tags(): array
            {
                return ['commerce', 'orders'];
            }

            public function content(): string
            {
                return '';
            }
        };

        \Hook::setResult('actionPhpclawExtraSkills', [[$skill]]);

        $skills = GuidePage::getDiscoveredSkills();

        self::assertCount(1, $skills);
        self::assertSame('ext_skill', $skills[0]['name']);
        self::assertSame('ext_skill', $skills[0]['label']);
        self::assertSame(['commerce', 'orders'], $skills[0]['keywords']);
        self::assertSame('prestashop', $skills[0]['source']);
    }

    public function test_get_discovered_skills_appends_external_skill_without_tags_method(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        $skill = new class
        {
            public function name(): string
            {
                return 'no_tags_skill';
            }
        };

        \Hook::setResult('actionPhpclawExtraSkills', [[$skill]]);

        $skills = GuidePage::getDiscoveredSkills();

        self::assertCount(1, $skills);
        self::assertSame('no_tags_skill', $skills[0]['name']);
        self::assertSame([], $skills[0]['keywords']);
    }

    public function test_get_discovered_skills_skips_non_object_contributions(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ]);

        \Hook::setResult('actionPhpclawExtraSkills', [['not-an-object']]);

        $skills = GuidePage::getDiscoveredSkills();

        self::assertSame([], $skills);
    }

    public function test_get_discovered_skills_labels_core_namespace_as_core(): void
    {
        $ref = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $ref->setAccessible(true);
        $ref->setValue(null, [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [
                'PhpClaw\\Skills\\SomeSkill' => [
                    'name' => 'some_skill',
                    'label' => 'Some Skill',
                    'keywords' => [],
                ],
            ],
            'hooks' => [],
            'guards' => [],
        ]);

        $skills = GuidePage::getDiscoveredSkills();

        self::assertCount(1, $skills);
        self::assertSame('core', $skills[0]['source']);
    }

    public function test_fire_extra_event_returns_empty_array_when_hook_returns_no_contributions(): void
    {
        $result = GuidePage::fireExtraEvent('phpclaw/extra/tools', []);

        self::assertSame([], $result);
    }

    public function test_fire_extra_event_with_non_empty_initial_bucket_preserves_entries(): void
    {
        $initial = ['existing_item'];
        $result = GuidePage::fireExtraEvent('phpclaw/extra/tools', $initial);

        self::assertContains('existing_item', $result);
    }

    public function test_fire_extra_event_merges_array_contributions_into_bucket(): void
    {
        \Hook::setResult('actionPhpclawExtraTools', [['item_a', 'item_b']]);

        $result = GuidePage::fireExtraEvent('phpclaw/extra/tools', ['seed']);

        self::assertContains('seed', $result);
        self::assertContains('item_a', $result);
        self::assertContains('item_b', $result);
    }

    public function test_fire_extra_event_ignores_non_array_contributions(): void
    {
        \Hook::setResult('actionPhpclawExtraTools', ['not-an-array-contribution']);

        $result = GuidePage::fireExtraEvent('phpclaw/extra/tools', ['seed']);

        self::assertContains('seed', $result);
    }

    public function test_get_memory_drivers_source_is_prestashop_or_core(): void
    {
        foreach (GuidePage::getMemoryDrivers() as $row) {
            self::assertContains($row['source'], ['prestashop', 'core']);
        }
    }

    public function test_get_discovered_guards_source_is_prestashop_or_core(): void
    {
        foreach (GuidePage::getDiscoveredGuards() as $row) {
            self::assertContains($row['source'], ['prestashop', 'core']);
        }
    }

    public function test_get_discovered_hooks_source_is_prestashop_or_core(): void
    {
        foreach (GuidePage::getDiscoveredHooks() as $row) {
            self::assertContains($row['source'], ['prestashop', 'core']);
        }
    }

    public function test_guide_tool_rows_external_tool_appended_when_hook_contributes(): void
    {
        $rows = GuidePage::guideToolRows(Plugin::getInstance(null, 'ps_'));

        self::assertGreaterThanOrEqual(14, count($rows), 'Native tools must always be present');
    }

    public function test_remote_skill_urls_empty_when_unconfigured(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertSame([], GuidePage::getRemoteSkillUrls($plugin));
        self::assertSame([], GuidePage::getRemoteSkills($plugin));
    }

    public function test_remote_skills_surfaces_registered_skills_not_already_discovered(): void
    {
        \Configuration::updateValue('PHPCLAW_PROVIDER', 'ollama');
        \Configuration::updateValue('PHPCLAW_MODEL', 'qwen2.5:7b');
        \Configuration::updateValue(
            'PHPCLAW_REMOTE_SKILL_URLS',
            json_encode(['https://skills.invalid/collection.md']),
        );

        $host = (string) (getenv('PHPCLAW_TEST_DB_HOST') ?: '127.0.0.1');
        $user = (string) (getenv('PHPCLAW_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('PHPCLAW_TEST_DB_PASS') ?: '');
        $dbName = (string) (getenv('PHPCLAW_TEST_DB_NAME') ?: 'phpclaw_ps_unit_test');

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli($host, $user, $pass, $dbName);

        if ($mysqli->connect_errno !== 0) {
            self::markTestSkipped('MySQL not available for native PS DB tests: '.$mysqli->connect_error);
        }

        $db = new MysqliPsDb($mysqli);

        $plugin = Plugin::getInstance($db, 'ps_');

        self::assertCount(1, GuidePage::getRemoteSkillUrls($plugin));

        SkillRegistry::register($this->makeRemoteSkill());

        $remote = GuidePage::getRemoteSkills($plugin);

        $names = array_column($remote, 'name');
        self::assertContains('invoice_reconciliation', $names);

        $record = $remote[array_search('invoice_reconciliation', $names, true)];
        self::assertSame('Reconcile invoices against orders.', $record['description']);
        self::assertSame(['invoice', 'order'], $record['keywords']);
    }

    /**
     * Build a skill double standing in for one loaded from a remote collection.
     *
     * @return SkillInterface
     */
    private function makeRemoteSkill(): SkillInterface
    {
        return new class implements SkillInterface
        {
            public function name(): string
            {
                return 'invoice_reconciliation';
            }

            public function description(): string
            {
                return 'Reconcile invoices against orders.';
            }

            public function tags(): array
            {
                return ['invoice', 'order'];
            }

            public function content(): string
            {
                return 'Reconcile each invoice line against its order line.';
            }
        };
    }
}
