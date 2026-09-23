<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit;

use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Skills\SkillResolver;
use PHPUnit\Framework\TestCase;

final class CanonicalDelegationTest extends TestCase
{
    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_hook_event_bridge_accepts_closure_dispatcher(): void
    {
        $ref = new \ReflectionClass(HookEventBridge::class);
        $param = $ref->getConstructor()->getParameters()[0];

        $this->assertSame('dispatcher', $param->getName());
        $this->assertSame(\Closure::class, $param->getType()?->getName());
    }

    public function test_hook_event_bridge_local_copy_does_not_exist(): void
    {
        $localPath = dirname(__DIR__, 2).'/component/src/Events/HookEventBridge.php';
        $this->assertFileDoesNotExist($localPath);

        $vendorPath = dirname(__DIR__, 2).'/vendor/phpclaw/phpclaw/src/Hooks/HookEventBridge.php';
        $this->assertFileExists($vendorPath);
    }

    public function test_hook_event_bridge_register_wires_lifecycle_events(): void
    {
        HookRegistry::reset();

        $called = [];

        (new HookEventBridge(
            static function (string $event, array $ctx) use (&$called): void {
                $called[] = $event;
            },
        ))->register();

        HookRegistry::fire('agent.before', []);

        $this->assertContains('agent.before', $called);
    }

    public function test_privacy_aware_memory_store_off_is_noop(): void
    {
        $inner = $this->createMock(MemoryInterface::class);
        $inner->expects($this->never())->method('set');

        $memory = new PrivacyAwareMemory($inner, false);
        $memory->set('conv-1', ['history' => [['role' => 'user', 'content' => 'secret']]], 'conversations');
    }

    public function test_privacy_aware_memory_local_copy_does_not_exist(): void
    {
        $localPath = dirname(__DIR__, 2).'/component/src/Memory/PrivacyAwareMemory.php';
        $this->assertFileDoesNotExist($localPath);

        $vendorPath = dirname(__DIR__, 2).'/vendor/phpclaw/phpclaw/src/Memory/PrivacyAwareMemory.php';
        $this->assertFileExists($vendorPath);
    }

    public function test_privacy_aware_memory_store_on_delegates_to_inner(): void
    {
        $inner = $this->createMock(MemoryInterface::class);
        $inner->expects($this->once())
            ->method('set')
            ->with('k', 'v', 'default', null);

        $memory = new PrivacyAwareMemory($inner, true);
        $memory->set('k', 'v', 'default');
    }

    public function test_skill_resolver_canonical_exists_in_core(): void
    {
        $vendorPath = dirname(__DIR__, 2).'/vendor/phpclaw/phpclaw/src/Skills/SkillResolver.php';
        $this->assertFileExists($vendorPath);
        $this->assertTrue(class_exists(SkillResolver::class));
    }

    public function test_skill_resolver_tags_field_is_optional(): void
    {
        $skills = SkillResolver::resolve([
            ['name' => 'test', 'description' => 'a test skill', 'content' => 'do the thing'],
        ]);

        $this->assertCount(1, $skills);
    }

    public function test_skill_resolver_no_local_copy_exists(): void
    {
        $found = glob(dirname(__DIR__, 2).'/component/src/**/**/SkillResolver.php') ?: [];
        $found = array_merge(
            $found,
            glob(dirname(__DIR__, 2).'/component/src/*/SkillResolver.php') ?: [],
        );

        $this->assertEmpty($found, 'No local SkillResolver.php should exist under component/src/');
    }
}
