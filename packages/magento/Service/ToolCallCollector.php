<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Collects tool invocations emitted during a single agent turn.
 */
final class ToolCallCollector
{
    private array $calls = [];

    /**
     * Register the ToolAfter listener that records each named tool call.
     *
     * @param  callable|null  $onCollect  Optional callable(ToolCall): void invoked for each recorded call
     *                                    (used by the SSE stream to emit a tool_after frame).
     * @return void
     */
    public function listen(?callable $onCollect = null): void
    {
        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use ($onCollect): void {
            $call = ToolCall::fromContext($ctx);
            if (! $call->isNamed()) {
                return;
            }
            $this->calls[] = $call;
            if ($onCollect !== null) {
                $onCollect($call);
            }
        });
    }

    /**
     * Whether no tool calls have been collected.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->calls === [];
    }

    /**
     * Collected calls in the client/response array shape.
     *
     * @return array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>
     */
    public function toArrayList(): array
    {
        return array_map(static fn (ToolCall $c): array => $c->toArray(), $this->calls);
    }
}
