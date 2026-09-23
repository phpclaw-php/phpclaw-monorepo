<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Extension;

use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\Extension\PhpClawExtensions;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class PhpClawExtensionsBagTest extends TestCase
{
    protected function setUp(): void
    {
        GuardRegistry::reset();
        SkillRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        ProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
        SkillRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        ProviderRegistry::reset();
    }

    public function test_extensions_bag_starts_empty(): void
    {
        $ext = new PhpClawExtensions;

        $this->assertSame([], $ext->tools);
        $this->assertSame([], $ext->guards);
        $this->assertSame([], $ext->hooks);
        $this->assertSame([], $ext->skills);
        $this->assertSame([], $ext->memory);
        $this->assertSame([], $ext->providers);
    }

    public function test_tools_bucket_accepts_tool_instances(): void
    {
        $ext = new PhpClawExtensions;

        $tool = $this->createMock(ToolInterface::class);
        $ext->tools[] = $tool;

        $this->assertCount(1, $ext->tools);
        $this->assertSame($tool, $ext->tools[0]);
    }

    public function test_guards_bucket_registers_into_guard_registry(): void
    {
        $ext = new PhpClawExtensions;

        $guard = new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };

        $ext->guards[] = $guard;

        foreach ($ext->guards as $g) {
            GuardRegistry::register($g);
        }

        $this->assertTrue(GuardRegistry::hasClass($guard::class));
    }

    public function test_hooks_bucket_registers_into_hook_registry(): void
    {
        $ext = new PhpClawExtensions;

        $fired = false;
        $handler = static function (array $ctx) use (&$fired): void {
            $fired = true;
        };

        $ext->hooks[] = ['event' => 'agent.before', 'handler' => $handler, 'priority' => 10];

        foreach ($ext->hooks as $hook) {
            HookRegistry::on($hook['event'], $hook['handler'], $hook['priority'] ?? 10);
        }

        HookRegistry::fire('agent.before', []);

        $this->assertTrue($fired);
    }

    public function test_skills_bucket_registers_into_skill_registry(): void
    {
        $ext = new PhpClawExtensions;

        $skill = new class implements SkillInterface
        {
            public function name(): string
            {
                return 'test_bag_skill';
            }

            public function description(): string
            {
                return 'Test skill';
            }

            public function tags(): array
            {
                return ['test'];
            }

            public function content(): string
            {
                return 'Test content';
            }
        };

        $ext->skills[] = $skill;

        foreach ($ext->skills as $s) {
            SkillRegistry::register($s);
        }

        $this->assertTrue(SkillRegistry::has('test_bag_skill'));
    }

    public function test_memory_bucket_registers_into_memory_registry(): void
    {
        $ext = new PhpClawExtensions;

        $ext->memory['test_scratch'] = static fn () => new ArrayMemory;

        foreach ($ext->memory as $slug => $factory) {
            MemoryRegistry::register($slug, $factory);
        }

        $this->assertTrue(MemoryRegistry::has('test_scratch'));
    }

    public function test_providers_bucket_accepts_slug_class_entries(): void
    {
        $ext = new PhpClawExtensions;

        $ext->providers['my_provider'] = ['class' => OpenAIProvider::class];

        $this->assertArrayHasKey('my_provider', $ext->providers);
        $this->assertSame(OpenAIProvider::class, $ext->providers['my_provider']['class']);
    }

    public function test_all_six_buckets_are_independently_mutable(): void
    {
        $ext1 = new PhpClawExtensions;
        $ext2 = new PhpClawExtensions;

        $ext1->tools[] = $this->createMock(ToolInterface::class);
        $ext1->guards[] = 'guard';
        $ext1->hooks[] = ['event' => 'agent.before', 'handler' => 'strlen'];
        $ext1->skills[] = 'skill';
        $ext1->memory['driver'] = 'factory';
        $ext1->providers['slug'] = ['class' => 'Provider'];

        foreach (['tools', 'guards', 'hooks', 'skills', 'memory', 'providers'] as $bucket) {
            $this->assertCount(1, $ext1->{$bucket}, "{$bucket} must hold the entry it was given");
            $this->assertCount(0, $ext2->{$bucket}, "{$bucket} must not be shared between instances");
        }
    }
}
