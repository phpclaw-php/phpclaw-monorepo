<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\InjectionGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Laravel\Memory\CacheMemory;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Laravel\Memory\DatabaseMemory;
use PhpClaw\Laravel\Memory\DatabaseRouterMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\RedisMemory;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;

final class PhpClawServiceProviderRegistriesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-api-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        PhpClawServiceProvider::resetEventBridge();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
        PhpClawServiceProvider::resetEventBridge();
    }

    private function defaultGuardBaseline(): int
    {
        GuardRegistry::reset();
        GuardRegistry::registerDefaults();

        return GuardRegistry::count();
    }

    public function test_eloquent_driver_is_registered_in_memory_registry(): void
    {
        $this->assertTrue(MemoryRegistry::has('eloquent'));
    }

    public function test_eloquent_driver_builds_router_memory_instance(): void
    {
        $driver = MemoryRegistry::build('eloquent');

        $this->assertInstanceOf(DatabaseRouterMemory::class, $driver);
    }

    public function test_eloquent_kv_driver_builds_raw_eloquent_memory_instance(): void
    {
        $driver = MemoryRegistry::build('eloquent_kv');

        $this->assertInstanceOf(DatabaseMemory::class, $driver);
    }

    public function test_eloquent_conversation_driver_builds_raw_conversation_memory_instance(): void
    {
        $driver = MemoryRegistry::build('eloquent_conversation');

        $this->assertInstanceOf(DatabaseConversationMemory::class, $driver);
    }

    public function test_file_and_array_drivers_still_registered(): void
    {
        $this->assertTrue(MemoryRegistry::has('file'));
        $this->assertTrue(MemoryRegistry::has('array'));
    }

    public function test_cache_driver_is_registered_in_memory_registry(): void
    {
        $this->assertTrue(MemoryRegistry::has('cache'));
    }

    public function test_cache_driver_builds_cache_memory_instance(): void
    {
        $driver = MemoryRegistry::build('cache');

        $this->assertInstanceOf(CacheMemory::class, $driver);
    }

    public function test_redis_driver_is_registered_when_redis_memory_class_available(): void
    {
        $this->assertSame(
            class_exists(RedisMemory::class),
            MemoryRegistry::has('redis'),
        );
    }

    public function test_memory_interface_resolves_from_container(): void
    {
        $this->app['config']->set('phpclaw.memory_driver', 'array');

        $this->assertInstanceOf(ArrayMemory::class, $this->app->make(MemoryInterface::class));
    }

    public function test_memory_driver_from_config_is_used(): void
    {
        $this->app->forgetInstance(MemoryInterface::class);
        $this->app['config']->set('phpclaw.memory_driver', 'eloquent');

        $memory = $this->app->make(MemoryInterface::class);

        $this->assertInstanceOf(DatabaseRouterMemory::class, $memory);
    }

    public function test_only_default_guards_registered_when_config_guards_empty(): void
    {
        $baseline = $this->defaultGuardBaseline();

        $this->app['config']->set('phpclaw.guards', []);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame($baseline, GuardRegistry::count());
    }

    public function test_local_default_guards_registered_on_boot_bug33_regression(): void
    {
        GuardRegistry::reset();
        $this->app['config']->set('phpclaw.guards', []);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertTrue(
            GuardRegistry::hasClass(InjectionGuard::class),
            'BUG33 regression: the local InjectionGuard must register via GuardRegistry::registerDefaults() '
            .'on boot, even with no config guards and no cloud key.'
        );
    }

    public function test_custom_guard_from_config_is_registered(): void
    {
        $baseline = $this->defaultGuardBaseline();

        $stubGuardClass = get_class(new class implements GuardInterface
        {
            public function scan(string $message): void {}
        });

        $this->app['config']->set('phpclaw.guards', [
            ['class' => $stubGuardClass, 'priority' => 5],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame($baseline + 1, GuardRegistry::count());
        $this->assertTrue(GuardRegistry::hasClass($stubGuardClass));
    }

    public function test_guard_entry_missing_class_is_skipped(): void
    {
        $baseline = $this->defaultGuardBaseline();

        $this->app['config']->set('phpclaw.guards', [
            ['priority' => 5],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame($baseline, GuardRegistry::count());
    }

    public function test_guard_with_nonexistent_class_is_skipped(): void
    {
        $baseline = $this->defaultGuardBaseline();

        $this->app['config']->set('phpclaw.guards', [
            ['class' => 'App\\Guards\\DoesNotExist', 'priority' => 5],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame($baseline, GuardRegistry::count());
    }

    public function test_no_hooks_registered_when_config_hooks_empty(): void
    {
        $this->app['config']->set('phpclaw.hooks', []);

        $this->assertSame(count(LifecycleEvent::all()) + 2, HookRegistry::count());
    }

    public function test_stream_event_bridge_registered_once_at_boot(): void
    {
        HookRegistry::reset();

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', []);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame(1, HookRegistry::count(LifecycleEvent::ToolBefore->value), 'StreamEventBridge must register once for tool.before');
        $this->assertSame(1, HookRegistry::count(LifecycleEvent::ToolAfter->value), 'StreamEventBridge must register once for tool.after');
        $this->assertSame(2, HookRegistry::count(), 'boot registers exactly the 2 StreamEventBridge listeners with the event bridge off and no config hooks');
    }

    public function test_custom_hook_from_config_is_registered(): void
    {
        HookRegistry::reset();

        $called = false;
        $handler = function () use (&$called): void {
            $called = true;
        };

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', [
            ['event' => 'agent.before', 'handler' => $handler, 'priority' => 10],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame(1, HookRegistry::count('agent.before'));
    }

    public function test_hook_fires_after_registration(): void
    {
        HookRegistry::reset();

        $received = [];
        $handler = function (array $ctx) use (&$received): void {
            $received = $ctx;
        };

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', [
            ['event' => 'agent.before', 'handler' => $handler, 'priority' => 10],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        HookRegistry::fire('agent.before', ['message' => 'hello']);

        $this->assertSame('hello', $received['message']);
    }

    public function test_hook_entry_missing_event_is_skipped(): void
    {
        HookRegistry::reset();

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', [
            ['handler' => fn () => null, 'priority' => 10],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame(2, HookRegistry::count());
    }

    public function test_hook_entry_missing_handler_is_skipped(): void
    {
        HookRegistry::reset();

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', [
            ['event' => 'agent.before', 'priority' => 10],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame(2, HookRegistry::count());
    }

    public function test_multiple_hooks_for_different_events_registered(): void
    {
        HookRegistry::reset();

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', [
            ['event' => 'agent.before', 'handler' => fn () => null, 'priority' => 10],
            ['event' => 'agent.after',  'handler' => fn () => null, 'priority' => 10],
            ['event' => 'tool.before',  'handler' => fn () => null, 'priority' => 10],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame(1, HookRegistry::count('agent.before'));
        $this->assertSame(1, HookRegistry::count('agent.after'));
        $this->assertSame(2, HookRegistry::count('tool.before'));
        $this->assertSame(5, HookRegistry::count());
    }

    public function test_hook_priority_from_config_is_respected(): void
    {
        HookRegistry::reset();

        $order = [];

        $this->app['config']->set('phpclaw.events.bridge', false);
        $this->app['config']->set('phpclaw.hooks', [
            ['event' => 'agent.before', 'handler' => function () use (&$order): void {
                $order[] = 'second';
            }, 'priority' => 20],
            ['event' => 'agent.before', 'handler' => function () use (&$order): void {
                $order[] = 'first';
            },  'priority' => 5],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        HookRegistry::fire('agent.before');

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_empty_skills_config_registers_discovered_defaults_always_on(): void
    {
        SkillRegistry::reset();

        $this->app['config']->set('phpclaw.skills', []);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertContains('php_best_practices', $this->skillNames());
    }

    public function test_inline_array_skill_registered_from_config(): void
    {
        SkillRegistry::reset();

        $this->app['config']->set('phpclaw.skills', [
            [
                'name' => 'laravel-expert',
                'description' => 'Laravel best practices',
                'tags' => ['laravel', 'eloquent'],
                'content' => 'Always use Eloquent over raw queries.',
            ],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertContains('laravel-expert', $this->skillNames());
    }

    public function test_file_skill_registered_from_config(): void
    {
        SkillRegistry::reset();

        $path = sys_get_temp_dir().'/phpclaw-test-skill-'.uniqid().'.md';
        file_put_contents($path, "---\nname: temp-skill\ndescription: Temp skill\ntags: [test]\n---\nSkill content here.");

        $this->app['config']->set('phpclaw.skills', [
            ['file' => $path],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertContains('temp-skill', $this->skillNames());

        unlink($path);
    }

    public function test_custom_skill_class_registered_via_container(): void
    {
        SkillRegistry::reset();

        $skillClass = get_class(new class implements SkillInterface
        {
            public function name(): string
            {
                return 'custom-skill';
            }

            public function description(): string
            {
                return 'A custom skill';
            }

            public function tags(): array
            {
                return ['custom'];
            }

            public function content(): string
            {
                return 'Custom skill content.';
            }
        });

        $this->app['config']->set('phpclaw.skills', [
            ['class' => $skillClass],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertContains('custom-skill', $this->skillNames());
    }

    public function test_invalid_skill_entry_skipped_silently(): void
    {
        SkillRegistry::reset();

        $this->app['config']->set('phpclaw.skills', [
            'not-an-array',
            ['file' => '/nonexistent/path/skill.md'],
            ['class' => 'App\\Skills\\DoesNotExist'],
            ['name' => 'missing-content'],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $names = $this->skillNames();
        $this->assertNotContains('missing-content', $names);
        $this->assertContains('php_best_practices', $names);
    }

    private function skillNames(): array
    {
        return array_map(static fn ($skill): string => $skill->name(), SkillRegistry::all());
    }
}
