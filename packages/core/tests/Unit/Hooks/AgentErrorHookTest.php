<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Claw;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class AgentErrorHookTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        SkillRegistry::reset();
    }

    private function makePhpClaw(ProviderInterface $provider): Claw
    {
        return Claw::builder()
            ->providerOverride($provider)
            ->useDefaultGuards(false)
            ->build();
    }

    private function makeThrowingProvider(\Throwable $e): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('openai');
        $mock->method('model')->willReturn('gpt-4o-mini');
        $mock->method('send')->willThrowException($e);
        $mock->method('stream')->willThrowException($e);

        return $mock;
    }

    private function makeMaxIterProvider(): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('openai');
        $mock->method('model')->willReturn('gpt-4o-mini');
        $mock->method('send')->willReturn([
            'type' => 'tool_use_batch',
            'calls' => [],
        ]);

        return $mock;
    }

    public function test_agent_error_fires_on_send_provider_exception(): void
    {
        $fired = false;
        $context = [];

        HookRegistry::on('agent.error', function (array $ctx) use (&$fired, &$context): void {
            $fired = true;
            $context = $ctx;
        });

        $agent = $this->makePhpClaw(
            $this->makeThrowingProvider(new ProviderException('rate limited'))
        );

        try {
            $agent->send('hello');
        } catch (ProviderException) {
        }

        $this->assertTrue($fired);
        $this->assertSame('hello', $context['message']);
        $this->assertSame('rate limited', $context['error']);
        $this->assertSame(ProviderException::class, $context['class']);
        $this->assertArrayNotHasKey('streaming', $context);
    }

    public function test_agent_error_fires_on_send_max_iterations(): void
    {
        $fired = false;

        HookRegistry::on('agent.error', function () use (&$fired): void {
            $fired = true;
        });

        $agent = Claw::builder()
            ->providerOverride($this->makeMaxIterProvider())
            ->useDefaultGuards(false)
            ->maxIterations(1)
            ->build();

        try {
            $agent->send('loop forever');
        } catch (MaxIterationsException) {
        }

        $this->assertTrue($fired, 'agent.error must fire for MaxIterationsException too');
    }

    public function test_agent_error_rethrows_original_exception_on_send(): void
    {
        HookRegistry::on('agent.error', fn () => null);

        $agent = $this->makePhpClaw(
            $this->makeThrowingProvider(new ProviderException('boom'))
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('boom');

        $agent->send('test');
    }

    public function test_agent_error_fires_on_stream_with_streaming_flag(): void
    {
        $context = [];

        HookRegistry::on('agent.error', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = $this->makePhpClaw(
            $this->makeThrowingProvider(new ProviderException('stream fail'))
        );

        try {
            $agent->stream('hello', fn (string $t) => null);
        } catch (ProviderException) {
        }

        $this->assertSame('hello', $context['message'] ?? null);
        $this->assertTrue($context['streaming'] ?? false, 'streaming flag must be true');
        $this->assertSame('stream fail', $context['error'] ?? null);
    }

    public function test_agent_error_rethrows_original_exception_on_stream(): void
    {
        HookRegistry::on('agent.error', fn () => null);

        $agent = $this->makePhpClaw(
            $this->makeThrowingProvider(new ProviderException('stream boom'))
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('stream boom');

        $agent->stream('test', fn (string $t) => null);
    }

    public function test_agent_error_fires_with_conversation_id_in_send_in_conversation(): void
    {
        $context = [];

        HookRegistry::on('agent.error', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = Claw::builder()
            ->providerOverride($this->makeThrowingProvider(new ProviderException('conv fail')))
            ->useDefaultGuards(false)
            ->memory(new ArrayMemory)
            ->build();

        $conv = $agent->conversation();

        try {
            $agent->sendInConversation($conv, 'hello');
        } catch (ProviderException) {
        }

        $this->assertSame($conv->id, $context['conversation_id'] ?? null);
        $this->assertSame('conv fail', $context['error'] ?? null);
        $this->assertSame('hello', $context['message'] ?? null);
    }

    public function test_agent_error_rethrows_in_send_in_conversation(): void
    {
        HookRegistry::on('agent.error', fn () => null);

        $agent = Claw::builder()
            ->providerOverride($this->makeThrowingProvider(new ProviderException('conv boom')))
            ->useDefaultGuards(false)
            ->memory(new ArrayMemory)
            ->build();

        $conv = $agent->conversation();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('conv boom');

        $agent->sendInConversation($conv, 'test');
    }

    public function test_agent_error_hook_crash_does_not_swallow_original_exception(): void
    {
        HookRegistry::on('agent.error', function (): void {
            throw new \RuntimeException('hook itself blew up');
        });

        $agent = $this->makePhpClaw(
            $this->makeThrowingProvider(new ProviderException('real error'))
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('real error');

        $agent->send('test');
    }
}
