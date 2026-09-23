<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\Rest\PsCounterStoreInterface;
use PhpClaw\PrestaShop\Rest\PsRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsRateLimiter::class)]
final class PsRateLimiterTest extends TestCase
{
    public function test_allows_until_limit_then_rejects(): void
    {
        $store = new class implements PsCounterStoreInterface
        {
            private array $counts = [];

            public function increment(string $key, int $ttl): int
            {
                return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
            }
        };

        $limiter = new PsRateLimiter($store, 3, 60);

        self::assertFalse($limiter->tooManyRequests('1.2.3.4'));
        self::assertFalse($limiter->tooManyRequests('1.2.3.4'));
        self::assertFalse($limiter->tooManyRequests('1.2.3.4'));
        self::assertTrue($limiter->tooManyRequests('1.2.3.4'));
    }

    public function test_fails_open_when_store_returns_zero(): void
    {
        $store = new class implements PsCounterStoreInterface
        {
            public function increment(string $key, int $ttl): int
            {
                return 0;
            }
        };

        $limiter = new PsRateLimiter($store, 1, 60);

        self::assertFalse($limiter->tooManyRequests('1.2.3.4'));
        self::assertFalse($limiter->tooManyRequests('1.2.3.4'));
    }

    public function test_separate_clients_have_separate_budgets(): void
    {
        $store = new class implements PsCounterStoreInterface
        {
            private array $counts = [];

            public function increment(string $key, int $ttl): int
            {
                return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
            }
        };

        $limiter = new PsRateLimiter($store, 1, 60);

        self::assertFalse($limiter->tooManyRequests('1.1.1.1'));
        self::assertTrue($limiter->tooManyRequests('1.1.1.1'));
        self::assertFalse($limiter->tooManyRequests('2.2.2.2'));
    }
}
