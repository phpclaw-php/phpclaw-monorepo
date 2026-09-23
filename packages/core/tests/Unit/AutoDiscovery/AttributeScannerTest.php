<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery;

use PhpClaw\AutoDiscovery\AttributeScanner;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureDefaultTool;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureGuard;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureHook;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureMemory;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureNoArgTool;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureProvider;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureSkill;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureTool;
use PhpClaw\Tests\Unit\AutoDiscovery\Fixtures\FixtureWildcardHook;
use PHPUnit\Framework\TestCase;

final class AttributeScannerTest extends TestCase
{
    private const ALL_FIXTURES = [
        FixtureTool::class,
        FixtureDefaultTool::class,
        FixtureNoArgTool::class,
        FixtureProvider::class,
        FixtureMemory::class,
        FixtureSkill::class,
        FixtureGuard::class,
        FixtureHook::class,
        FixtureWildcardHook::class,
    ];

    public function test_scan_returns_six_bucket_shape(): void
    {
        $result = AttributeScanner::scan([]);

        $this->assertSame(
            ['tools', 'providers', 'memory', 'skills', 'hooks', 'guards'],
            array_keys($result),
        );
        foreach ($result as $bucket) {
            $this->assertIsArray($bucket);
            $this->assertSame([], $bucket);
        }
    }

    public function test_scan_indexes_tool_attribute_classes(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertArrayHasKey(FixtureTool::class, $result['tools']);
        $this->assertSame('fixture_tool', $result['tools'][FixtureTool::class]['name']);
        $this->assertSame('Test fixture tool', $result['tools'][FixtureTool::class]['description']);
        $this->assertFalse($result['tools'][FixtureTool::class]['default']);
    }

    public function test_scan_indexes_default_flagged_tool(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertArrayHasKey(FixtureDefaultTool::class, $result['tools']);
        $this->assertTrue($result['tools'][FixtureDefaultTool::class]['default']);
        $this->assertSame(
            ['workspaceRoot' => 'string'],
            $result['tools'][FixtureDefaultTool::class]['needsConfig'],
        );
    }

    public function test_scan_indexes_provider_attribute(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertArrayHasKey(FixtureProvider::class, $result['providers']);
        $this->assertSame('fixture_provider', $result['providers'][FixtureProvider::class]['name']);
        $this->assertSame('fixture-model-v1', $result['providers'][FixtureProvider::class]['defaultModel']);
    }

    public function test_scan_indexes_memory_attribute(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertSame('fixture_driver', $result['memory'][FixtureMemory::class]['driver']);
    }

    public function test_scan_indexes_skill_with_keyword_list(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertSame(['fix', 'test'], $result['skills'][FixtureSkill::class]['keywords']);
    }

    public function test_scan_indexes_guard_priority(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertSame(25, $result['guards'][FixtureGuard::class]['priority']);
    }

    public function test_scan_collects_repeated_hook_attributes(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertCount(2, $result['hooks'][FixtureHook::class]);
        $events = array_column($result['hooks'][FixtureHook::class], 'event');
        $this->assertSame(['agent.before', 'agent.after'], $events);
    }

    public function test_scan_preserves_wildcard_hook_event(): void
    {
        $result = AttributeScanner::scan(self::ALL_FIXTURES);

        $this->assertSame('*', $result['hooks'][FixtureWildcardHook::class][0]['event']);
    }

    public function test_scan_ignores_classes_outside_phpclaw_namespace(): void
    {
        $result = AttributeScanner::scan([\stdClass::class, \DateTimeImmutable::class]);

        foreach ($result as $bucket) {
            $this->assertSame([], $bucket);
        }
    }

    public function test_scan_ignores_classes_without_phpclaw_attributes(): void
    {
        $result = AttributeScanner::scan([self::class]);

        foreach ($result as $bucket) {
            $this->assertSame([], $bucket);
        }
    }

    public function test_scan_skips_missing_classes_gracefully(): void
    {
        $result = AttributeScanner::scan(['PhpClaw\\Does\\Not\\Exist']);

        foreach ($result as $bucket) {
            $this->assertSame([], $bucket);
        }
    }

    public function test_scan_returns_empty_when_both_classmap_and_psr4_missing(): void
    {
        $result = AttributeScanner::scan(
            null,
            '/tmp/phpclaw-no-such-classmap.php',
            '/tmp/phpclaw-no-such-psr4.php',
        );

        foreach ($result as $bucket) {
            $this->assertSame([], $bucket);
        }
    }
}
