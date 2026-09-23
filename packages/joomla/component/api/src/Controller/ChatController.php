<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Api\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Joomla\Component\Administrator\Admin\DebugPanel;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;

/**
 * Web Services API controller for the phpClaw chat surface.
 */
final class ChatController extends BaseController
{
    private const COMPONENT = 'com_phpclaw';

    private const CHAT_CAPABILITY = 'phpclaw.chat.use';

    private const MESSAGE_MAX_LENGTH = 40000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

    private const SSE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private const STATUS_BAD_REQUEST = 400;

    private const STATUS_FORBIDDEN = 403;

    private const STATUS_SERVER_ERROR = 500;

    /**
     * Run one agent turn and answer with the complete result as JSON.
     *
     * @return void
     */
    public function send(): void
    {
        $this->loadComponentLanguage();

        if (! $this->callerMayChat()) {
            $this->emitJson(false, ['message' => Text::_('JERROR_ALERTNOAUTHOR')], self::STATUS_FORBIDDEN);

            return;
        }

        $message = $this->requestString('message');
        $invalid = $this->messageRejection($message);

        if ($invalid !== '') {
            $this->emitJson(false, ['message' => $invalid], self::STATUS_BAD_REQUEST);

            return;
        }

        try {
            $result = $this->buildDebugPanel()->send($message, $this->requestString('conversation_id'));

            $this->emitJson(true, $result);
        } catch (ConversationAccessDeniedException) {
            $this->emitJson(false, ['message' => Text::_('JERROR_ALERTNOAUTHOR')], self::STATUS_FORBIDDEN);
        } catch (GuardException $e) {
            error_log('phpClaw guard blocked: '.$e->getMessage());
            $this->emitJson(false, ['message' => Text::_('COM_PHPCLAW_ERROR_GUARD_BLOCKED')], self::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            error_log('phpClaw chat error: '.$e->getMessage());
            $this->emitJson(false, ['message' => Text::_('COM_PHPCLAW_ERROR_INTERNAL')], self::STATUS_SERVER_ERROR);
        }
    }

    /**
     * Run one agent turn and answer with Server-Sent Events.
     *
     * @return void
     */
    public function stream(): void
    {
        $this->loadComponentLanguage();

        if (! $this->callerMayChat()) {
            $this->emitSseError(Text::_('JERROR_ALERTNOAUTHOR'), self::STATUS_FORBIDDEN);

            return;
        }

        $message = $this->requestString('message');
        $invalid = $this->messageRejection($message);

        if ($invalid !== '') {
            $this->emitSseError($invalid, self::STATUS_BAD_REQUEST);

            return;
        }

        $conversationId = $this->requestString('conversation_id');

        $this->openStream();

        $emit = static function (string $event, array $data): void {
            echo 'event: '.$event."\n";
            echo 'data: '.json_encode($data, self::SSE_FLAGS)."\n\n";
            @flush();
        };

        try {
            $this->buildDebugPanel()->stream($message, $conversationId, $emit);
        } catch (ConversationAccessDeniedException) {
            $emit('error', ['message' => Text::_('JERROR_ALERTNOAUTHOR')]);
        } catch (GuardException $e) {
            error_log('phpClaw stream guard: '.$e->getMessage());
            $emit('error', ['message' => Text::_('COM_PHPCLAW_ERROR_GUARD_BLOCKED')]);
        } catch (\Throwable $e) {
            error_log('phpClaw stream error: '.$e->getMessage());
            $emit('error', ['message' => Text::_('COM_PHPCLAW_ERROR_INTERNAL')]);
        }

        Factory::getApplication()->close();
    }

    /**
     * Whether the token identity holds the chat capability on the component asset.
     *
     * @return bool True when the caller may run the agent.
     */
    private function callerMayChat(): bool
    {
        $identity = Factory::getApplication()->getIdentity();

        return $identity !== null && $identity->authorise(self::CHAT_CAPABILITY, self::COMPONENT);
    }

    /**
     * Translated reason the message cannot be accepted, or an empty string when it is usable.
     *
     * @param  string  $message  Submitted prompt.
     * @return string Rejection text, empty when the message passes.
     */
    private function messageRejection(string $message): string
    {
        if ($message === '') {
            return Text::_('COM_PHPCLAW_ERROR_EMPTY_MESSAGE');
        }

        if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
            return Text::_('COM_PHPCLAW_ERROR_MESSAGE_TOO_LONG');
        }

        return '';
    }

    /**
     * Read one request value, preferring a JSON body and falling back to a form body.
     *
     * @param  string  $key  Field name to read.
     * @return string Trimmed value, empty when absent.
     */
    private function requestString(string $key): string
    {
        $input = Factory::getApplication()->getInput();
        $value = trim((string) $input->json->getString($key, ''));

        return $value !== '' ? $value : trim((string) $input->post->getString($key, ''));
    }

    /**
     * Load the component's language files, which the api application does not load for us.
     *
     * @return void
     */
    private function loadComponentLanguage(): void
    {
        $language = Factory::getApplication()->getLanguage();

        $language->load(self::COMPONENT, JPATH_ADMINISTRATOR)
            || $language->load(self::COMPONENT, JPATH_ADMINISTRATOR.'/components/'.self::COMPONENT);
    }

    /**
     * Build a fresh DebugPanel bound to the current plugin params.
     *
     * @return DebugPanel Panel wrapping a configured engine.
     */
    private function buildDebugPanel(): DebugPanel
    {
        return new DebugPanel((new EngineFactory)->build(EngineFactory::getPluginParams()));
    }

    /**
     * Take the response over from the api application: drop the buffer opened around the
     * component dispatch, send the event-stream headers, and disable time limits.
     *
     * @return void
     */
    private function openStream(): void
    {
        @ini_set('display_errors', '0');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->sendStreamHeaders();

        @set_time_limit(0);
        @ignore_user_abort(false);
        ob_implicit_flush(true);
    }

    /**
     * Emit the response headers an SSE client needs.
     *
     * @return void
     */
    private function sendStreamHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }

    /**
     * Emit a single `error` event and end the request.
     *
     * @param  string  $message  Error text exposed to the client.
     * @param  int  $status  Status code to send when headers are still open.
     * @return void
     */
    private function emitSseError(string $message, int $status): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (! headers_sent()) {
            http_response_code($status);
        }

        $this->sendStreamHeaders();

        echo "event: error\n";
        echo 'data: '.json_encode(['message' => $message], self::SSE_FLAGS)."\n\n";
        @flush();

        Factory::getApplication()->close();
    }

    /**
     * Write the JSON response body and end the request.
     *
     * @param  bool  $success  Whether the turn succeeded.
     * @param  array<string, mixed>  $data  Response payload.
     * @param  ?int  $status  Status code, null to leave the default 200.
     * @return void
     */
    private function emitJson(bool $success, array $data, ?int $status = null): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $app = Factory::getApplication();

        if ($status !== null) {
            $app->setHeader('Status', (string) $status);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8');
        $app->sendHeaders();

        echo json_encode(['success' => $success, 'data' => $data], self::JSON_FLAGS);

        $app->close();
    }
}
