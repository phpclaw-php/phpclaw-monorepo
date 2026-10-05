<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\OpenCart\Admin\AdminResponder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminResponderTest extends TestCase
{
    public function test_generic_throwable_returns_generic_message(): void
    {
        self::assertSame(
            'An internal error occurred. Please try again.',
            AdminResponder::messageFor(new \RuntimeException('boom')),
        );
    }

    public function test_direct_guard_exception_returns_guard_message(): void
    {
        self::assertSame(
            'Request blocked by security guard.',
            AdminResponder::messageFor(new GuardException('blocked')),
        );
    }

    public function test_direct_provider_exception_returns_provider_message(): void
    {
        self::assertSame(
            'AI provider error. Check your API key and provider settings.',
            AdminResponder::messageFor(new ProviderException('down')),
        );
    }

    public function test_wrapped_guard_exception_unwraps_to_guard_message(): void
    {
        $wrapped = new \RuntimeException('Prompt blocked by security guard.', previous: new GuardException('blocked'));

        self::assertSame('Request blocked by security guard.', AdminResponder::messageFor($wrapped));
    }

    public function test_wrapped_provider_exception_unwraps_to_provider_message(): void
    {
        $wrapped = new \RuntimeException('AI provider error.', previous: new ProviderException('down'));

        self::assertSame('AI provider error. Check your API key and provider settings.', AdminResponder::messageFor($wrapped));
    }

    public function test_wrapped_unrelated_exception_stays_generic(): void
    {
        $wrapped = new \RuntimeException('Agent reached max iterations.', previous: new \LogicException('looped'));

        self::assertSame('An internal error occurred. Please try again.', AdminResponder::messageFor($wrapped));
    }

    public function test_direct_max_iterations_exception_returns_max_iterations_message(): void
    {
        self::assertSame(
            'Agent reached max iterations. Try a simpler or more specific request.',
            AdminResponder::messageFor(new MaxIterationsException('looped 20 times')),
        );
    }

    public function test_wrapped_max_iterations_exception_unwraps_to_max_iterations_message(): void
    {
        $wrapped = new \RuntimeException('Agent reached max iterations.', previous: new MaxIterationsException('looped 20 times'));

        self::assertSame(
            'Agent reached max iterations. Try a simpler or more specific request.',
            AdminResponder::messageFor($wrapped),
        );
    }

    public function test_a_spent_token_budget_returns_the_budget_message(): void
    {
        self::assertSame('Token budget reached for this run.', AdminResponder::messageFor(new TokenBudgetExceededException(1200, 1000)));
    }

    public function test_a_provider_429_returns_the_rate_limit_message_direct_or_wrapped(): void
    {
        $limited = new ProviderException('slow down', statusCode: 429);

        self::assertSame('Rate limit reached, try again shortly.', AdminResponder::messageFor($limited));
        self::assertSame('Rate limit reached, try again shortly.', AdminResponder::messageFor(new \RuntimeException('AI provider error.', previous: $limited)));
    }

    public function test_a_provider_error_that_is_not_a_429_keeps_the_provider_message(): void
    {
        self::assertSame('AI provider error. Check your API key and provider settings.', AdminResponder::messageFor(new ProviderException('down', statusCode: 500)));
    }

    public static function statusCases(): array
    {
        return [
            'token budget' => [new TokenBudgetExceededException(1200, 1000), 422],
            'provider 429' => [new ProviderException('slow down', statusCode: 429), 429],
            'wrapped provider 429' => [new \RuntimeException('AI provider error.', previous: new ProviderException('slow down', statusCode: 429)), 429],
            'provider 500' => [new ProviderException('down', statusCode: 500), 200],
            'anything else' => [new \RuntimeException('boom'), 200],
        ];
    }

    #[DataProvider('statusCases')]
    public function test_status_for_maps_limits_to_their_http_status(\Throwable $e, int $expected): void
    {
        self::assertSame($expected, AdminResponder::statusFor($e));
    }

    public function test_limit_message_is_null_for_a_failure_that_is_not_a_limit(): void
    {
        self::assertNull(AdminResponder::limitMessage(new GuardException('blocked')));
        self::assertNull(AdminResponder::limitMessage(new ProviderException('down', statusCode: 500)));
    }
}
