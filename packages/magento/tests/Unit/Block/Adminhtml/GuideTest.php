<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Magento\Block\Adminhtml\Guide;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Magento\Tools\DatabaseTool;
use PhpClaw\Magento\Tools\LogTool;
use PhpClaw\Magento\Tools\MagentoCacheTool;
use PhpClaw\Magento\Tools\MagentoCategoryTool;
use PhpClaw\Magento\Tools\MagentoCustomerTool;
use PhpClaw\Magento\Tools\MagentoInventoryTool;
use PhpClaw\Magento\Tools\MagentoOrderTool;
use PhpClaw\Magento\Tools\MagentoProductTool;
use PhpClaw\Magento\Tools\MagentoReportTool;
use PhpClaw\Magento\Tools\MagentoStoreTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GuideTest extends TestCase
{
    private Template\Context&MockObject $context;

    private PhpClawRegistrar&MockObject $registrar;

    private PhpClawFactoryInterface&MockObject $phpClawFactory;

    private Config&MockObject $config;

    private IdentityResolver&MockObject $identity;

    private Guide $block;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Template\Context::class);
        $this->registrar = $this->getMockBuilder(PhpClawRegistrar::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->registrar->method('getExtraTools')->willReturn([]);
        $this->registrar->method('getExternalSkills')->willReturn([]);
        $this->registrar->method('getExternalGuards')->willReturn([]);
        $this->registrar->method('getExternalMemoryDrivers')->willReturn([]);
        $this->registrar->method('getExternalHooks')->willReturn([]);

        $this->phpClawFactory = $this->createMock(PhpClawFactoryInterface::class);
        $this->config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->config->method('getRemoteSkillUrls')->willReturn([]);

        $this->identity = $this->createMock(IdentityResolver::class);
        $this->identity->method('manageAll')->willReturn(true);

        $this->block = new Guide($this->context, $this->registrar, $this->phpClawFactory, $this->config, $this->identity);
    }

    protected function tearDown(): void
    {
        DiscoveryCache::reset();
    }

    private function seedDiscoveryCache(array $data): void
    {
        $property = new \ReflectionProperty(DiscoveryCache::class, 'memoryCache');
        $property->setAccessible(true);
        $property->setValue(null, $data);
    }

    public function test_get_capability_records_returns_four_buckets(): void
    {
        self::assertSame(
            ['memory', 'skills', 'guards', 'hooks'],
            array_keys($this->block->getCapabilityRecords()),
        );
    }

    public function test_get_capability_records_guards_have_expected_keys(): void
    {
        $records = $this->block->getCapabilityRecords();

        self::assertNotEmpty($records['guards']);
        $guard = $records['guards'][0];
        self::assertArrayHasKey('name', $guard);
        self::assertArrayHasKey('priority', $guard);
        self::assertArrayHasKey('enabled_by_default', $guard);
        self::assertArrayHasKey('source', $guard);
        self::assertArrayHasKey('class', $guard);
        self::assertContains($guard['source'], ['core', 'magento']);
    }

    public function test_get_all_tabs_contains_required_tabs(): void
    {
        $tabs = $this->block->getAllTabs();

        self::assertContains('guide-quickstart', $tabs);
        self::assertContains('guide-tools', $tabs);
        self::assertContains('guide-providers', $tabs);
        self::assertContains('guide-memory', $tabs);
        self::assertContains('guide-guards', $tabs);
        self::assertContains('guide-hooks', $tabs);
        self::assertContains('guide-skills', $tabs);
        self::assertContains('guide-rest', $tabs);
        self::assertContains('guide-cli', $tabs);
        self::assertContains('guide-privacy', $tabs);
    }

    public function test_get_all_tabs_first_entry_is_quickstart(): void
    {
        $tabs = $this->block->getAllTabs();

        self::assertSame('guide-quickstart', $tabs[0]);
    }

    public function test_get_active_tab_defaults_to_quickstart_when_no_request_param(): void
    {
        $activeTab = $this->block->getActiveTab();

        self::assertSame('guide-quickstart', $activeTab);
    }

    public function test_get_active_tab_returns_valid_requested_tab(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('tab', '')->willReturn('guide-providers');

        $this->block->setTestRequest($request);

        self::assertSame('guide-providers', $this->block->getActiveTab());
    }

    public function test_get_active_tab_falls_back_to_quickstart_for_unknown_tab(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('tab', '')->willReturn('guide-nonexistent');

        $this->block->setTestRequest($request);

        self::assertSame('guide-quickstart', $this->block->getActiveTab());
    }

    public function test_get_active_tab_falls_back_to_quickstart_for_empty_string(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('tab', '')->willReturn('');

        $this->block->setTestRequest($request);

        self::assertSame('guide-quickstart', $this->block->getActiveTab());
    }

    public function test_get_active_tab_accepts_every_known_tab(): void
    {
        $tabs = $this->block->getAllTabs();

        self::assertSame([
            'guide-quickstart', 'guide-tools', 'guide-providers', 'guide-memory',
            'guide-guards', 'guide-hooks', 'guide-skills', 'guide-rest',
            'guide-cli', 'guide-privacy',
        ], $tabs);

        foreach ($tabs as $tabId) {
            $request = $this->createMock(RequestInterface::class);
            $request->method('getParam')->with('tab', '')->willReturn($tabId);

            $this->block->setTestRequest($request);

            self::assertSame($tabId, $this->block->getActiveTab(), "Expected active tab '{$tabId}'");
        }
    }

    public function test_get_capability_records_maps_memory_entry_from_cache(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'skills' => [], 'guards' => [], 'hooks' => [],
            'memory' => [
                'PhpClaw\\Magento\\Memory\\ResourceMemory' => ['driver' => 'resource', 'label' => 'Resource Memory'],
            ],
        ]);

        $records = $this->block->getCapabilityRecords();

        self::assertCount(1, $records['memory']);
        $entry = $records['memory'][0];
        self::assertSame('resource', $entry['driver']);
        self::assertSame('Resource Memory', $entry['label']);
        self::assertSame('magento', $entry['source']);
        self::assertSame('PhpClaw\\Magento\\Memory\\ResourceMemory', $entry['class']);
    }

    public function test_get_capability_records_maps_skill_entry_from_cache(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'memory' => [], 'guards' => [], 'hooks' => [],
            'skills' => [
                'PhpClaw\\Skills\\DemoSkill' => ['name' => 'demo', 'label' => 'Demo Skill', 'keywords' => ['hello', 'world']],
            ],
        ]);

        $records = $this->block->getCapabilityRecords();

        self::assertCount(1, $records['skills']);
        $entry = $records['skills'][0];
        self::assertSame('demo', $entry['name']);
        self::assertSame('Demo Skill', $entry['label']);
        self::assertSame(['hello', 'world'], $entry['keywords']);
        self::assertSame('core', $entry['source']);
        self::assertSame('PhpClaw\\Skills\\DemoSkill', $entry['class']);
    }

    public function test_get_capability_records_maps_hook_listener_from_cache(): void
    {
        $event = LifecycleEvent::ToolAfter->value;
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'memory' => [], 'skills' => [], 'guards' => [],
            'hooks' => [
                'PhpClaw\\Magento\\Events\\HookEventBridge' => [
                    ['event' => $event, 'name' => 'bridge', 'priority' => 10, 'enabledByDefault' => true],
                ],
            ],
        ]);

        $records = $this->block->getCapabilityRecords();

        self::assertCount(1, $records['hooks']);
        $entry = $records['hooks'][0];
        self::assertSame($event, $entry['event']);
        self::assertSame('bridge', $entry['name']);
        self::assertSame(10, $entry['priority']);
        self::assertTrue($entry['enabled_by_default']);
        self::assertSame('magento', $entry['source']);
        self::assertSame('PhpClaw\\Magento\\Events\\HookEventBridge', $entry['class']);
    }

    public function test_source_label_is_magento_only_for_magento_namespaced_classes(): void
    {
        $records = $this->block->getCapabilityRecords();
        $checked = 0;

        foreach (['memory', 'skills', 'guards', 'hooks'] as $category) {
            foreach ($records[$category] as $record) {
                $checked++;
                self::assertSame(
                    str_starts_with($record['class'], 'PhpClaw\\Magento\\') ? 'magento' : 'core',
                    $record['source'],
                    $record['class'],
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'no capability records were discovered to check');
    }

    public function test_get_providers_returns_array(): void
    {
        $providers = $this->block->getProviders();

        self::assertIsArray($providers);
        self::assertGreaterThanOrEqual(8, count($providers));
    }

    public function test_get_providers_rows_have_required_keys(): void
    {
        $providers = $this->block->getProviders();

        self::assertNotEmpty($providers);
        $row = $providers[0];
        self::assertArrayHasKey('name', $row);
        self::assertArrayHasKey('key', $row);
        self::assertArrayHasKey('models', $row);
        self::assertArrayHasKey('signup', $row);
        self::assertArrayHasKey('notes', $row);
    }

    public function test_get_providers_contains_anthropic(): void
    {
        $keys = array_column($this->block->getProviders(), 'key');

        self::assertContains('anthropic', $keys);
    }

    public function test_get_providers_contains_ollama(): void
    {
        $keys = array_column($this->block->getProviders(), 'key');

        self::assertContains('ollama', $keys);
    }

    public function test_get_core_utility_tools_returns_array(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [
                'PhpClaw\\Tools\\HttpTool' => ['default' => true,  'description' => 'HTTP tool'],
                'PhpClaw\\Tools\\FileReadTool' => ['default' => true,  'description' => 'File read'],
                'PhpClaw\\Tools\\FileWriteTool' => ['default' => true,  'description' => 'File write'],
                'PhpClaw\\Tools\\ShellTool' => ['default' => true,  'description' => 'Shell tool'],
                'PhpClaw\\Tools\\SomethingElse' => ['default' => false, 'description' => 'Not default'],
                'PhpClaw\\Magento\\Tools\\DatabaseTool' => ['default' => true, 'description' => 'DB tool'],
            ],
            'providers' => [], 'memory' => [], 'skills' => [], 'guards' => [], 'hooks' => [],
        ]);

        $tools = $this->block->getCoreUtilityTools();

        self::assertIsArray($tools);
        self::assertCount(4, $tools);
    }

    public function test_get_core_utility_tools_rows_have_required_keys(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [
                'PhpClaw\\Tools\\HttpTool' => ['default' => true, 'description' => 'HTTP tool'],
            ],
            'providers' => [], 'memory' => [], 'skills' => [], 'guards' => [], 'hooks' => [],
        ]);

        $tools = $this->block->getCoreUtilityTools();

        self::assertNotEmpty($tools);
        $row = $tools[0];
        self::assertArrayHasKey('tool', $row);
        self::assertArrayHasKey('status', $row);
        self::assertArrayHasKey('notes', $row);
    }

    public function test_get_core_utility_tools_excludes_non_default_and_non_core(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [
                'PhpClaw\\Tools\\HttpTool' => ['default' => true,  'description' => 'HTTP'],
                'PhpClaw\\Magento\\Tools\\DatabaseTool' => ['default' => true,  'description' => 'DB'],
                'PhpClaw\\Tools\\AnotherTool' => ['default' => false, 'description' => 'No'],
            ],
            'providers' => [], 'memory' => [], 'skills' => [], 'guards' => [], 'hooks' => [],
        ]);

        $tools = $this->block->getCoreUtilityTools();
        $toolNames = array_column($tools, 'tool');

        self::assertContains('HttpTool', $toolNames);
        self::assertNotContains('DatabaseTool', $toolNames);
        self::assertNotContains('AnotherTool', $toolNames);
    }

    private function nativeTools(): array
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $cacheTypeList = $this->createMock(TypeListInterface::class);

        return [
            new MagentoProductTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoOrderTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoCustomerTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoInventoryTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoCategoryTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoStoreTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoReportTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new MagentoCacheTool($cacheTypeList, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new DatabaseTool($resourceConnection, $this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
            new LogTool($this->createMock(IdentityResolver::class), $this->createMock(AuthorizationInterface::class)),
        ];
    }

    public function test_get_tool_rows_returns_ten_native_tools(): void
    {
        $this->phpClawFactory->method('registeredTools')->willReturn($this->nativeTools());

        $rows = $this->block->getToolRows();

        self::assertCount(10, $rows);
    }

    public function test_get_tool_rows_rows_have_required_keys(): void
    {
        $this->phpClawFactory->method('registeredTools')->willReturn($this->nativeTools());

        $rows = $this->block->getToolRows();

        self::assertNotEmpty($rows);
        $row = $rows[0];
        self::assertArrayHasKey('tool', $row);
        self::assertArrayHasKey('description', $row);
        self::assertArrayHasKey('examples', $row);
    }

    public function test_get_tool_rows_appends_external_tools(): void
    {
        $externalTool = new class
        {
            public function name(): string
            {
                return 'my_custom_tool';
            }

            public function description(): string
            {
                return 'Does something custom.';
            }
        };

        $this->phpClawFactory->method('registeredTools')->willReturn([...$this->nativeTools(), $externalTool]);

        $rows = $this->block->getToolRows();

        self::assertCount(11, $rows);
        $last = $rows[array_key_last($rows)];
        self::assertSame('my_custom_tool', $last['tool']);
        self::assertSame('Does something custom.', $last['description']);
    }

    public function test_get_capability_records_appends_external_memory_drivers(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'skills' => [], 'guards' => [], 'hooks' => [], 'memory' => [],
        ]);

        $registrar = $this->getMockBuilder(PhpClawRegistrar::class)
            ->disableOriginalConstructor()
            ->getMock();
        $registrar->method('getExtraTools')->willReturn([]);
        $registrar->method('getExternalSkills')->willReturn([]);
        $registrar->method('getExternalGuards')->willReturn([]);
        $registrar->method('getExternalMemoryDrivers')->willReturn([
            ['driver' => 'redis', 'label' => 'Redis', 'class' => 'Acme\\RedisMemory'],
        ]);
        $registrar->method('getExternalHooks')->willReturn([]);

        $block = new Guide($this->context, $registrar, $this->phpClawFactory, $this->config, $this->identity);
        $records = $block->getCapabilityRecords();

        self::assertCount(1, $records['memory']);
        $entry = $records['memory'][0];
        self::assertSame('redis', $entry['driver']);
        self::assertSame('magento', $entry['source']);
        self::assertSame('Acme\\RedisMemory', $entry['class']);
    }

    public function test_get_capability_records_appends_external_skills(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'memory' => [], 'guards' => [], 'hooks' => [], 'skills' => [],
        ]);

        $registrar = $this->getMockBuilder(PhpClawRegistrar::class)
            ->disableOriginalConstructor()
            ->getMock();
        $registrar->method('getExtraTools')->willReturn([]);
        $registrar->method('getExternalSkills')->willReturn([
            ['name' => 'faq', 'label' => 'FAQ Skill', 'keywords' => ['faq', 'help'], 'class' => 'Acme\\FaqSkill'],
        ]);
        $registrar->method('getExternalGuards')->willReturn([]);
        $registrar->method('getExternalMemoryDrivers')->willReturn([]);
        $registrar->method('getExternalHooks')->willReturn([]);

        $block = new Guide($this->context, $registrar, $this->phpClawFactory, $this->config, $this->identity);
        $records = $block->getCapabilityRecords();

        self::assertCount(1, $records['skills']);
        $entry = $records['skills'][0];
        self::assertSame('faq', $entry['name']);
        self::assertSame(['faq', 'help'], $entry['keywords']);
        self::assertSame('magento', $entry['source']);
    }

    public function test_get_capability_records_appends_external_guards(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'memory' => [], 'skills' => [], 'hooks' => [], 'guards' => [],
        ]);

        $registrar = $this->getMockBuilder(PhpClawRegistrar::class)
            ->disableOriginalConstructor()
            ->getMock();
        $registrar->method('getExtraTools')->willReturn([]);
        $registrar->method('getExternalSkills')->willReturn([]);
        $registrar->method('getExternalGuards')->willReturn([
            ['name' => 'PiiGuard', 'label' => 'PII Guard', 'priority' => 5, 'enabled_by_default' => true, 'class' => 'Acme\\PiiGuard'],
        ]);
        $registrar->method('getExternalMemoryDrivers')->willReturn([]);
        $registrar->method('getExternalHooks')->willReturn([]);

        $block = new Guide($this->context, $registrar, $this->phpClawFactory, $this->config, $this->identity);
        $records = $block->getCapabilityRecords();

        self::assertCount(1, $records['guards']);
        $entry = $records['guards'][0];
        self::assertSame('PiiGuard', $entry['name']);
        self::assertSame(5, $entry['priority']);
        self::assertTrue($entry['enabled_by_default']);
        self::assertSame('magento', $entry['source']);
    }

    public function test_get_capability_records_appends_external_hooks(): void
    {
        $this->seedDiscoveryCache([
            'tools' => [], 'providers' => [], 'memory' => [], 'skills' => [], 'guards' => [], 'hooks' => [],
        ]);

        $registrar = $this->getMockBuilder(PhpClawRegistrar::class)
            ->disableOriginalConstructor()
            ->getMock();
        $registrar->method('getExtraTools')->willReturn([]);
        $registrar->method('getExternalSkills')->willReturn([]);
        $registrar->method('getExternalGuards')->willReturn([]);
        $registrar->method('getExternalMemoryDrivers')->willReturn([]);
        $registrar->method('getExternalHooks')->willReturn([
            ['event' => 'agent.before', 'name' => 'AuditListener', 'priority' => 10, 'enabled_by_default' => true, 'class' => 'Acme\\AuditListener'],
        ]);

        $block = new Guide($this->context, $registrar, $this->phpClawFactory, $this->config, $this->identity);
        $records = $block->getCapabilityRecords();

        self::assertCount(1, $records['hooks']);
        $entry = $records['hooks'][0];
        self::assertSame('agent.before', $entry['event']);
        self::assertSame('AuditListener', $entry['name']);
        self::assertSame('magento', $entry['source']);
    }

    public function test_short_name_strips_namespace(): void
    {
        self::assertSame('MyClass', $this->block->shortName('Acme\\Foo\\MyClass'));
    }

    public function test_short_name_returns_input_when_no_backslash(): void
    {
        self::assertSame('MyClass', $this->block->shortName('MyClass'));
    }

    public function test_remote_skill_urls_empty_when_unconfigured(): void
    {
        self::assertSame([], $this->block->getRemoteSkillUrls());
        self::assertSame([], $this->block->getRemoteSkills());
    }

    public function test_remote_skill_urls_reads_from_config(): void
    {
        $url = 'https://raw.githubusercontent.com/iharnoor/html-everything/main/skills/html-everything/SKILL.md';

        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $config->method('getRemoteSkillUrls')->willReturn([$url]);

        $block = new Guide($this->context, $this->registrar, $this->phpClawFactory, $config, $this->identity);

        self::assertSame([$url], $block->getRemoteSkillUrls());
    }
}
