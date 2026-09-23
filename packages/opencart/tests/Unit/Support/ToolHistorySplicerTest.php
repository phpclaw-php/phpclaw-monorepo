<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Support;

use PhpClaw\OpenCart\Support\ToolHistorySplicer;
use PHPUnit\Framework\TestCase;

final class ToolHistorySplicerTest extends TestCase
{
    public function test_splice_inserts_tool_rows_before_last_assistant_message(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'thinking…'],
        ];

        $toolCalls = [
            ['tool_name' => 'oc_product', 'tool_input' => ['query' => 'shoe'], 'tool_result' => 'found 3'],
        ];

        $payload = ['history' => $history];
        $result = ToolHistorySplicer::splice($payload, $toolCalls);

        $resultHistory = $result['history'];

        self::assertCount(3, $resultHistory, 'Splicer must insert 1 tool row giving 3 total rows.');
        self::assertSame('user', $resultHistory[0]['role']);
        self::assertSame('tool', $resultHistory[1]['role'], 'Tool row must come before the last assistant turn.');
        self::assertSame('oc_product', $resultHistory[1]['tool_name']);
        self::assertSame('found 3', $resultHistory[1]['content']);
        self::assertSame('assistant', $resultHistory[2]['role']);
    }

    public function test_splice_is_a_no_op_when_no_tool_calls(): void
    {
        $payload = ['history' => [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello']]];

        $result = ToolHistorySplicer::splice($payload, []);

        self::assertSame($payload, $result, 'splice() must return the original payload unchanged when there are no tool calls.');
    }

    public function test_splice_filters_entries_with_empty_tool_name(): void
    {
        $payload = ['history' => [['role' => 'assistant', 'content' => 'ok']]];
        $toolCalls = [
            ['tool_name' => '', 'tool_input' => [], 'tool_result' => 'ignored'],
        ];

        $result = ToolHistorySplicer::splice($payload, $toolCalls);

        self::assertCount(1, $result['history'], 'Empty tool_name entries must be filtered before splicing.');
    }

    public function test_messages_key_is_used_when_history_key_absent(): void
    {
        $payload = ['messages' => [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => 'a']]];
        $toolCalls = [['tool_name' => 'tool_x', 'tool_input' => [], 'tool_result' => 'r']];

        $result = ToolHistorySplicer::splice($payload, $toolCalls);

        self::assertArrayHasKey('history', $result, 'splice() must write into the history key even when input uses messages key.');
        self::assertCount(3, $result['history']);
    }
}
