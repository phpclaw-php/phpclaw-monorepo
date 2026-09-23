<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit\Transport;

use PhpClaw\Mcp\Contracts\TransportInterface;
use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpRequest;
use PhpClaw\Mcp\McpSecurity;
use PhpClaw\Mcp\Transport\StreamableHttpTransport;
use PHPUnit\Framework\TestCase;

final class StreamableHttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        McpSecurity::resetRateLimits();
    }

    public function test_read_rejects_cross_origin_browser_request(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}';
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST',
            'HTTP_ORIGIN' => 'https://evil.example.com',
        ];

        $this->expectException(McpException::class);

        $this->withHttp($body, $server, static fn () => (new StreamableHttpTransport('right'))->read());
    }

    public function test_read_rejects_oversized_body(): void
    {
        $body = str_repeat('a', 1048577);
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST',
            'HTTP_AUTHORIZATION' => 'Bearer right',
        ];

        $this->expectException(McpException::class);

        $this->withHttp($body, $server, static fn () => (new StreamableHttpTransport('right'))->read());
    }

    public function test_implements_transport_interface(): void
    {
        $transport = new StreamableHttpTransport('test-token');

        self::assertInstanceOf(TransportInterface::class, $transport);
    }

    public function test_can_be_constructed_with_bearer_token(): void
    {
        $transport = new StreamableHttpTransport('test-token');

        self::assertInstanceOf(StreamableHttpTransport::class, $transport);
    }

    public function test_refuses_construction_with_empty_bearer_token(): void
    {
        $this->expectException(McpException::class);

        new StreamableHttpTransport('');
    }

    public function test_is_open_returns_true_initially(): void
    {
        $transport = new StreamableHttpTransport('test-token');

        self::assertTrue($transport->isOpen());
    }

    public function test_read_rejects_missing_remote_addr(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}';

        $this->expectException(McpException::class);

        $this->withHttp($body, ['REQUEST_METHOD' => 'POST'], static fn () => (new StreamableHttpTransport('test-token'))->read());
    }

    public function test_read_requires_token_even_for_initialize(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}';
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST',
        ];

        $this->expectException(McpException::class);

        $this->withHttp($body, $server, static fn () => (new StreamableHttpTransport('right'))->read());
    }

    public function test_read_rejects_wrong_bearer_token(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}';
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST',
            'HTTP_AUTHORIZATION' => 'Bearer wrong',
        ];

        $this->expectException(McpException::class);

        $this->withHttp($body, $server, static fn () => (new StreamableHttpTransport('right'))->read());
    }

    public function test_read_does_not_put_token_in_params(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"x":1}}';
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => 'POST',
            'HTTP_AUTHORIZATION' => 'Bearer right',
        ];

        $request = $this->withHttp($body, $server, static fn () => (new StreamableHttpTransport('right'))->read());

        self::assertInstanceOf(McpRequest::class, $request);
        self::assertArrayNotHasKey('__auth_token', $request->params);
    }

    private function withHttp(string $body, array $server, callable $fn): mixed
    {
        $originalServer = $_SERVER;
        $_SERVER = $server;
        MockPhpInputStream::$data = $body;

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', MockPhpInputStream::class);

        try {
            return $fn();
        } finally {
            stream_wrapper_restore('php');
            $_SERVER = $originalServer;
        }
    }
}

final class MockPhpInputStream
{
    public static string $data = '';

    private int $position = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}
