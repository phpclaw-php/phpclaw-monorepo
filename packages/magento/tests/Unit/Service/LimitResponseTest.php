<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Magento\Service\LimitResponse;
use PHPUnit\Framework\TestCase;

final class LimitResponseTest extends TestCase
{
    public function test_a_spent_token_budget_is_422_with_the_budget_message(): void
    {
        $e = new TokenBudgetExceededException(1200, 1000);

        self::assertSame('Token budget reached for this run.', LimitResponse::message($e));
        self::assertSame(422, LimitResponse::status($e));
    }

    public function test_a_provider_429_is_429_with_the_rate_limit_message(): void
    {
        $e = new ProviderException('slow down', statusCode: 429);

        self::assertSame('Rate limit reached, try again shortly.', LimitResponse::message($e));
        self::assertSame(429, LimitResponse::status($e));
    }

    public function test_a_wrapped_provider_429_is_still_a_rate_limit(): void
    {
        $e = new \RuntimeException('wrapped', previous: new ProviderException('slow down', statusCode: 429));

        self::assertSame('Rate limit reached, try again shortly.', LimitResponse::message($e));
        self::assertSame(429, LimitResponse::status($e));
    }

    public function test_a_provider_500_is_not_a_limit(): void
    {
        $e = new ProviderException('down', statusCode: 500);

        self::assertSame(null, LimitResponse::message($e));
        self::assertSame(null, LimitResponse::status($e));
    }

    public function test_a_guard_block_is_not_a_limit(): void
    {
        $e = new GuardException('blocked');

        self::assertSame(null, LimitResponse::message($e));
        self::assertSame(null, LimitResponse::status($e));
    }

    public function test_any_other_failure_is_not_a_limit(): void
    {
        $e = new \RuntimeException('boom');

        self::assertSame(null, LimitResponse::message($e));
        self::assertSame(null, LimitResponse::status($e));
    }
}
