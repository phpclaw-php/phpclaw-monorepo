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
        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use (&$collectedToolCalls): void {
            $collectedToolCalls[] = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
        });

        $title = '';
        $result = $this->engine->streamInConversation(
            $conversation,
            $message,
            static function (string $token): void {},
            $this->buildMemoryCallback($collectedToolCalls, $isNew, $message, $title),
        );
        $response = $result->response;

        $toolCalls = array_values(array_filter(
            $collectedToolCalls,
            static fn (array $c): bool => $c['tool_name'] !== '',
        ));

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
            fn (mixed $m) => is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant', 'tool'], true),
        ));

        $messages = array_map(static function (array $m): array {
            if ($m['role'] !== 'tool') {
                return $m;
            }

            return [
                'role' => 'tool',
                'tool_name' => (string) ($m['tool_name'] ?? ''),
                'tool_input' => is_array($m['tool_input'] ?? null) ? $m['tool_input'] : [],
                'tool_result' => (string) ($m['content'] ?? ''),
            ];
        }, $raw);

        return [
            'id' => $id,
            'title' => (string) ($stored['title'] ?? ''),
            'messages' => $messages,
        ];
    }

    /**
     * Find the index of the last assistant message - tool rows are spliced before it.
     *
     * @param  array<int, mixed>  $messages
     * @return int
     */
    private function findLastAssistantIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (is_array($messages[$i]) && ($messages[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($messages);
    }

    /**
     * Splice tool-call rows before the last assistant message in the history array.
     *
     * @param  array<string, mixed>  $payload  Conversation payload (mutated in place).
     * @param  list<array<string, mixed>>  $toolCalls  Collected tool call entries.
     * @return array<string, mixed> The mutated payload.
     */
    private function spliceToolCallsIntoHistory(array $payload, array $toolCalls): array
    {
        if ($toolCalls === []) {
            return $payload;
        }

        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
        $insertAt = $this->findLastAssistantIndex($history);
        $toolEntries = array_map(static fn (array $c): array => [
            'role' => 'tool',
            'content' => $c['tool_result'],
            'tool_name' => $c['tool_name'],
            'tool_input' => $c['tool_input'],
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
        HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
            $emit('tool_before', [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
            ]);
        });

        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use ($emit, &$collector): void {
            $entry = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
            if ($entry['tool_name'] !== '') {
                $collector[] = $entry;
                $emit('tool_after', $entry);
            }
        });

        HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $ctx) use ($emit): void {
            $token = (string) ($ctx['token'] ?? '');
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
            $toolCalls = array_values(array_filter(
                $collector,
                static fn (array $c): bool => $c['tool_name'] !== '',
            ));

            $payload = $this->spliceToolCallsIntoHistory($payload, $toolCalls);

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
