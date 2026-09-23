<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

use Closure;

/**
 * Composition-based bridge from phpClaw HookRegistry events to any host framework's native dispatcher.
 */
final class HookEventBridge
{
    /**
     * Create a new HookEventBridge instance.
     *
     * @param  Closure(string, array<string, mixed>): void  $dispatcher  Forward an event name + payload to the host dispatcher.
     * @param  list<string>|null  $events  When null, every {@see LifecycleEvent} is bridged. When an array, only the listed event names are bridged.
     * @param  int  $priority  HookRegistry listener priority; higher numbers run later.
     */
    public function __construct(
        private readonly Closure $dispatcher,
        private readonly ?array $events = null,
        private readonly int $priority = 50,
    ) {}

    /**
     * Attach one HookRegistry listener per event in the configured set.
     *
     * @return void
     */
    public function register(): void
    {
        foreach ($this->resolvedEvents() as $event) {
            HookRegistry::on(
                $event,
                function (array $context) use ($event): void {
                    ($this->dispatcher)($event, $context);
                },
                $this->priority,
            );
        }
    }

    /**
     * Return the lifecycle events this bridge is registered to forward.
     *
     * @return list<string>
     */
    public function resolvedEvents(): array
    {
        return $this->events ?? LifecycleEvent::all();
    }

    /**
     * Bridge listener priority used when registering against HookRegistry.
     *
     * @return int The resulting count.
     */
    public function priority(): int
    {
        return $this->priority;
    }
}
