<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Mcp\Contracts\TransportInterface;
use PhpClaw\Mcp\McpRequest;
use PhpClaw\Mcp\McpResponse;
use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Tests\Stubs\EchoTool;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawMcpServerTest extends TestCase
{
    private ToolRegistry $registry;

    private PhpClawMcpServer $server;

    protected function setUp(): void
    {
        GuardRegistry::reset();

        $this->registry = new ToolRegistry;
        $this->registry->register([new EchoTool]);

        $this->server = new PhpClawMcpServer($this->registry);
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
    }

    public function test_handle_initialize_succeeds(): void
    {
        $req = McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $resp = $this->server->handle($req);

        self::assertNull($resp->error);
        self::assertArrayHasKey('protocolVersion', $resp->result);
    }

    public function test_handle_tools_list_returns_tools(): void
    {
        $req = McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $resp = $this->server->handle($req);

        self::assertCount(1, $resp->result['tools']);
        self::assertSame('echo', $resp->result['tools'][0]['name']);
    }

    public function test_handle_tools_call_returns_result(): void
    {
        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'ping']],
        ]);
        $resp = $this->server->handle($req);

        self::assertNull($resp->error);
        self::assertSame('ping', $resp->result['content'][0]['text']);
    }

    public function test_server_does_not_authenticate_from_params(): void
    {
        $server = new PhpClawMcpServer($this->registry);

        $req = McpRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/list',
            'params' => ['__auth_token' => 'anything'],
        ]);
        $resp = $server->handle($req);

        self::assertNull($resp->error);
    }

    public function test_auth_skipped_for_initialize(): void
    {
        $server = new PhpClawMcpServer($this->registry);

        $req = McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'initialize']);
        $resp = $server->handle($req);

        self::assertNull($resp->error);
    }

    public function test_guards_env_rejects_non_guard_class(): void
    {
        GuardRegistry::reset();
        putenv('PHPCLAW_GUARDS');
        new PhpClawMcpServer($this->registry);
        $defaultCount = GuardRegistry::count();

        GuardRegistry::reset();
        putenv('PHPCLAW_GUARDS=[{"class":"ArrayObject"}]');
        new PhpClawMcpServer($this->registry);
        putenv('PHPCLAW_GUARDS');

        self::assertSame($defaultCount, GuardRegistry::count());
    }

    public function test_hooks_env_rejects_non_hook_handler(): void
    {
        HookRegistry::reset();
        putenv('PHPCLAW_HOOKS=[{"event":"tool.before","handler":"ArrayObject"}]');
        new PhpClawMcpServer($this->registry);
        putenv('PHPCLAW_HOOKS');

        self::assertSame(0, HookRegistry::count('tool.before'));
    }

    public function test_no_auth_when_token_is_null(): void
    {
        $req = McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list']);
        $resp = $this->server->handle($req);

        self::assertNull($resp->error);
    }

    public function test_serve_writes_response_for_each_request(): void
    {
        $written = [];
        $callCount = 0;

        $transport = new class($written, $callCount) implements TransportInterface
        {
            private array $responses;

            private int $calls;

            public function __construct(array &$responses, int &$calls)
            {
                $this->responses = &$responses;
                $this->calls = &$calls;
            }

            public function read(): McpRequest
            {
                return McpRequest::fromArray(['jsonrpc' => '2.0', 'id' => $this->calls + 1, 'method' => 'tools/list']);
            }

            public function write(McpResponse $response): void
            {
                $this->responses[] = $response;
            }

            public function isOpen(): bool
            {
                $this->calls++;

                return $this->calls <= 2;
            }
        };

        $this->server->serve($transport);

        self::assertCount(2, $written);
    }

    public function test_serve_does_not_write_for_notifications(): void
    {
        $written = [];
        $callCount = 0;

        $transport = new class($written, $callCount) implements TransportInterface
        {
            private array $responses;

            private int $calls;

            public function __construct(array &$responses, int &$calls)
            {
                $this->responses = &$responses;
                $this->calls = &$calls;
            }

            public function read(): McpRequest
            {
                return McpRequest::fromArray([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/initialized',
                ]);
            }

            public function write(McpResponse $response): void
            {
                $this->responses[] = $response;
            }

            public function isOpen(): bool
            {
                $this->calls++;

                return $this->calls <= 1;
            }
        };

        $this->server->serve($transport);

        self::assertCount(0, $written);
    }
}
