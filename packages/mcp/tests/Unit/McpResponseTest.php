<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\McpResponse;
use PHPUnit\Framework\TestCase;

final class McpResponseTest extends TestCase
{
    public function test_success_sets_result_field(): void
    {
        $resp = McpResponse::success(1, ['tools' => []]);

        self::assertSame(1, $resp->id);
        self::assertSame(['tools' => []], $resp->result);
        self::assertNull($resp->error);
    }

    public function test_success_to_array_shape(): void
    {
        $resp = McpResponse::success('req-1', ['text' => 'hello']);

        $arr = $resp->toArray();

        self::assertSame('2.0', $arr['jsonrpc']);
        self::assertSame('req-1', $arr['id']);
        self::assertSame(['text' => 'hello'], $arr['result']);
        self::assertArrayNotHasKey('error', $arr);
    }

    public function test_success_to_json_is_newline_terminated(): void
    {
        $json = McpResponse::success(1, [])->toJson();

        self::assertStringEndsWith("\n", $json);
    }

    public function test_success_to_json_is_valid_json(): void
    {
        $json = McpResponse::success(1, ['ok' => true])->toJson();
        $parsed = json_decode(trim($json), associative: true);

        self::assertSame('2.0', $parsed['jsonrpc']);
        self::assertSame(1, $parsed['id']);
        self::assertSame(['ok' => true], $parsed['result']);
    }

    public function test_error_sets_error_field(): void
    {
        $resp = McpResponse::error(1, -32601, 'Method not found');

        self::assertSame(1, $resp->id);
        self::assertNull($resp->result);
        self::assertSame(['code' => -32601, 'message' => 'Method not found'], $resp->error);
    }

    public function test_error_to_array_shape(): void
    {
        $arr = McpResponse::error(2, -32700, 'Parse error')->toArray();

        self::assertSame('2.0', $arr['jsonrpc']);
        self::assertSame(2, $arr['id']);
        self::assertSame(['code' => -32700, 'message' => 'Parse error'], $arr['error']);
        self::assertArrayNotHasKey('result', $arr);
    }

    public function test_error_to_json_is_valid_json(): void
    {
        $json = McpResponse::error(null, -32600, 'Invalid Request')->toJson();
        $parsed = json_decode(trim($json), associative: true);

        self::assertSame('2.0', $parsed['jsonrpc']);
        self::assertNull($parsed['id']);
        self::assertSame(-32600, $parsed['error']['code']);
        self::assertSame('Invalid Request', $parsed['error']['message']);
    }

    public function test_null_id_is_preserved_in_success(): void
    {
        $arr = McpResponse::success(null, 'ok')->toArray();
        self::assertNull($arr['id']);
    }

    public function test_null_id_is_preserved_in_error(): void
    {
        $arr = McpResponse::error(null, -32700, 'Parse error')->toArray();
        self::assertNull($arr['id']);
    }

    public function test_result_can_be_null(): void
    {
        $resp = McpResponse::success(1, null);
        $arr = $resp->toArray();

        self::assertArrayHasKey('result', $arr);
        self::assertNull($arr['result']);
    }

    public function test_json_encodes_unicode_unescaped(): void
    {
        $json = McpResponse::success(1, ['msg' => 'héllo'])->toJson();

        self::assertStringContainsString('héllo', $json);
    }

    public function test_single_line_output_before_newline(): void
    {
        $json = McpResponse::success(1, [])->toJson();
        $lines = explode("\n", trim($json));

        self::assertCount(1, $lines);
    }
}
