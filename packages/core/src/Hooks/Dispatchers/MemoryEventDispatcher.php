<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\HookRunContext;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for memory-driver lifecycle events.
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
        [$resolvedRunId, $resolvedParentRunId] = self::resolveRunContext($runId, $parentRunId);

        EventPayload::fire(
            LifecycleEvent::MemoryWrite->value,
            [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => $driver,
                'ttl' => $ttl,
            ],
            runId: $resolvedRunId,
            parentRunId: $resolvedParentRunId,
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
        [$resolvedRunId, $resolvedParentRunId] = self::resolveRunContext($runId, $parentRunId);

        EventPayload::fire(
            LifecycleEvent::MemoryForget->value,
            [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => $driver,
            ],
            runId: $resolvedRunId,
            parentRunId: $resolvedParentRunId,
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
        [$resolvedRunId, $resolvedParentRunId] = self::resolveRunContext($runId, $parentRunId);

        EventPayload::fire(
            LifecycleEvent::MemoryRead->value,
            [
                'key' => $key,
                'namespace' => $namespace,
                'driver' => $driver,
                'hit' => $hit,
            ],
            runId: $resolvedRunId,
            parentRunId: $resolvedParentRunId,
        );
    }

    /**
     * Fires after a recall lookup with how it ran and how many hits it found; never the query text.
     *
     * @param  string  $namespace  Namespace searched.
     * @param  string  $mode  "search" when the driver answered through search(), "scan" when its all() was ranked.
     * @param  int  $limit  Maximum hits asked for.
     * @param  int  $hitCount  Hits found.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function search(
        string $namespace,
        string $mode,
        int $limit,
        int $hitCount,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        [$resolvedRunId, $resolvedParentRunId] = self::resolveRunContext($runId, $parentRunId);

        EventPayload::fire(
            LifecycleEvent::MemorySearch->value,
            [
                'namespace' => $namespace,
                'mode' => $mode,
                'limit' => $limit,
                'hit_count' => $hitCount,
            ],
            runId: $resolvedRunId,
            parentRunId: $resolvedParentRunId,
        );
    }

    /**
     * Fires when recalled entries are injected into a message, with their keys and the block size; never the values.
     *
     * @param  list<string>  $keys  Keys of the injected entries.
     * @param  string  $namespace  Namespace they came from.
     * @param  int  $bytes  Size of the injected block in bytes.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function recalled(
        array $keys,
        string $namespace,
        int $bytes,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        [$resolvedRunId, $resolvedParentRunId] = self::resolveRunContext($runId, $parentRunId);

        EventPayload::fire(
            LifecycleEvent::MemoryRecalled->value,
            [
                'keys' => $keys,
                'namespace' => $namespace,
                'injected_count' => count($keys),
                'bytes' => $bytes,
            ],
            runId: $resolvedRunId,
            parentRunId: $resolvedParentRunId,
        );
    }

    /**
     * Resolve run and parent-run IDs from explicit arguments or the active hook context.
     *
     * @param  string  $runId  Caller-supplied run ID, or '' to read from context.
     * @param  string  $parentRunId  Caller-supplied parent run ID, or '' to read from context.
     * @return array{0: string, 1: string} Resolved run ID and parent run ID.
     */
    private static function resolveRunContext(string $runId, string $parentRunId): array
    {
        return [
            $runId !== '' ? $runId : HookRunContext::currentRunId(),
            $parentRunId !== '' ? $parentRunId : HookRunContext::currentParentRunId(),
        ];
    }
}
