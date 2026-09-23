<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\MemoryException;
use PHPUnit\Framework\TestCase;

final class ConversationTest extends TestCase
{
    public function test_start_returns_conversation_instance(): void
    {
        $conv = Conversation::start();
        $this->assertInstanceOf(Conversation::class, $conv);
    }

    public function test_start_generates_non_empty_id(): void
    {
        $conv = Conversation::start();
        $this->assertNotEmpty($conv->id);
    }

    public function test_start_generates_ulid_length_id(): void
    {
        $conv = Conversation::start();
        $this->assertSame(26, strlen($conv->id));
    }

    public function test_start_ids_are_unique(): void
    {
        $ids = array_map(fn () => Conversation::start()->id, range(1, 50));
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_start_sets_created_at_to_now(): void
    {
        $before = new \DateTimeImmutable;
        $conv = Conversation::start();
        $after = new \DateTimeImmutable;

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $conv->createdAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $conv->createdAt->getTimestamp());
    }

    public function test_start_history_is_empty(): void
    {
        $this->assertSame([], Conversation::start()->history);
    }

    public function test_start_accepts_metadata(): void
    {
        $conv = Conversation::start(['user_id' => 42, 'app' => 'phpClaw']);
        $this->assertSame(['user_id' => 42, 'app' => 'phpClaw'], $conv->metadata);
    }

    public function test_with_message_returns_new_instance(): void
    {
        $orig = Conversation::start();
        $updated = $orig->withMessage(Message::user('hello'));

        $this->assertNotSame($orig, $updated);
    }

    public function test_with_message_does_not_mutate_original(): void
    {
        $orig = Conversation::start();
        $orig->withMessage(Message::user('hello'));

        $this->assertSame(0, $orig->messageCount());
        $this->assertTrue($orig->isEmpty());
    }

    public function test_with_message_appends_to_history(): void
    {
        $conv = Conversation::start()
            ->withMessage(Message::user('first'))
            ->withMessage(Message::assistant('second'))
            ->withMessage(Message::user('third'));

        $this->assertSame(3, $conv->messageCount());
        $this->assertSame('first', $conv->history[0]->content);
        $this->assertSame('second', $conv->history[1]->content);
        $this->assertSame('third', $conv->history[2]->content);
    }

    public function test_with_message_preserves_id(): void
    {
        $orig = Conversation::start();
        $updated = $orig->withMessage(Message::user('hi'));

        $this->assertSame($orig->id, $updated->id);
    }

    public function test_with_message_preserves_created_at(): void
    {
        $orig = Conversation::start();
        $updated = $orig->withMessage(Message::user('hi'));

        $this->assertSame($orig->createdAt, $updated->createdAt);
    }

    public function test_with_message_preserves_metadata(): void
    {
        $orig = Conversation::start(['env' => 'prod']);
        $updated = $orig->withMessage(Message::user('hi'));

        $this->assertSame(['env' => 'prod'], $updated->metadata);
    }

    public function test_with_metadata_merges_values(): void
    {
        $conv = Conversation::start(['a' => 1])
            ->withMetadata(['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $conv->metadata);
    }

    public function test_with_metadata_does_not_mutate_original(): void
    {
        $orig = Conversation::start(['a' => 1]);
        $orig->withMetadata(['b' => 2]);

        $this->assertSame(['a' => 1], $orig->metadata);
    }

    public function test_with_metadata_overwrites_existing_key(): void
    {
        $conv = Conversation::start(['key' => 'old'])
            ->withMetadata(['key' => 'new']);

        $this->assertSame('new', $conv->metadata['key']);
    }

    public function test_last_message_returns_null_for_empty_conversation(): void
    {
        $this->assertNull(Conversation::start()->lastMessage());
    }

    public function test_last_message_returns_most_recent_message(): void
    {
        $conv = Conversation::start()
            ->withMessage(Message::user('first'))
            ->withMessage(Message::assistant('last'));

        $this->assertSame('last', $conv->lastMessage()?->content);
    }

    public function test_is_empty_returns_true_for_new_conversation(): void
    {
        $this->assertTrue(Conversation::start()->isEmpty());
    }

    public function test_is_empty_returns_false_after_message(): void
    {
        $conv = Conversation::start()->withMessage(Message::user('hi'));
        $this->assertFalse($conv->isEmpty());
    }

    public function test_message_count_is_zero_initially(): void
    {
        $this->assertSame(0, Conversation::start()->messageCount());
    }

    public function test_message_count_increments_with_each_message(): void
    {
        $conv = Conversation::start()
            ->withMessage(Message::user('a'))
            ->withMessage(Message::assistant('b'))
            ->withMessage(Message::user('c'));

        $this->assertSame(3, $conv->messageCount());
    }

    public function test_messages_by_role_filters_correctly(): void
    {
        $conv = Conversation::start()
            ->withMessage(Message::user('q1'))
            ->withMessage(Message::assistant('a1'))
            ->withMessage(Message::user('q2'))
            ->withMessage(Message::assistant('a2'));

        $userMessages = $conv->messagesByRole('user');
        $assistantMessages = $conv->messagesByRole('assistant');

        $this->assertCount(2, $userMessages);
        $this->assertCount(2, $assistantMessages);
        $this->assertSame('q1', $userMessages[0]->content);
        $this->assertSame('q2', $userMessages[1]->content);
    }

    public function test_to_array_contains_required_keys(): void
    {
        $arr = Conversation::start()->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('created_at', $arr);
        $this->assertArrayHasKey('history', $arr);
        $this->assertArrayHasKey('metadata', $arr);
    }

    public function test_to_array_serializes_messages(): void
    {
        $conv = Conversation::start()
            ->withMessage(Message::user('hello'))
            ->withMessage(Message::assistant('world'));

        $arr = $conv->toArray();

        $this->assertCount(2, $arr['history']);
        $this->assertSame('user', $arr['history'][0]['role']);
        $this->assertSame('hello', $arr['history'][0]['content']);
        $this->assertSame('assistant', $arr['history'][1]['role']);
    }

    public function test_from_array_restores_conversation(): void
    {
        $original = Conversation::start(['app' => 'test'])
            ->withMessage(Message::user('ping'))
            ->withMessage(Message::assistant('pong'));

        $restored = Conversation::fromArray($original->toArray());

        $this->assertSame($original->id, $restored->id);
        $this->assertSame($original->messageCount(), $restored->messageCount());
        $this->assertSame('ping', $restored->history[0]->content);
        $this->assertSame('pong', $restored->history[1]->content);
        $this->assertSame(['app' => 'test'], $restored->metadata);
    }

    public function test_from_array_restores_tool_use_message(): void
    {
        $original = Conversation::start()
            ->withMessage(Message::toolUse('id_01', 'shell_exec', ['command' => 'ls']));

        $restored = Conversation::fromArray($original->toArray());

        $msg = $restored->history[0];
        $this->assertSame('assistant', $msg->role);
        $this->assertSame('shell_exec', $msg->toolName);
        $this->assertSame(['command' => 'ls'], $msg->toolInput);
        $this->assertSame('id_01', $msg->toolUseId);
    }

    public function test_from_array_throws_on_missing_required_keys(): void
    {
        $this->expectException(MemoryException::class);
        Conversation::fromArray(['id' => '123']);
    }

    public function test_roundtrip_is_lossless(): void
    {
        $original = Conversation::start(['meta' => 'data'])
            ->withMessage(Message::user('question'))
            ->withMessage(Message::assistant('answer'))
            ->withMessage(Message::toolUse('t1', 'shell_exec', ['cmd' => 'pwd']))
            ->withMessage(Message::toolResult('t1', 'shell_exec', '/var/www'));

        $restored = Conversation::fromArray($original->toArray());

        $this->assertSame($original->id, $restored->id);
        $this->assertSame($original->messageCount(), $restored->messageCount());

        foreach (range(0, $original->messageCount() - 1) as $i) {
            $this->assertSame($original->history[$i]->role, $restored->history[$i]->role);
            $this->assertSame($original->history[$i]->content, $restored->history[$i]->content);
        }
    }
}
