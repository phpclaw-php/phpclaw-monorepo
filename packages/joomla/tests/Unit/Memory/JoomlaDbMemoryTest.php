<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Memory;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbMemory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class JoomlaDbMemoryTest extends TestCase
{
    private DatabaseInterface&MockObject $db;

    private QueryInterface&MockObject $query;

    private JoomlaDbMemory $memory;

    protected function setUp(): void
    {
        $this->query = $this->createMock(QueryInterface::class);
        $this->query->method('select')->willReturnSelf();
        $this->query->method('from')->willReturnSelf();
        $this->query->method('where')->willReturnSelf();
        $this->query->method('delete')->willReturnSelf();
        $this->query->method('update')->willReturnSelf();
        $this->query->method('insert')->willReturnSelf();
        $this->query->method('set')->willReturnSelf();
        $this->query->method('columns')->willReturnSelf();
        $this->query->method('values')->willReturnSelf();

        $this->db = $this->createMock(DatabaseInterface::class);
        $this->db->method('getQuery')->willReturn($this->query);
        $this->db->method('quoteName')->willReturnCallback(
            fn (mixed $n) => is_array($n) ? $n : (string) $n,
        );
        $this->db->method('quote')->willReturnCallback(
            fn (mixed $v) => "'".addslashes((string) $v)."'",
        );
        $this->db->method('setQuery')->willReturnSelf();

        $this->memory = new JoomlaDbMemory($this->db);
    }

    public function test_get_returns_null_when_not_found(): void
    {
        $this->db->method('loadResult')->willReturn(null);

        $result = $this->memory->get('missing_key');

        self::assertNull($result);
    }

    public function test_get_returns_string_value(): void
    {
        $this->db->method('loadResult')->willReturn('hello world');

        $result = $this->memory->get('greeting', 'test');

        self::assertSame('hello world', $result);
    }

    public function test_get_deserializes_array_value(): void
    {
        $this->db->method('loadResult')->willReturn(serialize(['a' => 1, 'b' => 2]));

        $result = $this->memory->get('config');

        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function test_get_uses_namespace_and_key_in_query(): void
    {
        $this->db->expects(self::atLeastOnce())->method('quote')
            ->with(self::logicalOr('custom_ns', 'my_key', self::anything()))
            ->willReturnCallback(fn (mixed $v) => "'".(string) $v."'");

        $this->db->method('loadResult')->willReturn(null);

        $this->memory->get('my_key', 'custom_ns');
    }

    public function test_set_inserts_when_key_does_not_exist(): void
    {
        $this->db->method('loadResult')->willReturn(null);

        $this->db->expects(self::atLeastOnce())->method('setQuery')->willReturnSelf();

        $this->db->expects(self::once())->method('execute');

        $this->memory->set('new_key', 'value');
    }

    public function test_set_updates_when_key_exists(): void
    {
        $this->db->method('loadResult')->willReturn('01HQ1234567890ABCDEFGHIJKL');

        $this->db->expects(self::once())->method('execute');

        $this->memory->set('existing_key', 'updated_value');
    }

    public function test_set_serializes_array_value(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $this->db->method('execute');

        $captured = [];
        $this->db->method('quote')->willReturnCallback(function (mixed $v) use (&$captured): string {
            $captured[] = $v;

            return "'".(string) $v."'";
        });

        $this->memory->set('arr_key', ['x' => 1]);

        $hasSerializedArray = false;
        foreach ($captured as $v) {
            if (str_starts_with((string) $v, 'a:')) {
                $hasSerializedArray = true;
                break;
            }
        }

        self::assertTrue($hasSerializedArray, 'Array should be PHP-serialized before storage');
    }

    public function test_set_stores_string_as_plain_string(): void
    {
        $this->db->method('loadResult')->willReturn(null);
        $this->db->method('execute');

        $captured = [];
        $this->db->method('quote')->willReturnCallback(function (mixed $v) use (&$captured): string {
            $captured[] = $v;

            return "'".(string) $v."'";
        });

        $this->memory->set('str_key', 'plain text');

        self::assertContains('plain text', $captured, 'Plain string should be stored as-is');
    }

    public function test_forget_executes_delete_query(): void
    {
        $this->db->expects(self::once())->method('execute');

        $this->memory->forget('some_key', 'default');
    }

    public function test_forget_uses_correct_namespace_and_key(): void
    {
        $this->db->method('execute');

        $quoted = [];
        $this->db->method('quote')->willReturnCallback(function (mixed $v) use (&$quoted): string {
            $quoted[] = $v;

            return "'".(string) $v."'";
        });

        $this->memory->forget('target', 'ns1');

        self::assertContains('ns1', $quoted);
        self::assertContains('target', $quoted);
    }

    public function test_flush_executes_delete_for_namespace(): void
    {
        $this->db->expects(self::once())->method('execute');

        $this->memory->flush('my_namespace');
    }

    public function test_all_returns_empty_array_when_no_rows(): void
    {
        $this->db->method('loadAssocList')->willReturn([]);

        $result = $this->memory->all();

        self::assertSame([], $result);
    }

    public function test_all_returns_deserialized_key_value_pairs(): void
    {
        $this->db->method('loadAssocList')->willReturn([
            'name' => 'Alice',
            'score' => serialize(42),
        ]);

        $result = $this->memory->all();

        self::assertSame('Alice', $result['name']);
        self::assertSame(42, $result['score']);
    }

    public function test_has_returns_true_when_key_found(): void
    {
        $this->db->method('loadResult')->willReturn('value');

        self::assertTrue($this->memory->has('existing', 'default'));
    }

    public function test_has_returns_false_when_key_not_found(): void
    {
        $this->db->method('loadResult')->willReturn(null);

        self::assertFalse($this->memory->has('missing', 'default'));
    }

    public function test_unserialize_returns_raw_string_when_corrupt_serialized_payload(): void
    {
        $this->db->method('loadResult')->willReturn('a:1:{i:0;s:5:"hello');

        set_error_handler(static fn () => true, E_WARNING);
        try {
            $result = $this->memory->get('corrupt-key', 'default');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('a:1:{i:0;s:5:"hello', $result);
    }

    public function test_unserialize_returns_null_for_serialized_null_sentinel(): void
    {
        $this->memory->set('null-key', null, 'default');
        $this->db->method('loadResult')->willReturn('N;');

        $this->assertNull($this->memory->get('null-key', 'default'));
    }
}
