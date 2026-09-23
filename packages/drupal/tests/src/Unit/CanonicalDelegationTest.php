<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Skills\SkillResolver;
use PHPUnit\Framework\TestCase;

final class CanonicalDelegationTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_hook_event_bridge_accepts_closure_dispatcher(): void
    {
        $ref = new \ReflectionClass(HookEventBridge::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $param = $constructor->getParameters()[0];

        $this->assertSame('dispatcher', $param->getName());
        $type = $param->getType();
        $this->assertNotNull($type);
        $this->assertSame(\Closure::class, $type->getName());
    }

    public function test_hook_event_bridge_resolves_to_the_core_package(): void
    {
        $file = (string) (new \ReflectionClass(HookEventBridge::class))->getFileName();

        self::assertStringContainsString(
            '/vendor/phpclaw/phpclaw/src/',
            $file,
            'HookEventBridge must resolve to the core package. A path under this package\'s own src/ '
            .'means a local fork is shadowing the canonical class.'
        );
        self::assertFileExists($file);
    }

    public function test_hook_event_bridge_register_fires_closure_on_emit(): void
    {
        $called = [];

        $bridge = new HookEventBridge(
            static function (string $event, array $ctx) use (&$called): void {
                $called[] = $event;
            },
        );
        $bridge->register();

        HookRegistry::fire('agent.before', []);

        $this->assertContains('agent.before', $called);
    }

    public function test_privacy_aware_memory_store_off_is_complete_noop(): void
    {
        $inner = $this->createMock(MemoryInterface::class);
        $inner->expects($this->never())->method('set');

        $mem = new PrivacyAwareMemory($inner, false);
        $mem->set('k', 'v', 'conversations');
        $mem->set('k2', 'v2', 'default');
    }

    public function test_privacy_aware_memory_resolves_to_the_core_package(): void
    {
        $file = (string) (new \ReflectionClass(PrivacyAwareMemory::class))->getFileName();

        self::assertStringContainsString(
            '/vendor/phpclaw/phpclaw/src/',
            $file,
            'PrivacyAwareMemory must resolve to the core package. A path under this package\'s own src/ '
            .'means a local fork is shadowing the canonical class.'
        );
        self::assertFileExists($file);
    }

    public function test_privacy_aware_memory_store_on_delegates_to_inner(): void
    {
        $inner = $this->createMock(MemoryInterface::class);
        $inner->expects($this->once())
            ->method('set')
            ->with('k', 'v', 'default', null);

        $mem = new PrivacyAwareMemory($inner, true);
        $mem->set('k', 'v', 'default');
    }

    public function test_skill_resolver_canonical_exists_in_core(): void
    {
        $pkgRoot = (string) realpath(__DIR__.'/../../../');
        $vendorPath = $pkgRoot.'/vendor/phpclaw/phpclaw/src/Skills/SkillResolver.php';

        $this->assertFileExists($vendorPath);
        $this->assertTrue(class_exists(SkillResolver::class));
    }

    public function test_skill_resolver_tags_field_is_optional(): void
    {
        $skills = SkillResolver::resolve([
            ['name' => 'test', 'description' => 'd', 'content' => 'i'],
        ]);

        $this->assertCount(1, $skills);
        $this->assertSame('test', $skills[0]->name());
    }

    public function test_skill_resolver_no_local_copy_exists(): void
    {
        $pkgRoot = (string) realpath(__DIR__.'/../../../');
        $srcDir = $pkgRoot.'/src';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        $found = false;

        foreach ($iterator as $file) {
            if ($file->getFilename() === 'SkillResolver.php') {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, 'No local SkillResolver.php should exist in src/');
    }
}
