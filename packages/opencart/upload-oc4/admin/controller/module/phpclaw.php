<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Phpclaw\Module;

use Opencart\System\Engine\Controller;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\OpenCart\Admin\AboutPage;
use PhpClaw\OpenCart\Admin\AdminNotice;
use PhpClaw\OpenCart\Admin\AdminResponder;
use PhpClaw\OpenCart\Admin\AutoUpdater;
use PhpClaw\OpenCart\Admin\DebugPanel;
use PhpClaw\OpenCart\Admin\SettingsPage;
use PhpClaw\OpenCart\Db\OcDbAdapter;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;
use PhpClaw\OpenCart\Plugin;

/**
 * phpClaw AI Agent admin controller for OpenCart 4.
 */
final class Phpclaw extends Controller
{
    private const MODULE_ROUTE = 'extension/phpclaw/module/phpclaw';

    private array $ocError = [];

    /**
     * Resolve whether the acting employee holds the phpClaw module grant. The route literal is
     * version-specific, so it lives here and is never carried by a tool.
     *
     * @return bool
     */
    private function mayUseModule(): bool
    {
        return (bool) $this->user->hasPermission('access', self::MODULE_ROUTE);
    }

    /**
     * Main settings page, for users holding access permission on the module.
     *
     * @return void
     */
    public function index(): void
    {
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all')) {
            if ($this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
                $this->response->redirect(
                    $this->url->link('extension/phpclaw/module/phpclaw.debug', 'user_token='.$this->session->data['user_token'], true),
                );

                return;
            }

            $this->response->setOutput('You do not have permission to access phpClaw.');

            return;
        }

        $this->load->language('extension/phpclaw/module/phpclaw');
        $this->document->addStyle('../extension/phpclaw/admin/view/stylesheet/phpclaw-admin.css');
        $this->document->setTitle($this->language->get('heading_title'));

        $plugin = $this->bootPlugin();

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->handleSettingsSave($plugin)) {
            return;
        }

        $saved = $plugin->saved();
        $config = $plugin->config();
        $current = SettingsPage::merge($saved);
        $notice = null;

        if (AdminNotice::shouldShow($saved)) {
            $notice = AdminNotice::data(
                $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
            );
        }

        $tools = [];
        try {
            $engine = $plugin->engine($this->mayUseModule());
            if (method_exists($engine, 'tools')) {
                foreach ($engine->tools() as $tool) {
                    $tools[] = ['name' => $tool->name(), 'description' => $tool->description()];
                }
            }
        } catch (\Throwable $e) {
            error_log('phpClaw guide tool list failed: '.$e->getMessage());
        }

        $update = null;
        try {
            $updater = new AutoUpdater(
                currentVersion: PHPCLAW_VERSION,
                updateServerUrl: (string) ($config['update_server'] ?? ''),
            );
            $update = $updater->checkForUpdate();
        } catch (\Throwable) {
        }

        $langData = $this->load->language('extension/phpclaw/module/phpclaw');

        $data = $this->buildLayoutData();
        $data += [
            'heading_title' => $this->language->get('heading_title'),
            'action' => $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
            'cancel' => $this->url->link('marketplace/extension', 'user_token='.$this->session->data['user_token'].'&type=module', true),
            'url_debug' => $this->url->link('extension/phpclaw/module/phpclaw.debug', 'user_token='.$this->session->data['user_token'], true),
            'url_about' => $this->url->link('extension/phpclaw/module/phpclaw.about', 'user_token='.$this->session->data['user_token'], true),
            'url_analytics' => $this->url->link('extension/phpclaw/module/phpclaw.analytics', 'user_token='.$this->session->data['user_token'], true),
            'url_guide' => $this->url->link('extension/phpclaw/module/phpclaw.guide', 'user_token='.$this->session->data['user_token'], true),
            'url_send' => $this->url->link('extension/phpclaw/module/phpclaw.send', 'user_token='.$this->session->data['user_token'], true),
            'url_test_connection' => $this->url->link('extension/phpclaw/module/phpclaw.test_connection', 'user_token='.$this->session->data['user_token'], true),
            'providers' => SettingsPage::providers(),
            'settings' => $current,
            'tools' => $tools,
            'notice' => $notice,
            'update' => $update,
            'cloud_installed' => class_exists(CloudManager::class),
            'errors' => $this->ocError,
            'success' => $this->session->data['success'] ?? '',
        ];

        $data += $langData;

        unset($this->session->data['success']);

        $this->response->setOutput($this->load->view('extension/phpclaw/module/phpclaw', $data));
    }

    /**
     * Chat (debug) page, for users holding access permission on the module.
     *
     * @return void
     */
    public function debug(): void
    {
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
            $this->response->setOutput('You do not have permission to access phpClaw.');

            return;
        }

        $this->load->language('extension/phpclaw/module/phpclaw');
        $this->document->addStyle('../extension/phpclaw/admin/view/stylesheet/phpclaw-admin.css');
        $this->document->setTitle($this->language->get('heading_title').' - Chat');

        $plugin = $this->bootPlugin();
        $actingUserId = (int) $this->user->getId();
        $manageAll = $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all');

        $engineError = null;
        $convs = [];
        $initConvId = '';
        $initTitle = '';
        $initMessages = [];

        try {
            $plugin->engine($this->mayUseModule());
            $memory = $plugin->scopedConversationMemory($actingUserId, $manageAll);
            $all = $memory->all('conversations');

            foreach ($all as $cid => $conv) {
                $ts = strtotime(($conv['updated_at'] ?? $conv['created_at'] ?? '').' UTC');
                $diff = time() - (int) $ts;
                $when = match (true) {
                    $diff < 60 => 'just now',
                    $diff < 3600 => (int) ($diff / 60).'m ago',
                    $diff < 86400 => (int) ($diff / 3600).'h ago',
                    $diff < 604800 => (int) ($diff / 86400).'d ago',
                    default => gmdate('M j', (int) $ts),
                };
                $convs[(string) $cid] = ['title' => (string) ($conv['title'] ?? ''), 'when' => $when];
            }

            if ($convs !== []) {
                $initConvId = (string) array_key_first($convs);
                $initTitle = $convs[$initConvId]['title'];
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
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $e) {
            error_log('phpClaw engine init error: '.$e->getMessage());
            $engineError = true;
        }

        $saved = $plugin->saved();
        $provider = $saved['provider'] ?? '';
        $model = $saved['model'] ?? '';

        $data = $this->buildLayoutData();
        $data += [
            'heading_title' => $this->language->get('heading_title').' - Chat',
            'url_settings' => $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
            'can_manage_all' => $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all'),
            'url_send' => $this->url->link('extension/phpclaw/module/phpclaw.send', 'user_token='.$this->session->data['user_token'], true),
            'url_load_conversation' => $this->url->link('extension/phpclaw/module/phpclaw.load_conversation', 'user_token='.$this->session->data['user_token'], true),
            'engine_error' => $engineError,
            'provider' => $provider,
            'model' => $model,
            'convs' => $convs,
            'init_conv_id' => $initConvId,
            'init_title' => $initTitle,
            'init_messages' => $initMessages,
        ];

        $this->response->setOutput($this->load->view('extension/phpclaw/module/phpclaw_debug', $data));
    }

    /**
     * AJAX endpoint returning one conversation's message history as JSON, for users holding access permission on the module.
     *
     * @return void
     */
    public function load_conversation(): void
    {
        $this->response->addHeader('Content-Type: application/json');
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error' => 'Permission denied.']));

            return;
        }
        $convId = trim((string) ($this->request->post['conversation_id'] ?? ''));
        if ($convId === '') {
            $this->response->setOutput(json_encode(['error' => 'conversation_id is required.']));

            return;
        }

        $actingUserId = (int) $this->user->getId();
        $manageAll = $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all');

        try {
            $plugin = $this->bootPlugin();
            $memory = $plugin->scopedConversationMemory($actingUserId, $manageAll);
            $data = $memory->get($convId, 'conversations');

            if (! is_array($data)) {
                $this->response->setOutput(json_encode(['error' => 'Conversation not found.']));

                return;
            }

            $raw = array_values(array_filter(
                (array) ($data['history'] ?? $data['messages'] ?? []),
                static fn ($m) => is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant', 'tool'], true),
            ));

            $messages = array_map(static function (array $m): array {
                if (($m['role'] ?? '') !== 'tool') {
                    return [
                        'role' => (string) ($m['role'] ?? ''),
                        'content' => (string) ($m['content'] ?? ''),
                    ];
                }

                return [
                    'role' => 'tool',
                    'tool_name' => (string) ($m['tool_name'] ?? ''),
                    'tool_input' => is_array($m['tool_input'] ?? null) ? $m['tool_input'] : [],
                    'tool_result' => (string) ($m['content'] ?? ''),
                ];
            }, $raw);

            $this->response->setOutput(json_encode([
                'success' => true,
                'title' => (string) ($data['title'] ?? ''),
                'messages' => $messages,
            ]));
        } catch (ConversationAccessDeniedException $e) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error' => AdminResponder::messageFor($e)]));
        } catch (\Throwable $e) {
            error_log('phpClaw error: '.$e->getMessage());
            $this->response->setOutput(json_encode(['error' => 'An internal error occurred. Please try again.']));
        }
    }

    /**
     * Analytics page with conversation counts, for users holding access permission on the module.
     *
     * @return void
     */
    public function analytics(): void
    {
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
            $this->response->setOutput('You do not have permission to access phpClaw.');

            return;
        }

        $this->load->language('extension/phpclaw/module/phpclaw');
        $this->document->addStyle('../extension/phpclaw/admin/view/stylesheet/phpclaw-admin.css');
        $this->document->setTitle($this->language->get('heading_title').' - Analytics');

        $actingUserId = (int) $this->user->getId();
        $manageAll = $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all');

        $stats = ['conversations' => 0, 'messages' => 0, 'active_24h' => 0];

        try {
            $this->bootPlugin();
            $prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
            $db = new OcDbAdapter($this->registry->get('db'));

            if ($manageAll) {
                $stats['conversations'] = (int) ($db->query("SELECT COUNT(*) AS cnt FROM {$prefix}phpclaw_conversations")->row['cnt'] ?? 0);
                $stats['messages'] = (int) ($db->query(
                    "SELECT COUNT(*) AS cnt FROM {$prefix}phpclaw_messages pm"
                    ." INNER JOIN {$prefix}phpclaw_conversations pc ON pm.conversation_id = pc.id"
                )->row['cnt'] ?? 0);
                $stats['active_24h'] = (int) ($db->query(
                    "SELECT COUNT(*) AS cnt FROM {$prefix}phpclaw_conversations WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                )->row['cnt'] ?? 0);
            } else {
                $stats['conversations'] = (int) ($db->query(
                    "SELECT COUNT(*) AS cnt FROM {$prefix}phpclaw_conversations WHERE owner_id = ?",
                    [$actingUserId],
                )->row['cnt'] ?? 0);
                $stats['messages'] = (int) ($db->query(
                    "SELECT COUNT(*) AS cnt FROM {$prefix}phpclaw_messages pm"
                    ." INNER JOIN {$prefix}phpclaw_conversations pc ON pm.conversation_id = pc.id"
                    ." WHERE pc.owner_id = ?",
                    [$actingUserId],
                )->row['cnt'] ?? 0);
                $stats['active_24h'] = (int) ($db->query(
                    "SELECT COUNT(*) AS cnt FROM {$prefix}phpclaw_conversations"
                    ." WHERE owner_id = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                    [$actingUserId],
                )->row['cnt'] ?? 0);
            }
        } catch (\Throwable $e) {
            error_log('phpClaw analytics query failed: '.$e->getMessage());
        }

        $data = $this->buildLayoutData();
        $data += [
            'heading_title' => $this->language->get('heading_title').' - Analytics',
            'url_settings' => $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
            'can_manage_all' => $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all'),
            'stats' => $stats,
        ];

        $this->response->setOutput($this->load->view('extension/phpclaw/module/phpclaw_analytics', $data));
    }

    /**
     * Guide page covering tools, memory, REST API and CLI, for users holding access permission on the module.
     *
     * @return void
     */
    public function guide(): void
    {
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
            $this->response->setOutput('You do not have permission to access phpClaw.');

            return;
        }

        $this->load->language('extension/phpclaw/module/phpclaw');
        $this->document->addStyle('../extension/phpclaw/admin/view/stylesheet/phpclaw-admin.css');
        $this->document->setTitle($this->language->get('heading_title').' - Guide');
        $plugin = $this->bootPlugin();

        $data = $this->buildLayoutData();
        $capabilityRecords = SettingsPage::buildCapabilityRecords($plugin->eventFirer());
        $data += [
            'heading_title' => $this->language->get('heading_title').' - Guide',
            'url_settings' => $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
            'can_manage_all' => $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all'),
            'url_debug' => $this->url->link('extension/phpclaw/module/phpclaw.debug', 'user_token='.$this->session->data['user_token'], true),
            'url_analytics' => $this->url->link('extension/phpclaw/module/phpclaw.analytics', 'user_token='.$this->session->data['user_token'], true),
            'url_guide' => $this->url->link('extension/phpclaw/module/phpclaw.guide', 'user_token='.$this->session->data['user_token'], true),
            'url_about' => $this->url->link('extension/phpclaw/module/phpclaw.about', 'user_token='.$this->session->data['user_token'], true),
            'url_send' => $this->url->link('extension/phpclaw/module/phpclaw.send', 'user_token='.$this->session->data['user_token'], true),
            'providers' => SettingsPage::guideProviders(),
            'core_tools' => SettingsPage::coreUtilityTools(),
            'tools' => SettingsPage::guideToolRows($plugin->guideTools(callerMayUseModule: $this->mayUseModule())),
            'capability_records' => $capabilityRecords,
            'remote_skill_urls' => SettingsPage::remoteSkillUrls($plugin),
            'remote_skills' => SettingsPage::remoteSkills($plugin, $capabilityRecords['skills'], $this->mayUseModule()),
        ];

        $this->response->setOutput($this->load->view('extension/phpclaw/module/phpclaw_guide', $data));
    }

    /**
     * About page, for users holding access permission on the module.
     *
     * @return void
     */
    public function about(): void
    {
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
            $this->response->setOutput('You do not have permission to access phpClaw.');

            return;
        }

        $this->load->language('extension/phpclaw/module/phpclaw');
        $this->document->addStyle('../extension/phpclaw/admin/view/stylesheet/phpclaw-admin.css');
        $this->document->setTitle($this->language->get('heading_title').' - About');

        $this->bootPlugin();

        $data = $this->buildLayoutData();
        $data += [
            'heading_title' => $this->language->get('heading_title').' - About',
            'url_settings' => $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
            'can_manage_all' => $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all'),
            'url_debug' => $this->url->link('extension/phpclaw/module/phpclaw.debug', 'user_token='.$this->session->data['user_token'], true),
            'url_analytics' => $this->url->link('extension/phpclaw/module/phpclaw.analytics', 'user_token='.$this->session->data['user_token'], true),
            'url_guide' => $this->url->link('extension/phpclaw/module/phpclaw.guide', 'user_token='.$this->session->data['user_token'], true),
            'url_about' => $this->url->link('extension/phpclaw/module/phpclaw.about', 'user_token='.$this->session->data['user_token'], true),
            'about' => AboutPage::data(),
        ];

        $this->response->setOutput($this->load->view('extension/phpclaw/module/phpclaw_about', $data));
    }

    /**
     * Grant module permissions and run the DB migration on install.
     *
     * @return void
     */
    public function install(): void
    {
        $plugin = $this->bootPlugin();
        $plugin->runMigration();

        $this->load->model('user/user_group');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/phpclaw/module/phpclaw');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/phpclaw/module/phpclaw');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/phpclaw/module/phpclaw/manage_all');
    }

    /**
     * Drop phpClaw tables and remove settings on uninstall.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `'.DB_PREFIX.'phpclaw_messages`');
        $this->db->query('DROP TABLE IF EXISTS `'.DB_PREFIX.'phpclaw_conversations`');
        $this->db->query('DROP TABLE IF EXISTS `'.DB_PREFIX.'phpclaw_memory`');
        $this->db->query('DELETE FROM `'.DB_PREFIX."setting` WHERE code = 'module_phpclaw'");
    }

    /**
     * POST: stream a chat turn over Server-Sent Events, for users holding modify permission on the module.
     *
     * @return void
     */
    public function stream(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('ob_implicit_flush')) {
            ob_implicit_flush(true);
        }
        ignore_user_abort(false);
        @set_time_limit(0);

        if (! $this->user->hasPermission('modify', 'extension/phpclaw/module/phpclaw')) {
            if (! headers_sent()) {
                http_response_code(403);
            }
            $this->sendSseHeaders();
            $this->emitSseFrame('error', ['error' => 'Permission denied.']);
            exit;
        }

        $message = trim((string) ($this->request->post['message'] ?? ''));
        $conversationId = trim((string) ($this->request->post['conversation_id'] ?? ''));

        $this->sendSseHeaders();

        if ($message === '') {
            if (! headers_sent()) {
                http_response_code(400);
            }
            $this->emitSseFrame('error', ['error' => 'Message is required.']);
            exit;
        }

        $actingUserId = (int) $this->user->getId();
        $manageAll = $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all');

        $emit = function (string $event, array $data): void {
            $this->emitSseFrame($event, $data);
        };

        try {
            $plugin = $this->bootPlugin();
            $panel = new DebugPanel($plugin->buildScopedEngine($actingUserId, $manageAll, callerMayUseModule: $this->mayUseModule(), mayQueryRaw: $manageAll));
            $panel->stream($message, $conversationId, $emit);
        } catch (ConversationAccessDeniedException $e) {
            if (! headers_sent()) {
                http_response_code(403);
            }
            $this->emitSseFrame('error', ['error' => AdminResponder::messageFor($e)]);
        } catch (\Throwable $e) {
            error_log('phpClaw stream error: '.$e->getMessage());
            $this->emitSseFrame('error', ['error' => AdminResponder::messageFor($e)]);
        }

        exit;
    }

    /**
     * Send the response headers required for a Server-Sent Events stream.
     *
     * @return void
     */
    private function sendSseHeaders(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /**
     * Write one server-sent-event frame to the output buffer and flush it.
     *
     * @param  string  $event  SSE event name.
     * @param  array<string, mixed>  $data  Payload encoded as the frame body.
     * @return void
     */
    private function emitSseFrame(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * POST: send a prompt to the agent and return JSON, for users holding modify permission on the module.
     *
     * @return void
     */
    public function send(): void
    {
        $this->response->addHeader('Content-Type: application/json');

        if (! $this->user->hasPermission('modify', 'extension/phpclaw/module/phpclaw')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error' => 'Permission denied.']));

            return;
        }

        $message = trim((string) ($this->request->post['message'] ?? ''));
        $conversationId = trim((string) ($this->request->post['conversation_id'] ?? ''));

        if ($message === '') {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput(json_encode(['error' => 'Message is required.']));

            return;
        }

        $actingUserId = (int) $this->user->getId();
        $manageAll = $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all');

        try {
            $plugin = $this->bootPlugin();
            $panel = new DebugPanel($plugin->buildScopedEngine($actingUserId, $manageAll, callerMayUseModule: $this->mayUseModule(), mayQueryRaw: $manageAll));
            $result = $panel->send($message, $conversationId);
            $this->response->setOutput(json_encode(['success' => true] + $result));
        } catch (ConversationAccessDeniedException $e) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error' => AdminResponder::messageFor($e)]));
        } catch (\Throwable $e) {
            error_log('phpClaw send error: '.$e->getMessage());
            $this->response->setOutput(json_encode(['error' => AdminResponder::messageFor($e)]));
        }
    }

    /**
     * POST: test the current provider connection and return JSON, for users holding access permission on the module.
     *
     * @return void
     */
    public function test_connection(): void
    {
        $this->response->addHeader('Content-Type: application/json');

        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error' => 'Permission denied.']));

            return;
        }

        try {
            $plugin = $this->bootPlugin();
            $saved = $plugin->saved();
            $current = SettingsPage::merge($saved);

            if (($current['provider'] ?? '') === '') {
                $this->response->setOutput(json_encode(['error' => 'No provider selected. Choose a provider in Settings and save.']));

                return;
            }

            if (($current['provider'] ?? '') !== 'ollama' && ($current['api_key'] ?? '') === '') {
                $this->response->setOutput(json_encode(['error' => 'No API key configured. Add your API key in Settings and save.']));

                return;
            }

            $engine = $plugin->engine($this->mayUseModule());
            $response = $engine->send('Reply with exactly: OK');
            $this->response->setOutput(json_encode([
                'success' => true,
                'provider' => $response->provider,
                'model' => $response->model,
                'text' => trim($response->text),
            ]));
        } catch (\Throwable $e) {
            error_log('phpClaw error: '.$e->getMessage());
            $this->response->setOutput(json_encode(['error' => 'An internal error occurred. Please try again.']));
        }
    }

    /**
     * GET: check for a newer version and return JSON, for users holding access permission on the module.
     *
     * @return void
     */
    public function check_update(): void
    {
        $this->response->addHeader('Content-Type: application/json');

        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode(['error' => 'Permission denied.']));

            return;
        }

        try {
            $plugin = $this->bootPlugin();
            $updater = new AutoUpdater(
                currentVersion: PHPCLAW_VERSION,
                updateServerUrl: (string) ($plugin->config()['update_server'] ?? ''),
            );
            $update = $updater->checkForUpdate();
            $this->response->setOutput(json_encode($update ?? ['status' => 'up_to_date']));
        } catch (\Throwable $e) {
            error_log('phpClaw error: '.$e->getMessage());
            $this->response->setOutput(json_encode(['error' => 'An internal error occurred. Please try again.']));
        }
    }

    /**
     * Bootstrap the phpClaw Plugin singleton using the OC4 extension directory.
     *
     * @return Plugin  The booted plugin singleton.
     */
    private function bootPlugin(): Plugin
    {
        $extensionRoot = DIR_EXTENSION.'phpclaw/';

        if (! defined('PHPCLAW_OC_FILE') && file_exists($extensionRoot.'phpclaw.php')) {
            require_once $extensionRoot.'phpclaw.php';
        }

        $prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
        $ocDb = null;
        try {
            $ocDb = $this->registry->get('db');
        } catch (\Throwable) {
        }
        $db = $ocDb !== null ? new OcDbAdapter($ocDb) : null;

        return Plugin::getInstance($prefix, $this->registry, $db);
    }

    /**
     * Handle the settings form POST: validate, save, redirect, for users holding access permission on the module.
     *
     * @param  Plugin  $plugin  Booted plugin instance.
     * @return bool True when the settings were saved and a redirect was issued, false when validation or permission errors must be rendered.
     */
    private function handleSettingsSave(Plugin $plugin): bool
    {
        if (! $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all')) {
            $this->ocError['warning'] = 'You do not have permission to modify phpClaw settings.';

            return false;
        }

        $result = SettingsPage::validate($this->request->post, $plugin->saved());

        if ($result['errors'] !== []) {
            $this->ocError = $result['errors'];

            return false;
        }

        $plugin->saveSettings($result['data']);

        $this->session->data['success'] = 'phpClaw settings saved successfully.';
        $this->response->redirect(
            $this->url->link('extension/phpclaw/module/phpclaw', 'user_token='.$this->session->data['user_token'], true),
        );

        return true;
    }

    /**
     * Build the common layout data (header, column_left, footer) for any page.
     *
     * @return array<string, mixed>
     */
    private function buildLayoutData(): array
    {
        return [
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
        ] + $this->buildNavUrls();
    }

    /**
     * Build the admin nav-bar URLs (Settings / Chat / Analytics / Guide / About).
     *
     * @return array<string, mixed>
     */
    private function buildNavUrls(): array
    {
        $t = 'user_token='.$this->session->data['user_token'];

        return [
            'url_settings' => $this->url->link('extension/phpclaw/module/phpclaw', $t, true),
            'can_manage_all' => $this->user->hasPermission('access', 'extension/phpclaw/module/phpclaw/manage_all'),
            'url_debug' => $this->url->link('extension/phpclaw/module/phpclaw.debug', $t, true),
            'url_analytics' => $this->url->link('extension/phpclaw/module/phpclaw.analytics', $t, true),
            'url_guide' => $this->url->link('extension/phpclaw/module/phpclaw.guide', $t, true),
            'url_about' => $this->url->link('extension/phpclaw/module/phpclaw.about', $t, true),
        ];
    }
}
