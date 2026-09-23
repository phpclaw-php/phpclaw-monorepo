<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Drupal\Service\ToolCall;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE streaming endpoint for the phpClaw chat UI.
 */
final class PhpClawChatStreamController extends ControllerBase
{
    use ConversationHistoryTrait;
    use CsrfValidationTrait;
    use ResolveAgentTrait;

    /**
     * Create a new PhpClawChatStreamController instance.
     *
     * @param  ClawInterface|null  $agent  The phpClaw agent for streaming conversations (nullable, see resolveAgent()).
     * @param  LoggerInterface|null  $logger  phpClaw logger channel.
     * @param  CsrfTokenGenerator|null  $csrfToken  CSRF token generator.
     * @return void
     */
    public function __construct(
        private readonly ?ClawInterface $agent = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CsrfTokenGenerator $csrfToken = null,
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
            self::resolveAgent($container, 'phpclaw.agent.chat'),
            $container->get('logger.channel.phpclaw'),
            $container->get('csrf_token'),
        );
    }

    /**
     * Stream a chat turn via Server-Sent Events.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return StreamedResponse
     */
    public function stream(Request $request): StreamedResponse
    {
        $response = new StreamedResponse;
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        if (! $this->validateCsrfToken($request)) {
            $response->setStatusCode(Response::HTTP_FORBIDDEN);
            $response->setCallback(function (): void {
                self::emitSseError('Invalid CSRF token.');
            });

            return $response;
        }

        ['message' => $message, 'conversation_id' => $conversationId, 'error' => $parseError] = self::parseJsonRequest($request);

        if ($parseError !== '') {
            $response->setStatusCode(Response::HTTP_BAD_REQUEST);
            $response->setCallback(function () use ($parseError): void {
                self::emitSseError($parseError);
            });

            return $response;
        }

        if ($message === '') {
            $response->setStatusCode(Response::HTTP_BAD_REQUEST);
            $response->setCallback(function (): void {
                self::emitSseError('Message is required.');
            });

            return $response;
        }

        if ($this->agent === null) {
            $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
            $response->setCallback(function (): void {
                self::emitSseError('Agent unavailable. Check the provider and API key in Settings.');
            });

            return $response;
        }
        $agent = $this->agent;
        $logger = $this->logger;

        try {
            $conversation = $agent->conversation($conversationId);
        } catch (ConversationAccessDeniedException $e) {
            $response->setStatusCode(Response::HTTP_FORBIDDEN);
            $response->setCallback(function (): void {
                self::emitSseError('You do not have permission to access this conversation.');
            });

            return $response;
        }

        $response->setCallback(function () use ($agent, $message, $conversation, $logger): void {
            self::sendSseHeaders();

            $emit = static function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $collectedToolCalls = [];

            HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
                $emit('tool_before', [
                    'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                    'tool_input' => (array) ($ctx['tool_input'] ?? []),
                ]);
            });

            self::collectToolCalls($collectedToolCalls, static function (ToolCall $call) use ($emit): void {
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
                    static function (array $payload) use (&$collectedToolCalls): array {
                        return self::spliceToolCallsIntoPayload($payload, $collectedToolCalls);
                    },
                );
                $resp = $turn->response;

                $emit('done', [
                    'text' => $resp->text,
                    'provider' => $resp->provider,
                    'model' => $resp->model,
                    'iterations' => $resp->iterations,
                    'tokens' => ($resp->inputTokens ?? 0) + ($resp->outputTokens ?? 0),
                    'conversation_id' => $turn->conversation->id,
                    'tool_calls' => array_map(static fn (ToolCall $c): array => $c->toArray(), $collectedToolCalls),
                ]);
            } catch (ConversationAccessDeniedException $e) {
                self::emitSseError('You do not have permission to access this conversation.');
            } catch (GuardException $e) {
                self::emitSseError('Blocked request.');
            } catch (ProviderException $e) {
                $logger?->error('@message', ['@message' => $e->getMessage()]);
                self::emitSseError('AI provider error. Check your API key and try again.');
            } catch (\Throwable $e) {
                $logger?->error('@message', ['@message' => $e->getMessage()]);
                self::emitSseError('Internal error. Check site logs.');
            }
        });

        return $response;
    }

    /**
     * Emit SSE response headers and drain output buffers.
     *
     * @return void
     */
    private static function sendSseHeaders(): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
    }

    /**
     * Emit a one-shot SSE error frame.
     *
     * @param  string  $message  The error message to emit.
     * @return void
     */
    private static function emitSseError(string $message): void
    {
        self::sendSseHeaders();
        echo "event: error\n";
        echo 'data: '.json_encode(['message' => $message])."\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
