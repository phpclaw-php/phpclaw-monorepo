<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Api;

use Drupal\Core\Controller\ControllerBase;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Controller\Admin\ConversationHistoryTrait;
use PhpClaw\Drupal\Controller\Admin\ResolveAgentTrait;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Drupal\Service\ToolCall;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * REST endpoints for the phpClaw agent, authenticated by Drupal's own providers.
 */
final class PhpClawApiController extends ControllerBase
{
    use ConversationHistoryTrait;
    use ResolveAgentTrait;

    private const AGENT_SERVICE = 'phpclaw.agent.chat';

    private const AGENT_UNAVAILABLE = 'Agent unavailable. Check the provider and API key in Settings.';

    private const CONVERSATION_DENIED = 'You do not have permission to access this conversation.';

    private const BLOCKED = 'Blocked request.';

    private const PROVIDER_ERROR = 'AI provider error. Check your API key and try again.';

    private const INTERNAL_ERROR = 'Internal error. Check site logs.';

    private const SSE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Create a new PhpClawApiController instance.
     *
     * @param  ClawInterface|null  $agent  The phpClaw agent, null when the provider is unconfigured.
     * @param  LoggerInterface|null  $logger  phpClaw logger channel.
     * @return void
     */
    public function __construct(
        private readonly ?ClawInterface $agent = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Create a new controller instance from the service container.
     *
     * @param  ContainerInterface  $container  The Drupal service container.
     * @return static
     */
    public static function create(ContainerInterface $container): static
    {
        return new self(
            self::resolveAgent($container, self::AGENT_SERVICE),
            $container->get('logger.channel.phpclaw'),
        );
    }

    /**
     * Run one agent turn and answer with the complete result as JSON.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return JsonResponse
     */
    public function send(Request $request): JsonResponse
    {
        ['message' => $message, 'conversation_id' => $conversationId, 'error' => $parseError] = self::parseJsonRequest($request);

        if ($parseError !== '') {
            return self::error($parseError, Response::HTTP_BAD_REQUEST);
        }

        if ($message === '') {
            return self::error('Message is required.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->agent === null) {
            return self::error(self::AGENT_UNAVAILABLE, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $toolCalls = [];
        self::collectToolCalls($toolCalls);

        try {
            $conversation = $this->agent->conversation($conversationId);
            $turn = $this->agent->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                static function (array $payload) use (&$toolCalls): array {
                    return self::spliceToolCallsIntoPayload($payload, $toolCalls);
                },
            );

            return new JsonResponse(self::turnPayload($turn, $toolCalls) + ['ok' => true]);
        } catch (ConversationAccessDeniedException) {
            return self::error(self::CONVERSATION_DENIED, Response::HTTP_FORBIDDEN);
        } catch (GuardException) {
            return self::error(self::BLOCKED, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ProviderException $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return self::error(self::PROVIDER_ERROR, Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return self::error(self::INTERNAL_ERROR, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Run one agent turn and answer with Server-Sent Events.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return StreamedResponse
     */
    public function stream(Request $request): StreamedResponse
    {
        $response = self::streamResponse();

        ['message' => $message, 'conversation_id' => $conversationId, 'error' => $parseError] = self::parseJsonRequest($request);

        if ($parseError !== '') {
            return self::streamError($response, $parseError, Response::HTTP_BAD_REQUEST);
        }

        if ($message === '') {
            return self::streamError($response, 'Message is required.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->agent === null) {
            return self::streamError($response, self::AGENT_UNAVAILABLE, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $agent = $this->agent;
        $logger = $this->logger;

        try {
            $conversation = $agent->conversation($conversationId);
        } catch (ConversationAccessDeniedException) {
            return self::streamError($response, self::CONVERSATION_DENIED, Response::HTTP_FORBIDDEN);
        }

        $response->setCallback(static function () use ($agent, $logger, $message, $conversation): void {
            self::drainOutputBuffers();

            $emit = static function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, self::SSE_FLAGS)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $toolCalls = [];

            HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
                $emit('tool_before', [
                    'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                    'tool_input' => (array) ($ctx['tool_input'] ?? []),
                ]);
            });

            self::collectToolCalls($toolCalls, static function (ToolCall $call) use ($emit): void {
                $emit('tool_after', $call->toArray());
            });

            HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $ctx) use ($emit): void {
                $token = (string) ($ctx['token'] ?? '');

                if ($token !== '') {
                    $emit('chunk', ['text' => $token]);
                }
            });

            try {
                $turn = $agent->streamInConversation(
                    $conversation,
                    $message,
                    static function (string $token): void {
                        unset($token);
                    },
                    static function (array $payload) use (&$toolCalls): array {
                        return self::spliceToolCallsIntoPayload($payload, $toolCalls);
                    },
                );

                $emit('done', self::turnPayload($turn, $toolCalls));
            } catch (ConversationAccessDeniedException) {
                $emit('error', ['message' => self::CONVERSATION_DENIED]);
            } catch (GuardException) {
                $emit('error', ['message' => self::BLOCKED]);
            } catch (ProviderException $e) {
                $logger?->error('@message', ['@message' => $e->getMessage()]);
                $emit('error', ['message' => self::PROVIDER_ERROR]);
            } catch (\Throwable $e) {
                $logger?->error('@message', ['@message' => $e->getMessage()]);
                $emit('error', ['message' => self::INTERNAL_ERROR]);
            }
        });

        return $response;
    }

    /**
     * Build the response body shared by both endpoints.
     *
     * @param  object  $turn  The completed agent turn.
     * @param  list<ToolCall>  $toolCalls  Tool calls collected during the turn.
     * @return array<string, mixed>
     */
    private static function turnPayload(object $turn, array $toolCalls): array
    {
        $response = $turn->response;

        return [
            'text' => $response->text,
            'provider' => $response->provider,
            'model' => $response->model,
            'iterations' => $response->iterations,
            'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
            'conversation_id' => $turn->conversation->id,
            'tool_calls' => array_map(static fn (ToolCall $call): array => $call->toArray(), $toolCalls),
        ];
    }

    /**
     * Build a JSON error response in the shape both endpoints use.
     *
     * @param  string  $message  Error text exposed to the caller.
     * @param  int  $status  HTTP status code.
     * @return JsonResponse
     */
    private static function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }

    /**
     * Build an empty SSE response carrying the headers a stream client needs.
     *
     * @return StreamedResponse
     */
    private static function streamResponse(): StreamedResponse
    {
        $response = new StreamedResponse;
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * Attach a single error frame to a stream response and set its status.
     *
     * @param  StreamedResponse  $response  The response being prepared.
     * @param  string  $message  Error text exposed to the caller.
     * @param  int  $status  HTTP status code.
     * @return StreamedResponse
     */
    private static function streamError(StreamedResponse $response, string $message, int $status): StreamedResponse
    {
        $response->setStatusCode($status);
        $response->setCallback(static function () use ($message): void {
            self::drainOutputBuffers();
            echo "event: error\n";
            echo 'data: '.json_encode(['message' => $message], self::SSE_FLAGS)."\n\n";
            @flush();
        });

        return $response;
    }

    /**
     * Drop every output buffer and disable compression so frames reach the client as they are written.
     *
     * @return void
     */
    private static function drainOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
    }
}
