<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class StreamHookTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeFastPathProvider(string $fullText = 'Hello world'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('stream')->willReturnCallback(
            function (array $history, callable $onToken) use ($fullText): string {
                foreach (str_split($fullText, 5) as $chunk) {
                    $onToken($chunk);
                }

                return $fullText;
            }
        );

        return $mock;
    }

    private function makeFailingStreamProvider(): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('stream')->willThrowException(new ProviderException('connection lost'));

        return $mock;
    }

    private function makeHybridProvider(string $text = 'Hybrid answer'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('openai');
        $mock->method('model')->willReturn('gpt-4o-mini');
        $mock->method('send')->willReturn(['type' => 'text', 'text' => $text]);

        return $mock;
    }

    private function makeDummyTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public function name(): string
            {
                return 'dummy';
            }

            public function description(): string
            {
                return 'dummy tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                return 'ok';
            }
        };
    }

    public function test_stream_start_fires_on_fast_path(): void
    {
        $context = [];

        HookRegistry::on('stream.start', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = new Agent($this->makeFastPathProvider(), new ToolRegistry, 5);
        $agent->stream('hello', fn (string $t) => null);

        $this->assertSame('hello', $context['message'] ?? null);
        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertSame('claude-haiku-4-5-20251001', $context['model'] ?? null);
    }

    public function test_stream_start_fires_on_hybrid_path(): void
    {
        $fired = false;

        HookRegistry::on('stream.start', function () use (&$fired): void {
            $fired = true;
        });

        $tools = new ToolRegistry;
        $tools->register([$this->makeDummyTool()]);

        $agent = new Agent($this->makeHybridProvider(), $tools, 5);
        $agent->stream('hello', fn (string $t) => null);

        $this->assertTrue($fired, 'stream.start must fire on the hybrid path too');
    }

    public function test_stream_end_fires_on_fast_path_with_duration_and_chars(): void
    {
        $context = [];

        HookRegistry::on('stream.end', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = new Agent($this->makeFastPathProvider('Hello world'), new ToolRegistry, 5);
        $agent->stream('hi', fn (string $t) => null);

        $this->assertSame('hi', $context['message'] ?? null);
        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertIsInt($context['duration_ms'] ?? null);
        $this->assertSame(11, $context['chars'] ?? null);
    }

    public function test_stream_end_fires_on_hybrid_path(): void
    {
        $context = [];

        HookRegistry::on('stream.end', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $tools = new ToolRegistry;
        $tools->register([$this->makeDummyTool()]);

        $agent = new Agent($this->makeHybridProvider('Hybrid answer'), $tools, 5);
        $agent->stream('hi', fn (string $t) => null);

        $this->assertSame('hi', $context['message'] ?? null);
        $this->assertSame(mb_strlen('Hybrid answer'), $context['chars'] ?? null);
        $this->assertIsInt($context['duration_ms'] ?? null);
    }

    public function test_stream_end_does_not_fire_when_stream_aborts(): void
    {
        $streamEndFired = false;

        HookRegistry::on('stream.end', function () use (&$streamEndFired): void {
            $streamEndFired = true;
        });

        $agent = new Agent($this->makeFailingStreamProvider(), new ToolRegistry, 5);

        try {
            $agent->stream('hi', fn (string $t) => null);
        } catch (ProviderException) {
        }

        $this->assertFalse($streamEndFired, 'stream.end must NOT fire when streaming aborts');
    }

    public function test_stream_abort_fires_when_fast_path_provider_throws(): void
    {
        $context = [];

        HookRegistry::on('stream.abort', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = new Agent($this->makeFailingStreamProvider(), new ToolRegistry, 5);

        try {
            $agent->stream('hi', fn (string $t) => null);
        } catch (ProviderException) {
        }

        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertSame('connection lost', $context['error'] ?? null);
    }

    public function test_stream_abort_rethrows_original_exception(): void
    {
        HookRegistry::on('stream.abort', fn () => null);

        $agent = new Agent($this->makeFailingStreamProvider(), new ToolRegistry, 5);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('connection lost');

        $agent->stream('hi', fn (string $t) => null);
    }

    public function test_stream_abort_does_not_fire_on_successful_stream(): void
    {
        $abortFired = false;

        HookRegistry::on('stream.abort', function () use (&$abortFired): void {
            $abortFired = true;
        });

        $agent = new Agent($this->makeFastPathProvider(), new ToolRegistry, 5);
        $agent->stream('hi', fn (string $t) => null);

        $this->assertFalse($abortFired, 'stream.abort must NOT fire on success');
    }

    public function test_provider_token_fires_for_each_chunk_on_fast_path(): void
    {
        $tokens = [];

        HookRegistry::on('provider.token', function (array $ctx) use (&$tokens): void {
            $tokens[] = $ctx['token'];
        });

        $agent = new Agent($this->makeFastPathProvider('Hello world'), new ToolRegistry, 5);
        $agent->stream('hi', fn (string $t) => null);

        $this->assertNotEmpty($tokens);
        $this->assertSame('Hello world', implode('', $tokens));
    }

    public function test_provider_token_context_includes_provider_and_model(): void
    {
        $context = [];

        HookRegistry::on('provider.token', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = new Agent($this->makeFastPathProvider('Hi'), new ToolRegistry, 5);
        $agent->stream('hi', fn (string $t) => null);

        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertSame('claude-haiku-4-5-20251001', $context['model'] ?? null);
    }

    public function test_provider_token_fires_for_each_chunk_on_hybrid_path(): void
    {
        $tokens = [];

        HookRegistry::on('provider.token', function (array $ctx) use (&$tokens): void {
            $tokens[] = $ctx['token'];
        });

        $tools = new ToolRegistry;
        $tools->register([$this->makeDummyTool()]);

        $agent = new Agent($this->makeHybridProvider(), $tools, 5);
        $agent->stream('hi', fn (string $t) => null);

        $this->assertNotEmpty($tokens, 'provider.token must fire on hybrid path (chunked final text) so streaming UIs can render tokens after the tool loop completes');
    }

    public function test_original_on_token_callback_still_called_despite_provider_token_hook(): void
    {
        $received = [];

        HookRegistry::on('provider.token', fn () => null);

        $agent = new Agent($this->makeFastPathProvider('Hello world'), new ToolRegistry, 5);
        $agent->stream('hi', function (string $t) use (&$received): void {
            $received[] = $t;
        });

        $this->assertSame('Hello world', implode('', $received));
    }
}
