<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Memory;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Memory\WpOptionsMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpOptionsMemory::class)]
final class WpOptionsMemoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_sets_and_gets_a_value(): void
    {
        $serialised = serialize('hello world');

        Functions\expect('update_option')->twice()->andReturn(true);
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_default_greeting', \Mockery::any())
            ->andReturn('');
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_default_greeting', \Mockery::any())
            ->andReturn($serialised);

        $memory = new WpOptionsMemory;
        $memory->set('greeting', 'hello world');

        $result = $memory->get('greeting');

        self::assertSame('hello world', $result);
    }

    public function test_it_returns_null_for_missing_key(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_default_missing', \Mockery::any())
            ->andReturn('');
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_default_missing', \Mockery::any())
            ->andReturn(false);

        $memory = new WpOptionsMemory;

        self::assertNull($memory->get('missing'));
    }

    public function test_it_respects_ttl_expiry(): void
    {
        $pastTimestamp = (string) (time() - 100);

        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_default_expired_key', \Mockery::any())
            ->andReturn($pastTimestamp);
        Functions\expect('delete_option')->twice()->andReturn(true);

        $memory = new WpOptionsMemory;

        self::assertNull($memory->get('expired_key'));
    }

    public function test_it_stores_value_with_ttl(): void
    {
        Functions\expect('update_option')->twice()->andReturn(true);

        $memory = new WpOptionsMemory;
        $memory->set('key', 'val', 'ns', 60);

    }

    public function test_it_forgets_a_key(): void
    {
        Functions\expect('delete_option')
            ->once()
            ->with('_phpclaw_default_mykey')
            ->andReturn(true);
        Functions\expect('delete_option')
            ->once()
            ->with('_phpclaw_expiry_default_mykey')
            ->andReturn(true);

        $memory = new WpOptionsMemory;
        $memory->forget('mykey');

    }

    public function test_has_returns_true_for_existing_key(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_default_exists', \Mockery::any())
            ->andReturn('');
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_default_exists', \Mockery::any())
            ->andReturn(serialize('value'));

        $memory = new WpOptionsMemory;

        self::assertTrue($memory->has('exists'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_default_nope', \Mockery::any())
            ->andReturn('');
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_default_nope', \Mockery::any())
            ->andReturn(false);

        $memory = new WpOptionsMemory;

        self::assertFalse($memory->has('nope'));
    }

    public function test_it_flushes_namespace_via_wpdb(): void
    {
        global $wpdb;

        $wpdb = new class
        {
            public string $options = 'wp_options';

            public function esc_like(string $s): string
            {
                return addcslashes($s, '_%\\');
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return vsprintf(str_replace('%s', "'%s'", $sql), $args);
            }

            public array $queries = [];

            public function query(string $sql): int
            {
                $this->queries[] = $sql;

                return 1;
            }
        };

        $memory = new WpOptionsMemory;
        $memory->flush('default');

        self::assertCount(2, $wpdb->queries);
        self::assertSame(
            "DELETE FROM wp_options WHERE option_name LIKE '\\_phpclaw\\_default\\_%'",
            $wpdb->queries[0],
        );
        self::assertSame(
            "DELETE FROM wp_options WHERE option_name LIKE '\\_phpclaw\\_expiry\\_default\\_%'",
            $wpdb->queries[1],
        );
    }

    public function test_all_returns_keys_under_namespace(): void
    {
        global $wpdb;

        $rowsByKey = [
            '_phpclaw_default_alpha' => serialize('A'),
            '_phpclaw_default_beta' => serialize('B'),
            '_phpclaw_expiry_default_alpha' => '',
            '_phpclaw_expiry_default_beta' => '',
        ];

        $wpdb = new class($rowsByKey)
        {
            public string $options = 'wp_options';

            public array $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                $i = 0;

                return preg_replace_callback('/%s|%d/', fn () => "'".($args[$i++] ?? '')."'", $sql);
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                $out = [];
                foreach ($this->rows as $name => $value) {
                    if (str_starts_with($name, '_phpclaw_expiry_')) {
                        continue;
                    }
                    $out[] = ['option_name' => $name, 'option_value' => $value];
                }

                return $out;
            }
        };

        Functions\when('get_option')->alias(static function (string $key, mixed $default = null) use (&$rowsByKey) {
            if (! array_key_exists($key, $rowsByKey)) {
                return $default;
            }

            return $rowsByKey[$key] === '' ? '' : $rowsByKey[$key];
        });

        $memory = new WpOptionsMemory;
        $result = $memory->all('default');

        self::assertArrayHasKey('alpha', $result);
        self::assertArrayHasKey('beta', $result);
        self::assertSame('A', $result['alpha']);
        self::assertSame('B', $result['beta']);
    }

    public function test_all_skips_expired_keys(): void
    {
        global $wpdb;

        $past = (string) (time() - 100);

        $rowsByKey = [
            '_phpclaw_default_alpha' => serialize('A'),
            '_phpclaw_expiry_default_alpha' => $past,
        ];

        $wpdb = new class($rowsByKey)
        {
            public string $options = 'wp_options';

            public array $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                $i = 0;

                return preg_replace_callback('/%s|%d/', fn () => "'".($args[$i++] ?? '')."'", $sql);
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                $out = [];
                foreach ($this->rows as $name => $value) {
                    if (str_starts_with($name, '_phpclaw_expiry_')) {
                        continue;
                    }
                    $out[] = ['option_name' => $name, 'option_value' => $value];
                }

                return $out;
            }
        };

        Functions\when('get_option')->alias(static function (string $key, mixed $default = null) use (&$rowsByKey) {
            return $rowsByKey[$key] ?? $default;
        });
        Functions\when('delete_option')->justReturn(true);

        $memory = new WpOptionsMemory;
        $result = $memory->all('default');

        self::assertArrayNotHasKey('alpha', $result);
    }

    public function test_all_returns_empty_when_no_rows(): void
    {
        global $wpdb;

        $wpdb = new class
        {
            public string $options = 'wp_options';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [];
            }
        };

        $memory = new WpOptionsMemory;

        self::assertSame([], $memory->all('default'));
    }

    public function test_set_serialises_array_values(): void
    {
        $captured = null;
        Functions\expect('update_option')->twice()->andReturnUsing(static function ($key, $val) use (&$captured) {
            if (str_starts_with($key, '_phpclaw_default_')) {
                $captured = $val;
            }

            return true;
        });

        $memory = new WpOptionsMemory;
        $memory->set('arr_key', ['a' => 1, 'b' => 2]);

        $unserialised = unserialize($captured);
        self::assertSame(['a' => 1, 'b' => 2], $unserialised);
    }

    public function test_keys_in_different_namespaces_are_independent(): void
    {
        $serialisedA = serialize('valueA');
        $serialisedB = serialize('valueB');

        Functions\expect('update_option')->times(4)->andReturn(true);

        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_ns_a_shared', \Mockery::any())
            ->andReturn('');
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_ns_a_shared', \Mockery::any())
            ->andReturn($serialisedA);

        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_expiry_ns_b_shared', \Mockery::any())
            ->andReturn('');
        Functions\expect('get_option')
            ->once()
            ->with('_phpclaw_ns_b_shared', \Mockery::any())
            ->andReturn($serialisedB);

        $memory = new WpOptionsMemory;
        $memory->set('shared', 'valueA', 'ns_a');
        $memory->set('shared', 'valueB', 'ns_b');

        self::assertSame('valueA', $memory->get('shared', 'ns_a'));
        self::assertSame('valueB', $memory->get('shared', 'ns_b'));
    }
}
