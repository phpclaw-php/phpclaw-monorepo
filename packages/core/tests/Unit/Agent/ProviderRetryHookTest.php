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

final class ProviderRetryHookTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeFlappyProvider(int $failTimes, string $errorMsg = 'timeout'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')->willReturnCallback(function () use (&$call, $failTimes, $errorMsg): array {
            $call++;
            if ($call <= $failTimes) {
                throw new ProviderException($errorMsg);
            }

            return ['type' => 'text', 'text' => 'Success after retries'];
        });

        return $mock;
    }

    private function makeAlwaysFailingProvider(string $errorMsg = 'permanent error'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willThrowException(new ProviderException($errorMsg));

        return $mock;
    }

    private function makeCachingProvider(int $cacheReadTokens, ?int $cacheWriteTokens = null): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturn([
            'type' => 'text',
            'text' => 'Cached response',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
        ]);

        return $mock;
    }

    public function test_provider_retry_fires_before_each_retry_attempt(): void
    {
        $attempts = [];

        HookRegistry::on('provider.retry', function (array $ctx) use (&$attempts): void {
            $attempts[] = $ctx['attempt'];
        });

        $agent = new Agent($this->makeFlappyProvider(2), new ToolRegistry, maxIterations: 5, maxRetries: 2);
        $agent->run('hello');

        $this->assertSame([1, 2], $attempts, 'provider.retry fires once per retry with 1-based attempt number');
    }

    public function test_provider_retry_context_includes_provider_model_error_iteration(): void
    {
        $context = [];

        HookRegistry::on('provider.retry', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = new Agent($this->makeFlappyProvider(1, 'rate limited'), new ToolRegistry, maxIterations: 5, maxRetries: 1);
        $agent->run('hello');

        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertSame('claude-haiku-4-5-20251001', $context['model'] ?? null);
        $this->assertSame('rate limited', $context['error'] ?? null);
        $this->assertSame(1, $context['iteration'] ?? null);
        $this->assertArrayNotHasKey('streaming', $context);
    }

    public function test_provider_retry_does_not_fire_when_max_retries_is_zero(): void
    {
        $fired = false;

        HookRegistry::on('provider.retry', function () use (&$fired): void {
            $fired = true;
        });

        $agent = new Agent($this->makeAlwaysFailingProvider(), new ToolRegistry, maxIterations: 5, maxRetries: 0);

        try {
            $agent->run('hello');
        } catch (ProviderException) {
        }

        $this->assertFalse($fired, 'provider.retry must NOT fire when maxRetries=0');
    }

    public function test_provider_error_fires_only_after_all_retries_exhausted(): void
    {
        $retryCount = 0;
        $errorFired = false;

        HookRegistry::on('provider.retry', function () use (&$retryCount): void {
            $retryCount++;
        });

        HookRegistry::on('provider.error', function () use (&$errorFired): void {
            $errorFired = true;
        });

        $agent = new Agent($this->makeAlwaysFailingProvider(), new ToolRegistry, maxIterations: 5, maxRetries: 2);

        try {
            $agent->run('hello');
        } catch (ProviderException) {
        }

        $this->assertSame(2, $retryCount, '2 retries before giving up');
        $this->assertTrue($errorFired, 'provider.error fires once after exhausting all retries');
    }

    public function test_provider_retry_fires_with_streaming_flag_in_hybrid_stream(): void
    {
        $context = [];

        HookRegistry::on('provider.retry', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $tools = new ToolRegistry;
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'dummy';
            }

            public function description(): string
            {
                return 'dummy';
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
        $tools->register([$tool]);

        $agent = new Agent($this->makeFlappyProvider(1), $tools, maxIterations: 5, maxRetries: 1);
        $agent->stream('hello', fn (string $t) => null);

        $this->assertTrue($context['streaming'] ?? false, 'streaming flag must be true in hybrid stream retry');
    }

    public function test_original_exception_is_rethrown_after_retries_exhausted(): void
    {
        HookRegistry::on('provider.retry', fn () => null);

        $agent = new Agent($this->makeAlwaysFailingProvider('permanent error'), new ToolRegistry, maxIterations: 5, maxRetries: 1);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('permanent error');

        $agent->run('hello');
    }

    public function test_provider_retry_does_not_fire_on_hallucination_rejection(): void
    {
        $retryFired = false;

        HookRegistry::on('provider.retry', function () use (&$retryFired): void {
            $retryFired = true;
        });

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')->willReturnCallback(function () use (&$call): array {
            $call++;
            if ($call === 1) {
                throw new ProviderException('tool call validation failed: unknown tool');
            }

            return ['type' => 'text', 'text' => 'Recovered'];
        });

        $agent = new Agent($mock, new ToolRegistry, maxIterations: 5, maxRetries: 3);
        $agent->run('hello');

        $this->assertFalse($retryFired, 'provider.retry must NOT fire for hallucination, those use the hallucination path, not retries');
    }

    public function test_provider_cache_hit_fires_when_cache_read_tokens_present(): void
    {
        $context = [];

        HookRegistry::on('provider.cache_hit', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = new Agent($this->makeCachingProvider(cacheReadTokens: 500, cacheWriteTokens: 100), new ToolRegistry, 5);
        $agent->run('hello');

        $this->assertSame('anthropic', $context['provider'] ?? null);
        $this->assertSame('claude-haiku-4-5-20251001', $context['model'] ?? null);
        $this->assertSame(500, $context['cache_read_tokens'] ?? null);
        $this->assertSame(100, $context['cache_write_tokens'] ?? null);
    }

    public function test_provider_cache_hit_does_not_fire_when_no_cache_tokens(): void
    {
        $fired = false;

        HookRegistry::on('provider.cache_hit', function () use (&$fired): void {
            $fired = true;
        });

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('openai');
        $mock->method('model')->willReturn('gpt-4o-mini');
        $mock->method('send')->willReturn(['type' => 'text', 'text' => 'No cache']);

        $agent = new Agent($mock, new ToolRegistry, 5);
        $agent->run('hello');

        $this->assertFalse($fired, 'provider.cache_hit must NOT fire when cache_read_tokens is absent');
    }

    public function test_provider_cache_hit_does_not_fire_when_cache_read_tokens_is_zero(): void
    {
        $fired = false;

        HookRegistry::on('provider.cache_hit', function () use (&$fired): void {
            $fired = true;
        });

        $agent = new Agent($this->makeCachingProvider(cacheReadTokens: 0), new ToolRegistry, 5);
        $agent->run('hello');

        $this->assertFalse($fired, 'provider.cache_hit must NOT fire when cache_read_tokens = 0');
    }

    public function test_provider_cache_hit_fires_in_stream_hybrid_path(): void
    {
        $fired = false;

        HookRegistry::on('provider.cache_hit', function () use (&$fired): void {
            $fired = true;
        });

        $tools = new ToolRegistry;
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'dummy';
            }

            public function description(): string
            {
                return 'dummy';
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
        $tools->register([$tool]);

        $agent = new Agent($this->makeCachingProvider(cacheReadTokens: 200), $tools, 5);
        $agent->stream('hello', fn (string $t) => null);

        $this->assertTrue($fired, 'provider.cache_hit must fire in stream() hybrid path too');
    }
}
