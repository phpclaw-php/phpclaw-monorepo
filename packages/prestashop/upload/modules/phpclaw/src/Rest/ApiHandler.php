<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Pure PHP handler for the phpClaw REST API send endpoint: no PrestaShop dependencies.
 */
final class ApiHandler
{
    public const MAX_MESSAGE_LENGTH = 50000;

    public const TEST_PROBE = 'Reply with the single word: ok';

    /**
     * Create a new ApiHandler instance.
     *
     * @param  ClawInterface  $engine  The phpClaw engine used to process conversation turns.
     */
    public function __construct(
        private readonly ClawInterface $engine,
    ) {}

    /**
     * Validate the message and run the AI engine.
     *
     * @param  string  $message  User message (already trimmed).
     * @param  string  $conversationId  Optional existing conversation ULID.
     * @return array{text:string,provider:string,model:string,tokens:int,iterations:int,conversation_id:string,tool_calls:list<array{tool_name:string,tool_input:array<array-key,mixed>,tool_result:string}>}
     *
     * @throws \InvalidArgumentException If message is empty or too long.
     * @throws GuardException If prompt injection is detected.
     * @throws ProviderException On AI provider API failure.
     * @throws MaxIterationsException If the agent loop exceeds the iteration cap.
     */
    public function handle(string $message, string $conversationId = ''): array
    {
        if ($message === '') {
            throw new \InvalidArgumentException('message is required.', 400);
        }

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException(
                'message exceeds maximum length of '.self::MAX_MESSAGE_LENGTH.' characters.',
                400,
            );
        }

        $conversation = $this->engine->conversation($conversationId);

        $collectedToolCalls = [];
        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use (&$collectedToolCalls): void {
            $collectedToolCalls[] = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
        });

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

                return $toolCalls === [] ? $payload : $this->spliceToolCallsIntoHistory($payload, $toolCalls);
            },
        );

        $response = $turn->response;

        return [
            'text' => $response->text,
            'provider' => $response->provider,
            'model' => $response->model,
            'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
            'iterations' => $response->iterations,
            'conversation_id' => $turn->conversation->id,
            'tool_calls' => array_values(array_filter(
                $collectedToolCalls,
                static fn (array $c): bool => $c['tool_name'] !== '',
            )),
        ];
    }

    /**
     * The configured max message length.
     *
     * @return int
     */
    public static function maxMessageLength(): int
    {
        return self::MAX_MESSAGE_LENGTH;
    }

    /**
     * Splice collected tool calls into the persisted history payload, just before the last assistant reply.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{tool_name:string,tool_input:array<array-key,mixed>,tool_result:string}>  $toolCalls
     * @return array<string, mixed>
     */
    private function spliceToolCallsIntoHistory(array $payload, array $toolCalls): array
    {
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
