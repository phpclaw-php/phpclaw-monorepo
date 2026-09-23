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

final class ProviderErrorHookTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeFailingProvider(string $errorMessage): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willThrowException(new ProviderException($errorMessage));

        return $mock;
    }

    private function makeHallucinationProvider(): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')
            ->willReturnCallback(function () use (&$call): array {
                $call++;
                if ($call === 1) {
                    throw new ProviderException('tool call validation failed: unknown tool');
                }

                return ['type' => 'text', 'text' => 'Recovered answer'];
            });

        return $mock;
    }

    public function test_provider_error_fires_on_real_provider_failure(): void
    {
        $fired = false;
        $context = [];

        HookRegistry::on('provider.error', function (array $ctx) use (&$fired, &$context): void {
            $fired = true;
            $context = $ctx;
        });

        $agent = new Agent(
            provider: $this->makeFailingProvider('API rate limit exceeded'),
            tools: new ToolRegistry,
            maxIterations: 3,
        );

        try {
            $agent->run('hello');
        } catch (ProviderException) {
        }

        $this->assertTrue($fired, 'provider.error hook should have fired');
        $this->assertSame('anthropic', $context['provider']);
        $this->assertSame('claude-haiku-4-5-20251001', $context['model']);
        $this->assertSame('API rate limit exceeded', $context['error']);
        $this->assertSame(1, $context['iteration']);
    }

    public function test_provider_error_context_includes_iteration_number(): void
    {
        $iterations = [];

        HookRegistry::on('provider.error', function (array $ctx) use (&$iterations): void {
            $iterations[] = $ctx['iteration'];
        });

        $agent = new Agent(
            provider: $this->makeFailingProvider('Connection timeout'),
            tools: new ToolRegistry,
            maxIterations: 3,
        );

        try {
            $agent->run('hello');
        } catch (ProviderException) {
        }

        $this->assertSame([1], $iterations, 'Should fire on iteration 1 and rethrow');
    }

    public function test_provider_error_does_not_fire_on_hallucination_retry(): void
    {
        $fired = false;

        HookRegistry::on('provider.error', function () use (&$fired): void {
            $fired = true;
        });

        $agent = new Agent(
            provider: $this->makeHallucinationProvider(),
            tools: new ToolRegistry,
            maxIterations: 5,
        );

        $response = $agent->run('hello');

        $this->assertFalse($fired, 'provider.error must NOT fire on hallucination retry, that is expected behaviour');
        $this->assertSame('Recovered answer', $response->text);
    }

    public function test_provider_error_fires_with_streaming_flag_in_stream_mode(): void
    {
        $context = [];

        HookRegistry::on('provider.error', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('groq');
        $provider->method('model')->willReturn('llama-3.1-8b-instant');

        $toolRegistry = new ToolRegistry;
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
        $toolRegistry->register([$tool]);

        $provider->method('send')->willThrowException(new ProviderException('groq overloaded'));

        $agent = new Agent(
            provider: $provider,
            tools: $toolRegistry,
            maxIterations: 3,
        );

        try {
            $agent->stream('hello', fn (string $t) => null);
        } catch (ProviderException) {
        }

        $this->assertSame('groq', $context['provider'] ?? null);
        $this->assertSame('groq overloaded', $context['error'] ?? null);
        $this->assertTrue($context['streaming'] ?? false);
    }

    public function test_provider_error_hook_exception_does_not_swallow_provider_exception(): void
    {
        HookRegistry::on('provider.error', function (): void {
            throw new \RuntimeException('hook itself crashed');
        });

        $agent = new Agent(
            provider: $this->makeFailingProvider('upstream error'),
            tools: new ToolRegistry,
            maxIterations: 3,
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('upstream error');

        $agent->run('hello');
    }
}
