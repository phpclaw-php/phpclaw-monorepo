<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Http;

use PhpClaw\Agent\RunStatus;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Symfony\RunApprovals;
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
     * @param  RunApprovals|null  $approvals  Describes a paused or suspended durable run; null reports only its id and status.
     */
    public function __construct(
        private readonly PhpClawInterface $agent,
        private readonly StreamEventBridge $streamBridge,
        private readonly ?RunApprovals $approvals = null,
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
        } catch (RunSuspendedException $e) {
            return new JsonResponse($this->describeSuspension($e), 202);
        } catch (ConversationAccessDeniedException) {
            return new JsonResponse(['error' => 'You do not have permission to access this conversation.'], 403);
        } catch (GuardException) {
            return new JsonResponse(['error' => 'Request blocked by security guard.'], 422);
        } catch (TokenBudgetExceededException) {
            return new JsonResponse(['error' => 'Token budget reached for this run.'], 422);
        } catch (ProviderException $e) {
            if ($e->statusCode === 429) {
                return new JsonResponse(['error' => 'Rate limit reached, try again shortly.'], 429);
            }

            return new JsonResponse(['error' => 'An internal error occurred. Please try again.'], 500);
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
            } catch (RunSuspendedException $e) {
                $emit($e->status === RunStatus::AwaitingApproval ? 'approval_required' : 'run_suspended', $this->describeSuspension($e));
            } catch (ConversationAccessDeniedException) {
                $emit('error', ['message' => 'You do not have permission to access this conversation.']);
            } catch (GuardException) {
                $emit('error', ['message' => 'Request blocked by security guard.']);
            } catch (TokenBudgetExceededException) {
                $emit('error', ['message' => 'Token budget reached for this run.']);
            } catch (ProviderException $e) {
                $emit('error', ['message' => $e->statusCode === 429
                    ? 'Rate limit reached, try again shortly.'
                    : 'An internal error occurred. Please try again.']);
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

    /**
     * The paused or suspended run as the client sees it; only its id and status when the engine cannot describe it.
     *
     * @param  RunSuspendedException  $e  The pause or suspension.
     * @return array<string, mixed>
     */
    private function describeSuspension(RunSuspendedException $e): array
    {
        if (! $this->agent instanceof Claw || $this->approvals === null) {
            return ['run_id' => $e->runId, 'status' => $e->status->value, 'conversation_id' => null, 'pending' => null];
        }

        return $this->approvals->describe($this->approvals->load($this->agent, $e->runId));
    }
}
