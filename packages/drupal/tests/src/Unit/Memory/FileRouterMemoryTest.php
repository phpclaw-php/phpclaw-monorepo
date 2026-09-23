<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Memory;

use PhpClaw\Drupal\Memory\FileRouterMemory;
use PHPUnit\Framework\TestCase;

final class FileRouterMemoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phpclaw-frm-'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $this->assertNull($mem->get('missing', 'default'));
    }

    public function test_set_and_get_roundtrip(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $mem->set('foo', 'bar', 'default');
        $this->assertSame('bar', $mem->get('foo', 'default'));
    }

    public function test_forget_removes_key(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $mem->set('key1', 'val1');
        $mem->forget('key1');
        $this->assertNull($mem->get('key1'));
    }

    public function test_flush_removes_all_keys_in_namespace(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $mem->set('a', 1, 'ns');
        $mem->set('b', 2, 'ns');
        $mem->flush('ns');
        $this->assertSame([], $mem->all('ns'));
    }

    public function test_all_returns_all_entries(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $mem->set('x', 10, 'things');
        $mem->set('y', 20, 'things');
        $all = $mem->all('things');
        $this->assertArrayHasKey('x', $all);
        $this->assertArrayHasKey('y', $all);
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $mem->set('present', 'yes');
        $this->assertTrue($mem->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $this->assertFalse($mem->has('absent'));
    }

    public function test_conversations_namespace_extracts_title_from_history(): void
    {
        $mem = new FileRouterMemory($this->dir);

        $convId = 'conv-abc';
        $data = [
            'history' => [
                ['role' => 'user', 'content' => 'Hello, what is the status?'],
                ['role' => 'assistant', 'content' => 'All systems normal.'],
            ],
        ];

        $mem->set($convId, $data, 'conversations');

        $stored = $mem->get($convId, 'conversations');
        $this->assertIsArray($stored);
        $this->assertSame('Hello, what is the status?', $stored['title']);
    }

    public function test_conversations_namespace_preserves_existing_title(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $convId = 'conv-preserve';

        $mem->set($convId, [
            'title' => 'My existing title',
            'history' => [
                ['role' => 'user', 'content' => 'Overwrite this?'],
            ],
        ], 'conversations');

        $stored = $mem->get($convId, 'conversations');
        $this->assertSame('My existing title', $stored['title']);
    }

    public function test_conversations_namespace_carries_forward_existing_title_on_update(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $convId = 'conv-carry';

        $mem->set($convId, [
            'history' => [['role' => 'user', 'content' => 'Original question']],
        ], 'conversations');

        $mem->set($convId, [
            'history' => [
                ['role' => 'user',      'content' => 'Original question'],
                ['role' => 'assistant', 'content' => 'Answer here'],
            ],
        ], 'conversations');

        $stored = $mem->get($convId, 'conversations');
        $this->assertSame('Original question', $stored['title']);
    }

    public function test_conversations_namespace_sets_created_at_and_updated_at(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $convId = 'conv-timestamps';

        $mem->set($convId, [
            'history' => [['role' => 'user', 'content' => 'Test message']],
        ], 'conversations');

        $stored = $mem->get($convId, 'conversations');
        $this->assertArrayHasKey('created_at', $stored);
        $this->assertArrayHasKey('updated_at', $stored);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $stored['created_at']);
    }

    public function test_conversations_namespace_history_with_no_user_message_yields_null_title(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $convId = 'conv-no-user';

        $mem->set($convId, [
            'history' => [
                ['role' => 'assistant', 'content' => 'Hello!'],
            ],
        ], 'conversations');

        $stored = $mem->get($convId, 'conversations');
        $this->assertNull($stored['title']);
    }

    public function test_non_conversations_namespace_stored_as_is(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $mem->set('config-key', ['setting' => 'value'], 'config');
        $stored = $mem->get('config-key', 'config');
        $this->assertSame(['setting' => 'value'], $stored);
    }

    public function test_null_storage_dir_uses_default(): void
    {
        $mem = new FileRouterMemory(null);
        $this->assertInstanceOf(FileRouterMemory::class, $mem);
    }

    public function test_conversations_preserves_created_at_across_updates(): void
    {
        $mem = new FileRouterMemory($this->dir);
        $convId = 'conv-created-preserve';

        $mem->set($convId, [
            'history' => [['role' => 'user', 'content' => 'First message']],
        ], 'conversations');

        $first = $mem->get($convId, 'conversations');
        $createdAt = $first['created_at'];

        $mem->set($convId, [
            'history' => [
                ['role' => 'user',      'content' => 'First message'],
                ['role' => 'assistant', 'content' => 'Reply'],
            ],
        ], 'conversations');

        $second = $mem->get($convId, 'conversations');
        $this->assertSame($createdAt, $second['created_at']);
    }
}
