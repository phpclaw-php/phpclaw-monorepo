<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Http;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Class-based hook listener that relays tool lifecycle events to the active SSE stream, holding per-stream state on the shared service instance the controller and the boot-time hook registration both receive.
 */
final class StreamEventBridge implements HookInterface
{
    private ?\Closure $emitter = null;

    private array $toolCalls = [];

    /**
     * Begin a stream: set the active emitter and reset the collected tool calls.
     *
     * @param  \Closure(string, array<string, mixed>): void  $emitter
     * @return void
     */
    public function begin(\Closure $emitter): void
    {
        $this->emitter = $emitter;
        $this->toolCalls = [];
    }

    /**
     * End a stream: clear the active emitter so the listener no-ops between requests.
     *
     * @return void
     */
    public function end(): void
    {
        $this->emitter = null;
        $this->toolCalls = [];
    }

    /**
     * Return the tool calls collected during the current/last stream.
     *
     * @return array<int, array{tool_name: string, tool_input: array<mixed>, tool_result: string}>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Relay a ToolBefore/ToolAfter event to the active SSE emitter.
     *
     * @param  array<string, mixed>  $context  Event context (carries the fired event name under 'event').
     * @return void
     */
    public function handle(array $context): void
    {
        $emit = $this->emitter;
        if ($emit === null) {
            return;
        }

        $event = (string) ($context['event'] ?? '');

        if ($event === LifecycleEvent::ToolBefore->value) {
            $emit('tool_before', [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'tool_input' => (array) ($context['tool_input'] ?? []),
            ]);

            return;
        }

        if ($event === LifecycleEvent::ToolAfter->value) {
            $name = (string) ($context['tool_name'] ?? '');
            if ($name === '') {
                return;
            }

            $entry = [
                'tool_name' => $name,
                'tool_input' => (array) ($context['tool_input'] ?? []),
                'tool_result' => (string) ($context['tool_result'] ?? ''),
            ];

            $this->toolCalls[] = $entry;
            $emit('tool_after', $entry);
        }
    }
}
