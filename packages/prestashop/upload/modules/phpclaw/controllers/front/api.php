<?php

declare(strict_types=1);

use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\PrestaShop\Admin\DebugPanel;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\PsPluginAccessor;
use PhpClaw\PrestaShop\PsSseHeadersTrait;
use PhpClaw\PrestaShop\Rest\ApcuCounterStore;
use PhpClaw\PrestaShop\Rest\ApiHandler;
use PhpClaw\PrestaShop\Rest\PsApiAuthenticator;
use PhpClaw\PrestaShop\Rest\PsRateLimiter;

if (! defined('_PS_VERSION_')) {
    exit;
}

/**
 * phpClaw REST API: ModuleFrontController handling send, stream, conversations, and settings endpoints.
 */
final class PhpclawApiModuleFrontController extends ModuleFrontController
{
    use PsPluginAccessor;
    use PsSseHeadersTrait;

    public $ajax = true;

    /**
     * Bind the module instance and load the package autoloader if not already loaded.
     */
    public function __construct()
    {
        $this->module = Module::getInstanceByName('phpclaw');
        parent::__construct();

        $autoload = _PS_MODULE_DIR_.'phpclaw/vendor/autoload.php';
        if (file_exists($autoload) && ! class_exists(Plugin::class, false)) {
            require_once $autoload;
        }
    }

    /**
     * Initialise the controller and force a JSON response content type.
     *
     * @return void
     */
    public function init(): void
    {
        parent::init();
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * Authenticate the request, then route it to the matching API action.
     *
     * @return void
     */
    public function display(): void
    {
        $limiter = new PsRateLimiter(new ApcuCounterStore);
        if ($limiter->tooManyRequests((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $this->jsonError('phpclaw_rate_limited', 'Too many requests. Please slow down.', 429);
        }

        if (! $this->isAuthenticated()) {
            $this->jsonError('phpclaw_forbidden', 'Unauthorized. Provide a valid Bearer token or log in to the Back Office.', 403);
        }

        $action = strtolower(trim((string) Tools::getValue('action', 'send')));

        match ($action) {
            'send' => $this->handleSend(),
            'chat/stream' => $this->handleStream(),
            default => $this->jsonError('phpclaw_not_found', "Unknown action '{$action}'.", 404),
        };
    }

    /**
     * REST action `send`: run one agent turn and return the JSON response.
     *
     * @return never
     */
    private function handleSend(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('phpclaw_forbidden', 'Method not allowed. Use POST.', 405);
        }

        $message = '';
        $conversationId = '';
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $body = (array) json_decode((string) file_get_contents('php://input'), true);
            $message = trim((string) ($body['message'] ?? ''));
            $conversationId = trim((string) ($body['conversation_id'] ?? ''));
        } else {
            $message = trim((string) Tools::getValue('message', ''));
            $conversationId = trim((string) Tools::getValue('conversation_id', ''));
        }

        if ($message === '') {
            $this->jsonError('phpclaw_empty_message', 'message is required.', 400);
        }

        if (strlen($message) > ApiHandler::MAX_MESSAGE_LENGTH) {
            $this->jsonError('phpclaw_long_message', 'message exceeds maximum length of '.ApiHandler::MAX_MESSAGE_LENGTH.' characters.', 400);
        }

        try {
            $plugin = $this->getPlugin();

            if (! $plugin->isConfigured()) {
                $this->jsonError('phpclaw_not_configured', 'phpClaw is not configured. Set your AI provider and API key in Settings.', 503);
            }

            $handler = new ApiHandler($plugin->engine());
            $result = $handler->handle($message, $conversationId);

            echo json_encode(['success' => true] + $result);
        } catch (ConversationAccessDeniedException $e) {
            $this->jsonError('phpclaw_forbidden', 'You do not have permission to access this conversation.', 403);
        } catch (GuardException $e) {
            $this->jsonError('phpclaw_guard', 'Prompt injection detected. Request blocked.', 422);
        } catch (AdapterException $e) {
            $this->jsonError('phpclaw_not_configured', 'Agent error. Check your provider settings.', 503);
        } catch (Throwable $e) {
            $this->jsonError('phpclaw_error', 'Agent error. Check your provider settings.', 500);
        }

        exit;
    }

    /**
     * REST action `chat/stream`: run one agent turn as an SSE token stream. The conversation is
     * resolved before SSE headers commit, after which a denial is only an error frame inside a 200.
     *
     * @return never
     */
    private function handleStream(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('phpclaw_forbidden', 'Method not allowed. Use POST.', 405);
        }

        $message = '';
        $conversationId = '';
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $body = (array) json_decode((string) file_get_contents('php://input'), true);
            $message = trim((string) ($body['message'] ?? ''));
            $conversationId = trim((string) ($body['conversation_id'] ?? ''));
        } else {
            $message = trim((string) Tools::getValue('message', ''));
            $conversationId = trim((string) Tools::getValue('conversation_id', ''));
        }

        if ($message === '') {
            $this->jsonError('phpclaw_empty_message', 'message is required.', 400);
        }

        if (strlen($message) > ApiHandler::MAX_MESSAGE_LENGTH) {
            $this->jsonError('phpclaw_long_message', 'message exceeds maximum length of '.ApiHandler::MAX_MESSAGE_LENGTH.' characters.', 400);
        }

        try {
            $plugin = $this->getPlugin();

            if (! $plugin->isConfigured()) {
                $this->jsonError('phpclaw_not_configured', 'phpClaw is not configured. Set your AI provider and API key in Settings.', 503);
            }

            if ($conversationId !== '') {
                try {
                    $plugin->engine()->conversation($conversationId);
                } catch (ConversationAccessDeniedException) {
                    $this->jsonError('phpclaw_forbidden', 'You do not have permission to access this conversation.', 403);
                }
            }

            $this->sendSseHeaders();

            $emit = static function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $panel = new DebugPanel($plugin->engine());
            $panel->stream($message, $conversationId, $emit);
        } catch (ConversationAccessDeniedException $e) {
            $emit = $emit ?? static function (string $ev, array $d): void {};
            $emit('error', ['message' => 'You do not have permission to access this conversation.']);
        } catch (GuardException $e) {
            $emit = $emit ?? static function (string $ev, array $d): void {};
            $emit('error', ['message' => 'Prompt injection detected. Request blocked.']);
        } catch (Throwable $e) {
            $emit = $emit ?? static function (string $ev, array $d): void {};
            $emit('error', ['message' => 'Agent error. Check your provider settings.']);
        }

        exit;
    }

    /**
     * Return true if the request carries valid credentials.
     *
     * @return bool
     */
    private function isAuthenticated(): bool
    {
        return (new PsApiAuthenticator)->isAuthenticated();
    }

    /**
     * Emit a JSON error response and exit.
     *
     * @param  string  $code  Machine-readable error code.
     * @param  string  $message  Human-readable message.
     * @param  int  $status  HTTP status code.
     * @return never
     */
    private function jsonError(string $code, string $message, int $status = 400): never
    {
        http_response_code($status);
        echo json_encode(['success' => false, 'code' => $code, 'error' => $message]);
        exit;
    }
}
