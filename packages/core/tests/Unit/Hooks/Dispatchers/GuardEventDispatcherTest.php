<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks\Dispatchers;

use PhpClaw\Hooks\Dispatchers\GuardEventDispatcher;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PHPUnit\Framework\TestCase;

final class GuardEventDispatcherTest extends TestCase
{
    private array $captured = [];

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->captured = [];

        $capture = function (string $event): callable {
            return function (array $context) use ($event): void {
                $this->captured[] = ['event' => $event, 'context' => $context];
            };
        };

        HookRegistry::on(LifecycleEvent::GuardBlocked->value, $capture(LifecycleEvent::GuardBlocked->value));
        HookRegistry::on(LifecycleEvent::GuardRateLimitExceeded->value, $capture(LifecycleEvent::GuardRateLimitExceeded->value));
        HookRegistry::on(LifecycleEvent::GuardToolOutputRedacted->value, $capture(LifecycleEvent::GuardToolOutputRedacted->value));
        HookRegistry::on(LifecycleEvent::GuardOutputPhpTagRemoved->value, $capture(LifecycleEvent::GuardOutputPhpTagRemoved->value));
        HookRegistry::on(LifecycleEvent::GuardOutputFunctionRedacted->value, $capture(LifecycleEvent::GuardOutputFunctionRedacted->value));
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_blocked_fires_guard_blocked_with_message_and_reason(): void
    {
        GuardEventDispatcher::blocked('drop table users', 'sql_injection_pattern');

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::GuardBlocked->value, $this->captured[0]['event']);
        $this->assertSame('drop table users', $this->captured[0]['context']['message']);
        $this->assertSame('sql_injection_pattern', $this->captured[0]['context']['reason']);
    }

    public function test_rate_limit_exceeded_fires_with_all_four_fields(): void
    {
        GuardEventDispatcher::rateLimitExceeded(
            callerId: 'user:42',
            count: 105,
            maxRequests: 100,
            windowSeconds: 60,
        );

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::GuardRateLimitExceeded->value, $this->captured[0]['event']);
        $this->assertSame('user:42', $this->captured[0]['context']['caller_id']);
        $this->assertSame(105, $this->captured[0]['context']['count']);
        $this->assertSame(100, $this->captured[0]['context']['max_requests']);
        $this->assertSame(60, $this->captured[0]['context']['window_seconds']);
    }

    public function test_tool_output_redacted_fires_with_tool_name_and_pattern(): void
    {
        GuardEventDispatcher::toolOutputRedacted('shell', 'eval\(');

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::GuardToolOutputRedacted->value, $this->captured[0]['event']);
        $this->assertSame('shell', $this->captured[0]['context']['tool_name']);
        $this->assertSame('eval\(', $this->captured[0]['context']['pattern']);
    }

    public function test_output_php_tag_removed_fires_with_tag(): void
    {
        GuardEventDispatcher::outputPhpTagRemoved('<?php');

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::GuardOutputPhpTagRemoved->value, $this->captured[0]['event']);
        $this->assertSame('<?php', $this->captured[0]['context']['tag']);
    }

    public function test_output_function_redacted_fires_with_function_name(): void
    {
        GuardEventDispatcher::outputFunctionRedacted('shell_exec');

        $this->assertCount(1, $this->captured);
        $this->assertSame(LifecycleEvent::GuardOutputFunctionRedacted->value, $this->captured[0]['event']);
        $this->assertSame('shell_exec', $this->captured[0]['context']['function']);
    }
}
