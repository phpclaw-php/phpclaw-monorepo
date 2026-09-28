<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\RateLimitGuard;
use PHPUnit\Framework\TestCase;

final class RateLimitGuardTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitGuard::reset();
    }

    protected function tearDown(): void
    {
        RateLimitGuard::reset();
    }

    public function test_allows_calls_up_to_the_limit(): void
    {
        $guard = new RateLimitGuard(3, 60, static fn (): string => 'caller-a');

        $guard->scan('one');
        $guard->scan('two');
        $guard->scan('three');

        $this->addToAssertionCount(1);
    }

    public function test_refuses_the_call_over_the_limit(): void
    {
        $guard = new RateLimitGuard(2, 60, static fn (): string => 'caller-b');
        $guard->scan('one');
        $guard->scan('two');

        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('Rate limit exceeded: maximum 2 requests per 60 seconds.');

        $guard->scan('three');
    }

    public function test_counts_each_caller_separately(): void
    {
        $first = new RateLimitGuard(1, 60, static fn (): string => 'caller-c');
        $second = new RateLimitGuard(1, 60, static fn (): string => 'caller-d');

        $first->scan('one');
        $second->scan('one');

        $this->expectException(GuardException::class);

        $first->scan('two');
    }

    public function test_a_new_window_restarts_the_count(): void
    {
        $guard = new RateLimitGuard(1, 1, static fn (): string => 'caller-e');
        $guard->scan('one');

        sleep(2);

        $guard->scan('two');

        $this->addToAssertionCount(1);
    }

    public function test_counts_survive_a_new_request_when_apcu_is_available(): void
    {
        $this->requireApcu();
        $guard = new RateLimitGuard(2, 60, static fn (): string => 'caller-f');
        $guard->scan('one');
        $guard->scan('two');

        $this->clearProcessCounters();

        $this->expectException(GuardException::class);

        (new RateLimitGuard(2, 60, static fn (): string => 'caller-f'))->scan('three');
    }

    public function test_reset_clears_the_shared_counts(): void
    {
        $this->requireApcu();
        $guard = new RateLimitGuard(1, 60, static fn (): string => 'caller-g');
        $guard->scan('one');

        RateLimitGuard::reset();

        $guard->scan('two');

        $this->addToAssertionCount(1);
    }

    private function requireApcu(): void
    {
        if (! function_exists('apcu_enabled') || ! \apcu_enabled()) {
            $this->markTestSkipped('APCu is not enabled for this SAPI; run with -d apc.enable_cli=1.');
        }
    }

    private function clearProcessCounters(): void
    {
        $buckets = new \ReflectionProperty(RateLimitGuard::class, 'buckets');
        $buckets->setValue(null, []);
    }
}
