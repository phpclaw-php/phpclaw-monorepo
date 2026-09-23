<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\StatementInterface;
use PhpClaw\Drupal\Memory\DrupalDbMemory;
use PHPUnit\Framework\TestCase;

final class DrupalDbMemoryTest extends TestCase
{
    private Connection $db;

    private DrupalDbMemory $memory;

    protected function setUp(): void
    {
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturnCallback(static fn () => time());
        $time->method('getCurrentTime')->willReturnCallback(static fn () => time());

        $this->db = $this->createMock(Connection::class);
        $this->memory = new DrupalDbMemory($this->db, $time);
    }

    public function test_it_returns_value_when_key_exists(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn('stored-value');

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->get('my-key', 'default');

        $this->assertSame('stored-value', $result);
    }

    public function test_it_returns_null_when_key_does_not_exist(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(false);

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->get('missing-key', 'default');

        $this->assertNull($result);
    }

    public function test_it_passes_namespace_and_key_to_query(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(false);

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('{phpclaw_memory}'),
                $this->callback(fn (array $args) => $args[':ns'] === 'mynamespace' && $args[':key'] === 'mykey'
                )
            )
            ->willReturn($stmt);

        $this->memory->get('mykey', 'mynamespace');
    }

    public function test_it_calls_merge_on_set(): void
    {
        $merge = $this->buildMergeMock();
        $this->db->expects($this->once())
            ->method('merge')
            ->with('phpclaw_memory')
            ->willReturn($merge);

        $this->memory->set('key1', 'hello', 'default');
    }

    public function test_it_serializes_non_string_values(): void
    {
        $merge = $this->buildMergeMock();
        $merge->expects($this->once())
            ->method('insertFields')
            ->with($this->callback(fn (array $fields) => $fields['value'] === json_encode(['a' => 1], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            ))
            ->willReturnSelf();

        $this->db->method('merge')->willReturn($merge);

        $this->memory->set('key1', ['a' => 1], 'default');
    }

    public function test_it_stores_string_values_directly(): void
    {
        $capturedFields = null;
        $merge = $this->buildMergeMock();
        $merge->method('insertFields')
            ->willReturnCallback(function (array $fields) use ($merge, &$capturedFields) {
                $capturedFields = $fields;

                return $merge;
            });
        $this->db->method('merge')->willReturn($merge);

        $this->memory->set('key1', 'plain-string', 'default');

        $this->assertSame('plain-string', $capturedFields['value'] ?? null);
    }

    public function test_it_sets_expires_at_when_ttl_given(): void
    {
        $capturedFields = null;

        $merge = $this->createMock(Merge::class);
        $merge->method('keys')->willReturnSelf();
        $merge->expects($this->once())
            ->method('insertFields')
            ->with($this->callback(function (array $fields) use (&$capturedFields) {
                $capturedFields = $fields;

                return true;
            }))
            ->willReturnSelf();
        $merge->method('updateFields')->willReturnSelf();
        $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

        $this->db->method('merge')->willReturn($merge);

        $beforeTs = time() + 59;
        $this->memory->set('key1', 'val', 'default', ttl: 60);
        $afterTs = time() + 61;

        $this->assertNotNull($capturedFields['expires_at']);
        $this->assertIsInt($capturedFields['expires_at']);
        $this->assertGreaterThanOrEqual($beforeTs, $capturedFields['expires_at']);
        $this->assertLessThanOrEqual($afterTs, $capturedFields['expires_at']);
    }

    public function test_it_sets_null_expires_at_when_no_ttl(): void
    {
        $capturedFields = null;

        $merge = $this->createMock(Merge::class);
        $merge->method('keys')->willReturnSelf();
        $merge->expects($this->once())
            ->method('insertFields')
            ->with($this->callback(function (array $fields) use (&$capturedFields) {
                $capturedFields = $fields;

                return true;
            }))
            ->willReturnSelf();
        $merge->method('updateFields')->willReturnSelf();
        $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

        $this->db->method('merge')->willReturn($merge);

        $this->memory->set('key1', 'val', 'default');

        $this->assertNull($capturedFields['expires_at']);
    }

    public function test_it_deletes_key_on_forget(): void
    {
        $delete = $this->buildDeleteMock(['namespace' => 'default', 'key' => 'del-key']);

        $this->db->expects($this->once())
            ->method('delete')
            ->with('phpclaw_memory')
            ->willReturn($delete);

        $this->memory->forget('del-key', 'default');
    }

    public function test_it_deletes_whole_namespace_on_flush(): void
    {
        $delete = $this->createMock(Delete::class);
        $delete->expects($this->once())
            ->method('condition')
            ->with('namespace', 'myns')
            ->willReturnSelf();
        $delete->method('execute')->willReturn(5);

        $this->db->expects($this->once())
            ->method('delete')
            ->with('phpclaw_memory')
            ->willReturn($delete);

        $this->memory->flush('myns');
    }

    public function test_it_returns_all_keys_in_namespace(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAllKeyed')->with(0, 1)->willReturn([
            'key1' => 'val1',
            'key2' => 'val2',
        ]);

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->all('default');

        $this->assertSame(['key1' => 'val1', 'key2' => 'val2'], $result);
    }

    public function test_it_returns_empty_array_when_namespace_empty(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAllKeyed')->with(0, 1)->willReturn(false);

        $this->db->method('query')->willReturn($stmt);

        $result = $this->memory->all('empty-ns');

        $this->assertSame([], $result);
    }

    public function test_it_returns_true_when_key_exists(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn('some-value');

        $this->db->method('query')->willReturn($stmt);

        $this->assertTrue($this->memory->has('existing-key', 'ns'));
    }

    public function test_it_returns_false_when_key_missing(): void
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(false);

        $this->db->method('query')->willReturn($stmt);

        $this->assertFalse($this->memory->has('missing-key', 'ns'));
    }

    private function buildMergeMock(): Merge
    {
        $merge = $this->createMock(Merge::class);
        $merge->method('keys')->willReturnSelf();
        $merge->method('insertFields')->willReturnSelf();
        $merge->method('updateFields')->willReturnSelf();
        $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

        return $merge;
    }

    private function buildDeleteMock(array $expectedConditions): Delete
    {
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willReturn(1);

        return $delete;
    }

    public function test_ttl_is_measured_from_wall_clock_not_the_frozen_request_time(): void
    {
        $frozen = time() - 7200;

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn($frozen);
        $time->method('getCurrentTime')->willReturnCallback(static fn () => time());

        $captured = [];
        $merge = $this->createMock(Merge::class);
        $merge->method('keys')->willReturnSelf();
        $merge->method('insertFields')->willReturnCallback(function (array $fields) use ($merge, &$captured) {
            $captured = $fields;

            return $merge;
        });
        $merge->method('updateFields')->willReturnSelf();
        $merge->method('execute')->willReturn(Merge::STATUS_INSERT);

        $db = $this->createMock(Connection::class);
        $db->method('merge')->willReturn($merge);

        (new DrupalDbMemory($db, $time))->set('k', 'v', 'default', ttl: 60);

        $this->assertGreaterThan(
            $frozen + 60,
            $captured['expires_at'],
            'A TTL set two hours into a long-lived process must expire 60s from now, not 60s from process start.',
        );
        $this->assertGreaterThanOrEqual(time() + 59, $captured['expires_at']);
        $this->assertLessThanOrEqual(time() + 61, $captured['expires_at']);
    }
}
