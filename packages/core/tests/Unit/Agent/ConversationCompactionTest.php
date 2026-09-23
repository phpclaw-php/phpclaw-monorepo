<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Agent\Message;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ConversationCompactionTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function makeCallbackProvider(callable $callback): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnCallback($callback);

        return $mock;
    }

    private function makeAgent(ProviderInterface $provider, int $maxHistoryLength): Agent
    {
        return new Agent($provider, new ToolRegistry, maxIterations: 20, maxRetries: 0, maxHistoryLength: $maxHistoryLength);
    }

    private function twoTurnHistory(): array
    {
        return [
            Message::user('first question'),
            Message::assistant('first answer'),
            Message::user('second question'),
            Message::assistant('second answer'),
        ];
    }

    public function test_no_compaction_when_history_within_limit(): void
    {
        $callCount = 0;

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function () use (&$callCount): array {
                $callCount++;

                return ['type' => 'text', 'text' => 'OK'];
            }),
            maxHistoryLength: 100,
        );

        $agent->run('hello');

        $this->assertSame(1, $callCount);
    }

    public function test_no_compaction_when_disabled(): void
    {
        $callCount = 0;

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function () use (&$callCount): array {
                $callCount++;

                return ['type' => 'text', 'text' => 'OK'];
            }),
            maxHistoryLength: 0,
        );

        $agent->run('hello');

        $this->assertSame(1, $callCount);
    }

    public function test_compaction_triggers_extra_provider_call_for_summary(): void
    {
        $callCount = 0;

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages) use (&$callCount): array {
                $callCount++;
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    return ['type' => 'text', 'text' => 'This is the summary.'];
                }

                return ['type' => 'text', 'text' => 'Final answer.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $this->assertGreaterThanOrEqual(2, $callCount);
    }

    public function test_summary_message_prepended_to_compacted_history(): void
    {
        $capturedHistory = [];

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages) use (&$capturedHistory): array {
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    return ['type' => 'text', 'text' => 'Compact summary text.'];
                }
                $capturedHistory = $messages;

                return ['type' => 'text', 'text' => 'Done.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $first = $capturedHistory[0] ?? null;
        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame('assistant', $first->role);
        $this->assertStringContainsString('[Conversation summary]', $first->content);
        $this->assertStringContainsString('Compact summary text.', $first->content);
    }

    public function test_recent_messages_preserved_after_compaction(): void
    {
        $capturedHistory = [];

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages) use (&$capturedHistory): array {
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    return ['type' => 'text', 'text' => 'Summary.'];
                }
                $capturedHistory = $messages;

                return ['type' => 'text', 'text' => 'Done.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $last = end($capturedHistory);
        $this->assertInstanceOf(Message::class, $last);
        $this->assertSame('user', $last->role);
        $this->assertSame('third question', $last->content);
    }

    public function test_summary_unavailable_used_when_provider_throws_on_summary(): void
    {
        $capturedHistory = [];

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages) use (&$capturedHistory): array {
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    throw new \RuntimeException('provider down');
                }
                $capturedHistory = $messages;

                return ['type' => 'text', 'text' => 'Done.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $first = $capturedHistory[0] ?? null;
        $this->assertInstanceOf(Message::class, $first);
        $this->assertStringContainsString('(summary unavailable)', $first->content);
    }

    public function test_context_overflow_hook_fires_after_compaction(): void
    {
        $hookFired = false;

        HookRegistry::on('context.overflow', function () use (&$hookFired): void {
            $hookFired = true;
        });

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages): array {
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    return ['type' => 'text', 'text' => 'Summary.'];
                }

                return ['type' => 'text', 'text' => 'Done.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $this->assertTrue($hookFired, 'context.overflow must fire when compaction occurs');
    }

    public function test_no_summary_call_when_compaction_disabled(): void
    {
        $callCount = 0;

        $provider = $this->makeCallbackProvider(function () use (&$callCount): array {
            $callCount++;

            return ['type' => 'text', 'text' => 'Final answer.'];
        });

        $agent = new Agent(
            $provider,
            new ToolRegistry,
            maxIterations: 20,
            maxRetries: 0,
            maxHistoryLength: 2,
            compactHistory: false,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $this->assertSame(1, $callCount);
    }

    public function test_compaction_before_hook_fires_when_compaction_runs(): void
    {
        $fired = false;

        HookRegistry::on('compaction.before', function () use (&$fired): void {
            $fired = true;
        });

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages): array {
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    return ['type' => 'text', 'text' => 'Summary.'];
                }

                return ['type' => 'text', 'text' => 'Done.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $this->assertTrue($fired, 'compaction.before must fire when compaction runs');
    }

    public function test_compaction_after_hook_carries_summary(): void
    {
        $captured = null;

        HookRegistry::on('compaction.after', function (array $ctx) use (&$captured): void {
            $captured = $ctx['summary'] ?? null;
        });

        $agent = $this->makeAgent(
            $this->makeCallbackProvider(function (array $messages): array {
                $last = end($messages);
                if ($last instanceof Message && str_contains($last->content, 'Summarise')) {
                    return ['type' => 'text', 'text' => 'Compact summary text.'];
                }

                return ['type' => 'text', 'text' => 'Done.'];
            }),
            maxHistoryLength: 2,
        );

        $agent->run('third question', $this->twoTurnHistory());

        $this->assertSame('Compact summary text.', $captured);
    }
}
