<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\PsHookBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsHookBridge::class)]
final class PsHookBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        \Hook::reset();

        parent::tearDown();
    }

    public function test_fire_extra_returns_bucket_unchanged_when_no_listeners(): void
    {
        $bucket = ['a', 'b'];

        $result = PsHookBridge::fireExtra('phpclaw/extra/tools', $bucket);

        self::assertSame($bucket, $result);
    }

    public function test_fire_extra_builds_hook_name_from_event_slug(): void
    {
        \Hook::setResult('actionPhpclawExtraTools', [['c']]);

        $result = PsHookBridge::fireExtra('phpclaw/extra/tools', ['a', 'b']);

        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_fire_extra_merges_single_contribution(): void
    {
        \Hook::setResult('actionPhpclawExtraSkills', [['skill_one']]);

        $result = PsHookBridge::fireExtra('phpclaw/extra/skills', []);

        self::assertSame(['skill_one'], $result);
    }

    public function test_fire_extra_merges_multiple_contributions_from_multiple_listeners(): void
    {
        \Hook::setResult('actionPhpclawExtraGuards', [['guard_one'], ['guard_two', 'guard_three']]);

        $result = PsHookBridge::fireExtra('phpclaw/extra/guards', ['guard_zero']);

        self::assertSame(['guard_zero', 'guard_one', 'guard_two', 'guard_three'], $result);
    }

    public function test_fire_extra_ignores_non_array_contributions(): void
    {
        \Hook::setResult('actionPhpclawExtraHooks', ['not-an-array', ['real']]);

        $result = PsHookBridge::fireExtra('phpclaw/extra/hooks', []);

        self::assertSame(['real'], $result);
    }

    public function test_fire_extra_preserves_bucket_keys_for_associative_arrays(): void
    {
        \Hook::setResult('actionPhpclawExtraMemory', [['driver' => 'redis']]);

        $result = PsHookBridge::fireExtra('phpclaw/extra/memory', ['driver' => 'array']);

        self::assertSame(['driver' => 'redis'], $result);
    }

    public function test_fire_extra_returns_bucket_unchanged_when_hook_exec_throws(): void
    {
        \Hook::throwOn('actionPhpclawExtraProviders');
        $bucket = ['existing'];

        $result = PsHookBridge::fireExtra('phpclaw/extra/providers', $bucket);

        self::assertSame($bucket, $result);
    }
}
