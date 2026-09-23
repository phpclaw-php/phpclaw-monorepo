<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Claw;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\InjectionGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Skills\PhpBestPracticesSkill;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\Http\StreamEventBridge;
use PhpClaw\Symfony\PhpClawBundle;
use PhpClaw\Symfony\Tests\Integration\IntegrationKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhpClawBundleBootTest extends KernelTestCase
{
    private array $handlersBefore = [];

    protected static function getKernelClass(): string
    {
        return IntegrationKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        GuardRegistry::reset();
        HookRegistry::reset();
        SkillRegistry::reset();
        PhpClawBundle::resetEventBridge();
        $this->handlersBefore = $this->captureHandlers();
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        SkillRegistry::reset();
        PhpClawBundle::resetEventBridge();
        self::ensureKernelShutdown();
        $this->restoreHandlers($this->handlersBefore);
        $this->handlersBefore = [];
    }

    public function test_boot_makes_the_engine_resolvable_via_the_phpclaw_helper(): void
    {
        require_once dirname(__DIR__, 2).'/src/helpers.php';
        PhpClawBundle::resetBootedContainer();

        IntegrationKernel::configure(['memory_driver' => 'file', 'store_messages' => false, 'event_bridge' => false]);

        $container = self::bootKernel()->getContainer();

        $resolved = phpclaw(fresh: true);

        $this->assertSame(
            $container->get(Claw::class),
            $resolved,
            'The standalone phpclaw() helper must resolve the same engine the bundle just booted, with no global $kernel involved.',
        );

        PhpClawBundle::resetBootedContainer();
    }

    public function test_boot_registers_stream_bridge_on_tool_events(): void
    {
        IntegrationKernel::configure(['memory_driver' => 'file', 'store_messages' => false, 'event_bridge' => false]);

        self::bootKernel();

        $this->assertSame(1, HookRegistry::count(LifecycleEvent::ToolBefore->value));
        $this->assertSame(1, HookRegistry::count(LifecycleEvent::ToolAfter->value));
    }

    public function test_boot_registers_the_same_stream_bridge_instance_the_container_shares(): void
    {
        IntegrationKernel::configure(['memory_driver' => 'file', 'store_messages' => false, 'event_bridge' => false]);

        $kernel = new IntegrationKernel('test', false);
        $kernel->boot();
        $bridge = $kernel->getContainer()->get(StreamEventBridge::class);

        $emitted = [];
        $bridge->begin(static function (string $event, array $payload) use (&$emitted): void {
            $emitted[] = $event;
        });

        HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
            'event' => LifecycleEvent::ToolAfter->value,
            'tool_name' => 'db_query',
            'tool_input' => [],
            'tool_result' => 'rows',
        ]);

        $this->assertSame(['tool_after'], $emitted, 'The event fired through HookRegistry must reach the same StreamEventBridge instance the container shares with ApiController.');
        $this->assertCount(1, $bridge->toolCalls());
    }

    public function test_a_second_boot_does_not_duplicate_stream_bridge_listeners(): void
    {
        IntegrationKernel::configure(['memory_driver' => 'file', 'store_messages' => false, 'event_bridge' => false]);

        self::bootKernel();
        self::ensureKernelShutdown();
        HookRegistry::reset();
        self::bootKernel();

        $this->assertSame(1, HookRegistry::count(LifecycleEvent::ToolBefore->value));
        $this->assertSame(1, HookRegistry::count(LifecycleEvent::ToolAfter->value));
    }

    public function test_boot_with_event_bridge_enabled_registers_hook_event_bridge(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => true,
        ]);

        self::bootKernel();

        $allEvents = LifecycleEvent::all();
        $total = 0;
        foreach ($allEvents as $event) {
            $total += HookRegistry::count($event);
        }

        $this->assertGreaterThan(0, $total, 'Event bridge must add at least one listener when event_bridge=true.');
    }

    public function test_boot_with_event_bridge_disabled_does_not_register_hook_event_bridge(): void
    {
        PhpClawBundle::resetEventBridge();

        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
        ]);

        self::bootKernel();

        $agentAfterCount = HookRegistry::count(LifecycleEvent::AgentAfter->value);

        $this->assertSame(0, $agentAfterCount, 'Event bridge must not register when event_bridge=false.');
    }

    public function test_boot_registers_configured_guard_into_guard_registry(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'guards' => [
                ['class' => InjectionGuard::class, 'priority' => 10],
            ],
        ]);

        self::bootKernel();

        $this->assertTrue(
            GuardRegistry::hasClass(InjectionGuard::class),
            'InjectionGuard must be registered when declared in guards config.',
        );
    }

    public function test_boot_skips_nonexistent_guard_class(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'guards' => [
                ['class' => 'NonExistent\\Guard\\ClassName', 'priority' => 10],
            ],
        ]);

        self::bootKernel();

        $this->assertFalse(
            GuardRegistry::hasClass('NonExistent\\Guard\\ClassName'),
        );
    }

    public function test_boot_registers_configured_hook_into_hook_registry(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'hooks' => [
                ['event' => 'agent.after', 'handler' => 'strlen', 'priority' => 5],
            ],
        ]);

        self::bootKernel();

        $this->assertGreaterThanOrEqual(1, HookRegistry::count('agent.after'));
    }

    public function test_boot_registers_array_skill_into_skill_registry(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'skills' => [
                [
                    'name' => 'boot_test_skill',
                    'description' => 'A skill registered via boot test.',
                    'tags' => ['test'],
                    'content' => 'Test skill content.',
                ],
            ],
        ]);

        self::bootKernel();

        $this->assertTrue(SkillRegistry::has('boot_test_skill'), 'ArraySkill must be registered from skills config.');
    }

    public function test_boot_registers_class_skill_into_skill_registry(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'skills' => [
                ['class' => PhpBestPracticesSkill::class],
            ],
        ]);

        self::bootKernel();

        $this->assertTrue(
            SkillRegistry::has('php_best_practices'),
            'PhpBestPracticesSkill must be registered when declared via class config.',
        );
    }

    public function test_boot_skips_invalid_skill_entries(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'skills' => [
                'not_an_array',
                ['class' => 'NonExistent\\Skill\\Class'],
            ],
        ]);

        self::bootKernel();

        $names = SkillRegistry::names();
        $this->assertNotContains('not_an_array', $names);
        $this->assertContains('php_best_practices', $names, 'Discovered skills stay always-on despite invalid config entries.');
    }

    public function test_boot_with_cloud_key_and_store_messages_on_still_wires_memory(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => true,
            'cloud_key' => '',
            'cloud_disable' => [],
            'event_bridge' => false,
        ]);

        $container = self::bootKernel()->getContainer();

        $this->assertTrue($container->has(MemoryInterface::class));
        $this->assertTrue($container->get(Claw::class)->storeMessages());
    }

    public function test_boot_with_array_memory_driver_registers_memory(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'array',
            'store_messages' => false,
            'event_bridge' => false,
        ]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $this->assertTrue($container->has(MemoryInterface::class));
    }

    public function test_boot_refuses_a_non_ownership_aware_driver_with_the_api_enabled(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'array',
            'store_messages' => false,
            'event_bridge' => false,
            'api' => ['enabled' => true],
        ]);

        $kernel = new IntegrationKernel('test', false);

        try {
            $this->expectException(PhpClawException::class);
            $this->expectExceptionMessageMatches("/memory_driver 'array'/");
            $kernel->boot();
        } finally {
            IntegrationKernel::configure(['memory_driver' => 'file', 'store_messages' => false, 'event_bridge' => false]);
        }
    }

    public function test_boot_allows_a_non_ownership_aware_driver_when_the_api_is_disabled(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'array',
            'store_messages' => false,
            'event_bridge' => false,
            'api' => ['enabled' => false],
        ]);

        $container = self::bootKernel()->getContainer();

        $this->assertTrue($container->has(MemoryInterface::class));
    }

    public function test_boot_with_unknown_memory_driver_still_wires_a_memory_service(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'nonexistent_driver',
            'store_messages' => false,
            'event_bridge' => false,
        ]);

        $container = self::bootKernel()->getContainer();

        $this->assertTrue(
            $container->has(MemoryInterface::class),
            'an unknown driver name must fall back, never leave the container without memory',
        );
        $this->assertInstanceOf(MemoryInterface::class, $container->get(MemoryInterface::class));
    }

    public function test_boot_skips_hooks_with_empty_event_name(): void
    {
        IntegrationKernel::configure([
            'memory_driver' => 'file',
            'store_messages' => false,
            'event_bridge' => false,
            'hooks' => [
                ['event' => '', 'handler' => 'strlen'],
            ],
        ]);

        self::bootKernel();

        $this->assertSame(0, HookRegistry::count(''), 'a hook with no event name must never register');
    }

    private function captureHandlers(): array
    {
        $handlers = [];
        $limit = 30;

        while ($limit-- > 0) {
            $handler = set_exception_handler(static function (\Throwable $e): void {
                throw $e;
            });
            restore_exception_handler();

            if ($handler === null) {
                restore_exception_handler();
                break;
            }

            restore_exception_handler();
            $handlers[] = $handler;
        }

        foreach (array_reverse($handlers) as $h) {
            set_exception_handler($h);
        }

        return $handlers;
    }

    private function restoreHandlers(array $target): void
    {
        $current = $this->captureHandlers();

        if (count($current) === count($target)) {
            return;
        }

        foreach ($current as $_) {
            restore_exception_handler();
        }

        foreach (array_reverse($target) as $h) {
            set_exception_handler($h);
        }
    }
}
