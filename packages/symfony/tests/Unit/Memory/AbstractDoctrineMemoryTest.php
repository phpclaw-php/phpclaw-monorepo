<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Symfony\Memory\AbstractDoctrineMemory;
use PHPUnit\Framework\TestCase;

final class AbstractDoctrineMemoryTest extends TestCase
{
    private AbstractDoctrineMemory $subject;

    protected function setUp(): void
    {
        $this->subject = new class extends AbstractDoctrineMemory
        {
            public function get(string $key, string $namespace = 'default'): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void {}

            public function forget(string $key, string $namespace = 'default'): void {}

            public function flush(string $namespace = 'default'): void {}

            public function all(string $namespace = 'default'): array
            {
                return [];
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return false;
            }

            public function runGuard(string $op, \Closure $fn): mixed
            {
                return $this->guard($op, $fn);
            }

            public function runUtcNow(): string
            {
                return $this->utcNow();
            }

            public function runExpiryAt(?int $ttl): ?string
            {
                return $this->expiryAt($ttl);
            }

            public function runIsExpired(string $expiresAt): bool
            {
                return $this->isExpired($expiresAt);
            }
        };
    }

    public function test_utc_now_returns_formatted_datetime(): void
    {
        $result = $this->subject->runUtcNow();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    public function test_expiry_at_returns_null_when_ttl_is_null(): void
    {
        self::assertNull($this->subject->runExpiryAt(null));
    }

    public function test_expiry_at_returns_future_datetime_string(): void
    {
        $result = $this->subject->runExpiryAt(3600);

        self::assertNotNull($result);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);

        $expiry = new \DateTimeImmutable($result, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::assertGreaterThan($now->getTimestamp(), $expiry->getTimestamp());
    }

    public function test_is_expired_returns_true_for_past_timestamp(): void
    {
        $past = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-1 hour')
            ->format('Y-m-d H:i:s');

        self::assertTrue($this->subject->runIsExpired($past));
    }

    public function test_is_expired_returns_false_for_future_timestamp(): void
    {
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+1 hour')
            ->format('Y-m-d H:i:s');

        self::assertFalse($this->subject->runIsExpired($future));
    }

    public function test_is_expired_returns_false_for_invalid_timestamp(): void
    {
        self::assertFalse($this->subject->runIsExpired('not-a-date'));
    }

    public function test_guard_returns_closure_result(): void
    {
        $result = $this->subject->runGuard('test-op', fn () => 'ok');

        self::assertSame('ok', $result);
    }

    public function test_guard_rethrows_memory_exception_as_is(): void
    {
        $original = new MemoryException('original');

        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('original');

        $this->subject->runGuard('test-op', function () use ($original): void {
            throw $original;
        });
    }

    public function test_guard_wraps_other_throwable_in_memory_exception(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('wrap-op failed.');

        $this->subject->runGuard('wrap-op', function (): void {
            throw new \RuntimeException('inner');
        });
    }

    public function test_guard_wrapped_exception_has_original_as_previous(): void
    {
        $inner = new \RuntimeException('inner-msg');

        try {
            $this->subject->runGuard('op', function () use ($inner): void {
                throw $inner;
            });
            self::fail('Expected MemoryException');
        } catch (MemoryException $e) {
            self::assertSame($inner, $e->getPrevious());
        }
    }
}
