<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit\Defaults;

use PhpClaw\Mcp\Defaults\DefaultResources;
use PhpClaw\Mcp\ResourceRegistry;
use PhpClaw\Mcp\Tests\Unit\Defaults\Stubs\DummyTool;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class DefaultResourcesTest extends TestCase
{
    protected function setUp(): void
    {
        ResourceRegistry::reset();
    }

    protected function tearDown(): void
    {
        ResourceRegistry::reset();
    }

    public function test_register_adds_config_resource(): void
    {
        DefaultResources::register();

        self::assertTrue(ResourceRegistry::has('phpclaw://config'));
    }

    public function test_register_adds_tools_resource(): void
    {
        DefaultResources::register();

        self::assertTrue(ResourceRegistry::has('phpclaw://tools'));
    }

    public function test_registers_exactly_two_resources(): void
    {
        DefaultResources::register();

        self::assertSame(2, ResourceRegistry::count());
    }

    public function test_config_resource_returns_json(): void
    {
        DefaultResources::register();

        $content = ResourceRegistry::read('phpclaw://config');
        $decoded = json_decode($content, true);

        self::assertIsArray($decoded);
    }

    public function test_config_resource_redacts_api_keys(): void
    {
        DefaultResources::register();

        $content = ResourceRegistry::read('phpclaw://config');

        self::assertStringNotContainsString('sk-', $content);
    }

    public function test_tools_resource_returns_empty_without_registry(): void
    {
        DefaultResources::register();

        $content = ResourceRegistry::read('phpclaw://tools');
        $decoded = json_decode($content, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('tools', $decoded);
        self::assertEmpty($decoded['tools']);
    }

    public function test_tools_resource_lists_registered_tools(): void
    {
        $registry = new ToolRegistry;
        $tool = new DummyTool;
        $registry->register([$tool]);

        DefaultResources::register($registry);

        $content = ResourceRegistry::read('phpclaw://tools');
        $decoded = json_decode($content, true);

        self::assertCount(1, $decoded['tools']);
        self::assertSame('dummy', $decoded['tools'][0]['name']);
    }

    public function test_schemas_contain_correct_mime_types(): void
    {
        DefaultResources::register();

        $schemas = ResourceRegistry::schemas();

        foreach ($schemas as $schema) {
            self::assertSame('application/json', $schema['mimeType']);
        }
    }
}
