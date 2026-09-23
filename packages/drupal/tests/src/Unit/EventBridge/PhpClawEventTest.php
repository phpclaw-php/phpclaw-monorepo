<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\EventBridge;

use PhpClaw\Drupal\EventBridge\PhpClawEvent;
use PHPUnit\Framework\TestCase;

final class PhpClawEventTest extends TestCase
{
    public function test_context_is_stored_and_accessible(): void
    {
        $ctx = ['provider' => 'anthropic', 'model' => 'claude-haiku'];
        $event = new PhpClawEvent($ctx);

        $this->assertSame($ctx, $event->context);
    }

    public function test_empty_context_is_accepted(): void
    {
        $event = new PhpClawEvent([]);

        $this->assertSame([], $event->context);
    }

    public function test_context_is_readonly(): void
    {
        $event = new PhpClawEvent(['foo' => 'bar']);

        $this->assertSame('bar', $event->context['foo']);
    }
}
