<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Laravel\Http\StreamEventBridge;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * REST controller exposing the phpClaw send and stream endpoints.
 */
final class PhpClawController extends Controller
{
    private const INTERNAL_ERROR = 'An internal error occurred. Please try again.';

    private const FORBIDDEN = 'You do not have permission to access this conversation.';

    private const GUARD_BLOCKED = 'Request blocked by security guard.';

    /**
     * Bind the resolved phpClaw engine this controller sends and streams through.
     *
     * @param  PhpClawInterface  $agent  The resolved phpClaw engine.
     * @return void
     */
    public function __construct(
        private readonly PhpClawInterface $agent,
    ) {}

    /**
     * POST /phpclaw/send, send a message and return the full agent response.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'present|nullable|string|max:50000',
            'conversation_id' => 'nullable|string|regex:/^[A-Za-z0-9]{26}$/',
        ]);
        $message = trim((string) ($data['message'] ?? ''));
        $conversationId = trim((string) ($data['conversation_id'] ?? ''));

        if ($message === '') {
            return response()->json(['error' => 'Message cannot be empty.'], 400);
        }

        $apiKey = (string) config('phpclaw.api_key', '');
        $provider = (string) config('phpclaw.provider', '');

        if ($apiKey === '' && $provider !== '' && $provider !== 'ollama') {
            return response()->json(['error' => 'phpClaw is not configured. Set your provider and API key.'], 503);
        }

        StreamEventBridge::begin(static function (string $event, array $payload): void {});

        try {
            $conversation = $this->agent->conversation($conversationId);
            $turn = $this->agent->sendInConversation($conversation, $message);
            $response = $turn->response;

            return response()->json([
                'text' => $response->text,
                'tool_calls' => $this->toolCalls($response->toolsCalled),
                'provider' => $response->provider,
                'model' => $response->model,
                'iterations' => $response->iterations,
                'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                'conversation_id' => $turn->conversation->id,
            ]);
        } catch (ConversationAccessDeniedException) {
            return response()->json(['error' => self::FORBIDDEN], 403);
        } catch (GuardException $e) {
            report($e);

            return response()->json(['error' => self::GUARD_BLOCKED], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => self::INTERNAL_ERROR], 500);
        } finally {
            StreamEventBridge::end();
        }
    }

    /**
     * Tool calls with real input and result when the lifecycle hooks captured them, falling
     * back to the response's tool names when they did not fire.
     *
     * @param  string[]  $toolsCalled  Tool names reported by the agent response.
     * @return array<int, array{tool_name: string, tool_input: array<mixed>, tool_result: string}>
     */
    private function toolCalls(array $toolsCalled): array
    {
        $captured = StreamEventBridge::toolCalls();

        if ($captured !== []) {
            return $captured;
        }

        return array_map(static fn (string $name): array => [
            'tool_name' => $name,
            'tool_input' => [],
            'tool_result' => '',
        ], array_values($toolsCalled));
    }

    /**
     * POST /phpclaw/chat/stream, stream a conversation turn as SSE.
     *
     * @param  Request  $request
     * @return StreamedResponse
     */
    public function stream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'message' => 'present|nullable|string|max:50000',
            'conversation_id' => 'nullable|string|regex:/^[A-Za-z0-9]{26}$/',
        ]);

        $message = trim((string) ($data['message'] ?? ''));
        $conversationId = trim((string) ($data['conversation_id'] ?? ''));

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

            $apiKey = (string) config('phpclaw.api_key', '');
            $provider = (string) config('phpclaw.provider', '');

            if ($apiKey === '' && $provider !== '' && $provider !== 'ollama') {
                $emit('error', ['message' => 'phpClaw is not configured. Set your provider and API key.']);

                return;
            }

            StreamEventBridge::begin($emit);

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
                    'tool_calls' => StreamEventBridge::toolCalls(),
                    'conversation_id' => $turn->conversation->id,
                ]);
            } catch (ConversationAccessDeniedException) {
                $emit('error', ['message' => self::FORBIDDEN]);
            } catch (GuardException $e) {
                report($e);
                $emit('error', ['message' => self::GUARD_BLOCKED]);
            } catch (\Throwable $e) {
                report($e);
                $emit('error', ['message' => self::INTERNAL_ERROR]);
            } finally {
                StreamEventBridge::end();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
