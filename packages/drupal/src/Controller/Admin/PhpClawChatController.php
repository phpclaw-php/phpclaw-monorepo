<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Drupal\Service\ToolCall;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Chat tab: chat-style conversation UI.
 */
final class PhpClawChatController extends ControllerBase
{
    use ConversationHistoryTrait;
    use CsrfValidationTrait;
    use ResolveAgentTrait;

    /**
     * Create a new PhpClawChatController instance.
     *
     * @param  ClawInterface|null  $agent  The phpClaw agent for chat conversations (nullable, see resolveAgent()).
     * @param  LoggerInterface|null  $logger  phpClaw logger channel.
     * @param  CsrfTokenGenerator|null  $csrfToken  CSRF token generator.
     * @param  TimeInterface|null  $time  Drupal time service for relative-time labels (nullable for direct construction in tests).
     * @return void
     */
    public function __construct(
        private readonly ?ClawInterface $agent = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CsrfTokenGenerator $csrfToken = null,
        private readonly ?TimeInterface $time = null,
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
            $container->get('datetime.time'),
        );
    }

    /**
     * Render the chat UI page with initial conversation data.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $memory = null;
        $convs = [];
        try {
            $memory = $this->agent->memory();
            $convs = $memory->all('conversations');
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);
        }

        $initConvId = '';
        $initTitle = '';
        $initMessages = [];

        if ($convs !== [] && $memory !== null) {
            $initConvId = (string) array_key_first($convs);
            $initTitle = is_array($convs[$initConvId])
                ? (string) ($convs[$initConvId]['title'] ?? '')
                : '';

            try {
                $stored = $memory->get($initConvId, 'conversations');
                if (is_array($stored)) {
                    foreach ((array) ($stored['history'] ?? []) as $msg) {
                        $role = $msg['role'] ?? '';
                        if (in_array($role, ['user', 'assistant'], true)) {
                            $initMessages[] = ['role' => $role, 'content' => (string) ($msg['content'] ?? '')];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->error('@message', ['@message' => $e->getMessage()]);
            }
        }

        $conversations = [];
        foreach ($convs as $cid => $conv) {
            $title = is_array($conv) ? (string) ($conv['title'] ?? '') : '';
            $display = $title !== '' ? $title : 'New conversation';
            $when = $this->relativeTime(is_array($conv) ? (string) ($conv['updated_at'] ?? $conv['created_at'] ?? '') : '');
            $conversations[(string) $cid] = ['display' => $display, 'when' => $when];
        }

        $messagesHtml = '';
        if ($initConvId === '') {
            $messagesHtml = '<div id="phpclaw-welcome"><h2>What can I help you with?</h2><p>Ask anything about your Drupal site: content types, users, database queries, modules, and more.</p></div>';
        } elseif ($initMessages === []) {
            $messagesHtml = '<div id="phpclaw-welcome"><h2>Conversation started</h2><p>No messages stored yet. Send a message below to continue.</p></div>';
        } else {
            foreach ($initMessages as $msg) {
                $messagesHtml .= self::renderBubbleHtml($msg['role'], $msg['content']);
            }
        }

        $config = $this->config('phpclaw.settings');
        $providerName = (string) ($config->get('provider') ?? '');
        $modelName = (string) ($config->get('model') ?? '');

        return [
            '#theme' => 'phpclaw_admin_chat',
            '#conversations' => $conversations,
            '#init_conv_id' => $initConvId,
            '#init_title' => $initTitle !== '' ? $initTitle : 'New Chat',
            '#init_messages_html' => $messagesHtml,
            '#provider' => $providerName,
            '#model' => $modelName,
            '#attached' => [
                'library' => ['phpclaw/admin.chat'],
                'drupalSettings' => [
                    'phpclaw_chat' => [
                        'csrf_token' => $this->csrfToken?->get('phpclaw-chat'),
                        'init_conv_id' => $initConvId,
                    ],
                ],
            ],
            '#cache' => ['max-age' => 0],
        ];
    }

    /**
     * Handle a chat message send request via AJAX.
     *
     * @param  Request  $request  The incoming AJAX request.
     * @return JsonResponse
     */
    public function send(Request $request): JsonResponse
    {
        if (! $this->validateCsrfToken($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        ['message' => $message, 'conversation_id' => $conversationId, 'error' => $parseError] = self::parseJsonRequest($request);

        if ($parseError !== '') {
            return new JsonResponse(['ok' => false, 'error' => $parseError], 400);
        }

        if ($message === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Message is required.'], 400);
        }

        if ($this->agent === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Agent unavailable. Check the provider and API key in Settings.'], 503);
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
            $response = $turn->response;

            return new JsonResponse([
                'ok' => true,
                'text' => $response->text,
                'provider' => $response->provider,
                'model' => $response->model,
                'iterations' => $response->iterations,
                'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
                'conversation_id' => $turn->conversation->id,
                'tool_calls' => array_map(static fn (ToolCall $c): array => $c->toArray(), $toolCalls),
            ]);
        } catch (ConversationAccessDeniedException $e) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to access this conversation.'], 403);
        } catch (GuardException $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Blocked request.'], 422);
        } catch (ProviderException $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return new JsonResponse(['ok' => false, 'error' => 'AI provider error. Check your API key and try again.'], 502);
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return new JsonResponse(['ok' => false, 'error' => 'Internal error. Check site logs.'], 500);
        }
    }

    /**
     * Load a conversation's messages via AJAX.
     *
     * @param  Request  $request  The incoming AJAX request.
     * @return JsonResponse
     */
    public function load(Request $request): JsonResponse
    {
        if (! $this->validateCsrfToken($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $json = json_decode($request->getContent(), true);
        $conversationId = is_array($json) ? trim((string) ($json['conversation_id'] ?? '')) : '';

        if ($conversationId === '') {
            return new JsonResponse(['ok' => false, 'error' => 'conversation_id is required.'], 400);
        }

        if ($this->agent === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Agent unavailable. Check the provider and API key in Settings.'], 503);
        }

        try {
            $memory = $this->agent->memory();
            $stored = $memory->get($conversationId, 'conversations');

            if (! is_array($stored)) {
                return new JsonResponse(['ok' => true, 'title' => '', 'messages' => []]);
            }

            $messages = [];
            foreach ((array) ($stored['history'] ?? []) as $msg) {
                $role = $msg['role'] ?? '';
                if (! in_array($role, ['user', 'assistant', 'tool'], true)) {
                    continue;
                }
                if ($role === 'tool') {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_name' => (string) ($msg['tool_name'] ?? ''),
                        'tool_input' => is_array($msg['tool_input'] ?? null) ? $msg['tool_input'] : [],
                        'tool_result' => (string) ($msg['content'] ?? ''),
                    ];

                    continue;
                }
                $messages[] = ['role' => $role, 'content' => (string) ($msg['content'] ?? '')];
            }

            return new JsonResponse([
                'ok' => true,
                'title' => (string) ($stored['title'] ?? ''),
                'messages' => $messages,
            ]);
        } catch (ConversationAccessDeniedException $e) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to access this conversation.'], 403);
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return new JsonResponse(['ok' => false, 'error' => 'Internal error. Check site logs.'], 500);
        }
    }

    /**
     * Delete a conversation via AJAX.
     *
     * @param  Request  $request  The incoming AJAX request.
     * @return JsonResponse
     */
    public function delete(Request $request): JsonResponse
    {
        if (! $this->validateCsrfToken($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $json = json_decode($request->getContent(), true);
        $conversationId = is_array($json) ? trim((string) ($json['conversation_id'] ?? '')) : '';

        if ($conversationId === '') {
            return new JsonResponse(['ok' => false, 'error' => 'conversation_id is required.'], 400);
        }

        if ($this->agent === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Agent unavailable. Check the provider and API key in Settings.'], 503);
        }

        try {
            $memory = $this->agent->memory();
            $memory->forget($conversationId, 'conversations');

            return new JsonResponse(['ok' => true]);
        } catch (ConversationAccessDeniedException $e) {
            return new JsonResponse(['ok' => false, 'error' => 'You do not have permission to access this conversation.'], 403);
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return new JsonResponse(['ok' => false, 'error' => 'Internal error. Check site logs.'], 500);
        }
    }

    /**
     * Render a chat message bubble as HTML.
     *
     * @param  string  $role  Message role, 'user' or 'assistant'.
     * @param  string  $content  Message text to escape and render.
     * @return string
     */
    private static function renderBubbleHtml(string $role, string $content): string
    {
        $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        if ($role === 'user') {
            return '<div class="phpclaw-bubble-wrap user"><div class="phpclaw-bubble user">'.$escaped.'</div></div>';
        }

        return '<div class="phpclaw-bubble-wrap assistant"><div class="phpclaw-avatar">🤖</div><div class="phpclaw-bubble assistant">'.$escaped.'</div></div>';
    }

    /**
     * Convert a datetime string to a relative time label.
     *
     * @param  string  $datetime  Stored datetime string to convert, or '' for no label.
     * @return string
     */
    private function relativeTime(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        $ts = (int) strtotime($datetime.' UTC');
        $now = $this->time?->getRequestTime() ?? time();
        $diff = $now - $ts;

        return match (true) {
            $diff < 60 => 'just now',
            $diff < 3600 => (int) ($diff / 60).'m ago',
            $diff < 86400 => (int) ($diff / 3600).'h ago',
            $diff < 604800 => (int) ($diff / 86400).'d ago',
            default => gmdate('M j', $ts),
        };
    }
}
