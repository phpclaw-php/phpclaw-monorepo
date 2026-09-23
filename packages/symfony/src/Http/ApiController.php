<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Http;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * REST controller exposing phpClaw endpoints for send, stream, test-connection and conversations.
 */
#[AsController]
final class ApiController
{
    /**
     * Bind the resolved phpClaw engine this controller sends and streams through, plus the stream bridge that relays tool events.
     *
     * @param  PhpClawInterface  $agent  The resolved phpClaw engine.
     * @param  StreamEventBridge  $streamBridge  Shared bridge instance, the same one registered as a hook listener at boot.
     */
    public function __construct(
        private readonly PhpClawInterface $agent,
        private readonly StreamEventBridge $streamBridge,
    ) {}

    /**
     * POST /phpclaw/send, send a message and return the full agent response.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return JsonResponse
     */
    public function send(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $message = trim((string) ($body['message'] ?? ''));
        $conversationId = trim((string) ($body['conversation_id'] ?? ''));

        if ($message === '') {
            return new JsonResponse(['error' => 'Message cannot be empty.'], 400);
        }

        $this->streamBridge->begin(static function (string $event, array $payload): void {});

        try {
            $conversation = $this->agent->conversation($conversationId);
            $turn = $this->agent->sendInConversation($conversation, $message);
            $response = $turn->response;

            return new JsonResponse([
                'text' => $response->text,
                'tool_calls' => $this->buildToolCalls($response->toolsCalled, $this->streamBridge->toolCalls()),
                'provider' => $response->provider,
                'model' => $response->model,
                'iterations' => $response->iterations,
                'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                'conversation_id' => $turn->conversation->id,
            ]);
        } catch (ConversationAccessDeniedException) {
            return new JsonResponse(['error' => 'You do not have permission to access this conversation.'], 403);
        } catch (GuardException) {
            return new JsonResponse(['error' => 'Request blocked by security guard.'], 422);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'An internal error occurred. Please try again.'], 500);
        } finally {
            $this->streamBridge->end();
        }
    }

    /**
     * POST /phpclaw/chat/stream, stream a conversation turn as SSE.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return StreamedResponse
     */
    public function stream(Request $request): StreamedResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $message = trim((string) ($body['message'] ?? ''));
        $conversationId = trim((string) ($body['conversation_id'] ?? ''));

        return new StreamedResponse(function () use ($message, $conversationId): void {
            $emit = static function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            if ($message === '') {
                $emit('error', ['message' => 'Message cannot be empty.']);

                return;
            }

            $this->streamBridge->begin($emit);

            try {
                $conversation = $this->agent->conversation($conversationId);
                $turn = $this->agent->streamInConversation(
                    $conversation,
                    $message,
                    static function (string $token) use ($emit): void {
                        if ($token !== '') {
                            $emit('chunk', ['text' => $token]);
                        }
                    },
                );

                $response = $turn->response;

                $emit('done', [
                    'text' => $response->text,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                    'iterations' => $response->iterations,
                    'tool_calls' => $this->streamBridge->toolCalls(),
                    'conversation_id' => $turn->conversation->id,
                ]);
            } catch (ConversationAccessDeniedException) {
                $emit('error', ['message' => 'You do not have permission to access this conversation.']);
            } catch (GuardException) {
                $emit('error', ['message' => 'Request blocked by security guard.']);
            } catch (\Throwable) {
                $emit('error', ['message' => 'An internal error occurred. Please try again.']);
            } finally {
                $this->streamBridge->end();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Build the tool_calls response shape, preferring the bridge's recorded input and result over the bare names the core response carries.
     *
     * @param  string[]  $toolsCalled  Tool names reported by the agent response.
     * @param  array<int, array{tool_name: string, tool_input: array<mixed>, tool_result: string}>  $recorded  Calls the bridge observed during this run.
     * @return array<int, array{tool_name: string, tool_input: array<mixed>, tool_result: string}>
     */
    private function buildToolCalls(array $toolsCalled, array $recorded = []): array
    {
        if ($recorded !== []) {
            return array_values($recorded);
        }

        return array_map(static fn (string $name): array => [
            'tool_name' => $name,
            'tool_input' => [],
            'tool_result' => '',
        ], array_values($toolsCalled));
    }
}
