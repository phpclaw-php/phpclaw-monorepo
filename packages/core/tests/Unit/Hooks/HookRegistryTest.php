<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\HookRegistry;
use PHPUnit\Framework\TestCase;

final class HookRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_fire_with_no_handlers_does_nothing(): void
    {
        $this->expectNotToPerformAssertions();
        HookRegistry::fire('agent.before', ['message' => 'hello']);
    }

    public function test_count_is_zero_after_reset(): void
    {
        HookRegistry::on('agent.before', fn () => null);
        HookRegistry::reset();
        $this->assertSame(0, HookRegistry::count());
    }

    public function test_registered_handler_is_called_on_fire(): void
    {
        $called = false;
        HookRegistry::on('agent.before', function () use (&$called): void {
            $called = true;
        });

        HookRegistry::fire('agent.before');

        $this->assertTrue($called);
    }

    public function test_handler_receives_context(): void
    {
        $received = [];
        HookRegistry::on('agent.before', function (array $ctx) use (&$received): void {
            $received = $ctx;
        });

        HookRegistry::fire('agent.before', ['message' => 'hello', 'provider' => 'anthropic']);

        $this->assertSame('hello', $received['message']);
        $this->assertSame('anthropic', $received['provider']);
    }

    public function test_firing_unknown_event_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        HookRegistry::fire('event.that.does.not.exist', ['foo' => 'bar']);
    }

    public function test_multiple_handlers_for_same_event_all_called(): void
    {
        $calls = [];
        HookRegistry::on('agent.after', function () use (&$calls): void {
            $calls[] = 'A';
        });
        HookRegistry::on('agent.after', function () use (&$calls): void {
            $calls[] = 'B';
        });

        HookRegistry::fire('agent.after');

        $this->assertSame(['A', 'B'], $calls);
    }

    public function test_lower_priority_runs_first(): void
    {
        $order = [];
        HookRegistry::on('tool.before', function () use (&$order): void {
            $order[] = 'second';
        }, 20);
        HookRegistry::on('tool.before', function () use (&$order): void {
            $order[] = 'first';
        }, 5);

        HookRegistry::fire('tool.before');

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_default_priority_is_10(): void
    {
        $order = [];
        HookRegistry::on('tool.after', function () use (&$order): void {
            $order[] = 'default';
        });
        HookRegistry::on('tool.after', function () use (&$order): void {
            $order[] = 'earlier';
        }, 5);

        HookRegistry::fire('tool.after');

        $this->assertSame(['earlier', 'default'], $order);
    }

    public function test_throwing_handler_does_not_propagate(): void
    {
        $this->expectNotToPerformAssertions();

        HookRegistry::on('agent.before', function (): void {
            throw new \RuntimeException('This should be swallowed');
        });

        HookRegistry::fire('agent.before');
    }

    public function test_second_handler_runs_even_if_first_throws(): void
    {
        $secondCalled = false;

        HookRegistry::on('agent.before', function (): void {
            throw new \RuntimeException('First handler fails');
        }, 1);

        HookRegistry::on('agent.before', function () use (&$secondCalled): void {
            $secondCalled = true;
        }, 2);

        HookRegistry::fire('agent.before');

        $this->assertTrue($secondCalled, 'Second handler must run even after first throws');
    }

    public function test_throwing_handler_is_logged_with_event_name(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_log_');
        $prevLog = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            HookRegistry::on('agent.before', function (): void {
                throw new \RuntimeException('boom from handler');
            });

            HookRegistry::fire('agent.before');

            $logged = (string) file_get_contents($logFile);
            $this->assertStringContainsString('[phpclaw] hook error on agent.before', $logged);
            $this->assertStringContainsString('boom from handler', $logged);
        } finally {
            ini_set('error_log', $prevLog === false ? '' : $prevLog);
            @unlink($logFile);
        }
    }

    public function test_count_per_event(): void
    {
        HookRegistry::on('agent.before', fn () => null);
        HookRegistry::on('agent.before', fn () => null);
        HookRegistry::on('agent.after', fn () => null);

        $this->assertSame(2, HookRegistry::count('agent.before'));
        $this->assertSame(1, HookRegistry::count('agent.after'));
    }

    public function test_count_total_across_all_events(): void
    {
        HookRegistry::on('agent.before', fn () => null);
        HookRegistry::on('agent.after', fn () => null);
        HookRegistry::on('tool.before', fn () => null);

        $this->assertSame(3, HookRegistry::count());
    }

    public function test_count_unknown_event_returns_zero(): void
    {
        $this->assertSame(0, HookRegistry::count('unknown.event'));
    }

    public function test_all_thirteen_event_names_are_fireable(): void
    {
        $events = [
            'agent.before',
            'agent.after',
            'agent.iteration',
            'agent.max_iterations',
            'tool.before',
            'tool.after',
            'tool.error',
            'guard.blocked',
            'memory.read',
            'memory.write',
            'memory.forget',
            'provider.request',
            'provider.response',
        ];

        $fired = [];
        foreach ($events as $event) {
            HookRegistry::on($event, function () use ($event, &$fired): void {
                $fired[] = $event;
            });
        }

        foreach ($events as $event) {
            HookRegistry::fire($event);
        }

        $this->assertSame($events, $fired);
    }

    public function test_class_based_handler_is_called(): void
    {
        $called = false;

        $handler = new class($called) implements HookInterface
        {
            public function __construct(private bool &$called) {}

            public function handle(array $context): void
            {
                $this->called = true;
            }
        };

        HookRegistry::on('agent.before', [$handler, 'handle']);
        HookRegistry::fire('agent.before');

        $this->assertTrue($called);
    }

    public function test_reset_clears_all_handlers(): void
    {
        $called = false;
        HookRegistry::on('agent.before', function () use (&$called): void {
            $called = true;
        });
        HookRegistry::reset();
        HookRegistry::fire('agent.before');

        $this->assertFalse($called, 'Handler must not run after reset');
    }

    public function test_off_removes_the_exact_handler_it_was_given(): void
    {
        $called = false;
        $handler = function () use (&$called): void {
            $called = true;
        };
        HookRegistry::on('agent.before', $handler);

        HookRegistry::off('agent.before', $handler);
        HookRegistry::fire('agent.before');

        $this->assertFalse($called, 'Handler removed via off() must not run on fire()');
        $this->assertSame(0, HookRegistry::count('agent.before'));
    }

    public function test_off_leaves_other_handlers_for_the_same_event_untouched(): void
    {
        $firstCalled = false;
        $secondCalled = false;
        $first = function () use (&$firstCalled): void {
            $firstCalled = true;
        };
        $second = function () use (&$secondCalled): void {
            $secondCalled = true;
        };
        HookRegistry::on('agent.before', $first);
        HookRegistry::on('agent.before', $second);

        HookRegistry::off('agent.before', $first);
        HookRegistry::fire('agent.before');

        $this->assertFalse($firstCalled, 'Removed handler must not run');
        $this->assertTrue($secondCalled, 'Untouched handler must still run');
    }

    public function test_off_on_unknown_event_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        HookRegistry::off('agent.before', fn () => null);
    }

    public function test_off_repeated_stream_style_registration_never_accumulates_duplicate_calls(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $calls = 0;
            $handler = function () use (&$calls): void {
                $calls++;
            };
            HookRegistry::on('agent.before', $handler);
            HookRegistry::fire('agent.before');
            HookRegistry::off('agent.before', $handler);

            $this->assertSame(1, $calls, "Iteration {$i}: exactly one prior registration must fire, proving off() prevented accumulation");
        }

        $this->assertSame(0, HookRegistry::count('agent.before'));
    }
}
