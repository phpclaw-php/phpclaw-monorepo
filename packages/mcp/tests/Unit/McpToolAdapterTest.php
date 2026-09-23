<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\McpToolAdapter;
use PhpClaw\Mcp\Tests\Stubs\EchoTool;
use PhpClaw\Mcp\Tests\Stubs\FailingTool;
use PHPUnit\Framework\TestCase;

final class McpToolAdapterTest extends TestCase
{
    private McpToolAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new McpToolAdapter;
    }

    public function test_to_descriptor_maps_name(): void
    {
        $descriptor = $this->adapter->toDescriptor(new EchoTool);
        self::assertSame('echo', $descriptor['name']);
    }

    public function test_to_descriptor_maps_description(): void
    {
        $descriptor = $this->adapter->toDescriptor(new EchoTool);
        self::assertSame('Returns input unchanged', $descriptor['description']);
    }

    public function test_to_descriptor_maps_input_schema(): void
    {
        $descriptor = $this->adapter->toDescriptor(new EchoTool);
        $schema = $descriptor['inputSchema'];

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('text', $schema['properties']);
        self::assertSame(['text'], $schema['required']);
    }

    public function test_to_descriptor_shape_has_exactly_three_keys(): void
    {
        $descriptor = $this->adapter->toDescriptor(new EchoTool);
        self::assertSame(['name', 'description', 'inputSchema'], array_keys($descriptor));
    }

    public function test_to_descriptor_works_for_failing_tool(): void
    {
        $descriptor = $this->adapter->toDescriptor(new FailingTool);
        self::assertSame('fail', $descriptor['name']);
        self::assertSame('object', $descriptor['inputSchema']['type']);
        self::assertInstanceOf(\stdClass::class, $descriptor['inputSchema']['properties']);
        self::assertStringContainsString('"properties":{}', json_encode($descriptor['inputSchema']));
    }

    public function test_to_list_wraps_tools_under_tools_key(): void
    {
        $list = $this->adapter->toList([new EchoTool]);
        self::assertArrayHasKey('tools', $list);
    }

    public function test_to_list_returns_all_tools(): void
    {
        $list = $this->adapter->toList([new EchoTool, new FailingTool]);
        self::assertCount(2, $list['tools']);
    }

    public function test_to_list_is_numerically_indexed(): void
    {
        $list = $this->adapter->toList([new EchoTool, new FailingTool]);
        self::assertSame([0, 1], array_keys($list['tools']));
    }

    public function test_to_list_empty_tools_array(): void
    {
        $list = $this->adapter->toList([]);
        self::assertSame(['tools' => []], $list);
    }

    public function test_to_list_preserves_tool_order(): void
    {
        $list = $this->adapter->toList([new EchoTool, new FailingTool]);
        self::assertSame('echo', $list['tools'][0]['name']);
        self::assertSame('fail', $list['tools'][1]['name']);
    }
}
