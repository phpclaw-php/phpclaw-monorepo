<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Rest;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;

/**
 * WP REST controller for the chat streaming endpoint.
 */
final class PhpClawAdminController
{
    private const NAMESPACE = 'phpclaw';

    /**
     * Bind the phpClaw engine and plugin configuration this controller streams with.
     *
     * @param  PhpClawInterface|null  $engine  The phpClaw engine, or null when not configured.
     * @param  array<string, mixed>  $config  Plugin configuration.
     */
    public function __construct(
        private readonly ?PhpClawInterface $engine,
        private readonly array $config,
    ) {}

    /**
     * Register the chat streaming REST route. Call from the rest_api_init hook.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/chat/stream', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handleChatStream'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    /**
     * Permission check for chat usage - requires the configured chat capability.
     *
     * @param  \WP_REST_Request  $request  The incoming REST request.
     * @return bool|\WP_Error
     */
    public function checkPermission(\WP_REST_Request $request): bool|\WP_Error
    {
        $capability = (string) apply_filters(
            'phpclaw_rest_capability',
            $this->config['rest_capability'] ?? 'phpclaw_use_chat',
        );

        if ($capability === '' || in_array(strtolower($capability), ['read', 'exist', 'level_0'], true)) {
            $capability = 'manage_options';
        }

        if (! current_user_can($capability)) {
            return new \WP_Error(
                'phpclaw_forbidden',
                __('You do not have permission to use phpClaw.', 'phpclaw'),
                ['status' => 403],
            );
        }

        return true;
    }

    /**
     * Stream a chat response as Server-Sent Events.
     *
     * @param  \WP_REST_Request  $request  The incoming REST request.
     * @return \WP_Error
     */
    public function handleChatStream(\WP_REST_Request $request): \WP_Error
    {
        if ($this->engine === null) {
            return $this->notConfigured();
        }

        $message = trim((string) $request->get_param('message'));
        $conversationId = trim((string) ($request->get_param('conversation_id') ?? ''));

        if ($message === '') {
            return new \WP_Error(
                'phpclaw_empty_message',
                __('Message cannot be empty.', 'phpclaw'),
                ['status' => 400],
            );
        }

        $this->sendSseHeaders();
        $this->streamChat($message, $conversationId, $this->buildEmitter());

        exit;
    }

    /**
     * Build the SSE emit callable - writes one framed event per call and flushes.
     *
     * @return callable(string, array<string, mixed>): void
     */
    private function buildEmitter(): callable
    {
        return static function (string $event, array $data): void {
            echo 'event: '.$event."\n";
            echo 'data: '.wp_json_encode($data)."\n\n";
            if (function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        };
    }

    /**
     * Run one streaming chat turn - wires hook listeners, calls the engine, emits frames.
     *
     * @param  string  $message  The user message.
     * @param  string  $conversationId  The conversation id to continue, or '' for a new one.
     * @param  callable(string, array<string, mixed>): void  $emit  SSE frame emitter.
     * @return void
     */
    private function streamChat(string $message, string $conversationId, callable $emit): void
    {
        try {
            $isNew = ($conversationId === '');
            $collector = [];
            $title = '';

            HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
                $emit('tool_before', [
                    'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                    'tool_input' => (array) ($ctx['tool_input'] ?? []),
                ]);
            });

            HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use ($emit, &$collector): void {
                $entry = [
                    'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                    'tool_input' => (array) ($ctx['tool_input'] ?? []),
                    'tool_result' => (string) ($ctx['tool_result'] ?? ''),
                ];
                if ($entry['tool_name'] !== '') {
                    $collector[] = $entry;
                    $emit('tool_after', $entry);
                }
            });

            HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $ctx) use ($emit): void {
                $token = (string) ($ctx['token'] ?? '');
                if ($token !== '') {
                    $emit('chunk', ['text' => $token]);
                }
            });

            $conversation = $this->engine->conversation($conversationId);
            $turn = $this->engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                function (array $payload) use (&$collector, $isNew, $message, &$title): array {
                    $toolCalls = $collector;

                    if ($toolCalls !== []) {
                        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
                        $insertAt = $this->findLastAssistantIndex($history);
                        $toolEntries = array_map(static fn (array $c): array => [
                            'role' => 'tool',
                            'content' => $c['tool_result'],
                            'tool_name' => $c['tool_name'],
                            'tool_input' => $c['tool_input'],
                        ], $toolCalls);
                        array_splice($history, $insertAt, 0, $toolEntries);
                        $payload['history'] = $history;
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

            $emit('done', [
                'text' => $response->text !== '' ? $response->text : PhpClawRestController::EMPTY_RESPONSE_TEXT,
                'provider' => $response->provider,
                'model' => $response->model,
                'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                'iterations' => $response->iterations,
                'conversation_id' => $turn->conversation->id,
                'title' => $title,
                'is_new' => $isNew,
                'tool_calls' => $collector,
            ]);
        } catch (ConversationAccessDeniedException $e) {
            if (! headers_sent()) {
                status_header(403);
            }
            $emit('error', [
                'message' => __('You do not have permission to access this resource.', 'phpclaw'),
            ]);
        } catch (\Throwable $e) {
            error_log('phpClaw REST stream error: '.$e->getMessage());
            $emit('error', [
                'message' => __('An internal error occurred. Please try again.', 'phpclaw'),
            ]);
        }
    }

    /**
     * Find the index of the last assistant message - tool rows are spliced before it.
     *
     * @param  array<int, mixed>  $messages  The conversation history rows.
     * @return int
     */
    private function findLastAssistantIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (is_array($messages[$i]) && ($messages[$i]['role'] ?? '') === 'assistant') {
                return $i;
            }
        }

        return count($messages);
    }

    /**
     * Emit SSE response headers (text/event-stream, no buffering, CORS-safe).
     *
     * @return void
     */
    private function sendSseHeaders(): void
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /**
     * Build the 503-not-configured error response.
     *
     * @return \WP_Error
     */
    private function notConfigured(): \WP_Error
    {
        return new \WP_Error(
            'phpclaw_not_configured',
            __('phpClaw is not configured. Set your provider and API key in Settings → phpClaw.', 'phpclaw'),
            ['status' => 503],
        );
    }
}
