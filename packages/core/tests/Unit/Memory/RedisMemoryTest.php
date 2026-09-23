<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\RedisMemory;
use PHPUnit\Framework\TestCase;

final class RedisMemoryTest extends TestCase
{
    private \Redis $mockRedis;

    private RedisMemory $memory;

    protected function setUp(): void
    {
        if (! class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not installed: mock based on \\Redis cannot be built.');
        }

        $this->mockRedis = $this->createMock(\Redis::class);
        $this->memory = new RedisMemory(redis: $this->mockRedis);
    }

    public function test_implements_memory_interface(): void
    {
        $this->assertInstanceOf(MemoryInterface::class, $this->memory);
    }

    public function test_canonical_method_names_exist(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(RedisMemory::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['get', 'set', 'forget', 'flush', 'all', 'has'] as $canonical) {
            $this->assertContains($canonical, $methods, "RedisMemory missing canonical method: {$canonical}");
        }

        $this->assertNotContains('delete', $methods, 'RedisMemory must use forget() not delete()');
    }

    public function test_default_prefix(): void
    {
        $this->assertSame('phpclaw:', $this->memory->prefix());
    }

    public function test_custom_prefix(): void
    {
        $mem = new RedisMemory(redis: $this->mockRedis, prefix: 'myapp:');
        $this->assertSame('myapp:', $mem->prefix());
    }

    public function test_get_returns_null_when_key_missing(): void
    {
        $this->mockRedis->method('get')->willReturn(false);

        $this->assertNull($this->memory->get('nonexistent'));
    }

    public function test_get_decodes_stored_value(): void
    {
        $this->mockRedis->method('get')
            ->willReturnCallback(function (string $key): string|false {
                return $key === 'phpclaw:default:greeting' ? json_encode('hello world') : false;
            });

        $this->assertSame('hello world', $this->memory->get('greeting'));
    }

    public function test_get_decodes_complex_value(): void
    {
        $complex = ['a' => 1, 'b' => [2, 3], 'c' => true];

        $this->mockRedis->method('get')->willReturn(json_encode($complex));

        $this->assertSame($complex, $this->memory->get('data'));
    }

    public function test_get_returns_null_on_corrupt_value(): void
    {
        $this->mockRedis->method('get')->willReturn('not-valid-json');

        $this->assertNull($this->memory->get('corrupt'));
    }

    public function test_get_namespace_affects_key(): void
    {
        $capturedKey = null;

        $this->mockRedis->method('get')
            ->willReturnCallback(function (string $key) use (&$capturedKey): string|false {
                $capturedKey = $key;

                return false;
            });

        $this->memory->get('item', 'conversations');

        $this->assertSame('phpclaw:conversations:item', $capturedKey);
    }

    public function test_set_uses_setex_when_default_ttl_positive(): void
    {
        $mem = new RedisMemory(redis: $this->mockRedis, defaultTtl: 3600);

        $this->mockRedis->expects($this->once())
            ->method('setex')
            ->with('phpclaw:default:name', 3600, json_encode('Alice'));

        $mem->set('name', 'Alice');
    }

    public function test_set_uses_explicit_ttl_over_default(): void
    {
        $mem = new RedisMemory(redis: $this->mockRedis, defaultTtl: 3600);

        $this->mockRedis->expects($this->once())
            ->method('setex')
            ->with('phpclaw:default:name', 60, json_encode('Bob'));

        $mem->set('name', 'Bob', ttl: 60);
    }

    public function test_set_uses_set_when_ttl_zero_for_no_expiry(): void
    {
        $mem = new RedisMemory(redis: $this->mockRedis, defaultTtl: 0);

        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with('phpclaw:default:permanent', json_encode('forever'));

        $mem->set('permanent', 'forever');
    }

    public function test_set_scopes_to_namespace(): void
    {
        $this->mockRedis->expects($this->once())
            ->method('setex')
            ->with('phpclaw:conversations:001HZZ', 86400, json_encode(['turn' => 1]));

        $this->memory->set('001HZZ', ['turn' => 1], 'conversations');
    }

    public function test_forget_calls_del_on_correct_key(): void
    {
        $this->mockRedis->expects($this->once())
            ->method('del')
            ->with('phpclaw:default:doomed');

        $this->memory->forget('doomed');
    }

    public function test_forget_scopes_to_namespace(): void
    {
        $this->mockRedis->expects($this->once())
            ->method('del')
            ->with('phpclaw:conversations:abc');

        $this->memory->forget('abc', 'conversations');
    }

    public function test_flush_deletes_all_keys_matching_namespace_pattern(): void
    {
        $this->mockRedis->method('keys')
            ->with('phpclaw:temp:*')
            ->willReturn(['phpclaw:temp:a', 'phpclaw:temp:b', 'phpclaw:temp:c']);

        $this->mockRedis->expects($this->once())
            ->method('del')
            ->with(['phpclaw:temp:a', 'phpclaw:temp:b', 'phpclaw:temp:c']);

        $this->memory->flush('temp');
    }

    public function test_flush_is_noop_when_no_keys_match(): void
    {
        $this->mockRedis->method('keys')->willReturn([]);
        $this->mockRedis->expects($this->never())->method('del');

        $this->memory->flush('empty');
    }

    public function test_all_returns_all_entries_keyed_by_short_name(): void
    {
        $this->mockRedis->method('keys')
            ->with('phpclaw:default:*')
            ->willReturn(['phpclaw:default:k1', 'phpclaw:default:k2']);

        $this->mockRedis->method('get')
            ->willReturnCallback(function (string $key): string|false {
                return match ($key) {
                    'phpclaw:default:k1' => json_encode('v1'),
                    'phpclaw:default:k2' => json_encode(42),
                    default => false,
                };
            });

        $result = $this->memory->all();

        $this->assertSame(['k1' => 'v1', 'k2' => 42], $result);
    }

    public function test_all_returns_empty_when_namespace_empty(): void
    {
        $this->mockRedis->method('keys')->willReturn([]);

        $this->assertSame([], $this->memory->all());
    }

    public function test_all_skips_corrupt_values(): void
    {
        $this->mockRedis->method('keys')
            ->willReturn(['phpclaw:default:good', 'phpclaw:default:bad']);

        $this->mockRedis->method('get')
            ->willReturnCallback(function (string $key): string|false {
                return match ($key) {
                    'phpclaw:default:good' => json_encode('ok'),
                    'phpclaw:default:bad' => 'not-valid-json',
                    default => false,
                };
            });

        $this->assertSame(['good' => 'ok'], $this->memory->all());
    }

    public function test_has_returns_true_when_key_exists(): void
    {
        $this->mockRedis->method('exists')
            ->with('phpclaw:default:name')
            ->willReturn(1);

        $this->assertTrue($this->memory->has('name'));
    }

    public function test_has_returns_false_when_key_missing(): void
    {
        $this->mockRedis->method('exists')->willReturn(0);

        $this->assertFalse($this->memory->has('nope'));
    }

    public function test_set_explicit_ttl_zero_overrides_positive_default_ttl(): void
    {
        $mem = new RedisMemory(redis: $this->mockRedis, defaultTtl: 3600);

        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with('phpclaw:default:forever', json_encode('no-expiry'));

        $this->mockRedis->expects($this->never())->method('setex');

        $mem->set('forever', 'no-expiry', ttl: 0);
    }

    public function test_set_throws_json_exception_for_unencodable_value(): void
    {
        $this->expectException(\JsonException::class);

        $this->memory->set('nan', NAN);
    }

    public function test_get_returns_null_for_stored_json_null_literal(): void
    {
        $this->mockRedis->method('get')->willReturn('null');

        $this->assertNull($this->memory->get('stored-null'));
    }

    public function test_has_scopes_correctly_to_namespace(): void
    {
        $capturedKey = null;

        $this->mockRedis->method('exists')
            ->willReturnCallback(function (string $key) use (&$capturedKey): int {
                $capturedKey = $key;

                return 1;
            });

        $result = $this->memory->has('session-id', 'sessions');

        $this->assertSame('phpclaw:sessions:session-id', $capturedKey);
        $this->assertTrue($result);
    }

    public function test_flush_is_noop_when_keys_returns_non_array(): void
    {
        $this->mockRedis->method('keys')->willReturn(false);
        $this->mockRedis->expects($this->never())->method('del');

        $this->memory->flush('ghost');

        $this->addToAssertionCount(1);
    }

    public function test_all_returns_empty_when_keys_returns_non_array(): void
    {
        $this->mockRedis->method('keys')->willReturn(false);

        $this->assertSame([], $this->memory->all('ghost'));
    }

    public function test_all_skips_keys_that_disappeared_during_scan(): void
    {
        $this->mockRedis->method('keys')
            ->willReturn(['phpclaw:default:alive', 'phpclaw:default:gone']);

        $this->mockRedis->method('get')
            ->willReturnCallback(function (string $key): string|false {
                return match ($key) {
                    'phpclaw:default:alive' => json_encode('still here'),
                    default => false,
                };
            });

        $this->assertSame(['alive' => 'still here'], $this->memory->all());
    }

    public function test_all_handles_key_without_expected_prefix(): void
    {
        $this->mockRedis->method('keys')
            ->willReturn(['unexpected:key:format']);

        $this->mockRedis->method('get')
            ->willReturn(json_encode('value'));

        $result = $this->memory->all();

        $this->assertArrayHasKey('unexpected:key:format', $result);
        $this->assertSame('value', $result['unexpected:key:format']);
    }

    public function test_constructor_throws_memory_exception_on_invalid_url(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not installed: cannot exercise real connect path.');
        }

        $this->expectException(MemoryException::class);
        new RedisMemory(url: 'not-a-valid-url');
    }

    public function test_default_ttl_accessor_returns_constructor_value(): void
    {
        $mockRedis = $this->createMock(\Redis::class);
        $memory = new RedisMemory(redis: $mockRedis, defaultTtl: 3600);

        $this->assertSame(3600, $memory->defaultTtl());
    }

    public function test_default_ttl_clamps_negative_to_zero(): void
    {
        $mockRedis = $this->createMock(\Redis::class);
        $memory = new RedisMemory(redis: $mockRedis, defaultTtl: -100);

        $this->assertSame(0, $memory->defaultTtl());
    }

    public function test_ttl_method_returns_remaining_seconds(): void
    {
        $mockRedis = $this->createMock(\Redis::class);
        $mockRedis->expects($this->once())
            ->method('ttl')
            ->with('phpclaw:default:my-key')
            ->willReturn(42);

        $memory = new RedisMemory(redis: $mockRedis);

        $this->assertSame(42, $memory->ttl('my-key'));
    }

    public function test_constructor_throws_when_connect_fails_to_unreachable_port(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not installed.');
        }

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/RedisMemory: could not connect/');

        new RedisMemory(url: 'redis://127.0.0.1:1');
    }

    public function test_constructor_throws_when_url_has_no_host(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not installed.');
        }

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessageMatches('/invalid REDIS_URL/');

        new RedisMemory(url: 'redis:///');
    }

    public function test_constructor_reads_redis_url_env_var(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not installed.');
        }

        $previous = $_ENV['REDIS_URL'] ?? null;
        $_ENV['REDIS_URL'] = 'redis://127.0.0.1:1';

        try {
            $this->expectException(MemoryException::class);
            new RedisMemory;
        } finally {
            if ($previous === null) {
                unset($_ENV['REDIS_URL']);
            } else {
                $_ENV['REDIS_URL'] = $previous;
            }
        }
    }

    public function test_connect_handles_url_with_password_and_db(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not installed.');
        }

        $this->expectException(MemoryException::class);
        new RedisMemory(url: 'redis://:somepass@127.0.0.1:1/3');
    }
}
