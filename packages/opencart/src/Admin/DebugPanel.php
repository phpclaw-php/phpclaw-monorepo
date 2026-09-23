<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\OpenCart\Support\ToolHistorySplicer;

/**
 * Debug panel logic for the phpClaw OpenCart admin module.
 */
final class DebugPanel
{
    /**
     * Bind the Claw engine this panel sends and streams through.
     *
     * @param  ClawInterface  $engine  Configured AI agent engine.
     */
    public function __construct(
        private readonly ClawInterface $engine,
    ) {}

    /**
     * Send a message to the agent and return the response array.
     *
     * @param  string  $message  User prompt to send.
     * @param  string  $conversationId  Existing conversation ID; empty starts a new conversation.
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
     * @throws \InvalidArgumentException if $message is empty
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
                    return ToolHistorySplicer::splice($payload, $collectedToolCalls);
                },
            );
            $response = $turn->response;

            $text = $response->text;
            if ($text === '') {
                $text = 'The model did not return a response. Try rephrasing your question or use a more specific query (e.g. "What is the price of Wireless Mouse?").';
            }

            $toolCalls = array_values(array_filter(
                $collectedToolCalls,
                static fn (array $c): bool => $c['tool_name'] !== '',
            ));

            return [
                'text' => $text,
                'provider' => $response->provider,
                'model' => $response->model,
                'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                'iterations' => $response->iterations,
                'conversation_id' => $conversation->id,
                'tool_calls' => $toolCalls,
            ];
        } catch (GuardException $e) {
            throw new \RuntimeException('Request blocked by security guard.', previous: $e);
        } catch (ProviderException $e) {
            throw new \RuntimeException('AI provider error.', previous: $e);
        } catch (MaxIterationsException $e) {
            throw new \RuntimeException('Agent reached max iterations.', previous: $e);
        }
    }

    /**
     * Stream a chat turn via SSE, emitting tool_before / tool_after / chunk / done frames.
     *
     * @param  string  $message  User prompt.
     * @param  string  $conversationId  Existing conversation ID; empty starts a new one.
     * @param  callable(string, array<string, mixed>): void  $emit  SSE frame emitter.
     * @return void
     *
     * @throws \InvalidArgumentException if $message is empty
     * @throws \RuntimeException on agent failure
     */
    public function stream(string $message, string $conversationId, callable $emit): void
    {
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required.');
        }

        $isNew = ($conversationId === '');
        $conversation = $this->engine->conversation($conversationId);
        $collectedToolCalls = [];

        $listeners = $this->registerStreamHooks($emit, $collectedToolCalls);

        try {
            $title = '';
            $result = $this->engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                function (array $payload) use (&$collectedToolCalls, $isNew, $message, &$title): array {
                    return $this->mutatePayloadForStream($payload, $collectedToolCalls, $isNew, $message, $title);
                },
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
                'tool_calls' => $collectedToolCalls,
            ]);
        } catch (GuardException $e) {
            throw new \RuntimeException('Request blocked by security guard.', previous: $e);
        } catch (ProviderException $e) {
            throw new \RuntimeException('AI provider error.', previous: $e);
        } catch (MaxIterationsException $e) {
            throw new \RuntimeException('Agent reached max iterations.', previous: $e);
        } finally {
            foreach ($listeners as [$event, $handler]) {
                HookRegistry::off($event, $handler);
            }
        }
    }

    /**
     * Load conversation message history for display in the debug UI.
     *
     * @param  string  $conversationId  Conversation ID to load; returns empty on blank.
     * @return array{title: string, messages: array<int, array<string, mixed>>}
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

        $raw = array_values(array_filter(
            (array) ($data['history'] ?? $data['messages'] ?? []),
            static fn ($m) => is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant', 'tool'], true),
        ));

        $messages = array_map(static function (array $m): array {
            if (($m['role'] ?? '') !== 'tool') {
                return [
                    'role' => (string) ($m['role'] ?? ''),
                    'content' => (string) ($m['content'] ?? ''),
                ];
            }

            return [
                'role' => 'tool',
                'tool_name' => (string) ($m['tool_name'] ?? ''),
                'tool_input' => is_array($m['tool_input'] ?? null) ? $m['tool_input'] : [],
                'tool_result' => (string) ($m['content'] ?? ''),
            ];
        }, $raw);

        return [
            'title' => (string) ($data['title'] ?? ''),
            'messages' => $messages,
        ];
    }

    /**
     * Register the three HookRegistry listeners needed for SSE streaming, returning each as an [event, handler] pair so stream() can deregister them once the turn ends.
     *
     * @param  callable  $emit  SSE frame emitter.
     * @param  list<array<string, mixed>>  &$collectedToolCalls  Mutable collection appended by the tool.after listener.
     * @return list<array{0: string, 1: callable}>
     */
    private function registerStreamHooks(callable $emit, array &$collectedToolCalls): array
    {
        $onToolBefore = static function (array $ctx) use ($emit): void {
            $emit('tool_before', [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
            ]);
        };

        $onToolAfter = static function (array $ctx) use ($emit, &$collectedToolCalls): void {
            $entry = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
            if ($entry['tool_name'] !== '') {
                $collectedToolCalls[] = $entry;
                $emit('tool_after', $entry);
            }
        };

        $onProviderToken = static function (array $ctx) use ($emit): void {
            $token = (string) ($ctx['token'] ?? '');
            if ($token !== '') {
                $emit('chunk', ['text' => $token]);
            }
        };

        HookRegistry::on(LifecycleEvent::ToolBefore->value, $onToolBefore);
        HookRegistry::on(LifecycleEvent::ToolAfter->value, $onToolAfter);
        HookRegistry::on(LifecycleEvent::ProviderToken->value, $onProviderToken);

        return [
            [LifecycleEvent::ToolBefore->value, $onToolBefore],
            [LifecycleEvent::ToolAfter->value, $onToolAfter],
            [LifecycleEvent::ProviderToken->value, $onProviderToken],
        ];
    }

    /**
     * Mutate the persistence payload before it is written to memory by streamInConversation.
     *
     * @param  array<string, mixed>  $payload  Conversation payload.
     * @param  list<array<string, mixed>>  $collectedToolCalls  Tool calls collected during the stream.
     * @param  bool  $isNew  Whether this is a new conversation.
     * @param  string  $message  Original user message (used as title source).
     * @param  string  &$title  Mutable title reference updated here.
     * @return array<string, mixed>
     */
    private function mutatePayloadForStream(
        array $payload,
        array $collectedToolCalls,
        bool $isNew,
        string $message,
        string &$title,
    ): array {
        $payload = ToolHistorySplicer::splice($payload, $collectedToolCalls);

        if ($isNew) {
            $payload['title'] = mb_strlen($message) > 60
                ? mb_substr($message, 0, 60)."\u{2026}"
                : $message;
        }

        $title = (string) ($payload['title'] ?? '');

        return $payload;
    }
}
