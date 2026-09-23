<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Debug panel: handles prompt send and conversation load for the admin playground.
 */
final class DebugPanel
{
    /**
     * Create a new DebugPanel instance.
     *
     * @param  ClawInterface  $engine
     * @return void
     */
    public function __construct(
        private readonly ClawInterface $engine,
    ) {}

    /**
     * Send a message to the agent and return the response array.
     *
     * @param  string  $message
     * @param  string  $conversationId
     * @return array{
     *     text: string,
     *     provider: string,
     *     model: string,
     *     tokens: int,
     *     iterations: int,
     *     conversation_id: string,
     *     tool_calls: list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>
     * }
     *
     * @throws \RuntimeException on agent failure
     */
    public function send(string $message, string $conversationId = ''): array
    {
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required.');
        }

        $collectedToolCalls = [];
        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use (&$collectedToolCalls): void {
            $collectedToolCalls[] = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
        });

        try {
            $conversation = $this->engine->conversation($conversationId);
            $turn = $this->engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                function (array $payload) use (&$collectedToolCalls): array {
                    $toolCalls = array_values(array_filter(
                        $collectedToolCalls,
                        static fn (array $c): bool => $c['tool_name'] !== '',
                    ));

                    if ($toolCalls !== []) {
                        $payload = $this->spliceToolCallsIntoHistory($payload, $toolCalls);
                    }

                    return $payload;
                },
            );
            $response = $turn->response;

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
                'conversation_id' => $turn->conversation->id,
                'tool_calls' => $toolCalls,
            ];
        } catch (GuardException $e) {
            throw new \RuntimeException('Prompt blocked by security guard', previous: $e);
        } catch (ProviderException $e) {
            throw new \RuntimeException('AI provider error', previous: $e);
        } catch (MaxIterationsException $e) {
            throw new \RuntimeException('Agent reached max iterations', previous: $e);
        }
    }

    /**
     * Stream a message as SSE frames for tool calls and assistant text chunks, persisting tool rows before the engine saves.
     *
     * @param  string  $message
     * @param  string  $conversationId
     * @param  callable(string, array<string, mixed>): void  $emit
     * @return void
     */
    public function stream(string $message, string $conversationId, callable $emit): void
    {
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required.');
        }

        $isNew = ($conversationId === '');
        $conversation = $this->engine->conversation($conversationId);
        $collector = [];
        $title = '';

        $this->registerStreamHooks($emit, $collector);

        try {
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
                'conversation_id' => $result->conversation->id,
                'title' => $title,
                'is_new' => $isNew,
                'tool_calls' => array_values(array_filter(
                    $collector,
                    static fn (array $c): bool => $c['tool_name'] !== '',
                )),
            ]);
        } catch (GuardException $e) {
            $emit('error', ['message' => 'Prompt blocked by security guard.']);
        } catch (ProviderException $e) {
            $emit('error', ['message' => 'AI provider error.']);
        } catch (MaxIterationsException $e) {
            $emit('error', ['message' => 'Agent reached max iterations.']);
        }
    }

    /**
     * Load conversation message history for display in the debug UI.
     *
     * @param  string  $conversationId
     * @return array{title: string, messages: list<array{role: string, content?: string, tool_name?: string, tool_input?: array<array-key, mixed>, tool_result?: string}>}
     */
    public function loadConversation(string $conversationId): array
    {
        if ($conversationId === '') {
            return ['title' => '', 'messages' => []];
        }

        $memory = $this->engine->memory();
        $data = $memory->get($conversationId, 'conversations');

        if (! is_array($data)) {
            return ['title' => '', 'messages' => []];
        }

        $messages = [];
        foreach ((array) ($data['history'] ?? []) as $msg) {
            if (! is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? '');

            if ($role === 'tool') {
                $messages[] = [
                    'role' => 'tool',
                    'tool_name' => (string) ($msg['tool_name'] ?? ''),
                    'tool_input' => is_array($msg['tool_input'] ?? null) ? $msg['tool_input'] : [],
                    'tool_result' => (string) ($msg['content'] ?? ''),
                ];

                continue;
            }

            if (in_array($role, ['user', 'assistant'], true)) {
                $messages[] = [
                    'role' => $role,
                    'content' => (string) ($msg['content'] ?? ''),
                ];
            }
        }

        return [
            'title' => (string) ($data['title'] ?? ''),
            'messages' => $messages,
        ];
    }

    /**
     * List stored conversations for the sidebar, most recent first.
     *
     * @return array<int, array{id: string, title: string, time: string}>
     */
    public function listConversations(): array
    {
        try {
            $all = $this->engine->memory()->all('conversations');
        } catch (\Throwable) {
            return [];
        }

        $list = [];
        foreach ($all as $id => $conv) {
            if (! is_array($conv)) {
                continue;
            }
            $time = $conv['updated_at'] ?? $conv['created_at'] ?? '';
            $time = is_scalar($time) ? (string) $time : '';
            $list[] = [
                'id' => (string) $id,
                'title' => (string) ($conv['title'] ?? ''),
                'time' => $time,
            ];
        }

        usort($list, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));

        return $list;
    }

    /**
     * Register the tool_before / tool_after / chunk SSE emitters on HookRegistry.
     *
     * @param  callable(string, array<string, mixed>): void  $emit
     * @param  list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>  $collector
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
     * Build the before-persist callback that splices collected tool rows into history and sets the title on a new conversation.
     *
     * @param  list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>  $collector
     * @param  bool  $isNew  True when this is the first turn of a new conversation.
     * @param  string  $message  The user message used to derive the conversation title.
     * @param  string  $titleRef  Written back with the resolved conversation title.
     * @return callable Receives and returns the conversation payload (array<string, mixed>).
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
                $payload['title'] = mb_strlen($message) > 60
                    ? mb_substr($message, 0, 60)."\u{2026}"
                    : $message;
            }

            $titleRef = (string) ($payload['title'] ?? '');

            return $payload;
        };
    }

    /**
     * Splice collected tool calls into a stored conversation's history before the last assistant message.
     *
     * @param  array<string, mixed>  $payload  Stored conversation (`history` key).
     * @param  list<array{tool_name: string, tool_input: array<array-key, mixed>, tool_result: string}>  $toolCalls
     * @return array<string, mixed>
     */
    private function spliceToolCallsIntoHistory(array $payload, array $toolCalls): array
    {
        if ($toolCalls === []) {
            return $payload;
        }

        $history = (array) ($payload['history'] ?? []);
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
     * Index of the last assistant message in a history list, or the list length when there is none.
     *
     * @param  array<int, mixed>  $history
     * @return int
     */
    private function findLastAssistantIndex(array $history): int
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (is_array($history[$i]) && ($history[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($history);
    }
}
