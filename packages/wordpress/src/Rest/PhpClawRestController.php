<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Rest;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;
use PhpClaw\WordPress\Support\ToolHistorySplicer;

/**
 * WP REST API controller.
 */
final class PhpClawRestController
{
    public const EMPTY_RESPONSE_TEXT = 'The model did not return a response. Try rephrasing your question or use a more specific query.';

    private const NAMESPACE = 'phpclaw';

    private const ROUTE = '/send';

    /**
     * Create a new REST controller instance.
     *
     * @param  PhpClawInterface|null  $engine  The phpClaw engine instance, or null if not configured.
     * @param  array<string, mixed>  $config  Plugin configuration array.
     */
    public function __construct(
        private readonly ?PhpClawInterface $engine,
        private readonly array $config,
    ) {}

    /**
     * Register the REST route - call from the rest_api_init hook.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => $this->argSchema(),
            ],
        );
    }

    /**
     * Permission check - requires the configured capability.
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
     * Handle the POST request and return the agent response.
     *
     * @param  \WP_REST_Request  $request  The incoming REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($this->engine === null) {
            return new \WP_Error(
                'phpclaw_not_configured',
                __('phpClaw is not configured. Set your provider and API key in Settings → phpClaw.', 'phpclaw'),
                ['status' => 503],
            );
        }

        $message = trim((string) $request->get_param('message'));

        if ($message === '') {
            return new \WP_Error(
                'phpclaw_empty_message',
                __('Message cannot be empty.', 'phpclaw'),
                ['status' => 400],
            );
        }

        try {
            $conversationId = trim((string) ($request->get_param('conversation_id') ?? ''));
            $conversation = $this->engine->conversation($conversationId);

            $toolCalls = [];
            ToolHistorySplicer::collect($toolCalls);

            $turn = $this->engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                ToolHistorySplicer::beforePersist($toolCalls),
            );
            $response = $turn->response;

            return new \WP_REST_Response([
                'text' => $response->text !== '' ? $response->text : self::EMPTY_RESPONSE_TEXT,
                'provider' => $response->provider,
                'model' => $response->model,
                'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                'iterations' => $response->iterations,
                'conversation_id' => $turn->conversation->id,
            ], 200);
        } catch (ConversationAccessDeniedException $e) {
            return new \WP_Error(
                'phpclaw_forbidden',
                __('You do not have permission to access this resource.', 'phpclaw'),
                ['status' => 403],
            );
        } catch (GuardException $e) {
            error_log('phpClaw guard blocked: '.$e->getMessage());

            return new \WP_Error(
                'phpclaw_guard',
                'Request blocked by security guard.',
                ['status' => 422],
            );
        } catch (\Throwable $e) {
            error_log('phpClaw REST error: '.$e->getMessage());

            return new \WP_Error(
                'phpclaw_error',
                'An internal error occurred. Please try again or contact your administrator.',
                ['status' => 500],
            );
        }
    }

    /**
     * Build the WP REST API argument schema for the send endpoint.
     *
     * @return array<string, mixed>
     */
    private function argSchema(): array
    {
        return [
            'message' => [
                'description' => __('The prompt to send to the AI agent.', 'phpclaw'),
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static function ($v): bool|\WP_Error {
                    if (! is_string($v) || strlen(trim($v)) === 0) {
                        return new \WP_Error('phpclaw_empty', 'Message cannot be empty.', ['status' => 400]);
                    }
                    if (mb_strlen($v) > 40000) {
                        return new \WP_Error('phpclaw_too_long', 'Message exceeds 40 000 character limit.', ['status' => 400]);
                    }

                    return true;
                },
            ],
            'conversation_id' => [
                'description' => __('Existing conversation ID to continue. Omit to start a new conversation.', 'phpclaw'),
                'type' => 'string',
                'required' => false,
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static function ($v): bool|\WP_Error {
                    if ($v !== '' && ! preg_match('/^[0-9A-Z]{26}$/', (string) $v)) {
                        return new \WP_Error('phpclaw_invalid_id', 'Invalid conversation_id format.', ['status' => 400]);
                    }

                    return true;
                },
            ],
            'stream' => [
                'description' => __('Whether to stream the response (not supported via REST).', 'phpclaw'),
                'type' => 'boolean',
                'default' => false,
            ],
        ];
    }
}
