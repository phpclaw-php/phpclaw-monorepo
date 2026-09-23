<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\Rest\ApcuCounterStore;
use PhpClaw\PrestaShop\Rest\PsCounterStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApcuCounterStore::class)]
final class ApcuCounterStoreTest extends TestCase
{
    public function test_it_is_a_counter_store(): void
    {
        self::assertInstanceOf(PsCounterStoreInterface::class, new ApcuCounterStore);
    }

    public function test_increment_returns_zero_when_apcu_is_unavailable(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            self::markTestSkipped('APCu is enabled; the fail-open branch is unreachable here.');
        }

        self::assertSame(0, (new ApcuCounterStore)->increment('phpclaw:test', 60));
    }

    public function test_increment_counts_up_within_the_window_when_apcu_is_available(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled in this PHP build.');
        }

        $store = new ApcuCounterStore;
        $key = 'phpclaw:test:'.bin2hex(random_bytes(4));

        self::assertSame(1, $store->increment($key, 60));
        self::assertSame(2, $store->increment($key, 60));
    }
}
