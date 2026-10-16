<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Admin;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Debug panel service for the Joomla phpClaw adapter.
 */
final class DebugPanel
{
    private const TITLE_MAX_LENGTH = 60;

    /**
     * Bind the phpClaw engine the debug panel sends messages through.
     *
     * @param  ClawInterface  $engine  PhpClaw engine instance for sending messages
     */
    public function __construct(
        private readonly ClawInterface $engine,
    ) {}

    /**
     * Send a message via the engine, optionally in a conversation.
     *
     * @param  string  $message  User message to send
     * @param  string  $conversationId  Existing conversation ID, or empty to start new
     * @return array{
     *     text: string,
     *     provider: string,
     *     model: string,
     *     tokens: int,
     *     iterations: int,
     *     conversation_id: string,
     *     tool_calls: list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>
     * }
     */
    public function send(string $message, string $conversationId = ''): array
    {
        $isNew = ($conversationId === '');
        $conversation = $this->engine->conversation($conversationId ?: '');

        $collectedToolCalls = [];
        self::collectToolCalls($collectedToolCalls);

        $title = '';
        $result = $this->engine->streamInConversation(
            $conversation,
            $message,
            static function (string $token): void {},
            $this->buildMemoryCallback($collectedToolCalls, $isNew, $message, $title),
        );
        $response = $result->response;
        $toolCalls = self::namedToolCalls($collectedToolCalls);

        return [
            'text' => $response->text,
            'provider' => $response->provider,
            'model' => $response->model,
            'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
            'iterations' => $response->iterations,
            'conversation_id' => $conversation->id,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Stream a chat turn via SSE, emitting tool/chunk/done frames through the callback.
     *
     * @param  string  $message
     * @param  string  $conversationId
     * @param  callable(string, array<string, mixed>): void  $emit  SSE frame emitter
     * @return void
     */
    public function stream(string $message, string $conversationId, callable $emit): void
    {
        $isNew = ($conversationId === '');
        $conversation = $this->engine->conversation($conversationId ?: '');
        $collector = [];
        $title = '';

        $this->registerStreamHooks($emit, $collector);

        $result = $this->engine->streamInConversation(
            $conversation,
            $message,
            static function (string $token): void {},
            $this->buildMemoryCallback($collector, $isNew, $message, $title),
        );

        $response = $result->response;

        $emit('done', [
            'text' => $response->text,
            'provider' => $response->provider,
            'model' => $response->model,
            'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
            'iterations' => $response->iterations,
            'conversation_id' => $conversation->id,
            'title' => $title,
            'is_new' => $isNew,
            'tool_calls' => $collector,
        ]);
    }

    /**
     * Load a past conversation from memory for display in the debug panel.
     *
     * @param  string  $id  Conversation ID to load
     * @return array{id: string, title: string, messages: list<array<string,mixed>>}
     */
    public function loadConversation(string $id): array
    {
        $stored = $this->engine->memory()->get($id, 'conversations');

        if (! is_array($stored)) {
            return ['id' => $id, 'title' => '', 'messages' => []];
        }

        $raw = array_values(array_filter(
            (array) ($stored['history'] ?? $stored['messages'] ?? []),
            fn (mixed $entry) => is_array($entry) && in_array($entry['role'] ?? '', ['user', 'assistant', 'tool'], true),
        ));

        $messages = array_map(static function (array $entry): array {
            if ($entry['role'] !== 'tool') {
                return $entry;
            }

            return [
                'role' => 'tool',
                'tool_name' => (string) ($entry['tool_name'] ?? ''),
                'tool_input' => is_array($entry['tool_input'] ?? null) ? $entry['tool_input'] : [],
                'tool_result' => (string) ($entry['content'] ?? ''),
            ];
        }, $raw);

        return [
            'id' => $id,
            'title' => (string) ($stored['title'] ?? ''),
            'messages' => $messages,
        ];
    }

    /**
     * Collect every tool call of the current turn into $toolCalls; remove the returned listener when the turn ends.
     *
     * @param  list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>  $toolCalls  Filled as each tool finishes.
     * @return \Closure The registered tool-after listener.
     */
    public static function collectToolCalls(array &$toolCalls): \Closure
    {
        $listener = static function (array $context) use (&$toolCalls): void {
            $toolCalls[] = [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'tool_input' => (array) ($context['tool_input'] ?? []),
                'tool_result' => (string) ($context['tool_result'] ?? ''),
            ];
        };
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $listener);

        return $listener;
    }

    /**
     * Build the beforePersist callback that stores the collected tool calls as tool rows before the last assistant message.
     *
     * @param  list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>  $toolCalls  Tool calls of the turn, read when the payload is saved.
     * @return callable(array<string, mixed>): array<string, mixed>
     */
    public static function toolCallPersister(array &$toolCalls): callable
    {
        return static function (array $payload) use (&$toolCalls): array {
            return self::spliceToolCallsIntoHistory($payload, self::namedToolCalls($toolCalls));
        };
    }

    /**
     * Drop collected entries that carry no tool name.
     *
     * @param  list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>  $toolCalls  Collected entries.
     * @return list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>
     */
    private static function namedToolCalls(array $toolCalls): array
    {
        return array_values(array_filter(
            $toolCalls,
            static fn (array $call): bool => $call['tool_name'] !== '',
        ));
    }

    /**
     * Find the index of the last assistant message, or the history length when there is none.
     *
     * @param  array<int, mixed>  $messages
     * @return int
     */
    private static function findLastAssistantIndex(array $messages): int
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (is_array($messages[$index]) && ($messages[$index]['role'] ?? '') === 'assistant') {
                return $index;
            }
        }

        return count($messages);
    }

    /**
     * Splice tool-call rows before the last assistant message in the history array.
     *
     * @param  array<string, mixed>  $payload  Conversation payload.
     * @param  list<array<string, mixed>>  $toolCalls  Collected tool call entries.
     * @return array<string, mixed> The payload with the tool rows in its history.
     */
    private static function spliceToolCallsIntoHistory(array $payload, array $toolCalls): array
    {
        if ($toolCalls === []) {
            return $payload;
        }

        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
        $insertAt = self::findLastAssistantIndex($history);
        $toolEntries = array_map(static fn (array $call): array => [
            'role' => 'tool',
            'content' => $call['tool_result'],
            'tool_name' => $call['tool_name'],
            'tool_input' => $call['tool_input'],
        ], $toolCalls);

        array_splice($history, $insertAt, 0, $toolEntries);
        $payload['history'] = $history;

        return $payload;
    }

    /**
     * Register the tool_before / tool_after / chunk SSE emitters on HookRegistry.
     *
     * @param  callable(string, array<string, mixed>): void  $emit
     * @param  list<array<string, mixed>>  &$collector  Reference to tool-call accumulator.
     * @return void
     */
    private function registerStreamHooks(callable $emit, array &$collector): void
    {
        HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $context) use ($emit): void {
            $emit('tool_before', [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'tool_input' => (array) ($context['tool_input'] ?? []),
            ]);
        });

        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $context) use ($emit, &$collector): void {
            $entry = [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'tool_input' => (array) ($context['tool_input'] ?? []),
                'tool_result' => (string) ($context['tool_result'] ?? ''),
            ];
            if ($entry['tool_name'] !== '') {
                $collector[] = $entry;
                $emit('tool_after', $entry);
            }
        });

        HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $context) use ($emit): void {
            $token = (string) ($context['token'] ?? '');
            if ($token !== '') {
                $emit('chunk', ['text' => $token]);
            }
        });
    }

    /**
     * Build the memory-update callback for streamInConversation().
     *
     * @param  list<array<string, mixed>>  &$collector
     * @param  bool  $isNew
     * @param  string  $message
     * @param  string  &$titleRef  Written back with the final title.
     * @return callable(array<string, mixed>): array
     */
    private function buildMemoryCallback(array &$collector, bool $isNew, string $message, string &$titleRef): callable
    {
        return function (array $payload) use (&$collector, $isNew, $message, &$titleRef): array {
            $payload = self::spliceToolCallsIntoHistory($payload, self::namedToolCalls($collector));

            if ($isNew) {
                $payload['title'] = mb_strlen($message) > self::TITLE_MAX_LENGTH
                    ? mb_substr($message, 0, self::TITLE_MAX_LENGTH)."\u{2026}"
                    : $message;
            }

            $titleRef = (string) ($payload['title'] ?? '');

            return $payload;
        };
    }
}
