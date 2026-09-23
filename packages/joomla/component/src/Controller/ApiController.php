<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\DatabaseQuery;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Joomla\Component\Administrator\Admin\DebugPanel;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;

/**
 * AJAX/REST controller for the phpClaw admin component.
 */
final class ApiController extends BaseController
{
    private const EXTENSIONS_TABLE = '#__extensions';

    private const PLUGIN_ELEMENT = 'phpclaw';

    private const PLUGIN_FOLDER = 'system';

    private const PLUGIN_TYPE = 'plugin';

    private const MESSAGE_MAX_LENGTH = 40000;

    private const MASKED_SECRET_REGEX = '/^\*+[A-Za-z0-9]{0,4}$/';

    private const SECRET_FIELDS = ['api_key', 'cloud_key', 'cloud_signing_secret'];

    private const PLAIN_FIELDS = [
        'provider', 'model', 'base_url', 'store_messages',
        'system_prompt', 'max_iterations', 'remote_skill_urls', 'cloud_disable',
    ];

    private const CLOUD_FEATURES = ['scan', 'observability', 'hide_inputs', 'hide_outputs', 'hide_metadata'];

    private const MIN_ITERATIONS = 1;

    private const MAX_ITERATIONS = 50;

    private const MAX_SYSTEM_PROMPT = 8000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

    private const STATUS_BAD_REQUEST = 400;

    private const STATUS_FORBIDDEN = 403;

    private const STATUS_SERVER_ERROR = 500;

    private const CONNECTION_TEST_PROMPT = 'Reply with exactly: OK';

    /**
     * Send a chat message via the AI engine.
     *
     * @return void
     */
    public function send(): void
    {
        $this->silenceErrorOutput();

        $this->runAction(function (): void {
            $message = $this->postString('message');

            if ($message === '') {
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_EMPTY_MESSAGE'), self::STATUS_BAD_REQUEST);

                return;
            }

            if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_MESSAGE_TOO_LONG'), self::STATUS_BAD_REQUEST);

                return;
            }

            $conversationId = $this->postString('conversation_id');

            try {
                $result = $this->buildDebugPanel()->send($message, $conversationId);

                $this->sendJsonSuccess($result);
            } catch (ConversationAccessDeniedException) {
                $this->sendJsonError(Text::_('JERROR_ALERTNOAUTHOR'), self::STATUS_FORBIDDEN);
            } catch (GuardException $e) {
                error_log('phpClaw guard blocked: '.$e->getMessage());
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_GUARD_BLOCKED'), self::STATUS_BAD_REQUEST);
            } catch (\Throwable $e) {
                error_log('phpClaw chat error: '.$e->getMessage());
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_INTERNAL'), self::STATUS_SERVER_ERROR);
            }
        });
    }

    /**
     * Stream a chat message as Server-Sent Events, after verifying the CSRF token and
     * requiring phpclaw.chat.use.
     *
     * @return void
     */
    public function stream(): void
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (! Session::checkToken('post')) {
            $this->emitSseError(Text::_('JINVALID_TOKEN'), self::STATUS_FORBIDDEN);

            return;
        }

        if (! $this->canUseChat()) {
            $this->emitSseError(Text::_('JERROR_ALERTNOAUTHOR'), self::STATUS_FORBIDDEN);

            return;
        }

        $message = $this->postString('message');
        $conversationId = $this->postString('conversation_id');

        if ($message === '') {
            $this->emitSseError(Text::_('COM_PHPCLAW_ERROR_EMPTY_MESSAGE'), self::STATUS_BAD_REQUEST);

            return;
        }

        if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
            $this->emitSseError(Text::_('COM_PHPCLAW_ERROR_MESSAGE_TOO_LONG'), self::STATUS_BAD_REQUEST);

            return;
        }

        $this->sendSseHeaders();
        @set_time_limit(0);
        @ignore_user_abort(false);
        ob_implicit_flush(true);

        $emit = static function (string $event, array $data): void {
            echo 'event: '.$event."\n";
            echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
            @flush();
        };

        try {
            $this->buildDebugPanel()->stream($message, $conversationId, $emit);
        } catch (ConversationAccessDeniedException) {
            if (! headers_sent()) {
                http_response_code(self::STATUS_FORBIDDEN);
            }
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
     * Load a conversation's messages.
     *
     * @return void
     */
    public function loadConversation(): void
    {
        $this->runAction(function (): void {
            $id = $this->postString('conversation_id');

            if ($id === '') {
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_NO_CONVERSATION_ID'), self::STATUS_BAD_REQUEST);

                return;
            }

            $this->sendJsonSuccess($this->buildDebugPanel()->loadConversation($id));
        });
    }

    /**
     * Update plugin settings. Accepts every field the plugin form declares; a field absent from
     * the request keeps its stored value, and a secret sent empty or masked keeps its stored value.
     *
     * @return void
     */
    public function saveSettings(): void
    {
        $this->runAdminAction(function (): void {
            $params = $this->loadPluginParams();
            $written = [];
            $skippedSecrets = [];

            foreach (self::SECRET_FIELDS as $field) {
                if (! $this->postHas($field)) {
                    continue;
                }

                $value = self::normaliseSecret($this->postString($field));

                if ($value === '' || self::isMaskedSecret($value)) {
                    $skippedSecrets[] = $field;

                    continue;
                }

                $params[$field] = $value;
                $written[] = $field;
            }

            foreach (self::PLAIN_FIELDS as $field) {
                if (! $this->postHas($field)) {
                    continue;
                }

                $params[$field] = $this->validatedPlainValue($field, $this->postString($field));
                $written[] = $field;
            }

            $this->savePluginParams($params);

            $this->sendJsonSuccess([
                'saved' => true,
                'written' => $written,
                'skipped_secrets' => $skippedSecrets,
            ]);
        });
    }

    /**
     * Report whether the request carries the given POST key, so an absent field can be told apart
     * from one deliberately submitted empty.
     *
     * @param  string  $key  POST key to look for.
     * @return bool True when the key is present in the request body.
     */
    private function postHas(string $key): bool
    {
        return array_key_exists($key, (array) Factory::getApplication()->getInput()->post->getArray());
    }

    /**
     * Validate and normalise one non-secret setting, ending the request with a 400 when the value
     * is not one the plugin form would accept.
     *
     * @param  string  $field  Plugin param name.
     * @param  string  $value  Submitted value, already trimmed.
     * @return string Value to persist.
     */
    private function validatedPlainValue(string $field, string $value): string
    {
        if ($field === 'store_messages' && ! in_array($value, ['0', '1'], true)) {
            $this->sendJsonError('store_messages accepts only 0 or 1.');
        }

        if ($field === 'max_iterations') {
            return (string) max(self::MIN_ITERATIONS, min(self::MAX_ITERATIONS, (int) $value));
        }

        if ($field === 'base_url' && $value !== '' && ! PhpClawConfig::isAllowedProviderUrl($value)) {
            $this->sendJsonError('base_url must be https, or http on a loopback host.');
        }

        if ($field === 'system_prompt') {
            return mb_substr($value, 0, self::MAX_SYSTEM_PROMPT);
        }

        if ($field === 'remote_skill_urls') {
            return implode("\n", PhpClawConfig::filterRemoteSkillUrls($value));
        }

        if ($field === 'cloud_disable') {
            return $this->validatedCloudDisable($value);
        }

        return $value;
    }

    /**
     * Validate the cloud_disable list of features and hide_ names, ending the request with a 400 on an unknown name.
     *
     * @param  string  $value  Comma-separated feature and hide_ names.
     * @return string Normalised comma-separated list.
     */
    private function validatedCloudDisable(string $value): string
    {
        $features = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $f): bool => $f !== ''));

        foreach ($features as $feature) {
            if (! in_array($feature, self::CLOUD_FEATURES, true)) {
                $this->sendJsonError('cloud_disable accepts only '.implode(', ', self::CLOUD_FEATURES).'.');
            }
        }

        return implode(',', $features);
    }

    /**
     * Enable the phpClaw system plugin.
     *
     * @return void
     */
    public function enablePlugin(): void
    {
        $this->runAdminAction(function (): void {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $update = $this->scopeToPluginRow(
                $db->getQuery(true)
                    ->update($db->quoteName(self::EXTENSIONS_TABLE))
                    ->set($db->quoteName('enabled').' = 1'),
            );

            $db->setQuery($update)->execute();

            $this->sendJsonSuccess(['enabled' => true]);
        });
    }

    /**
     * Fire a small probe send() against the configured provider.
     *
     * @return void
     */
    public function testConnection(): void
    {
        $this->runAdminAction(function (): void {
            $params = EngineFactory::getPluginParams();
            $provider = (string) $params->get('provider', '');
            $apiKey = (string) $params->get('api_key', '');

            if ($provider === '') {
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_NO_PROVIDER'), self::STATUS_BAD_REQUEST);

                return;
            }

            if ($provider !== 'ollama' && $apiKey === '') {
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_NO_API_KEY'), self::STATUS_BAD_REQUEST);

                return;
            }

            try {
                $response = (new EngineFactory)->build($params)->send(self::CONNECTION_TEST_PROMPT);

                $this->sendJsonSuccess([
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'text' => $response->text,
                ]);
            } catch (\Throwable) {
                $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_CONNECTION_FAILED'), self::STATUS_SERVER_ERROR);
            }
        });
    }

    /**
     * Emit the SSE response headers required by the browser EventSource API.
     *
     * @return void
     */
    private function sendSseHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }

    /**
     * Emit an `event: error` SSE frame and terminate the stream.
     *
     * @param  string  $message  Error text exposed to the client.
     * @param  int  $httpCode  Status to send when headers have not been flushed yet.
     * @return void
     */
    private function emitSseError(string $message, int $httpCode = 0): void
    {
        if ($httpCode > 0 && ! headers_sent()) {
            http_response_code($httpCode);
        }

        $this->sendSseHeaders();
        echo "event: error\n";
        echo 'data: '.json_encode(['message' => $message], JSON_UNESCAPED_UNICODE)."\n\n";
        @flush();
        Factory::getApplication()->close();
    }

    /**
     * Wrap an endpoint body: verify the Joomla CSRF token, require phpclaw.chat.use, then
     * run $work.
     *
     * @param  callable  $work
     * @return void
     */
    private function runAction(callable $work): void
    {
        if (! Session::checkToken('post')) {
            $this->sendJsonError(Text::_('JINVALID_TOKEN'), self::STATUS_FORBIDDEN);

            return;
        }

        if (! $this->canUseChat()) {
            $this->sendJsonError(Text::_('JERROR_ALERTNOAUTHOR'), self::STATUS_FORBIDDEN);

            return;
        }

        try {
            $work();
        } catch (ConversationAccessDeniedException) {
            $this->sendJsonError(Text::_('JERROR_ALERTNOAUTHOR'), self::STATUS_FORBIDDEN);
        } catch (\Throwable) {
            $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_INTERNAL'), self::STATUS_SERVER_ERROR);
        }
    }

    /**
     * Whether the acting identity may use the agent at all.
     *
     * @return bool
     */
    private function canUseChat(): bool
    {
        $identity = Factory::getApplication()->getIdentity();

        return $identity !== null && $identity->authorise('phpclaw.chat.use', 'com_phpclaw');
    }

    /**
     * Wrap a privileged endpoint body: verify the CSRF token, require Super-User (core.admin), then run $work.
     *
     * @param  callable  $work
     * @return void
     */
    private function runAdminAction(callable $work): void
    {
        if (! Session::checkToken('post')) {
            $this->sendJsonError(Text::_('JINVALID_TOKEN'), self::STATUS_FORBIDDEN);

            return;
        }

        $identity = Factory::getApplication()->getIdentity();

        if ($identity === null || ! $identity->authorise('core.admin')) {
            $this->sendJsonError(Text::_('JERROR_ALERTNOAUTHOR'), self::STATUS_FORBIDDEN);

            return;
        }

        try {
            $work();
        } catch (\Throwable) {
            $this->sendJsonError(Text::_('COM_PHPCLAW_ERROR_INTERNAL'), self::STATUS_SERVER_ERROR);
        }
    }

    /**
     * Build a fresh DebugPanel bound to the current plugin params.
     *
     * @return DebugPanel
     */
    private function buildDebugPanel(): DebugPanel
    {
        return new DebugPanel((new EngineFactory)->build(EngineFactory::getPluginParams()));
    }

    /**
     * Disable PHP error output and flush all output buffers for clean SSE frames.
     *
     * @return void
     */
    private function silenceErrorOutput(): void
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
    }

    /**
     * Read a single POST string by key, trimmed.
     *
     * @param  string  $key
     * @return string
     */
    private function postString(string $key): string
    {
        return trim((string) Factory::getApplication()->getInput()->post->getString($key, ''));
    }

    /**
     * Strip an environment-variable name accidentally pasted in front of a credential, for
     * example `PHPCLAW_CLOUD_KEY=sk_live_...`. Only a leading uppercase NAME= prefix is removed.
     *
     * @param  string  $value  Raw submitted credential.
     * @return string Credential with any leading env-var-name prefix removed.
     */
    private static function normaliseSecret(string $value): string
    {
        return trim((string) preg_replace('/^[A-Z][A-Z0-9_]*=/', '', trim($value)));
    }

    /**
     * Load the plugin row's `params` JSON and decode to an array.
     *
     * @return array<string, mixed>
     */
    private function loadPluginParams(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $this->scopeToPluginRow(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName(self::EXTENSIONS_TABLE)),
        );

        $decoded = json_decode((string) $db->setQuery($query)->loadResult(), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the given params array back to the plugin row, flattening a cloud_disable
     * array to a comma-separated string first.
     *
     * @param  array<string, mixed>  $params
     * @return void
     */
    private function savePluginParams(array $params): void
    {
        if (isset($params['cloud_disable']) && is_array($params['cloud_disable'])) {
            $params['cloud_disable'] = implode(',', $params['cloud_disable']);
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $update = $this->scopeToPluginRow(
            $db->getQuery(true)
                ->update($db->quoteName(self::EXTENSIONS_TABLE))
                ->set($db->quoteName('params').' = '.$db->quote(json_encode($params, JSON_THROW_ON_ERROR))),
        );

        $db->setQuery($update)->execute();
    }

    /**
     * Add the plugin-row triplet (element/folder/type) to a `#__extensions` query.
     *
     * @param  DatabaseQuery  $query
     * @return DatabaseQuery
     */
    private function scopeToPluginRow(DatabaseQuery $query): DatabaseQuery
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return $query
            ->where($db->quoteName('element').' = '.$db->quote(self::PLUGIN_ELEMENT))
            ->where($db->quoteName('folder').' = '.$db->quote(self::PLUGIN_FOLDER))
            ->where($db->quoteName('type').' = '.$db->quote(self::PLUGIN_TYPE));
    }

    /**
     * True when the given string is the export-rendered masked form (e.g. `***1234`).
     *
     * @param  string  $value
     * @return bool
     */
    private static function isMaskedSecret(string $value): bool
    {
        return preg_match(self::MASKED_SECRET_REGEX, $value) === 1;
    }

    /**
     * Send a JSON success response and close the application.
     *
     * @param  array<string, mixed>  $data
     * @return void
     */
    private function sendJsonSuccess(array $data): void
    {
        $this->emitJson(['success' => true, 'data' => $data]);
    }

    /**
     * Send a JSON error response and close the application.
     *
     * @param  string  $message
     * @param  int  $code  HTTP status code.
     * @return void
     */
    private function sendJsonError(string $message, int $code = self::STATUS_BAD_REQUEST): void
    {
        $this->emitJson(
            ['success' => false, 'data' => ['message' => $message]],
            $code,
        );
    }

    /**
     * Shared response writer - sets headers, prints the JSON body, closes the app.
     *
     * @param  array<string, mixed>  $payload
     * @param  ?int  $statusCode
     * @return void
     */
    private function emitJson(array $payload, ?int $statusCode = null): void
    {
        @ob_end_clean();

        try {
            $app = Factory::getApplication();
        } catch (\Throwable) {
            echo json_encode($payload, self::JSON_FLAGS);

            return;
        }

        if ($statusCode !== null) {
            $app->setHeader('Status', (string) $statusCode);
        }
        $app->setHeader('Content-Type', 'application/json; charset=utf-8');
        $app->sendHeaders();

        echo json_encode($payload, self::JSON_FLAGS);

        $app->close();
    }
}
