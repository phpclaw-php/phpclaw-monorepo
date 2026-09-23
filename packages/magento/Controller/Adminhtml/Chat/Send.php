<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Magento\Exception\ConversationAccessDeniedException;
use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Magento\Service\ToolCallCollectorFactory;
use PhpClaw\Magento\Service\ToolHistorySplicer;
use PhpClaw\Support\Ulid;
use Psr\Log\LoggerInterface;

/**
 * Admin AJAX endpoint that sends a message to the phpClaw agent and returns the reply as JSON.
 */
// non-final: Magento interceptor required
class Send extends Action
{
    public const ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_chat';

    /**
     * Bind the action context and JSON factory this endpoint responds through.
     *
     * @param  Context  $context  Magento backend action context.
     * @param  JsonFactory  $jsonFactory  Factory for JSON result objects.
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that builds the configured agent.
     * @param  LoggerInterface  $logger  PSR-3 logger for unexpected errors.
     * @param  ToolHistorySplicer  $splicer  Splices tool-call entries into conversation history.
     * @param  ToolCallCollectorFactory  $toolCallCollectorFactory  Builds a fresh tool-call collector per turn.
     * @return void
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly PhpClawFactoryInterface $phpClawFactory,
        private readonly LoggerInterface $logger,
        private readonly ToolHistorySplicer $splicer,
        private readonly ToolCallCollectorFactory $toolCallCollectorFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Extract form_key from JSON body and inject into request params for POST validation.
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
     * Run the agent for the POSTed message and return the reply with metadata as JSON.
     *
     * @return Json { text, conversation_id, provider, model, tokens, iterations, tool_calls } or error shape.
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?? [];

        $message = trim((string) ($data['message'] ?? ''));
        $conversationId = trim((string) ($data['conversation_id'] ?? '')) ?: Ulid::generate();

        if ($message === '') {
            return $result->setData(['error' => 'Message is required.'])->setHttpResponseCode(400);
        }

        try {
            $agent = $this->phpClawFactory->create();
            $conversation = $agent->conversation($conversationId);

            $collector = $this->toolCallCollectorFactory->create();
            $collector->listen();

            $turn = $agent->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {},
                function (array $payload) use ($collector): array {
                    $toolCalls = $collector->toArrayList();
                    if ($toolCalls !== []) {
                        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
                        $insertAt = $this->splicer->findLastAssistantIndex($history);
                        $payload['history'] = $this->splicer->splice($history, $toolCalls, $insertAt);
                    }

                    return $payload;
                },
            );
            $response = $turn->response;
            $toolCalls = $collector->toArrayList();

            return $result->setData([
                'text' => $response->text,
                'conversation_id' => $turn->conversation->id,
                'provider' => $response->provider,
                'model' => $response->model,
                'tokens' => $response->totalTokens(),
                'iterations' => $response->iterations,
                'tool_calls' => $toolCalls,
                'run_id' => $response->runId,
            ]);
        } catch (ConversationAccessDeniedException) {
            return $result->setData(['error' => 'You do not have permission to access this conversation.'])->setHttpResponseCode(403);
        } catch (GuardException) {
            return $result->setData(['error' => 'Blocked request.'])->setHttpResponseCode(422);
        } catch (ProviderException) {
            return $result->setData(['error' => 'AI provider error. Check your API key and try again.'])->setHttpResponseCode(502);
        } catch (MaxIterationsException) {
            return $result->setData(['error' => 'Could not complete. Try a simpler question.'])->setHttpResponseCode(504);
        } catch (\Throwable $e) {
            $this->logger->error('phpclaw chat send: unexpected error', ['exception' => $e]);

            return $result->setData(['error' => 'An internal error occurred. Please try again.'])->setHttpResponseCode(500);
        }
    }
}
