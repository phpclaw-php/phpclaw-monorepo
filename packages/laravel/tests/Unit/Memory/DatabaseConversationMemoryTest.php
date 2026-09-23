<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Memory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class DatabaseConversationMemoryTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseConversationMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('phpclaw.store_messages', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new DatabaseConversationMemory;
    }

    public function test_set_and_get_conversation(): void
    {
        $this->memory->set('conv_1', ['history' => []]);

        $result = $this->memory->get('conv_1');

        $this->assertNotNull($result);
        $this->assertSame('conv_1', $result['id']);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->get('nonexistent'));
    }

    public function test_set_upserts_existing_conversation(): void
    {
        $this->memory->set('conv_upsert', ['title' => 'First',   'history' => []]);
        $this->memory->set('conv_upsert', ['title' => 'Updated', 'history' => []]);

        $result = $this->memory->get('conv_upsert');

        $this->assertSame('Updated', $result['title']);
    }

    public function test_set_throws_on_non_array_value(): void
    {
        $this->expectException(MemoryException::class);

        $this->memory->set('bad', 'not an array');
    }

    public function test_get_includes_metadata(): void
    {
        $this->memory->set('conv_meta', ['metadata' => ['source' => 'api'], 'history' => []]);

        $result = $this->memory->get('conv_meta');

        $this->assertSame(['source' => 'api'], $result['metadata']);
    }

    public function test_title_auto_extracted_from_first_user_message(): void
    {
        $this->memory->set('conv_title', [
            'history' => [
                ['role' => 'user', 'content' => 'Hello, what time is it?'],
            ],
        ]);

        $result = $this->memory->get('conv_title');

        $this->assertSame('Hello, what time is it?', $result['title']);
    }

    public function test_explicit_title_takes_precedence_over_auto_extract(): void
    {
        $this->memory->set('conv_explicit_title', [
            'title' => 'My Custom Title',
            'history' => [
                ['role' => 'user', 'content' => 'This should not be the title'],
            ],
        ]);

        $result = $this->memory->get('conv_explicit_title');

        $this->assertSame('My Custom Title', $result['title']);
    }

    public function test_has_returns_true_for_existing_conversation(): void
    {
        $this->memory->set('conv_has', ['history' => []]);

        $this->assertTrue($this->memory->has('conv_has'));
    }

    public function test_has_returns_false_for_missing_conversation(): void
    {
        $this->assertFalse($this->memory->has('never_set'));
    }

    public function test_forget_removes_conversation(): void
    {
        $this->memory->set('conv_del', ['history' => []]);
        $this->memory->forget('conv_del');

        $this->assertNull($this->memory->get('conv_del'));
        $this->assertFalse($this->memory->has('conv_del'));
    }

    public function test_forget_nonexistent_key_does_not_throw(): void
    {
        $this->memory->set('kept', ['history' => []]);

        $this->memory->forget('does_not_exist');

        $this->assertNull($this->memory->get('does_not_exist'));
        $this->assertNotNull($this->memory->get('kept'), 'forgetting an absent key must not touch its neighbours');
    }

    public function test_flush_removes_all_conversations_in_namespace(): void
    {
        $this->memory->set('c1', ['history' => []]);
        $this->memory->set('c2', ['history' => []]);

        $this->memory->flush();

        $this->assertSame([], $this->memory->all());
    }

    public function test_flush_does_not_affect_other_namespaces(): void
    {
        $this->memory->set('c_default', ['history' => []], 'default');
        $this->memory->set('c_other', ['history' => []], 'other');

        $this->memory->flush('default');

        $this->assertNull($this->memory->get('c_default', 'default'));
        $this->assertNotNull($this->memory->get('c_other', 'other'));
    }

    public function test_all_returns_every_conversation_in_namespace(): void
    {
        $this->memory->set('x', ['history' => []]);
        $this->memory->set('y', ['history' => []]);

        $all = $this->memory->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('x', $all);
        $this->assertArrayHasKey('y', $all);
    }

    public function test_all_returns_empty_for_unknown_namespace(): void
    {
        $this->assertSame([], $this->memory->all('nonexistent_ns'));
    }

    public function test_namespace_isolation(): void
    {
        $this->memory->set('conv_ns_a', ['title' => 'in-ns-a', 'history' => []], 'ns_a');
        $this->memory->set('conv_ns_b', ['title' => 'in-ns-b', 'history' => []], 'ns_b');

        $this->assertNotNull($this->memory->get('conv_ns_a', 'ns_a'));
        $this->assertNotNull($this->memory->get('conv_ns_b', 'ns_b'));

        $this->assertNull($this->memory->get('conv_ns_a', 'ns_b'));
        $this->assertNull($this->memory->get('conv_ns_b', 'ns_a'));
    }

    public function test_messages_not_written_when_store_messages_false(): void
    {
        config(['phpclaw.store_messages' => false]);

        $this->memory->set('priv_conv', [
            'history' => [
                ['role' => 'user',      'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
            ],
        ]);

        $messageCount = DB::table('phpclaw_messages')
            ->where('conversation_id', 'priv_conv')
            ->count();

        $this->assertSame(0, $messageCount, 'Messages must NOT be stored when store_messages=false');
    }

    public function test_history_empty_when_store_messages_false(): void
    {
        config(['phpclaw.store_messages' => false]);

        $this->memory->set('priv_conv2', [
            'history' => [
                ['role' => 'user',      'content' => 'Question'],
                ['role' => 'assistant', 'content' => 'Answer'],
            ],
        ]);

        $result = $this->memory->get('priv_conv2');

        $this->assertSame([], $result['history'], 'History must be empty when store_messages=false');
    }

    public function test_messages_written_when_store_messages_true(): void
    {
        config(['phpclaw.store_messages' => true]);

        $this->memory->set('stored_conv', [
            'history' => [
                ['role' => 'user',      'content' => 'Question'],
                ['role' => 'assistant', 'content' => 'Answer'],
            ],
        ]);

        $messageCount = DB::table('phpclaw_messages')
            ->where('conversation_id', 'stored_conv')
            ->count();

        $this->assertSame(2, $messageCount, 'Both messages must be stored when store_messages=true');
    }

    public function test_history_returned_when_store_messages_true(): void
    {
        config(['phpclaw.store_messages' => true]);

        $this->memory->set('hist_conv', [
            'history' => [
                ['role' => 'user',      'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello'],
            ],
        ]);

        $result = $this->memory->get('hist_conv');

        $this->assertCount(2, $result['history']);

        $roles = array_column($result['history'], 'role');
        $contents = array_column($result['history'], 'content');

        $this->assertContains('user', $roles);
        $this->assertContains('assistant', $roles);
        $this->assertContains('Hi', $contents);
        $this->assertContains('Hello', $contents);
    }

    public function test_set_replaces_existing_messages_on_upsert(): void
    {
        config(['phpclaw.store_messages' => true]);

        $this->memory->set('upd_conv', [
            'history' => [
                ['role' => 'user', 'content' => 'First message'],
            ],
        ]);
        $this->memory->set('upd_conv', [
            'history' => [
                ['role' => 'user',      'content' => 'Replaced'],
                ['role' => 'assistant', 'content' => 'Reply'],
            ],
        ]);

        $result = $this->memory->get('upd_conv');

        $this->assertCount(2, $result['history']);

        $contents = array_column($result['history'], 'content');
        $this->assertContains('Replaced', $contents);
        $this->assertContains('Reply', $contents);
        $this->assertNotContains('First message', $contents);
    }

    public function test_value_persists_across_instances(): void
    {
        $writer = new DatabaseConversationMemory;
        $writer->set('persist_conv', ['title' => 'Persisted', 'history' => []]);

        $reader = new DatabaseConversationMemory;
        $result = $reader->get('persist_conv');

        $this->assertSame('Persisted', $result['title']);
    }

    public function test_history_load_order_is_deterministic_with_same_created_at(): void
    {
        config(['phpclaw.store_messages' => true]);

        $sharedTimestamp = '2024-01-01 00:00:00';
        $convId = 'order_test_conv';

        DB::table('phpclaw_conversations')->insert([
            'id' => $convId,
            'namespace' => 'default',
            'title' => 'Order Test',
            'metadata' => null,
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        DB::table('phpclaw_messages')->insert([
            'id' => 'ZZZZZZZZZZZZZZZZZZZZZZZZZA',
            'conversation_id' => $convId,
            'role' => 'user',
            'content' => 'third',
            'tool_name' => null,
            'tool_input' => null,
            'created_at' => $sharedTimestamp,
        ]);
        DB::table('phpclaw_messages')->insert([
            'id' => 'ZZZZZZZZZZZZZZZZZZZZZZZZZB',
            'conversation_id' => $convId,
            'role' => 'assistant',
            'content' => 'fourth',
            'tool_name' => null,
            'tool_input' => null,
            'created_at' => $sharedTimestamp,
        ]);
        DB::table('phpclaw_messages')->insert([
            'id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAA',
            'conversation_id' => $convId,
            'role' => 'user',
            'content' => 'first',
            'tool_name' => null,
            'tool_input' => null,
            'created_at' => $sharedTimestamp,
        ]);
        DB::table('phpclaw_messages')->insert([
            'id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAB',
            'conversation_id' => $convId,
            'role' => 'assistant',
            'content' => 'second',
            'tool_name' => null,
            'tool_input' => null,
            'created_at' => $sharedTimestamp,
        ]);

        $result = $this->memory->get($convId);
        $contents = array_column($result['history'], 'content');

        $this->assertSame(['first', 'second', 'third', 'fourth'], $contents);
    }

    public function test_flush_removes_child_messages(): void
    {
        config(['phpclaw.store_messages' => true]);

        $this->memory->set('flush_msg_conv', [
            'history' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ]);

        $before = DB::table('phpclaw_messages')
            ->where('conversation_id', 'flush_msg_conv')
            ->count();
        $this->assertSame(1, $before);

        $this->memory->flush();

        $after = DB::table('phpclaw_messages')
            ->where('conversation_id', 'flush_msg_conv')
            ->count();
        $this->assertSame(0, $after, 'flush() must delete child messages');
    }

    public function test_has_returns_false_after_forget(): void
    {
        $this->memory->set('del_check', ['history' => []]);
        $this->memory->forget('del_check');

        $this->assertFalse($this->memory->has('del_check'));
    }

    public function test_all_entries_have_expected_shape(): void
    {
        $this->memory->set('shape_conv', ['title' => 'Shape Test', 'history' => []]);

        $all = $this->memory->all();
        $item = $all['shape_conv'] ?? null;

        $this->assertNotNull($item);
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayHasKey('metadata', $item);
    }

    public function test_all_returns_conversations_in_deterministic_id_order_with_same_created_at(): void
    {
        $sharedTimestamp = '2024-01-01 00:00:00';

        DB::table('phpclaw_conversations')->insert([
            [
                'id' => 'ZZZZZZZZZZZZZZZZZZZZZZZZZA',
                'namespace' => 'default',
                'title' => 'conv-z',
                'metadata' => null,
                'created_at' => $sharedTimestamp,
                'updated_at' => $sharedTimestamp,
            ],
            [
                'id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAA',
                'namespace' => 'default',
                'title' => 'conv-a',
                'metadata' => null,
                'created_at' => $sharedTimestamp,
                'updated_at' => $sharedTimestamp,
            ],
        ]);

        $all = $this->memory->all('default');
        $keys = array_keys($all);

        $this->assertSame('AAAAAAAAAAAAAAAAAAAAAAAAAA', $keys[0]);
        $this->assertSame('ZZZZZZZZZZZZZZZZZZZZZZZZZA', $keys[1]);
    }
}
