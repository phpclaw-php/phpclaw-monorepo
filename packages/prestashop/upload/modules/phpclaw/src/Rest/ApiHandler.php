<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;

/**
 * Pure PHP handler for the phpClaw REST API send endpoint: no PrestaShop dependencies.
 */
final class ApiHandler
{
    public const MAX_MESSAGE_LENGTH = 50000;

    public const TEST_PROBE = 'Reply with the single word: ok';

    private const HTTP_UNPROCESSABLE = 422;

    private const HTTP_TOO_MANY_REQUESTS = 429;

    private const BUDGET_MESSAGE = 'Token budget reached for this run.';

    private const RATE_LIMIT_MESSAGE = 'Rate limit reached, try again shortly.';

    private const AGENT_ERROR_MESSAGE = 'Agent error. Check your provider settings.';

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
     * The REST error for a failed send: error code, message and HTTP status.
     *
     * @param  \Throwable  $e  The failure raised while handling the request.
     * @return array{0: string, 1: string, 2: int}
     */
    public static function errorFor(\Throwable $e): array
    {
        return match (true) {
            $e instanceof ConversationAccessDeniedException => ['phpclaw_forbidden', 'You do not have permission to access this conversation.', 403],
            $e instanceof GuardException => ['phpclaw_guard', 'Prompt injection detected. Request blocked.', 422],
            $e instanceof AdapterException => ['phpclaw_not_configured', self::AGENT_ERROR_MESSAGE, 503],
            $e instanceof TokenBudgetExceededException => ['phpclaw_budget_exceeded', self::BUDGET_MESSAGE, self::HTTP_UNPROCESSABLE],
            $e instanceof ProviderException && $e->statusCode === self::HTTP_TOO_MANY_REQUESTS => ['phpclaw_rate_limited', self::RATE_LIMIT_MESSAGE, self::HTTP_TOO_MANY_REQUESTS],
            default => ['phpclaw_error', self::AGENT_ERROR_MESSAGE, 500],
        };
    }

    /**
     * HTTP status for a limit failure: 422 for a spent token budget, 429 for a provider rate limit, null otherwise.
     *
     * @param  \Throwable  $e  The failure, possibly wrapping the original exception.
     * @return int|null
     */
    public static function limitStatus(\Throwable $e): ?int
    {
        return match (self::limitMessage($e)) {
            self::BUDGET_MESSAGE => self::HTTP_UNPROCESSABLE,
            self::RATE_LIMIT_MESSAGE => self::HTTP_TOO_MANY_REQUESTS,
            default => null,
        };
    }

    /**
     * The user-facing message when a failure, or any exception it wraps, is a spent token budget or a provider 429.
     *
     * @param  \Throwable  $e  The failure, possibly wrapping the original exception.
     * @return string|null The limit message, or null when the failure is not a limit.
     */
    public static function limitMessage(\Throwable $e): ?string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TokenBudgetExceededException) {
                return self::BUDGET_MESSAGE;
            }

            if ($cause instanceof ProviderException && $cause->statusCode === self::HTTP_TOO_MANY_REQUESTS) {
                return self::RATE_LIMIT_MESSAGE;
            }
        }

        return null;
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
