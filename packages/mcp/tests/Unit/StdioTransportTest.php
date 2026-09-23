<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Mcp\McpResponse;
use PhpClaw\Mcp\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class StdioTransportTest extends TestCase
{
    private function makeInput(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function makeOutput()
    {
        return fopen('php://memory', 'r+');
    }

    private function readOutput($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function test_read_parses_valid_json_line(): void
    {
        $input = $this->makeInput('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n");
        $transport = new StdioTransport($input, $this->makeOutput());

        $req = $transport->read();

        self::assertSame('tools/list', $req->method);
        self::assertSame(1, $req->id);
    }

    public function test_read_throws_on_invalid_json(): void
    {
        $input = $this->makeInput("not-json\n");
        $transport = new StdioTransport($input, $this->makeOutput());

        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        $transport->read();
    }

    public function test_read_throws_on_eof(): void
    {
        $input = $this->makeInput('');
        $transport = new StdioTransport($input, $this->makeOutput());

        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        $transport->read();
    }

    public function test_read_throws_on_empty_line(): void
    {
        $input = $this->makeInput("\n");
        $transport = new StdioTransport($input, $this->makeOutput());

        $this->expectException(McpException::class);
        $this->expectExceptionCode(-32700);

        $transport->read();
    }

    public function test_write_outputs_json_with_newline(): void
    {
        $output = $this->makeOutput();
        $transport = new StdioTransport($this->makeInput(''), $output);

        $transport->write(McpResponse::success(1, ['ok' => true]));

        $written = $this->readOutput($output);
        self::assertStringEndsWith("\n", $written);

        $parsed = json_decode(trim($written), associative: true);
        self::assertSame('2.0', $parsed['jsonrpc']);
        self::assertSame(1, $parsed['id']);
    }

    public function test_write_outputs_error_response(): void
    {
        $output = $this->makeOutput();
        $transport = new StdioTransport($this->makeInput(''), $output);

        $transport->write(McpResponse::error(null, -32601, 'Method not found'));

        $written = $this->readOutput($output);
        $parsed = json_decode(trim($written), associative: true);

        self::assertSame(-32601, $parsed['error']['code']);
    }

    public function test_is_open_true_before_eof(): void
    {
        $input = $this->makeInput('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n");
        $transport = new StdioTransport($input, $this->makeOutput());

        self::assertTrue($transport->isOpen());
    }

    public function test_is_open_false_after_eof_read(): void
    {
        $input = $this->makeInput('');
        $transport = new StdioTransport($input, $this->makeOutput());

        try {
            $transport->read();
        } catch (McpException) {
        }

        self::assertFalse($transport->isOpen());
    }

    public function test_multiple_writes_are_independent(): void
    {
        $output = $this->makeOutput();
        $transport = new StdioTransport($this->makeInput(''), $output);

        $transport->write(McpResponse::success(1, 'first'));
        $transport->write(McpResponse::success(2, 'second'));

        $written = $this->readOutput($output);
        $lines = array_filter(explode("\n", trim($written)));

        self::assertCount(2, $lines);
    }
}
