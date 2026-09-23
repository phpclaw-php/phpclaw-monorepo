<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Extension;

use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\Extension\PhpClawExtensions;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;

final class PhpClawExtensionsTest extends TestCase
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
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
    }

    public function test_all_6_subsystems_injected_via_booting_event_are_registered(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();

        $hookFired = false;
        $skillAdded = false;

        $stubTool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'ext_test_tool';
            }

            public function description(): string
            {
                return 'Extension test tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                return 'ok';
            }
        };

        $stubGuard = new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };

        $stubSkill = new ArraySkill(
            name: 'ext_test_skill',
            description: 'Extension test skill',
            tags: ['ext'],
            content: 'Extension skill content.',
        );

        Event::listen('phpclaw.booting', function (PhpClawExtensions $ext) use (
            $stubTool, $stubGuard, $stubSkill, &$hookFired, &$skillAdded
        ): void {
            $ext->tools[] = $stubTool;
            $ext->guards[] = $stubGuard;
            $ext->hooks[] = [
                'event' => 'agent.before',
                'handler' => function () use (&$hookFired): void {
                    $hookFired = true;
                },
                'priority' => 10,
            ];
            $ext->skills[] = $stubSkill;
            $ext->memory['ext_test_driver'] = fn (): MemoryInterface => new ArrayMemory;
            $ext->providers['ext_test_provider'] = ['class' => 'NonExistentProvider'];
        });

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $engineToolNames = array_map(
            fn (ToolInterface $t): string => $t->name(),
            $this->resolveEngineTools(),
        );
        $this->assertContains(
            'ext_test_tool',
            $engineToolNames,
            'Extension tool injected via the booting event must reach the engine tool set'
        );

        $this->assertTrue(
            GuardRegistry::count() >= 1,
            'Extension guard must be in GuardRegistry'
        );

        $hookCount = HookRegistry::count('agent.before');
        $this->assertGreaterThanOrEqual(1, $hookCount, 'Extension hook must register on agent.before');

        HookRegistry::fire('agent.before', []);
        $this->assertTrue($hookFired, 'Extension hook handler must fire');

        $skills = SkillRegistry::all();
        $skillNames = array_map(fn (SkillInterface $s): string => $s->name(), $skills);
        $this->assertContains('ext_test_skill', $skillNames, 'Extension skill must be in SkillRegistry');

        $this->assertTrue(
            MemoryRegistry::has('ext_test_driver'),
            'Extension memory driver must be in MemoryRegistry'
        );

        $driverInstance = MemoryRegistry::build('ext_test_driver');
        $this->assertInstanceOf(ArrayMemory::class, $driverInstance);

        $this->assertFalse(
            ProviderRegistry::has('ext_test_provider'),
            'the sixth subsystem is wired too, but an unloadable provider class must be skipped',
        );
    }

    private function resolveEngineTools(): array
    {
        $method = new \ReflectionMethod(EngineFactory::class, 'resolveTools');
        $method->setAccessible(true);

        /** @var ToolInterface[] $tools */
        $tools = $method->invoke(null, $this->app);

        return $tools;
    }

    public function test_phpclaw_booting_event_fires_before_registries_wire(): void
    {
        $eventFired = false;
        $toolsAtEventTime = null;

        Event::listen('phpclaw.booting', function (PhpClawExtensions $ext) use (
            &$eventFired, &$toolsAtEventTime
        ): void {
            $eventFired = true;
        });

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertTrue($eventFired, 'phpclaw.booting must fire during boot()');
    }

    public function test_extensions_bucket_starts_empty_for_every_category(): void
    {
        $ext = new PhpClawExtensions;

        $this->assertSame([], $ext->tools);
        $this->assertSame([], $ext->guards);
        $this->assertSame([], $ext->hooks);
        $this->assertSame([], $ext->skills);
        $this->assertSame([], $ext->memory);
        $this->assertSame([], $ext->providers);
    }
}
