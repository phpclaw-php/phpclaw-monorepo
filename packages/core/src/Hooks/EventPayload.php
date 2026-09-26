<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

/**
 * Shared payload-augmentation + fire helper used by every domain-specific event dispatcher in {@see PhpClaw\Hooks\Dispatchers}.
 */
final class EventPayload
{
    /**
     * Fire an event, conditionally appending common metadata keys.
     *
     * @param  string  $event  Event name to dispatch.
     * @param  array<string, mixed>  $ctx  Event payload; common keys are appended in-place.
     * @param  string  $runId  Run ID to attach, if non-empty.
     * @param  string  $parentRunId  Parent run ID to attach, if non-empty.
     * @param  bool  $streaming  True to mark the event as part of a streaming flow.
     * @param  string  $conversationId  Conversation ID to attach, if non-empty.
     * @return void
     */
    public static function fire(
        string $event,
        array $ctx,
        string $runId = '',
        string $parentRunId = '',
        bool $streaming = false,
        string $conversationId = '',
    ): void {
        if ($runId !== '') {
            $ctx['run_id'] = $runId;
        }

        if ($parentRunId !== '') {
            $ctx['parent_run_id'] = $parentRunId;
        }

        if ($streaming) {
            $ctx['streaming'] = true;
        }

        if ($conversationId !== '') {
            $ctx['conversation_id'] = $conversationId;
        }

        HookRegistry::fire($event, $ctx);
    }
}
