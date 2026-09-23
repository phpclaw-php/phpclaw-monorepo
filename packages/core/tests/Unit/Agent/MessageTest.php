<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function test_user_factory_creates_correct_message(): void
    {
        $msg = Message::user('Hello, world');

        $this->assertSame('user', $msg->role);
        $this->assertSame('Hello, world', $msg->content);
        $this->assertNull($msg->toolName);
        $this->assertNull($msg->toolInput);
        $this->assertNull($msg->toolUseId);
    }

    public function test_assistant_factory_creates_correct_message(): void
    {
        $msg = Message::assistant('I can help with that.');

        $this->assertSame('assistant', $msg->role);
        $this->assertSame('I can help with that.', $msg->content);
        $this->assertNull($msg->toolName);
    }

    public function test_tool_result_factory_creates_correct_message(): void
    {
        $msg = Message::toolResult(
            toolUseId: 'toolu_01',
            toolName: 'shell_exec',
            result: 'total 48',
            toolInput: ['command' => 'ls'],
        );

        $this->assertSame('tool', $msg->role);
        $this->assertSame('total 48', $msg->content);
        $this->assertSame('shell_exec', $msg->toolName);
        $this->assertSame(['command' => 'ls'], $msg->toolInput);
        $this->assertSame('toolu_01', $msg->toolUseId);
    }

    public function test_tool_use_factory_creates_correct_message(): void
    {
        $msg = Message::toolUse(
            toolUseId: 'toolu_02',
            toolName: 'shell_exec',
            toolInput: ['command' => 'pwd'],
        );

        $this->assertSame('assistant', $msg->role);
        $this->assertSame('shell_exec', $msg->toolName);
        $this->assertSame(['command' => 'pwd'], $msg->toolInput);
        $this->assertSame('toolu_02', $msg->toolUseId);
    }

    public function test_is_tool_use_returns_true_for_tool_use_message(): void
    {
        $msg = Message::toolUse('id_01', 'shell_exec', ['command' => 'ls']);
        $this->assertTrue($msg->isToolUse());
    }

    public function test_is_tool_use_returns_false_for_user_message(): void
    {
        $msg = Message::user('hello');
        $this->assertFalse($msg->isToolUse());
    }

    public function test_is_tool_use_returns_false_for_assistant_text(): void
    {
        $msg = Message::assistant('hello');
        $this->assertFalse($msg->isToolUse());
    }

    public function test_is_tool_result_returns_true_for_tool_result(): void
    {
        $msg = Message::toolResult('id_01', 'shell_exec', 'output');
        $this->assertTrue($msg->isToolResult());
    }

    public function test_is_tool_result_returns_false_for_user_message(): void
    {
        $msg = Message::user('hello');
        $this->assertFalse($msg->isToolResult());
    }

    public function test_message_is_readonly(): void
    {
        $msg = Message::user('test');

        $this->expectException(\Error::class);
        $msg->role = 'admin';
    }

    public function test_tool_batch_factory_sets_role_and_data(): void
    {
        $calls = [
            ['tool_use_id' => 'id_1', 'tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls']],
            ['tool_use_id' => 'id_2', 'tool_name' => 'file_read',  'tool_input' => ['path' => 'app.log']],
        ];
        $results = ['id_1' => 'file.txt', 'id_2' => 'log content'];

        $msg = Message::toolBatch($calls, $results);

        $this->assertSame('tool_batch', $msg->role);
        $this->assertSame('', $msg->content);
        $this->assertSame($calls, $msg->batchCalls);
        $this->assertSame($results, $msg->batchResults);
    }

    public function test_is_batch_tool_use_returns_true_for_tool_batch(): void
    {
        $msg = Message::toolBatch(
            [['tool_use_id' => 'id_1', 'tool_name' => 'shell_exec', 'tool_input' => []]],
            ['id_1' => 'ok'],
        );

        $this->assertTrue($msg->isBatchToolUse());
    }

    public function test_is_batch_tool_use_returns_false_for_other_roles(): void
    {
        $this->assertFalse(Message::user('hi')->isBatchToolUse());
        $this->assertFalse(Message::assistant('hi')->isBatchToolUse());
        $this->assertFalse(Message::toolResult('id', 'tool', 'result')->isBatchToolUse());
    }

    public function test_tool_batch_has_null_single_tool_fields(): void
    {
        $msg = Message::toolBatch([], []);

        $this->assertNull($msg->toolName);
        $this->assertNull($msg->toolInput);
        $this->assertNull($msg->toolUseId);
    }

    public function test_tool_factory_creates_message_with_required_tool_use_id(): void
    {
        $msg = Message::tool(
            toolUseId: 'use_abc123',
            toolName: 'database_query',
            result: '5 rows returned',
        );

        $this->assertSame(Message::ROLE_TOOL, $msg->role);
        $this->assertSame('5 rows returned', $msg->content);
        $this->assertSame('database_query', $msg->toolName);
        $this->assertSame('use_abc123', $msg->toolUseId);
    }

    public function test_to_string_returns_formatted_string_for_user_role(): void
    {
        $msg = Message::user('Hello there');

        $this->assertSame('[user] Hello there', (string) $msg);
    }

    public function test_to_string_returns_formatted_string_for_tool_role(): void
    {
        $msg = Message::tool('use_1', 'http_get', 'fetched 200 OK');
        $output = (string) $msg;

        $this->assertStringContainsString('[tool', $output);
        $this->assertStringContainsString('http_get', $output);
        $this->assertStringContainsString('fetched 200 OK', $output);
    }

    public function test_to_string_truncates_content_longer_than_200_chars(): void
    {
        $longContent = str_repeat('x', 300);
        $msg = Message::user($longContent);

        $output = (string) $msg;

        $this->assertLessThan(220, strlen($output));
        $this->assertStringContainsString('...', $output);
    }

    public function test_with_tool_input_returns_new_instance_preserving_other_fields(): void
    {
        $original = Message::toolResult(
            toolUseId: 'use_x',
            toolName: 'web_search',
            result: 'found 10 results',
            toolInput: ['query' => 'php'],
        );

        $updated = $original->withToolInput(['query' => 'rust']);

        $this->assertNotSame($original, $updated);
        $this->assertSame(['query' => 'php'], $original->toolInput);
        $this->assertSame(['query' => 'rust'], $updated->toolInput);
        $this->assertSame($original->role, $updated->role);
        $this->assertSame($original->content, $updated->content);
        $this->assertSame($original->toolName, $updated->toolName);
        $this->assertSame($original->toolUseId, $updated->toolUseId);
    }

    public function test_from_array_reconstructs_message_from_array(): void
    {
        $data = [
            'role' => 'tool',
            'content' => 'result text',
            'tool_use_id' => 'use_42',
            'tool_name' => 'shell_run',
            'tool_input' => ['command' => 'ls'],
        ];

        $msg = Message::fromArray($data);

        $this->assertSame(Message::ROLE_TOOL, $msg->role);
        $this->assertSame('result text', $msg->content);
        $this->assertSame('use_42', $msg->toolUseId);
        $this->assertSame('shell_run', $msg->toolName);
        $this->assertSame(['command' => 'ls'], $msg->toolInput);
    }

    public function test_constructor_throws_on_unknown_role(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown message role');

        new Message('admin', 'hello');
    }

    public function test_to_array_and_from_array_are_symmetric_roundtrip(): void
    {
        $original = Message::toolResult(
            toolUseId: 'use_777',
            toolName: 'web_search',
            result: 'roundtrip test',
            toolInput: ['query' => 'symmetry'],
        );

        $serialized = $original->toArray();
        $reconstructed = Message::fromArray($serialized);

        $this->assertSame($original->role, $reconstructed->role);
        $this->assertSame($original->content, $reconstructed->content);
        $this->assertSame($original->toolUseId, $reconstructed->toolUseId);
        $this->assertSame($original->toolName, $reconstructed->toolName);
        $this->assertSame($original->toolInput, $reconstructed->toolInput);
    }
}
