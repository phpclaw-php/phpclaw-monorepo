<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\EventBridge;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Generic event dispatched by HookEventBridge for all phpClaw lifecycle events.
 */
final class PhpClawEvent extends Event
{
    /**
     * Construct the event with its HookRegistry context payload.
     *
     * @param  array<string, mixed>  $context  The HookRegistry event context.
     * @return void
     */
    public function __construct(
        public readonly array $context,
    ) {}
}
