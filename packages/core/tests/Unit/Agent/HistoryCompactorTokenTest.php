<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\HistoryCompactor;
use PhpClaw\Agent\Message;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PHPUnit\Framework\TestCase;

final class HistoryCompactorTokenTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function provider(): ProviderInterface
    {
        return new class implements ProviderInterface
        {
            public function name(): string
            {
                return 'fake';
            }

            public function model(): string
            {
                return 'fake-model';
            }

            public function send(array $messages, array $tools = []): array
            {
                return ['text' => 'SUMMARY'];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }
        };
    }

    private function history(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = Message::user("message number {$i} ".str_repeat('x', 50));
        }

        return $out;
    }

    public function test_token_overflow_triggers_compaction_with_count_disabled(): void
    {
        $compactor = new HistoryCompactor($this->provider(), maxHistoryLength: 0, enabled: true, maxTokens: 10);

        $result = $compactor->applyIfOversized($this->history(6), 'msg', 'run', 'iter');

        self::assertLessThan(6, count($result));
        self::assertStringContainsString('[Conversation summary]', $result[0]->content);
        self::assertStringContainsString('SUMMARY', $result[0]->content);
    }

    public function test_no_compaction_when_under_both_ceilings(): void
    {
        $compactor = new HistoryCompactor($this->provider(), maxHistoryLength: 0, enabled: true, maxTokens: 100000);

        $history = $this->history(6);
        $result = $compactor->applyIfOversized($history, 'msg', 'run', 'iter');

        self::assertCount(6, $result);
    }

    public function test_disabled_compactor_ignores_token_overflow(): void
    {
        $compactor = new HistoryCompactor($this->provider(), maxHistoryLength: 0, enabled: false, maxTokens: 1);

        $history = $this->history(6);
        $result = $compactor->applyIfOversized($history, 'msg', 'run', 'iter');

        self::assertCount(6, $result);
    }

    public function test_estimate_tokens_counts_tool_input_and_batch_results(): void
    {
        $compactor = new HistoryCompactor($this->provider(), maxHistoryLength: 0, enabled: true, maxTokens: 0);

        $method = new \ReflectionMethod($compactor, 'estimateTokens');
        $history = [
            new Message(role: 'assistant', content: str_repeat('a', 40), toolInput: ['key' => str_repeat('b', 40)]),
        ];

        self::assertGreaterThan(10, (int) $method->invoke($compactor, $history));
    }
}
