<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Memory;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\WordPress\Memory\WpTransientMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpTransientMemory::class)]
final class WpTransientMemoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $store = [];

    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->store = [];
        $this->options = [];

        $store = &$this->store;
        $options = &$this->options;

        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $exp = 0) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

        Functions\when('get_transient')->alias(function (string $key) use (&$store): mixed {
            return $store[$key] ?? false;
        });

        Functions\when('delete_transient')->alias(function (string $key) use (&$store): bool {
            unset($store[$key]);

            return true;
        });

        Functions\when('get_option')->alias(function (string $name, mixed $default = false) use (&$options): mixed {
            return $options[$name] ?? $default;
        });

        Functions\when('update_option')->alias(function (string $name, mixed $value) use (&$options): bool {
            $options[$name] = $value;

            return true;
        });

        Functions\when('delete_option')->alias(function (string $name) use (&$options): bool {
            unset($options[$name]);

            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_set_and_get_round_trip(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('key1', 'hello');

        self::assertSame('hello', $memory->get('key1'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $memory = new WpTransientMemory;

        self::assertNull($memory->get('not-set'));
    }

    public function test_set_array_value(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('arr', ['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $memory->get('arr'));
    }

    public function test_forget_removes_key(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('bye', 'value');
        $memory->forget('bye');

        self::assertNull($memory->get('bye'));
    }

    public function test_has_returns_true_for_set_key(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('present', 'yes');

        self::assertTrue($memory->has('present'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $memory = new WpTransientMemory;

        self::assertFalse($memory->has('absent'));
    }

    public function test_all_returns_all_set_keys(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('k1', 'v1');
        $memory->set('k2', 'v2');

        $all = $memory->all();

        self::assertArrayHasKey('k1', $all);
        self::assertArrayHasKey('k2', $all);
        self::assertSame('v1', $all['k1']);
    }

    public function test_flush_removes_all_namespace_keys(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('x', 'a');
        $memory->set('y', 'b');
        $memory->flush();

        self::assertNull($memory->get('x'));
        self::assertNull($memory->get('y'));
    }

    public function test_set_with_ttl_passes_through(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('temp_key', 'value', 'default', 60);

        self::assertSame('value', $memory->get('temp_key'));
    }

    public function test_namespace_isolation_for_get(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('shared', 'ns-a-value', 'ns_a');
        $memory->set('shared', 'ns-b-value', 'ns_b');

        self::assertSame('ns-a-value', $memory->get('shared', 'ns_a'));
        self::assertSame('ns-b-value', $memory->get('shared', 'ns_b'));
    }

    public function test_flush_only_clears_target_namespace(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('alpha', 'A', 'keep_ns');
        $memory->set('beta', 'B', 'flush_ns');

        $memory->flush('flush_ns');

        self::assertNull($memory->get('beta', 'flush_ns'));
        self::assertSame('A', $memory->get('alpha', 'keep_ns'));
    }

    public function test_all_within_namespace_returns_only_that_namespace(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('a', '1', 'foo');
        $memory->set('b', '2', 'foo');
        $memory->set('c', '3', 'bar');

        $foo = $memory->all('foo');

        self::assertArrayHasKey('a', $foo);
        self::assertArrayHasKey('b', $foo);
        self::assertArrayNotHasKey('c', $foo);
    }

    public function test_forget_with_explicit_namespace(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('temp', 'hi', 'special');
        $memory->forget('temp', 'special');

        self::assertNull($memory->get('temp', 'special'));
    }

    public function test_has_with_explicit_namespace(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('present_in_x', 'v', 'x');

        self::assertTrue($memory->has('present_in_x', 'x'));
        self::assertFalse($memory->has('present_in_x', 'y'));
    }

    public function test_long_key_is_hashed(): void
    {
        $longKey = str_repeat('A', 250);

        $memory = new WpTransientMemory;
        $memory->set($longKey, 'value');

        self::assertSame('value', $memory->get($longKey));
    }

    public function test_get_wraps_throwable_in_memory_exception(): void
    {
        Functions\when('get_transient')->alias(static function (string $key): never {
            throw new \RuntimeException('storage offline');
        });

        try {
            (new WpTransientMemory)->get('any-key');
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {

            self::assertSame('WpTransientMemory::get failed', $e->getMessage());
            self::assertSame('storage offline', $e->getPrevious()?->getMessage());
        }
    }

    public function test_set_wraps_throwable_in_memory_exception(): void
    {
        Functions\when('set_transient')->alias(static function (string $key, $value, int $exp = 0): never {
            throw new \RuntimeException('disk full');
        });

        try {
            (new WpTransientMemory)->set('k', 'v');
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {
            self::assertSame('WpTransientMemory::set failed', $e->getMessage());
            self::assertSame('disk full', $e->getPrevious()?->getMessage());
        }
    }

    public function test_forget_wraps_throwable_in_memory_exception(): void
    {
        Functions\when('delete_transient')->alias(static function (string $key): never {
            throw new \RuntimeException('cannot delete');
        });

        try {
            (new WpTransientMemory)->forget('k');
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {
            self::assertSame('WpTransientMemory::forget failed', $e->getMessage());
            self::assertSame('cannot delete', $e->getPrevious()?->getMessage());
        }
    }

    public function test_has_wraps_throwable_in_memory_exception(): void
    {
        Functions\when('get_transient')->alias(static function (string $key): never {
            throw new \RuntimeException('boom');
        });

        $this->expectException(MemoryException::class);

        (new WpTransientMemory)->has('k');
    }

    public function test_all_wraps_throwable_in_memory_exception(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, $default = null): array => ['k1']);
        Functions\when('get_transient')->alias(static function (string $k): never {
            throw new \RuntimeException('all-broken');
        });

        try {
            (new WpTransientMemory)->all();
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {
            self::assertSame('WpTransientMemory::all failed', $e->getMessage());
            self::assertSame('all-broken', $e->getPrevious()?->getMessage());
        }
    }

    public function test_flush_wraps_throwable_in_memory_exception(): void
    {
        Functions\when('get_option')->alias(static fn (string $n, $d = null): array => ['k1']);
        Functions\when('delete_transient')->alias(static function (string $k): never {
            throw new \RuntimeException('flush-broken');
        });

        try {
            (new WpTransientMemory)->flush();
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {
            self::assertSame('WpTransientMemory::flush failed', $e->getMessage());
            self::assertSame('flush-broken', $e->getPrevious()?->getMessage());
        }
    }

    public function test_set_twice_for_same_key_does_not_duplicate_tracker(): void
    {
        $memory = new WpTransientMemory;
        $memory->set('same', 'v1');
        $memory->set('same', 'v2');

        $all = $memory->all();

        self::assertCount(1, $all);
        self::assertSame('v2', $all['same']);
    }
}
