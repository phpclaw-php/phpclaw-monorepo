<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\ToolHistorySplicer;
use PHPUnit\Framework\TestCase;

final class ToolHistorySplicerTest extends TestCase
{
    private ToolHistorySplicer $splicer;

    protected function setUp(): void
    {
        $this->splicer = new ToolHistorySplicer;
    }

    public function test_splice_inserts_tool_calls_after_last_assistant(): void
    {
        $history = [
            ['role' => 'user',      'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi'],
        ];

        $toolCalls = [
            ['tool_name' => 'db_query', 'tool_input' => ['query' => 'SELECT 1'], 'tool_result' => '[{"1":1}]'],
        ];

        $result = $this->splicer->splice($history, $toolCalls, 1);

        self::assertCount(3, $result);
        self::assertSame('tool', $result[1]['role']);
        self::assertSame('db_query', $result[1]['tool_name']);
        self::assertSame('assistant', $result[2]['role']);
    }

    public function test_splice_handles_no_assistant_entry(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $toolCalls = [
            ['tool_name' => 'shell', 'tool_input' => ['cmd' => 'ls'], 'tool_result' => 'file.txt'],
        ];

        $result = $this->splicer->splice($history, $toolCalls, 1);

        self::assertCount(2, $result);
        self::assertSame('user', $result[0]['role']);
        self::assertSame('tool', $result[1]['role']);
    }

    public function test_find_last_assistant_index_returns_index_of_last_assistant(): void
    {
        $history = [
            ['role' => 'user',      'content' => 'A'],
            ['role' => 'assistant', 'content' => 'B'],
            ['role' => 'user',      'content' => 'C'],
            ['role' => 'assistant', 'content' => 'D'],
        ];

        self::assertSame(3, $this->splicer->findLastAssistantIndex($history));
    }

    public function test_find_last_assistant_index_returns_the_history_length_when_absent(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'A'],
            ['role' => 'user', 'content' => 'B'],
        ];

        self::assertSame(
            count($history),
            $this->splicer->findLastAssistantIndex($history),
            'with no assistant turn the splice point is the end of the history',
        );
    }

    public function test_find_last_assistant_index_returns_count_on_empty_history(): void
    {
        self::assertSame(0, $this->splicer->findLastAssistantIndex([]));
    }

    public function test_splice_maps_tool_result_to_content(): void
    {
        $history = [['role' => 'assistant', 'content' => 'ok']];
        $toolCalls = [
            ['tool_name' => 'http', 'tool_input' => [], 'tool_result' => '{"status":200}'],
        ];

        $result = $this->splicer->splice($history, $toolCalls, 0);

        self::assertSame('{"status":200}', $result[0]['content']);
        self::assertSame('tool', $result[0]['role']);
    }
}
