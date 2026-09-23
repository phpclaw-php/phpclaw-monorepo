<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpRequest;
use PHPUnit\Framework\TestCase;

final class McpRequestTest extends TestCase
{
    public function test_from_json_parses_valid_request(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}';
        $req = McpRequest::fromJson($json);

        self::assertSame('tools/list', $req->method);
        self::assertSame(1, $req->id);
        self::assertFalse($req->isNotification);
    }

    public function test_from_json_throws_on_invalid_json(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        McpRequest::fromJson('{not valid json}');
    }

    public function test_from_json_throws_on_missing_method(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        McpRequest::fromJson('{"jsonrpc":"2.0","id":1}');
    }

    public function test_from_array_builds_request(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 'abc',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);

        self::assertSame('initialize', $req->method);
        self::assertSame('abc', $req->id);
        self::assertSame(['protocolVersion' => '2024-11-05'], $req->params);
        self::assertFalse($req->isNotification);
    }

    public function test_from_array_throws_when_jsonrpc_missing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        McpRequest::fromArray(['method' => 'tools/list']);
    }

    public function test_from_array_throws_when_jsonrpc_wrong_version(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        McpRequest::fromArray(['jsonrpc' => '1.0', 'method' => 'tools/list']);
    }

    public function test_from_array_throws_when_method_missing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1]);
    }

    public function test_from_array_throws_when_method_empty(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => '']);
    }

    public function test_from_array_throws_when_method_not_string(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32600);

        McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 42]);
    }

    public function test_notification_has_no_id(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        self::assertNull($req->id);
        self::assertTrue($req->isNotification);
    }

    public function test_request_with_null_id_is_not_notification(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => null,
            'method' => 'tools/list',
        ]);

        self::assertNull($req->id);
        self::assertFalse($req->isNotification);
    }

    public function test_params_defaults_to_empty_array_when_absent(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        self::assertSame([], $req->params);
    }

    public function test_params_defaults_to_empty_array_when_not_array(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => 'invalid',
        ]);

        self::assertSame([], $req->params);
    }

    public function test_get_param_returns_value(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']],
        ]);

        self::assertSame('echo', $req->getParam('name'));
        self::assertSame(['text' => 'hi'], $req->getParam('arguments'));
    }

    public function test_get_param_returns_null_for_missing_key(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        self::assertNull($req->getParam('nonexistent'));
    }

    public function test_integer_id_is_preserved(): void
    {
        $req = McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/list']);
        self::assertSame(42, $req->id);
    }

    public function test_string_id_is_preserved(): void
    {
        $req = McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 'req-001', 'method' => 'tools/list']);
        self::assertSame('req-001', $req->id);
    }
}
