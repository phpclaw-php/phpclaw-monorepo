<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Hooks\HookRegistry;
use PHPUnit\Framework\TestCase;

final class HookRegistryWildcardTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    public function test_on_any_receives_every_fired_event_regardless_of_name(): void
    {
        $received = [];

        HookRegistry::onAny(function (array $ctx) use (&$received): void {
            $received[] = $ctx['event'];
        });

        HookRegistry::fire('agent.before', ['run_id' => 'r1']);
        HookRegistry::fire('my.custom.event', ['payload' => 'hi']);
        HookRegistry::fire('another', []);

        $this->assertSame(['agent.before', 'my.custom.event', 'another'], $received);
    }

    public function test_on_any_priority_orders_correctly_with_other_anyhooks(): void
    {
        $order = [];

        HookRegistry::onAny(function (array $ctx) use (&$order): void {
            $order[] = 'second';
        }, priority: 20);

        HookRegistry::onAny(function (array $ctx) use (&$order): void {
            $order[] = 'first';
        }, priority: 5);

        HookRegistry::fire('any.event', []);

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_reset_clears_wildcard_handlers(): void
    {
        HookRegistry::onAny(static fn (array $ctx) => null);
        $this->assertSame(1, HookRegistry::countAny());

        HookRegistry::reset();

        $this->assertSame(0, HookRegistry::countAny());
    }

    public function test_typed_and_wildcard_both_fire_for_same_event(): void
    {
        $typed = 0;
        $wildcard = 0;

        HookRegistry::on('agent.before', function (array $ctx) use (&$typed): void {
            $typed++;
        });

        HookRegistry::onAny(function (array $ctx) use (&$wildcard): void {
            $wildcard++;
        });

        HookRegistry::fire('agent.before', []);

        $this->assertSame(1, $typed);
        $this->assertSame(1, $wildcard);
    }

    public function test_wildcard_handler_receives_event_name_in_context(): void
    {
        $captured = null;

        HookRegistry::onAny(function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        HookRegistry::fire('user.signup', ['email' => 'a@b.test']);

        $this->assertIsArray($captured);
        $this->assertSame('user.signup', $captured['event']);
        $this->assertSame('a@b.test', $captured['email']);
    }

    public function test_wildcard_handler_exception_does_not_crash_agent(): void
    {
        HookRegistry::onAny(static function (): void {
            throw new \RuntimeException('boom');
        });

        $reached = false;
        HookRegistry::onAny(function () use (&$reached): void {
            $reached = true;
        });

        HookRegistry::fire('any.event', []);

        $this->assertTrue($reached);
    }

    public function test_fire_with_no_handlers_is_noop(): void
    {
        HookRegistry::fire('nothing.listening', []);

        $this->assertSame(0, HookRegistry::count());
        $this->assertSame(0, HookRegistry::countAny());
    }

    public function test_count_any_reflects_registered_wildcard_handlers(): void
    {
        $this->assertSame(0, HookRegistry::countAny());

        HookRegistry::onAny(static fn () => null);
        HookRegistry::onAny(static fn () => null);

        $this->assertSame(2, HookRegistry::countAny());
    }
}
