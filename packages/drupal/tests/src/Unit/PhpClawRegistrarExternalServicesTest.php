<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class PhpClawRegistrarExternalServicesTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        ProviderRegistry::reset();

        $this->database = $this->createMock(Connection::class);
    }

    private function makeContainer(
        array $parameters = [],
        array $services = [],
    ): DrupalContainerInterface {
        $container = $this->createMock(DrupalContainerInterface::class);

        $container->method('hasParameter')->willReturnCallback(
            static fn (string $name): bool => array_key_exists($name, $parameters)
        );
        $container->method('getParameter')->willReturnCallback(
            static fn (string $name): mixed => $parameters[$name] ?? []
        );
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $services[$id] ?? null
        );

        return $container;
    }

    private function buildRegistrar(
        DrupalContainerInterface $container,
        array $config = [],
        ?LoggerChannelInterface $logger = null,
    ): PhpClawRegistrar {
        $defaults = [
            'guards' => [], 'hooks' => [], 'skills' => [],
            'guards_enabled' => [], 'hooks_enabled' => [],
            'memory_config' => [],
        ];
        $merged = array_merge($defaults, $config);

        $immutable = $this->createMock(ImmutableConfig::class);
        $immutable->method('get')->willReturnCallback(
            static fn (string $key = ''): mixed => $key === '' ? $merged : ($merged[$key] ?? null)
        );

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($immutable);

        $cache = $this->createMock(CacheBackendInterface::class);
        $time = $this->createMock(TimeInterface::class);

        $registrar = new PhpClawRegistrar($factory, $this->database, $cache, $time, $container, null, $logger);
        $registrar->boot();

        return $registrar;
    }

    public function test_get_tagged_ids_true_branch_when_parameter_exists(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('tagged-tool');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_tool' => ['svc.tagged_tool']],
            services: ['svc.tagged_tool' => $tool],
        );

        $registrar = $this->buildRegistrar($container);

        $this->assertContains($tool, $registrar->getExternalTools());
    }

    public function test_boot_external_tools_valid_tool_is_collected(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('ext-tool');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_tool' => ['svc.ext_tool']],
            services: ['svc.ext_tool' => $tool],
        );

        $registrar = $this->buildRegistrar($container);

        $this->assertCount(1, $registrar->getExternalTools());
        $this->assertSame($tool, $registrar->getExternalTools()[0]);
    }

    public function test_boot_external_tools_multiple_tools_all_collected(): void
    {
        $toolA = $this->createMock(ToolInterface::class);
        $toolA->method('name')->willReturn('tool-a');
        $toolB = $this->createMock(ToolInterface::class);
        $toolB->method('name')->willReturn('tool-b');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_tool' => ['svc.a', 'svc.b']],
            services: ['svc.a' => $toolA, 'svc.b' => $toolB],
        );

        $registrar = $this->buildRegistrar($container);

        $this->assertCount(2, $registrar->getExternalTools());
    }

    public function test_boot_external_tools_no_parameter_returns_empty_external_tools(): void
    {
        $registrar = $this->buildRegistrar($this->makeContainer());

        $this->assertSame([], $registrar->getExternalTools());
    }

    public function test_boot_external_providers_valid_provider_registered(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('ext-provider-'.uniqid());

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_provider' => ['svc.ext_provider']],
            services: ['svc.ext_provider' => $provider],
        );

        $this->buildRegistrar($container);

        $this->assertTrue(ProviderRegistry::has($provider->name()));
    }

    public function test_boot_external_providers_duplicate_slug_not_re_registered(): void
    {
        $slug = 'dup-provider-'.uniqid();
        $original = $this->createMock(ProviderInterface::class);
        $original->method('name')->willReturn($slug);

        ProviderRegistry::register($slug, static fn () => $original);

        $duplicate = $this->createMock(ProviderInterface::class);
        $duplicate->method('name')->willReturn($slug);

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_provider' => ['svc.dup']],
            services: ['svc.dup' => $duplicate],
        );

        $this->buildRegistrar($container);

        $this->assertTrue(ProviderRegistry::has($slug));
    }

    public function test_boot_external_memory_drivers_valid_driver_increases_count(): void
    {
        $driver = $this->createMock(MemoryInterface::class);

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_memory_driver' => ['svc.ext_mem']],
            services: ['svc.ext_mem' => $driver],
        );

        $this->buildRegistrar($container);

        $this->assertGreaterThan(3, count(MemoryRegistry::drivers()));
    }

    public function test_boot_external_guards_valid_guard_increases_count(): void
    {
        $defaultContainer = $this->makeContainer();
        $this->buildRegistrar($defaultContainer);
        $defaultCount = GuardRegistry::count();
        GuardRegistry::reset();

        $guard = $this->createMock(GuardInterface::class);

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_guard' => ['svc.ext_guard']],
            services: ['svc.ext_guard' => $guard],
        );

        $this->buildRegistrar($container);

        $this->assertSame($defaultCount + 1, GuardRegistry::count());
    }

    public function test_boot_external_skills_valid_skill_registered(): void
    {
        $skill = $this->createMock(SkillInterface::class);
        $skill->method('name')->willReturn('ext-skill');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_skill' => ['svc.ext_skill']],
            services: ['svc.ext_skill' => $skill],
        );

        $this->buildRegistrar($container);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('ext-skill', $all[0]->name());
    }

    public function test_boot_external_hook_listeners_valid_listener_registered(): void
    {
        $listener = new class
        {
            public function handle(array $ctx): void {}
        };

        $container = $this->makeContainer(
            parameters: [
                'phpclaw.tagged_services.hook_listeners' => [
                    ['service_id' => 'svc.listener', 'event' => 'agent.after', 'priority' => 5],
                ],
            ],
            services: ['svc.listener' => $listener],
        );

        $this->buildRegistrar($container);

        $this->assertSame(1, HookRegistry::count('agent.after'));
    }

    public function test_boot_external_hook_listeners_default_priority_used_when_absent(): void
    {
        $listener = new class
        {
            public function handle(array $ctx): void {}
        };

        $container = $this->makeContainer(
            parameters: [
                'phpclaw.tagged_services.hook_listeners' => [
                    ['service_id' => 'svc.listener', 'event' => 'agent.before'],
                ],
            ],
            services: ['svc.listener' => $listener],
        );

        $this->buildRegistrar($container);

        $this->assertSame(1, HookRegistry::count('agent.before'));
    }

    public function test_boot_external_hook_listeners_missing_service_id_is_skipped(): void
    {
        $container = $this->makeContainer(
            parameters: [
                'phpclaw.tagged_services.hook_listeners' => [
                    ['event' => 'agent.before'],
                ],
            ],
        );

        $this->buildRegistrar($container);

        $this->assertSame(0, HookRegistry::count('agent.before'));
    }

    public function test_boot_external_hook_listeners_missing_event_is_skipped(): void
    {
        $listener = new class
        {
            public function handle(array $ctx): void {}
        };

        $container = $this->makeContainer(
            parameters: [
                'phpclaw.tagged_services.hook_listeners' => [
                    ['service_id' => 'svc.listener'],
                ],
            ],
            services: ['svc.listener' => $listener],
        );

        $this->buildRegistrar($container);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_external_hook_listeners_no_parameter_registers_nothing(): void
    {
        $this->buildRegistrar($this->makeContainer());

        $this->assertSame(0, HookRegistry::count('agent.after'));
    }

    public function test_resolve_skills_entry_missing_all_required_keys_is_skipped(): void
    {
        $container = $this->makeContainer();

        $this->buildRegistrar($container, [
            'skills' => [
                ['name' => 'partial', 'description' => 'Missing tags and content'],
            ],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }

    private function makeLogger(): LoggerChannelInterface
    {
        return $this->createMock(LoggerChannelInterface::class);
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        ProviderRegistry::reset();
    }

    public function test_boot_external_tools_wrong_type_logs_warning_and_skips(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('warning');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_tool' => ['svc.bad']],
            services: ['svc.bad' => new \stdClass],
        );

        $registrar = $this->buildRegistrar($container, logger: $logger);

        $this->assertSame([], $registrar->getExternalTools());
    }

    public function test_boot_external_tools_exception_logs_error_and_continues(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('error');

        $adapterContainer = $this->createMock(DrupalContainerInterface::class);
        $adapterContainer->method('hasParameter')->willReturnCallback(
            static fn (string $n): bool => $n === 'phpclaw.tagged_services.phpclaw_tool'
        );
        $adapterContainer->method('getParameter')->willReturn(['svc.throw']);
        $adapterContainer->method('get')->willThrowException(new \RuntimeException('boom'));

        $registrar = $this->buildRegistrar($adapterContainer, logger: $logger);

        $this->assertSame([], $registrar->getExternalTools());
    }

    public function test_boot_external_providers_wrong_type_logs_warning_and_skips(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('warning');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_provider' => ['svc.bad']],
            services: ['svc.bad' => new \stdClass],
        );

        $this->buildRegistrar($container, logger: $logger);

        $this->assertFalse(ProviderRegistry::has('stdclass'));
    }

    public function test_boot_external_providers_exception_logs_error_and_continues(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('error');

        $adapterContainer = $this->createMock(DrupalContainerInterface::class);
        $adapterContainer->method('hasParameter')->willReturnCallback(
            static fn (string $n): bool => $n === 'phpclaw.tagged_services.phpclaw_provider'
        );
        $adapterContainer->method('getParameter')->willReturn(['svc.throw']);
        $adapterContainer->method('get')->willThrowException(new \RuntimeException('provider fail'));

        $this->buildRegistrar($adapterContainer, logger: $logger);
    }

    public function test_boot_external_memory_drivers_wrong_type_logs_warning_and_skips(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('warning');

        $container = $this->makeContainer(
            parameters: ['phpclaw.tagged_services.phpclaw_memory_driver' => ['svc.bad']],
            services: ['svc.bad' => new \stdClass],
        );

        $this->buildRegistrar($container, logger: $logger);
    }

    public function test_boot_external_guards_exception_logs_error_and_continues(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('error');

        $adapterContainer = $this->createMock(DrupalContainerInterface::class);
        $adapterContainer->method('hasParameter')->willReturnCallback(
            static fn (string $n): bool => $n === 'phpclaw.tagged_services.phpclaw_guard'
        );
        $adapterContainer->method('getParameter')->willReturn(['svc.throw']);
        $adapterContainer->method('get')->willThrowException(new \RuntimeException('guard fail'));

        $this->buildRegistrar($adapterContainer, logger: $logger);
    }

    public function test_boot_external_skills_exception_logs_error_and_continues(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('error');

        $adapterContainer = $this->createMock(DrupalContainerInterface::class);
        $adapterContainer->method('hasParameter')->willReturnCallback(
            static fn (string $n): bool => $n === 'phpclaw.tagged_services.phpclaw_skill'
        );
        $adapterContainer->method('getParameter')->willReturn(['svc.throw']);
        $adapterContainer->method('get')->willThrowException(new \RuntimeException('skill fail'));

        $this->buildRegistrar($adapterContainer, logger: $logger);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_boot_external_hook_listeners_no_handle_method_logs_warning(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('warning');

        $listenerNoHandle = new \stdClass;

        $container = $this->makeContainer(
            parameters: [
                'phpclaw.tagged_services.hook_listeners' => [
                    ['service_id' => 'svc.no_handle', 'event' => 'agent.before', 'priority' => 1],
                ],
            ],
            services: ['svc.no_handle' => $listenerNoHandle],
        );

        $this->buildRegistrar($container, logger: $logger);

        $this->assertSame(0, HookRegistry::count('agent.before'));
    }

    public function test_boot_external_hook_listeners_exception_logs_error_and_continues(): void
    {
        $logger = $this->makeLogger();
        $logger->expects($this->once())->method('error');

        $adapterContainer = $this->createMock(DrupalContainerInterface::class);
        $adapterContainer->method('hasParameter')->willReturnCallback(
            static fn (string $n): bool => $n === 'phpclaw.tagged_services.hook_listeners'
        );
        $adapterContainer->method('getParameter')->willReturn([
            ['service_id' => 'svc.throw', 'event' => 'agent.after', 'priority' => 5],
        ]);
        $adapterContainer->method('get')->willThrowException(new \RuntimeException('listener fail'));

        $this->buildRegistrar($adapterContainer, logger: $logger);

        $this->assertSame(0, HookRegistry::count('agent.after'));
    }
}
