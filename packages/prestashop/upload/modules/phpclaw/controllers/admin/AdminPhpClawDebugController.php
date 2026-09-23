<?php

declare(strict_types=1);
use PhpClaw\PrestaShop\Admin\DebugPanel;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;
use PhpClaw\PrestaShop\PsIdentityResolver;
use PhpClaw\PrestaShop\PsSseHeadersTrait;

if (! defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/AdminPhpClawBaseController.php';

/**
 * phpClaw Debug Playground admin controller: interactive prompt playground.
 */
final class AdminPhpClawDebugController extends AdminPhpClawBaseController
{
    use PsSseHeadersTrait;

    /**
     * Set the page title after the parent bootstrap.
     */
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->trans('phpClaw: Chat', [], 'Modules.Phpclaw.Admin');
    }

    /**
     * Render the Chat playground template with stored conversation history.
     *
     * @return void
     */
    public function initContent(): void
    {
        parent::initContent();

        $plugin = $this->getPlugin();

        $conversations = [];
        if ($plugin->isConfigured() && ($plugin->saved()['store_messages'] ?? '1') !== '0') {
            try {
                $conversations = (new DebugPanel($plugin->engine()))->listConversations();
            } catch (Throwable) {
                $conversations = [];
            }
        }

        $this->context->smarty->assign([
            'phpclaw_is_configured' => $plugin->isConfigured(),
            'phpclaw_conversations' => $conversations,
            'url_settings' => $this->context->link->getAdminLink('AdminPhpClawSettings'),
            'can_manage_all' => PsIdentityResolver::manageAll(),
            'url_send' => $this->context->link->getAdminLink('AdminPhpClawDebug', true, [], ['ajax' => 1, 'action' => 'Send']),
            'url_stream' => $this->context->link->getAdminLink('AdminPhpClawDebug', true, [], ['ajax' => 1, 'action' => 'Stream']),
            'url_load_conversation' => $this->context->link->getAdminLink('AdminPhpClawDebug', true, [], ['ajax' => 1, 'action' => 'LoadConversation']),
        ]);

        $this->content .= $this->context->smarty->fetch('file:'._PS_MODULE_DIR_.'phpclaw/views/templates/admin/debug.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * POST ajax=1&action=Send: send a message to the agent and return the full response.
     *
     * @return void
     */
    public function ajaxProcessSend(): void
    {
        if (! $this->canDo('edit')) {
            $this->respondJson(['error' => 'Permission denied.']);
        }

        if (! $this->checkToken()) {
            $this->respondJson(['error' => 'Invalid security token.']);
        }

        $message = trim((string) Tools::getValue('message'));
        $conversationId = trim((string) Tools::getValue('conversation_id', ''));

        if ($message === '') {
            $this->respondJson(['error' => 'Message is required.']);
        }

        try {
            $panel = new DebugPanel($this->getPlugin()->engine());
            $result = $panel->send($message, $conversationId);
            $this->respondJson(['success' => true] + $result);
        } catch (Throwable) {
            $this->respondJson(['error' => 'Agent error. Check your provider settings.']);
        }
    }

    /**
     * GET ajax=1&action=LoadConversation: replay a stored conversation by ID.
     *
     * @return void
     */
    public function ajaxProcessLoadConversation(): void
    {
        if (! $this->canDo('view')) {
            $this->respondJson(['error' => 'Permission denied.']);
        }

        if (! $this->checkToken()) {
            $this->respondJson(['error' => 'Invalid security token.']);
        }

        $conversationId = trim((string) Tools::getValue('conversation_id'));

        if ($conversationId === '') {
            $this->respondJson(['error' => 'conversation_id is required.']);
        }

        try {
            $panel = new DebugPanel($this->getPlugin()->engine());
            $result = $panel->loadConversation($conversationId);
            $this->respondJson([
                'success' => true,
                'title' => $result['title'],
                'messages' => $result['messages'],
            ]);
        } catch (ConversationAccessDeniedException) {
            $this->respondJson(['error' => 'You do not have permission to access this conversation.']);
        } catch (Throwable) {
            $this->respondJson(['error' => 'Could not load conversation.']);
        }
    }

    /**
     * POST ajax=1&action=Stream: stream agent tokens as Server-Sent Events.
     *
     * @return void
     */
    public function ajaxProcessStream(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->emitSseError('Method not allowed. Use POST.', 405);
            exit;
        }

        if (! $this->canDo('edit')) {
            $this->emitSseError('Permission denied.', 403);
            exit;
        }

        if (! $this->checkToken()) {
            $this->emitSseError('Invalid security token.', 403);
            exit;
        }

        $message = trim((string) Tools::getValue('message'));
        $conversationId = trim((string) Tools::getValue('conversation_id', ''));

        if ($message === '') {
            $this->emitSseError('Message is required.', 400);
            exit;
        }

        try {
            $plugin = $this->getPlugin();

            if ($conversationId !== '') {
                $plugin->engine()->conversation($conversationId);
            }
        } catch (ConversationAccessDeniedException) {
            $this->emitSseError('You do not have permission to access this conversation.', 403);
            exit;
        } catch (Throwable) {
            $this->emitSseError('Agent error. Check your provider settings.', 500);
            exit;
        }

        $this->sendSseHeaders();

        $emit = static function (string $event, array $data): void {
            echo 'event: '.$event."\n";
            echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
            flush();
        };

        try {
            $panel = new DebugPanel($plugin->engine());
            $panel->stream($message, $conversationId, $emit);
        } catch (ConversationAccessDeniedException) {
            $emit('error', ['message' => 'You do not have permission to access this conversation.']);
        } catch (Throwable) {
            $this->emitSseError('Agent error. Check your provider settings.');
        }

        exit;
    }

    /**
     * Emit a single SSE error frame.
     *
     * @param  string  $message
     * @param  int  $httpCode  Status to send when headers have not been flushed yet.
     * @return void
     */
    private function emitSseError(string $message, int $httpCode = 0): void
    {
        if (! headers_sent()) {
            if ($httpCode > 0) {
                http_response_code($httpCode);
            }

            $this->sendSseHeaders();
        }

        echo 'event: error'."\n";
        echo 'data: '.json_encode(['message' => $message], JSON_UNESCAPED_UNICODE)."\n\n";
        flush();
    }
}
