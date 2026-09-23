<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Registry;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\MessageLengthGuard;
use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Magento\Memory\ResourceMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\HttpTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PhpClawRegistrarTest extends TestCase
{
    private ResourceMemory&MockObject $resourceMemory;

    private ManagerInterface&MockObject $eventManager;

    private DataObjectFactory&MockObject $dataObjectFactory;

    protected function setUp(): void
    {
        $this->resourceMemory = $this->createMock(ResourceMemory::class);
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->dataObjectFactory = $this->createMock(DataObjectFactory::class);
        $this->dataObjectFactory
            ->method('create')
            ->willReturnCallback(static fn (array $data = []) => new DataObject($data));
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        ProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        ProviderRegistry::reset();
    }

    private function makeRegistrar(array $guards = [], array $hooks = []): PhpClawRegistrar
    {
        return new PhpClawRegistrar($this->resourceMemory, $this->eventManager, $this->dataObjectFactory, $guards, $hooks);
    }

    public function test_constructor_registers_resource_memory_driver(): void
    {
        $this->makeRegistrar();

        self::assertTrue(MemoryRegistry::has('resource'));
    }

    public function test_resource_driver_factory_returns_injected_instance(): void
    {
        $this->makeRegistrar();

        $resolved = MemoryRegistry::build('resource');

        self::assertSame($this->resourceMemory, $resolved);
    }

    public function test_constructor_registers_default_guards(): void
    {
        self::assertSame(0, GuardRegistry::count());

        $this->makeRegistrar();

        self::assertGreaterThan(0, GuardRegistry::count());
    }

    public function test_constructor_registers_valid_di_guard_entry(): void
    {
        $guardClass = MessageLengthGuard::class;
        $countBefore = GuardRegistry::count();

        $this->makeRegistrar(guards: [['class' => $guardClass, 'priority' => 5]]);

        self::assertGreaterThan($countBefore, GuardRegistry::count());
    }

    public function test_constructor_skips_guard_entry_without_class_key(): void
    {
        $this->makeRegistrar(guards: [['priority' => 5]]);

        self::assertGreaterThan(0, GuardRegistry::count());
    }

    public function test_constructor_registers_valid_hook_entry(): void
    {
        $handler = static function (array $ctx): void {};
        $this->makeRegistrar();
        $beforeCount = HookRegistry::count('agent.after');
        HookRegistry::reset();

        $this->makeRegistrar(hooks: [['event' => 'agent.after', 'handler' => $handler, 'priority' => 10]]);

        self::assertGreaterThan($beforeCount, HookRegistry::count('agent.after'));
    }

    public function test_constructor_skips_hook_entry_without_event_key(): void
    {
        $handler = static function (array $ctx): void {};

        $this->makeRegistrar(hooks: [['handler' => $handler]]);

        self::assertSame(0, HookRegistry::count('some.unknown.event'));
    }

    public function test_constructor_skips_hook_entry_with_empty_event(): void
    {
        $handler = static function (array $ctx): void {};

        $this->makeRegistrar(hooks: [['event' => '', 'handler' => $handler]]);

        self::assertSame(0, HookRegistry::count(''));
    }

    public function test_boot_uses_activate_defaults_and_config_has_no_per_skill_toggle(): void
    {
        self::assertFalse(
            method_exists(Config::class, 'getSkillsEnabled'),
            'Skills are enabled by activation, not by a per-skill Config toggle. A getSkillsEnabled() '
            .'on Config would reintroduce a second source of truth for which skills are on.',
        );

        $this->makeRegistrar();
    }

    public function test_boot_activates_the_default_skill_catalogue(): void
    {
        SkillRegistry::reset();
        $this->makeRegistrar();

        self::assertSame(
            0,
            SkillRegistry::count(),
            'no #[Skill] classes are discoverable in this suite, so activateDefaults() registers none',
        );
    }

    public function test_boot_calls_bootstrap_boot_when_class_exists(): void
    {
        self::assertTrue(class_exists(Bootstrap::class));

        $this->makeRegistrar();

        self::assertTrue(class_exists(Bootstrap::class));
    }

    public function test_constructor_registers_multiple_hooks(): void
    {
        $noop = static function (array $ctx): void {};

        $this->makeRegistrar(hooks: [
            ['event' => 'agent.before', 'handler' => $noop],
            ['event' => 'agent.after',  'handler' => $noop],
        ]);

        self::assertGreaterThanOrEqual(1, HookRegistry::count('agent.before'));
        self::assertGreaterThanOrEqual(1, HookRegistry::count('agent.after'));
    }

    public function test_get_extra_tools_returns_empty_array_when_no_observers(): void
    {
        $registrar = $this->makeRegistrar();

        self::assertSame([], $registrar->getExtraTools());
    }

    public function test_boot_dispatches_six_extra_events(): void
    {
        $expectedEvents = [
            'phpclaw_extra_tools',
            'phpclaw_extra_skills',
            'phpclaw_extra_guards',
            'phpclaw_extra_hooks',
            'phpclaw_extra_memory',
            'phpclaw_extra_providers',
        ];

        $dispatched = [];

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName) use (&$dispatched): void {
                $dispatched[] = $eventName;
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        foreach ($expectedEvents as $event) {
            self::assertContains(
                $event,
                $dispatched,
                "Expected event '{$event}' to be dispatched during boot."
            );
        }

        $extraDispatched = array_filter(
            $dispatched,
            static fn (string $e): bool => str_starts_with($e, 'phpclaw_extra_'),
        );
        self::assertCount(6, array_values($extraDispatched));
    }

    public function test_extra_tools_observer_result_returned_by_get_extra_tools(): void
    {
        $fakeTool = $this->createMock(ToolInterface::class);
        $fakeTool->method('name')->willReturn('observer_tool');

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args) use ($fakeTool): void {
                if ($eventName === 'phpclaw_extra_tools') {
                    $transport = $args['transport'];
                    $tools = (array) $transport->getData('tools');
                    $tools[] = $fakeTool;
                    $transport->setData('tools', $tools);
                }
            });

        $registrar = new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertContains($fakeTool, $registrar->getExtraTools());
    }

    public function test_extra_memory_observer_result_registered_in_memory_registry(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_memory') {
                    $transport = $args['transport'];
                    $drivers = (array) $transport->getData('drivers');
                    $drivers['b11_ut'] = static fn () => new ArrayMemory;
                    $transport->setData('drivers', $drivers);
                }
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertTrue(MemoryRegistry::has('b11_ut'));
    }

    public function test_extra_hooks_observer_result_registered_in_hook_registry(): void
    {
        $called = false;
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args) use (&$called): void {
                if ($eventName === 'phpclaw_extra_hooks') {
                    $transport = $args['transport'];
                    $hooks = (array) $transport->getData('hooks');
                    $hooks[] = [
                        'event' => 'agent.before',
                        'handler' => static function (array $ctx) use (&$called): void {
                            $called = true;
                        },
                        'priority' => 10,
                    ];
                    $transport->setData('hooks', $hooks);
                }
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertGreaterThanOrEqual(1, HookRegistry::count('agent.before'));
    }

    public function test_dispatch_extra_tools_string_class_name_is_instantiated(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_tools') {
                    $transport = $args['transport'];
                    $tools = (array) $transport->getData('tools');
                    $tools[] = HttpTool::class;
                    $transport->setData('tools', $tools);
                }
            });

        $registrar = new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        $names = array_map(static fn ($t) => $t->name(), $registrar->getExtraTools());
        self::assertContains('http_request', $names);
    }

    public function test_dispatch_extra_guards_valid_class_registers_guard(): void
    {
        $countBefore = GuardRegistry::count();

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_guards') {
                    $transport = $args['transport'];
                    $guards = (array) $transport->getData('guards');
                    $guards[] = ['class' => MessageLengthGuard::class, 'priority' => 5];
                    $transport->setData('guards', $guards);
                }
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertGreaterThan($countBefore, GuardRegistry::count());
    }

    public function test_dispatch_extra_guards_invalid_entry_skipped(): void
    {
        GuardRegistry::reset();
        $this->makeRegistrar();
        $baseline = GuardRegistry::count();
        self::assertGreaterThan(0, $baseline);

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_guards') {
                    $transport = $args['transport'];
                    $guards = (array) $transport->getData('guards');
                    $guards[] = 'not-an-array';
                    $guards[] = ['priority' => 5];
                    $guards[] = ['class' => 'NonExistentClass99999'];
                    $transport->setData('guards', $guards);
                }
            });

        GuardRegistry::reset();
        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertSame(
            $baseline,
            GuardRegistry::count(),
            'a non-array entry, a missing class key and an unloadable class must all be skipped',
        );
    }

    public function test_dispatch_extra_hooks_non_closure_callable_wrapped(): void
    {
        $invoked = false;
        $callable = static function (array $ctx) use (&$invoked): void {
            $invoked = true;
        };

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args) use ($callable): void {
                if ($eventName === 'phpclaw_extra_hooks') {
                    $transport = $args['transport'];
                    $hooks = (array) $transport->getData('hooks');
                    $hooks[] = ['event' => 'agent.after', 'handler' => $callable, 'priority' => 10];
                    $transport->setData('hooks', $hooks);
                }
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertGreaterThanOrEqual(1, HookRegistry::count('agent.after'));
    }

    public function test_dispatch_extra_hooks_non_callable_handler_skipped(): void
    {
        HookRegistry::reset();
        $this->makeRegistrar();
        $baseline = HookRegistry::count('agent.after');

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_hooks') {
                    $transport = $args['transport'];
                    $hooks = (array) $transport->getData('hooks');
                    $hooks[] = ['event' => 'agent.after', 'handler' => 'not-a-callable-string-x99'];
                    $transport->setData('hooks', $hooks);
                }
            });

        HookRegistry::reset();
        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertSame(
            $baseline,
            HookRegistry::count('agent.after'),
            'a handler that is not callable must never reach the hook registry',
        );
    }

    public function test_dispatch_extra_hooks_hook_interface_instance_registered(): void
    {
        $hook = new class implements HookInterface
        {
            public function handle(array $context): void {}
        };

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args) use ($hook): void {
                if ($eventName === 'phpclaw_extra_hooks') {
                    $transport = $args['transport'];
                    $hooks = (array) $transport->getData('hooks');
                    $hooks[] = ['event' => 'agent.iteration', 'handler' => $hook, 'priority' => 10];
                    $transport->setData('hooks', $hooks);
                }
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertGreaterThanOrEqual(1, HookRegistry::count('agent.iteration'));
    }

    public function test_dispatch_extra_memory_invalid_slug_skipped(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_memory') {
                    $transport = $args['transport'];
                    $drivers = (array) $transport->getData('drivers');
                    $drivers[0] = static fn () => new ArrayMemory;
                    $drivers[''] = static fn () => new ArrayMemory;
                    $drivers['bad_val'] = 42;
                    $transport->setData('drivers', $drivers);
                }
            });

        $registrar = new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertInstanceOf(PhpClawRegistrar::class, $registrar);
        self::assertFalse(MemoryRegistry::has(''));
    }

    public function test_dispatch_extra_providers_valid_class_registers(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_providers') {
                    $transport = $args['transport'];
                    $providers = (array) $transport->getData('providers');
                    $providers['anthropic_extra'] = ['class' => AnthropicProvider::class];
                    $transport->setData('providers', $providers);
                }
            });

        ProviderRegistry::reset();

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertTrue(ProviderRegistry::has('anthropic_extra'));
    }

    public function test_dispatch_extra_providers_invalid_entries_skipped(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args): void {
                if ($eventName === 'phpclaw_extra_providers') {
                    $transport = $args['transport'];
                    $providers = (array) $transport->getData('providers');
                    $providers[0] = ['class' => AnthropicProvider::class];
                    $providers[''] = ['class' => AnthropicProvider::class];
                    $providers['no_class'] = ['other' => 'value'];
                    $providers['bad_class'] = ['class' => 'NonExistentProviderXYZ'];
                    $transport->setData('providers', $providers);
                }
            });

        ProviderRegistry::reset();

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        foreach (['0', '', 'no_class', 'bad_class'] as $slug) {
            self::assertFalse(ProviderRegistry::has($slug), "slug '{$slug}' must be skipped");
        }
    }

    public function test_dispatch_extra_skills_skill_interface_instance_registered(): void
    {
        $fakeSkill = $this->createMock(SkillInterface::class);
        $fakeSkill->method('name')->willReturn('test_skill_ut');

        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')
            ->willReturnCallback(static function (string $eventName, array $args) use ($fakeSkill): void {
                if ($eventName === 'phpclaw_extra_skills') {
                    $transport = $args['transport'];
                    $skills = (array) $transport->getData('skills');
                    $skills[] = $fakeSkill;
                    $transport->setData('skills', $skills);
                }
            });

        new PhpClawRegistrar($this->resourceMemory, $eventManager, $this->dataObjectFactory);

        self::assertTrue(SkillRegistry::has('test_skill_ut'));
    }
}
