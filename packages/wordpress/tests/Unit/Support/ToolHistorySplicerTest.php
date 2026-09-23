<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Support;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\WordPress\Support\ToolHistorySplicer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolHistorySplicer::class)]
final class ToolHistorySplicerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        parent::tearDown();
    }

    public function test_collect_records_every_tool_after_event_in_order(): void
    {
        $calls = [];
        ToolHistorySplicer::collect($calls);

        HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
            'tool_name' => 'wp_query',
            'tool_input' => ['post_type' => 'post'],
            'tool_result' => '{"posts":[]}',
        ]);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
            'tool_name' => 'read_log',
            'tool_input' => [],
            'tool_result' => 'no errors',
        ]);

        self::assertCount(2, $calls);
        self::assertSame('wp_query', $calls[0]['tool_name']);
        self::assertSame(['post_type' => 'post'], $calls[0]['tool_input']);
        self::assertSame('{"posts":[]}', $calls[0]['tool_result']);
        self::assertSame('read_log', $calls[1]['tool_name']);
    }

    public function test_collect_normalises_a_context_that_is_missing_every_key(): void
    {
        $calls = [];
        ToolHistorySplicer::collect($calls);

        HookRegistry::fire(LifecycleEvent::ToolAfter->value, []);

        self::assertSame([['tool_name' => '', 'tool_input' => [], 'tool_result' => '']], $calls);
    }

    public function test_before_persist_leaves_the_payload_untouched_when_nothing_ran(): void
    {
        $calls = [];
        $payload = ['history' => [['role' => 'user', 'content' => 'hi']]];

        self::assertSame($payload, (ToolHistorySplicer::beforePersist($calls))($payload));
    }

    public function test_before_persist_drops_entries_that_carry_no_tool_name(): void
    {
        $calls = [['tool_name' => '', 'tool_input' => [], 'tool_result' => 'orphan']];
        $payload = ['history' => [['role' => 'user', 'content' => 'hi']]];

        self::assertSame($payload, (ToolHistorySplicer::beforePersist($calls))($payload));
    }

    public function test_tool_entries_are_spliced_directly_before_the_last_assistant_reply(): void
    {
        $calls = [[
            'tool_name' => 'wp_query',
            'tool_input' => ['post_type' => 'post'],
            'tool_result' => '3 posts',
        ]];

        $result = (ToolHistorySplicer::beforePersist($calls))([
            'history' => [
                ['role' => 'user', 'content' => 'how many posts?'],
                ['role' => 'assistant', 'content' => 'You have 3.'],
            ],
        ]);

        self::assertSame(
            ['user', 'tool', 'assistant'],
            array_column($result['history'], 'role'),
        );
        self::assertSame('3 posts', $result['history'][1]['content']);
        self::assertSame('wp_query', $result['history'][1]['tool_name']);
        self::assertSame(['post_type' => 'post'], $result['history'][1]['tool_input']);
    }

    public function test_tool_entries_are_appended_when_no_assistant_reply_exists_yet(): void
    {
        $calls = [['tool_name' => 'read_log', 'tool_input' => [], 'tool_result' => 'clean']];

        $result = (ToolHistorySplicer::beforePersist($calls))([
            'history' => [['role' => 'user', 'content' => 'check the log']],
        ]);

        self::assertSame(['user', 'tool'], array_column($result['history'], 'role'));
    }

    public function test_a_payload_keyed_messages_is_read_and_written_back_as_history(): void
    {
        $calls = [['tool_name' => 'read_log', 'tool_input' => [], 'tool_result' => 'clean']];

        $result = (ToolHistorySplicer::beforePersist($calls))([
            'messages' => [
                ['role' => 'user', 'content' => 'check the log'],
                ['role' => 'assistant', 'content' => 'All clean.'],
            ],
        ]);

        self::assertSame(['user', 'tool', 'assistant'], array_column($result['history'], 'role'));
    }

    public function test_only_the_last_assistant_reply_is_used_as_the_splice_point(): void
    {
        $calls = [['tool_name' => 'wp_query', 'tool_input' => [], 'tool_result' => 'rows']];

        $result = (ToolHistorySplicer::beforePersist($calls))([
            'history' => [
                ['role' => 'assistant', 'content' => 'earlier turn'],
                ['role' => 'user', 'content' => 'and now?'],
                ['role' => 'assistant', 'content' => 'latest turn'],
            ],
        ]);

        self::assertSame(
            ['assistant', 'user', 'tool', 'assistant'],
            array_column($result['history'], 'role'),
        );
    }
}
