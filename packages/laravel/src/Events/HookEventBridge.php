<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Events;

use Illuminate\Contracts\Events\Dispatcher;
use PhpClaw\Hooks\HookEventBridge as CoreHookEventBridge;

/**
 * Bridges every HookRegistry lifecycle event into Laravel's native event dispatcher under the `phpclaw.{event}` namespace.
 */
final class HookEventBridge
{
    private bool $registered = false;

    /**
     * Bind Laravel's event dispatcher that phpClaw lifecycle events are relayed through.
     *
     * @param  Dispatcher  $events  Laravel's native event dispatcher.
     * @return void
     */
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * Bridge every phpClaw lifecycle event into Laravel's dispatcher under `phpclaw.{event}` (idempotent).
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        (new CoreHookEventBridge(
            dispatcher: fn (string $event, array $ctx): mixed => $this->events->dispatch('phpclaw.'.$event, [$ctx]),
        ))->register();

        $this->registered = true;
    }
}
