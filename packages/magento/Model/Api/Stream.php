<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model\Api;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Magento\Api\Data\StreamResponseInterface;
use PhpClaw\Magento\Api\StreamInterface;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Service\SseTransport;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use PhpClaw\Magento\Service\TurnErrorFrames;
use PhpClaw\Support\Ulid;
use Psr\Log\LoggerInterface;

/**
 * REST API implementation for SSE streaming chat.
 */
// non-final: Magento interceptor required
class Stream implements StreamInterface
{
    use SseTransport;
    use TurnErrorFrames;

    /**
     * Bind the agent factory and logger this REST endpoint streams through.
     *
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds the configured agent.
     * @param  LoggerInterface  $logger  PSR-3 logger for unexpected errors.
     * @param  ToolHistorySplicer  $splicer  Splices tool-call entries into conversation history.
     * @return void
     */
    public function __construct(
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly LoggerInterface $logger,
        private readonly ToolHistorySplicer $splicer,
    ) {}

    /**
     * Start an SSE stream for the given message.
     *
     * @param  string  $message  User prompt to send to the agent.
     * @param  string  $conversationId  Existing conversation ULID, or '' for a new conversation.
     * @return StreamResponseInterface Placeholder; exit is called before this returns.
     */
    public function stream(string $message, string $conversationId = ''): StreamResponseInterface
    {
        $denial = $this->conversationDenial($conversationId);
        if ($denial !== null) {
            http_response_code($denial['code']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($denial);

            exit;
        }

        $this->sendSseHeaders();

        $emit = function (string $event, array $payload): void {
            $this->emit($event, $payload);
        };

        $frame = $this->processStream($message, $conversationId, $emit);
        $event = isset($frame['error']) ? 'error' : 'done';
        $emit($event, $frame);

        exit;
    }

    /**
     * Resolve the requested conversation before any SSE header is sent, so a refusal keeps its real status.
     *
     * @param  string  $conversationId  Existing conversation ULID, or '' for a new conversation.
     * @return array{error: string, code: int}|null Error frame when the caller may not touch the conversation, null otherwise.
     */
    private function conversationDenial(string $conversationId): ?array
    {
        $conversationId = trim($conversationId);

        if ($conversationId === '') {
            return null;
        }

        try {
            $this->phpClawFactory->resolveMemory()->get($conversationId, 'conversations');
        } catch (ConversationAccessDeniedException $e) {
            return $this->errorFrame($e, 'phpclaw rest stream: conversation access denied');
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Execute one streaming agent turn and return the final SSE frame payload.
     *
     * @param  string  $message  User prompt; an empty string returns an error payload.
     * @param  string  $conversationId  Existing conversation ULID, or '' to generate a new one.
     * @param  callable  $emit  Callable(string $event, array $payload): void, receives intermediate SSE frames.
     * @return array<string, mixed> Final SSE frame: done payload or error payload.
     */
    public function processStream(string $message, string $conversationId, callable $emit): array
    {
        $message = trim($message);
        $conversationId = trim($conversationId);
        $isNew = ($conversationId === '');
        if ($isNew) {
            $conversationId = Ulid::generate();
        }

        if ($message === '') {
            return ['error' => 'Message is required.', 'code' => 400];
        }

        $collectedToolCalls = [];

        HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
            $emit('tool_before', [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
            ]);
        });

        HookRegistry::on(LifecycleEvent::ToolAfter->value, static function (array $ctx) use ($emit, &$collectedToolCalls): void {
            $entry = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
            if ($entry['tool_name'] !== '') {
                $collectedToolCalls[] = $entry;
                $emit('tool_after', $entry);
            }
        });

        HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $ctx) use ($emit): void {
            $token = (string) ($ctx['token'] ?? '');
            if ($token !== '') {
                $emit('chunk', ['text' => $token]);
            }
        });

        $title = '';

        try {
            $agent = $this->phpClawFactory->create();
            $conversation = $agent->conversation($conversationId);

            $turn = $agent->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                function (array $payload) use (&$collectedToolCalls, $isNew, $message, &$title): array {
                    if ($collectedToolCalls !== []) {
                        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
                        $insertAt = $this->splicer->findLastAssistantIndex($history);
                        $payload['history'] = $this->splicer->splice($history, $collectedToolCalls, $insertAt);
                    }
                    if ($isNew) {
                        $payload['title'] = mb_strlen($message) > 60
                            ? mb_substr($message, 0, 60)."\u{2026}"
                            : $message;
                    }
                    $title = (string) ($payload['title'] ?? '');

                    return $payload;
                },
            );

            $response = $turn->response;

            return [
                'text' => $response->text,
                'provider' => $response->provider,
                'model' => $response->model,
                'tokens' => $response->totalTokens(),
                'iterations' => $response->iterations,
                'conversation_id' => $turn->conversation->id,
                'title' => $title,
                'is_new' => $isNew,
                'tool_calls' => $collectedToolCalls,
                'run_id' => $response->runId,
            ];
        } catch (\Throwable $e) {
            return $this->errorFrame($e, 'phpclaw rest stream: unexpected error');
        }
    }

    /**
     * Return the PSR-3 logger for the TurnErrorFrames trait.
     *
     * @return LoggerInterface
     */
    protected function getErrorLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
