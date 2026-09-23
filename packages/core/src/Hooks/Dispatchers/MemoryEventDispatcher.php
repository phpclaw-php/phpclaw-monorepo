<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\HookRunContext;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for memory-driver lifecycle events.
 *
 * @internal
 */
final class MemoryEventDispatcher
{
    /**
     * Fires when a value is written to a memory driver.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace to scope the operation.
     * @param  string  $driver  Memory driver name.
     * @param  int|null  $ttl  Time-to-live in seconds, or null for no expiry.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function write(
        string $key,
        string $namespace,
        string $driver,
        ?int $ttl = null,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::MemoryWrite->value,
            [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => $driver,
                'ttl' => $ttl,
            ],
            runId: $runId !== '' ? $runId : HookRunContext::currentRunId(),
            parentRunId: $parentRunId !== '' ? $parentRunId : HookRunContext::currentParentRunId(),
        );
    }

    /**
     * Fires when a key is explicitly deleted from a memory driver.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace to scope the operation.
     * @param  string  $driver  Memory driver name.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function forget(
        string $key,
        string $namespace,
        string $driver,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::MemoryForget->value,
            [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => $driver,
            ],
            runId: $runId !== '' ? $runId : HookRunContext::currentRunId(),
            parentRunId: $parentRunId !== '' ? $parentRunId : HookRunContext::currentParentRunId(),
        );
    }

    /**
     * Fires on every memory read attempt, whether the key exists or not.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace to scope the operation.
     * @param  string  $driver  Memory driver name.
     * @param  bool  $hit  Whether the read hit an existing value.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function read(
        string $key,
        string $namespace,
        string $driver,
        bool $hit,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::MemoryRead->value,
            [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => $driver,
                'hit' => $hit,
            ],
            runId: $runId !== '' ? $runId : HookRunContext::currentRunId(),
            parentRunId: $parentRunId !== '' ? $parentRunId : HookRunContext::currentParentRunId(),
        );
    }
}
