<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Events;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event object dispatched for every bridged HookRegistry lifecycle event.
 */
final class PhpClawEvent extends Event
{
    /**
     * Constructs a new lifecycle event with the given name and context payload.
     *
     * @param  string  $name  The HookRegistry event name.
     * @param  array<string, mixed>  $context  Event context data.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $context,
    ) {}
}
