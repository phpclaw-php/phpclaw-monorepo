<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Support\Log;

/**
 * Static registry for lifecycle hook handlers, dispatched in priority order.
 */
final class HookRegistry
{
    public const DEFAULT_PRIORITY = 10;

    private const EVENT_CONTEXT_KEY = 'event';

    private const WILDCARD = '*';

    private static array $hooks = [];

    private static array $sorted = [];

    private static array $anyHooks = [];

    private static bool $anySorted = true;

    private static array $registeredClasses = [];

    /**
     * Register a handler for a specific event.
     *
     * @param  string  $event  One of the supported event names, see {@see LifecycleEvent}.
     * @param  callable|HookInterface  $handler  Called with array $context when the event fires.
     * @param  int  $priority  Lower = runs first.
     * @return void
     */
    public static function on(string $event, callable|HookInterface $handler, int $priority = self::DEFAULT_PRIORITY): void
    {
        if ($handler instanceof HookInterface) {
            $class = $handler::class;
            if (isset(self::$registeredClasses[$event][$class])) {
                return;
            }
            self::$registeredClasses[$event][$class] = true;
        }

        self::$hooks[$event][] = [
            'priority' => $priority,
            'handler' => self::wrapHandler($handler),
        ];
        self::$sorted[$event] = false;
    }

    /**
     * Whether a HookInterface class is already registered for the given event.
     *
     * @param  string  $event  Lifecycle event name.
     * @param  class-string<HookInterface>  $class  Fully-qualified class name of the listener.
     * @return bool
     */
    public static function hasListener(string $event, string $class): bool
    {
        return isset(self::$registeredClasses[$event][$class]);
    }

    /**
     * Register a wildcard handler invoked for every fired event.
     *
     * @param  callable|HookInterface  $handler  Called with the event context.
     * @param  int  $priority  Lower = runs first.
     * @return void
     */
    public static function onAny(callable|HookInterface $handler, int $priority = self::DEFAULT_PRIORITY): void
    {
        if ($handler instanceof HookInterface) {
            $class = $handler::class;
            if (isset(self::$registeredClasses[self::WILDCARD][$class])) {
                return;
            }
            self::$registeredClasses[self::WILDCARD][$class] = true;
        }

        self::$anyHooks[] = [
            'priority' => $priority,
            'handler' => self::wrapHandler($handler),
        ];
        self::$anySorted = false;
    }

    /**
     * Fire an event, calling all registered handlers in priority order. Any handler exception is swallowed: hooks are fire-and-forget.
     *
     * @param  string  $event  Event name being dispatched.
     * @param  array<string, mixed>  $context  Data describing the event.
     * @return void
     */
    public static function fire(string $event, array $context = []): void
    {
        $hasTyped = ! empty(self::$hooks[$event]);
        $hasWildcard = ! empty(self::$anyHooks);

        if (! $hasTyped && ! $hasWildcard) {
            return;
        }

        $context[self::EVENT_CONTEXT_KEY] = $context[self::EVENT_CONTEXT_KEY] ?? $event;

        if ($hasTyped) {
            self::sortIfDirty(self::$hooks[$event], self::$sorted[$event] ?? true);
            self::$sorted[$event] = true;
            self::dispatch(self::$hooks[$event], $context);
        }

        if ($hasWildcard) {
            self::sortIfDirty(self::$anyHooks, self::$anySorted);
            self::$anySorted = true;
            self::dispatch(self::$anyHooks, $context);
        }
    }

    /**
     * Return the number of handlers registered for a given event. Returns the total across all events when $event is null.
     *
     * @param  string|null  $event  Event name to count, or null for the global total.
     * @return int
     */
    public static function count(?string $event = null): int
    {
        if ($event !== null) {
            return count(self::$hooks[$event] ?? []);
        }

        return array_sum(array_map('count', self::$hooks));
    }

    /**
     * Return the number of wildcard handlers registered via onAny().
     *
     * @return int
     */
    public static function countAny(): int
    {
        return count(self::$anyHooks);
    }

    /**
     * Deregister the first handler registered for $event that is identical (===) to $handler, the counterpart to on() for a caller that must not outlive a single call.
     *
     * @param  string  $event  Event name the handler was registered under.
     * @param  callable  $handler  The exact callable instance passed to on().
     * @return void
     */
    public static function off(string $event, callable $handler): void
    {
        if (! isset(self::$hooks[$event])) {
            return;
        }

        foreach (self::$hooks[$event] as $i => $entry) {
            if ($entry['handler'] === $handler) {
                unset(self::$hooks[$event][$i]);
                self::$hooks[$event] = array_values(self::$hooks[$event]);

                return;
            }
        }
    }

    /**
     * Remove all registered hooks. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$hooks = [];
        self::$sorted = [];
        self::$anyHooks = [];
        self::$anySorted = true;
        self::$registeredClasses = [];
    }

    /**
     * Normalise a handler: HookInterface instances are wrapped in a closure so the dispatch loop can call every entry as `$handler($context)`.
     *
     * @param  callable|HookInterface  $handler  Raw handler supplied by the caller.
     * @return callable Closure that accepts the context array.
     */
    private static function wrapHandler(callable|HookInterface $handler): callable
    {
        if ($handler instanceof HookInterface) {
            return static function (array $context) use ($handler): void {
                $handler->handle($context);
            };
        }

        return $handler;
    }

    /**
     * Stable-sort handlers by ascending priority when the dirty flag is unset.
     *
     * @param  array<int, array{priority: int, handler: callable}>  $list  Handler list to sort in-place.
     * @param  bool  $isSorted  Skip the sort when true.
     * @return void
     */
    private static function sortIfDirty(array &$list, bool $isSorted): void
    {
        if ($isSorted) {
            return;
        }

        usort($list, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    /**
     * Invoke every handler in $list with $context, swallowing any exception.
     *
     * @param  array<int, array{priority: int, handler: callable}>  $list  Ordered handler list to invoke.
     * @param  array<string, mixed>  $context  Event context delivered to each handler.
     * @return void
     */
    private static function dispatch(array $list, array $context): void
    {
        foreach ($list as ['handler' => $handler]) {
            try {
                $handler($context);
            } catch (\Throwable $exception) {
                Log::error('[phpclaw] hook error on '.($context[self::EVENT_CONTEXT_KEY] ?? 'unknown').': '.$exception->getMessage());
            }
        }
    }
}
