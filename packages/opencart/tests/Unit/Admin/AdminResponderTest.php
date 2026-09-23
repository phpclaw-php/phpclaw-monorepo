<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\OpenCart\Admin\AdminResponder;
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
}
