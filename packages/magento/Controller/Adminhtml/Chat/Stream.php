<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Service\SseTransport;
use PhpClaw\Magento\Service\ToolCall;
use PhpClaw\Magento\Service\ToolCallCollector;
use PhpClaw\Magento\Service\ToolCallCollectorFactory;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use PhpClaw\Magento\Service\TurnErrorFrames;
use PhpClaw\Support\Ulid;
use Psr\Log\LoggerInterface;

/**
 * Admin SSE endpoint that streams tool events live during a chat turn.
 */
// non-final: Magento interceptor required
class Stream extends Action
{
    use SseTransport;
    use TurnErrorFrames;

    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_chat';

    /**
     * Bind the action context and agent factory this SSE endpoint streams through.
     *
     * @param  Context  $context  Magento backend action context.
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds the configured agent.
     * @param  LoggerInterface  $logger  PSR-3 logger for unexpected errors.
     * @param  ToolHistorySplicer  $splicer  Splices tool-call entries into conversation history.
     * @param  ToolCallCollectorFactory  $toolCallCollectorFactory  Builds a fresh tool-call collector per turn.
     * @param  JsonFactory  $jsonFactory  Builds the JSON result used when a refusal must carry its own HTTP status.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly LoggerInterface $logger,
        private readonly ToolHistorySplicer $splicer,
        private readonly ToolCallCollectorFactory $toolCallCollectorFactory,
        private readonly JsonFactory $jsonFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Extract form_key from JSON body for Magento's FormKeyValidator.
     *
     * @return bool
     */
    public function _processUrlKeys(): bool
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?? [];
        if (isset($data['form_key'])) {
            $this->getRequest()->setParam('form_key', $data['form_key']);
        }

        return parent::_processUrlKeys();
    }

    /**
     * Run the agent for the POSTed message and stream SSE frames to the client.
     *
     * @return ResultInterface|ResponseInterface|null
     */
    public function execute(): ResultInterface|ResponseInterface|null
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?? [];

        $denial = $this->conversationDenial($data);
        if ($denial !== null) {
            return $this->jsonFactory->create()->setData($denial)->setHttpResponseCode($denial['code']);
        }

        $this->sendSseHeaders();

        $emit = function (string $event, array $payload): void {
            $this->emit($event, $payload);
        };

        $frame = $this->processTurn($data, $emit);

        $event = isset($frame['error']) ? 'error' : 'done';
        $emit($event, $frame);

        return null;
    }

    /**
     * Resolve the requested conversation before any SSE header is sent, so a refusal keeps its real status.
     *
     * @param  array<string, mixed>  $data  Request body decoded from JSON.
     * @return array{error: string, code: int}|null Error frame when the caller may not touch the conversation, null otherwise.
     */
    private function conversationDenial(array $data): ?array
    {
        $conversationId = trim((string) ($data['conversation_id'] ?? ''));

        if ($conversationId === '') {
            return null;
        }

        try {
            $this->phpClawFactory->resolveMemory()->get($conversationId, 'conversations');
        } catch (ConversationAccessDeniedException $e) {
            return $this->errorFrame($e, 'phpclaw chat stream: conversation access denied');
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Execute one agent turn and return the final SSE frame payload.
     *
     * @param  array<string, mixed>  $data  Request body decoded from JSON.
     * @param  callable  $emit  Callable(string $event, array $payload): void, receives intermediate SSE frames.
     * @return array<string, mixed> Final SSE frame: done payload or error payload.
     */
    public function processTurn(array $data, callable $emit): array
    {
        $message = trim((string) ($data['message'] ?? ''));
        $conversationId = trim((string) ($data['conversation_id'] ?? ''));
        $isNew = ($conversationId === '');
        if ($isNew) {
            $conversationId = Ulid::generate();
        }

        if ($message === '') {
            return ['error' => 'Message is required.', 'code' => 400];
        }

        $collector = $this->toolCallCollectorFactory->create();
        $this->registerStreamHooks($collector, $emit);

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
                function (array $payload) use ($collector, $isNew, $message, &$title): array {
                    if (! $collector->isEmpty()) {
                        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
                        $insertAt = $this->splicer->findLastAssistantIndex($history);
                        $payload['history'] = $this->splicer->splice($history, $collector->toArrayList(), $insertAt);
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
                'tool_calls' => $collector->toArrayList(),
                'run_id' => $response->runId,
            ];
        } catch (\Throwable $e) {
            return $this->errorFrame($e, 'phpclaw chat stream: unexpected error');
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

    /**
     * Register lifecycle listeners that drive SSE frames for one streamed turn.
     *
     * @param  ToolCallCollector  $collector  Collects tool calls and emits a tool_after frame each.
     * @param  callable  $emit  Callable(string $event, array $payload): void.
     * @return void
     */
    private function registerStreamHooks(ToolCallCollector $collector, callable $emit): void
    {
        HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
            $emit('tool_before', [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
            ]);
        });

        $collector->listen(static function (ToolCall $call) use ($emit): void {
            $emit('tool_after', $call->toArray());
        });

        HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $ctx) use ($emit): void {
            $token = (string) ($ctx['token'] ?? '');
            if ($token !== '') {
                $emit('chunk', ['text' => $token]);
            }
        });
    }
}
