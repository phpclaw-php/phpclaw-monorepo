<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Memory;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\WordPress\Memory\WpDbMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpDbMemory::class)]
final class WpDbMemoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->wpdb = new class
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            public array $queryLog = [];

            public array $deleteLog = [];

            public ?array $nextGetRow = null;

            public ?array $nextGetResults = null;

            public function prepare(string $sql, mixed ...$args): string
            {
                $i = 0;

                return preg_replace_callback('/%s|%d|%i/', function () use (&$i, $args) {
                    return "'".addslashes((string) ($args[$i++] ?? ''))."'";
                }, $sql);
            }

            public function query(string $sql): int|false
            {
                $this->queryLog[] = $sql;

                return 1;
            }

            public function get_row(string $sql, string $output = OBJECT): mixed
            {
                return $this->nextGetRow;
            }

            public function get_results(string $sql, string $output = OBJECT): array
            {
                return $this->nextGetResults ?? [];
            }

            public function delete(string $table, array $where, array $format = []): int|false
            {
                $this->deleteLog[] = [$table, $where];

                return 1;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_sets_value_via_wpdb_insert(): void
    {
        $memory = new WpDbMemory;
        $memory->set('key1', 'value1');

        self::assertCount(1, $this->wpdb->queryLog);
        $sql = $this->wpdb->queryLog[0];
        self::assertStringContainsString('INSERT INTO', $sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsString('key1', $sql);
        self::assertStringContainsString('default', $sql);
        self::assertStringContainsString(addslashes(serialize('value1')), $sql);
        self::assertStringContainsString('NULL', $sql);
    }

    public function test_it_gets_existing_value(): void
    {
        $this->wpdb->nextGetRow = [
            'value' => serialize('stored value'),
            'expires_at' => null,
        ];

        $memory = new WpDbMemory;
        $result = $memory->get('key1');

        self::assertSame('stored value', $result);
    }

    public function test_it_returns_null_for_missing_key(): void
    {
        $this->wpdb->nextGetRow = null;

        $memory = new WpDbMemory;

        self::assertNull($memory->get('missing'));
    }

    public function test_it_returns_null_for_expired_row(): void
    {
        $this->wpdb->nextGetRow = [
            'value' => serialize('expired'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 100),
        ];

        $memory = new WpDbMemory;
        $result = $memory->get('expired_key');

        self::assertNull($result);
        self::assertCount(1, $this->wpdb->deleteLog);
    }

    public function test_it_forgets_via_delete(): void
    {
        $memory = new WpDbMemory;
        $memory->forget('gone');

        self::assertCount(1, $this->wpdb->deleteLog);
        self::assertSame(['namespace' => 'default', 'lookup_key' => 'gone'], $this->wpdb->deleteLog[0][1]);
    }

    public function test_it_flushes_namespace(): void
    {
        $memory = new WpDbMemory;
        $memory->flush('ns_test');

        self::assertCount(1, $this->wpdb->deleteLog);
        self::assertSame(['namespace' => 'ns_test'], $this->wpdb->deleteLog[0][1]);
    }

    public function test_it_returns_all_non_expired_values(): void
    {
        $this->wpdb->nextGetResults = [
            ['lookup_key' => 'k1', 'value' => serialize('v1'), 'expires_at' => null],
            ['lookup_key' => 'k2', 'value' => serialize('v2'), 'expires_at' => null],
        ];

        $memory = new WpDbMemory;
        $result = $memory->all();

        self::assertSame(['k1' => 'v1', 'k2' => 'v2'], $result);
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $this->wpdb->nextGetRow = [
            'value' => serialize(true),
            'expires_at' => null,
        ];

        $memory = new WpDbMemory;

        self::assertTrue($memory->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $this->wpdb->nextGetRow = null;

        $memory = new WpDbMemory;

        self::assertFalse($memory->has('absent'));
    }

    public function test_set_with_ttl_includes_expires_at_in_sql(): void
    {
        $memory = new WpDbMemory;
        $memory->set('ttl_key', 'ttl_value', 'default', 300);

        self::assertCount(1, $this->wpdb->queryLog);
        $sql = $this->wpdb->queryLog[0];
        self::assertStringContainsString('INSERT INTO', $sql);

        self::assertStringNotContainsString('NULL, %s, %s', $sql);
    }

    public function test_set_throws_memory_exception_when_query_returns_false(): void
    {
        $this->wpdb = new class extends \stdClass
        {
            public string $prefix = 'wp_';

            public string $last_error = 'Duplicate entry';

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function query(string $sql): int|false
            {
                return false;
            }

            public function get_row(string $sql, string $output = OBJECT): mixed
            {
                return null;
            }

            public function get_results(string $sql, string $output = OBJECT): array
            {
                return [];
            }

            public function delete(string $t, array $w, array $f = []): int|false
            {
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $memory = new WpDbMemory;

        $this->expectException(MemoryException::class);
        $memory->set('fail_key', 'value');
    }

    public function test_get_returns_false_for_serialized_false_value(): void
    {
        $this->wpdb->nextGetRow = [
            'value' => 'b:0;',
            'expires_at' => null,
        ];

        $memory = new WpDbMemory;
        $result = $memory->get('bool_false_key');

        self::assertFalse($result);
    }

    public function test_is_expired_returns_false_for_zero_date(): void
    {
        $this->wpdb->nextGetRow = [
            'value' => serialize('alive'),
            'expires_at' => '0000-00-00 00:00:00',
        ];

        $memory = new WpDbMemory;
        $result = $memory->get('zero_date_key');

        self::assertSame('alive', $result);
    }

    public function test_all_skips_expired_rows_from_results(): void
    {
        $this->wpdb->nextGetResults = [
            ['lookup_key' => 'live', 'value' => serialize('ok'), 'expires_at' => null],
            ['lookup_key' => 'dead', 'value' => serialize('gone'), 'expires_at' => gmdate('Y-m-d H:i:s', time() - 100)],
        ];

        $memory = new WpDbMemory;
        $result = $memory->all();

        self::assertArrayHasKey('live', $result);
        self::assertArrayNotHasKey('dead', $result);
        self::assertSame('ok', $result['live']);
    }

    public function test_set_with_ttl_via_non_null_branch(): void
    {
        $memory = new WpDbMemory;
        $memory->set('exp_key', ['data' => 42], 'myns', 60);

        self::assertCount(1, $this->wpdb->queryLog);
        $sql = $this->wpdb->queryLog[0];

        self::assertMatchesRegularExpression('/expires_at.*VALUES/', $sql);
        self::assertStringContainsString('myns', $sql);
    }
}
