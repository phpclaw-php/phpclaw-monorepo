<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use PhpClaw\Drupal\Service\ToolCall;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides shared conversation-history helpers for phpClaw admin controllers.
 */
trait ConversationHistoryTrait
{
    /**
     * Maximum accepted message length, enforced in parseJsonRequest().
     *
     * @return int
     */
    private static function maxMessageLength(): int
    {
        return 10000;
    }

    /**
     * Parse and sanitise the JSON request body into message + conversation_id.
     *
     * @param  Request  $request  The incoming HTTP request with a JSON body.
     * @return array{message: string, conversation_id: string, error: string}
     */
    private static function parseJsonRequest(Request $request): array
    {
        $json = json_decode($request->getContent(), true);
        $message = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
        $conversationId = is_array($json) ? trim((string) ($json['conversation_id'] ?? '')) : '';
        $error = '';

        if ($message !== '') {
            if (mb_strlen($message) > self::maxMessageLength()) {
                $message = '';
                $error = sprintf('Message exceeds the %d-character limit.', self::maxMessageLength());
            } else {
                $message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $message);
            }
        }

        return ['message' => $message, 'conversation_id' => $conversationId, 'error' => $error];
    }

    /**
     * Register a ToolAfter listener that collects named tool calls.
     *
     * @param  array<int, ToolCall>  $calls  Accumulator populated as tools fire.
     * @param  callable|null  $onCollect  Optional callback(ToolCall) per collected call.
     * @return void
     */
    private static function collectToolCalls(array &$calls, ?callable $onCollect = null): void
    {
        HookRegistry::on(LifecycleEvent::ToolAfter->value, static function (array $ctx) use (&$calls, $onCollect): void {
            $call = ToolCall::fromContext($ctx);
            if (! $call->isNamed()) {
                return;
            }
            $calls[] = $call;
            if ($onCollect !== null) {
                $onCollect($call);
            }
        });
    }

    /**
     * Splice collected tool-call rows into a conversation payload array.
     *
     * @param  array<string, mixed>  $payload  The conversation payload to mutate.
     * @param  array<int, ToolCall>  $toolCalls  Tool calls to splice into history.
     * @return array<string, mixed>
     */
    private static function spliceToolCallsIntoPayload(array $payload, array $toolCalls): array
    {
        if ($toolCalls === []) {
            return $payload;
        }
        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
        $insertAt = self::findLastAssistantIndex($history);
        $entries = array_map(static fn (ToolCall $c): array => $c->toHistoryEntry(), $toolCalls);
        array_splice($history, $insertAt, 0, $entries);
        $payload['history'] = $history;

        return $payload;
    }

    /**
     * Find the index of the last assistant message, before which tool rows are spliced.
     *
     * @param  array<int, mixed>  $messages  Ordered history array to search.
     * @return int
     */
    private static function findLastAssistantIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (is_array($messages[$i]) && ($messages[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($messages);
    }
}
