<?php

declare(strict_types=1);

namespace PhpClaw\WordPress;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\ClawConfig;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\WordPress\Admin\AboutPage;
use PhpClaw\WordPress\Admin\AdminNotice;
use PhpClaw\WordPress\Admin\AnalyticsPage;
use PhpClaw\WordPress\Admin\AutoUpdater;
use PhpClaw\WordPress\Admin\ChatPage;
use PhpClaw\WordPress\Admin\DashboardWidget;
use PhpClaw\WordPress\Admin\GuidePage;
use PhpClaw\WordPress\Admin\SettingsPage;
use PhpClaw\WordPress\Admin\SiteHealth;
use PhpClaw\WordPress\CLI\McpServerCommand;
use PhpClaw\WordPress\CLI\PhpClawCommand;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;
use PhpClaw\WordPress\Memory\FileRouterMemory;
use PhpClaw\WordPress\Memory\WpDbConversationMemory;
use PhpClaw\WordPress\Memory\WpDbMemory;
use PhpClaw\WordPress\Memory\WpOptionsMemory;
use PhpClaw\WordPress\Memory\WpRouterMemory;
use PhpClaw\WordPress\Memory\WpTransientMemory;
use PhpClaw\WordPress\Rest\PhpClawAdminController;
use PhpClaw\WordPress\Rest\PhpClawRestController;

/**
 * Main plugin bootstrap singleton.
 */
class Plugin
{
    private const FILE_STORAGE_SUBPATH = '/phpclaw-storage';

    private static ?self $instance = null;

    private ?PhpClawInterface $engine = null;

    private ?\Throwable $engineError = null;

    private array $config;

    /**
     * Bootstrap the plugin registries and WordPress hooks.
     */
    private function __construct()
    {
        $this->config = require __DIR__.'/../config/phpclaw.php';

        $this->bootRegistries();
        $this->maybeRunMigration();

        $this->registerWordPressHooks();
    }

    /**
     * Singleton accessor - returns the single Plugin instance.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Expose the PhpClaw engine (lazy - built on first call).
     *
     * @return PhpClawInterface The configured engine instance.
     *
     * @throws \Throwable If the engine could not be built (e.g. no API key).
     */
    public function engine(): PhpClawInterface
    {
        if ($this->engine === null && $this->engineError === null) {
            try {
                $this->engine = $this->buildEngine();
            } catch (\Throwable $e) {
                $this->engineError = $e;
            }
        }

        if ($this->engineError !== null) {
            throw $this->engineError;
        }

        return $this->engine;
    }

    /**
     * Return the resolved plugin config array.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Resolve the boolean store_messages flag from saved settings.
     *
     * @param  array<string, mixed>|null  $saved  Pre-loaded settings. Loads from
     *                                            get_option() when null.
     * @param  mixed  $default  Fallback when store_messages
     *                          key is absent. Defaults to true
     *                          so multi-turn chat works out of
     *                          the box.
     * @return bool True if message persistence is enabled.
     */
    public static function storeMessagesEnabled(?array $saved = null, mixed $default = true): bool
    {
        $saved ??= (array) get_option('phpclaw_settings', []);

        if (! array_key_exists('store_messages', $saved)) {
            return (bool) $default;
        }

        $parsed = filter_var($saved['store_messages'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? false;
    }

    /**
     * List of extra tool classes to auto-discover.
     *
     * @return array<class-string>
     */
    public static function extraToolClasses(): array
    {
        $defaults = class_exists(ToolCatalogue::class)
            ? ToolCatalogue::all()
            : [];

        return (array) apply_filters('phpclaw_extra_tools', $defaults);
    }

    /**
     * Enqueue CSS and JS assets on phpClaw admin pages.
     *
     * @param  string  $hook  Current admin page hook suffix.
     * @return void
     */
    public static function enqueueAdminAssets(string $hook): void
    {
        $phpClawHooks = [
            'toplevel_page_phpclaw',
            'phpclaw_page_phpclaw-chat',
            'phpclaw_page_phpclaw-analytics',
            'phpclaw_page_phpclaw-guide',
            'phpclaw_page_phpclaw-about',
            'index.php',
        ];

        if (! in_array($hook, $phpClawHooks, true)) {
            return;
        }

        $baseUrl = plugins_url('resources/admin/', PHPCLAW_PLUGIN_FILE);
        $baseDir = dirname(PHPCLAW_PLUGIN_FILE).'/resources/admin/';
        $version = defined('PHPCLAW_VERSION') ? PHPCLAW_VERSION : '0.0.0';

        wp_enqueue_style(
            'phpclaw-admin',
            $baseUrl.'css/phpclaw-admin.css',
            [],
            self::assetVersion($baseDir.'css/phpclaw-admin.css', $version),
        );

        if ($hook === 'phpclaw_page_phpclaw-chat') {
            wp_enqueue_script(
                'phpclaw-chat',
                $baseUrl.'js/phpclaw-chat.js',
                [],
                self::assetVersion($baseDir.'js/phpclaw-chat.js', $version),
                true,
            );
            wp_localize_script('phpclaw-chat', 'phpClawChatData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'adminUrl' => admin_url(),
                'nonceSend' => wp_create_nonce('phpclaw_send'),
                'nonceStream' => wp_create_nonce('phpclaw_stream'),
                'nonceLoad' => wp_create_nonce('phpclaw_load_conversation'),
                'settingsUrl' => admin_url('admin.php?page=phpclaw'),
            ]);
        }

        if ($hook === 'toplevel_page_phpclaw') {
            wp_enqueue_script(
                'phpclaw-admin',
                $baseUrl.'js/phpclaw-admin.js',
                [],
                self::assetVersion($baseDir.'js/phpclaw-admin.js', $version),
                true,
            );
            wp_localize_script('phpclaw-admin', 'phpClawAdminData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'testNonce' => wp_create_nonce('phpclaw_nonce'),
                'settingsUrl' => admin_url('admin.php?page=phpclaw'),
            ]);
        }

        if ($hook === 'phpclaw_page_phpclaw-guide') {
            wp_register_script('phpclaw-guide', false, [], $version, true);
            wp_enqueue_script('phpclaw-guide');
            wp_add_inline_script(
                'phpclaw-guide',
                'var phpClawGuideData = '.wp_json_encode([
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'testNonce' => wp_create_nonce('phpclaw_nonce'),
                ]).';',
                'before',
            );
        }
    }

    /**
     * Build a cache-busting asset version from the file modification time.
     *
     * @param  string  $path  Absolute path to the asset file.
     * @param  string  $fallback  Plugin version used when the file is unreadable.
     * @return string Version string appended to the asset URL.
     */
    private static function assetVersion(string $path, string $fallback): string
    {
        $mtime = is_readable($path) ? filemtime($path) : false;

        if ($mtime === false) {
            return $fallback;
        }

        return $fallback.'.'.$mtime;
    }

    /**
     * Register the top-level phpClaw menu and all submenus.
     *
     * @return void
     */
    public static function registerAdminMenu(): void
    {
        $icon = 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ffffff">'
            .'<path d="M12 2a2 2 0 0 1 2 2 2 2 0 0 1-2 2 2 2 0 0 1-2-2 2 2 0 0 1 2-2m0 5c2.67 0 8 1.34 8 4v2H4v-2c0-2.66 5.33-4 8-4z"/>'
            .'<rect x="3" y="14" width="18" height="8" rx="2"/>'
            .'<circle cx="8.5" cy="18" r="1.5" fill="#1d2327"/>'
            .'<circle cx="15.5" cy="18" r="1.5" fill="#1d2327"/>'
            .'<rect x="10.5" y="16.5" width="3" height="1" rx=".5" fill="#1d2327"/>'
            .'</svg>'
        );

        add_menu_page(
            page_title: 'phpClaw',
            menu_title: 'phpClaw',
            capability: 'phpclaw_use_chat',
            menu_slug: 'phpclaw',
            callback: [SettingsPage::class, 'render'],
            icon_url: $icon,
            position: 80,
        );

        SettingsPage::register();
        ChatPage::register();
        AnalyticsPage::register();
        GuidePage::register();
        AboutPage::register();

        if (! current_user_can('phpclaw_use_admin_chat')) {
            remove_submenu_page('phpclaw', 'phpclaw');
        }
    }

    /**
     * Add "Settings" and "About" links on the Plugins list page next to Deactivate.
     *
     * @param  string[]  $links
     * @return string[]
     */
    public static function pluginActionLinks(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=phpclaw')),
            __('Settings', 'phpclaw'),
        );

        $aboutLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=phpclaw-about')),
            __('About', 'phpclaw'),
        );

        array_unshift($links, $aboutLink, $settingsLink);

        return $links;
    }

    /**
     * Add "View details" link in the plugin row meta (below description on Plugins page).
     *
     * @param  string[]  $links
     * @param  string  $file
     * @return string[]
     */
    public static function pluginRowMeta(array $links, string $file): array
    {
        if (plugin_basename(PHPCLAW_PLUGIN_FILE) !== $file) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=phpclaw-about')),
            __('View details', 'phpclaw'),
        );

        return $links;
    }

    /**
     * AJAX handler for the admin chat "Send Prompt" form.
     *
     * @return void
     */
    public function handleAjaxSend(): void
    {
        $this->assertSendPermission();
        $message = sanitize_text_field(wp_unslash((string) ($_POST['message'] ?? '')));
        $conversationId = sanitize_text_field(wp_unslash((string) ($_POST['conversation_id'] ?? '')));

        if ($message === '') {
            wp_send_json_error(['message' => 'Message is required.'], 400);
        }

        try {
            $engine = $this->engine();
            $conversation = $engine->conversation($conversationId);

            $toolCalls = [];
            $this->registerToolCallCollector($toolCalls);

            $turn = $engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                function (array $payload) use (&$toolCalls): array {
                    $finalToolCalls = $this->filterNamedToolCalls($toolCalls);

                    if ($finalToolCalls !== []) {
                        $history = (array) ($payload['history'] ?? $payload['messages'] ?? []);
                        $insertAt = $this->findLastAssistantIndex($history);
                        $toolEntries = array_map(static fn (array $c): array => [
                            'role' => 'tool',
                            'content' => $c['tool_result'],
                            'tool_name' => $c['tool_name'],
                            'tool_input' => $c['tool_input'],
                        ], $finalToolCalls);
                        array_splice($history, $insertAt, 0, $toolEntries);
                        $payload['history'] = $history;
                    }

                    return $payload;
                },
            );
            $response = $turn->response;

            $finalToolCalls = $this->filterNamedToolCalls($toolCalls);

            wp_send_json_success($this->buildSendResponse($response, $turn->conversation->id, $finalToolCalls));
        } catch (ConversationAccessDeniedException $e) {
            wp_send_json_error(['message' => __('You do not have permission to access this resource.', 'phpclaw')], 403);
        } catch (GuardException $e) {
            error_log('phpClaw: guard blocked AJAX send: '.$e->getMessage());
            wp_send_json_error(['message' => __('Your message was blocked by a security guard. Rephrase your prompt and try again.', 'phpclaw')], 422);
        } catch (\Throwable $e) {
            error_log('phpClaw: AJAX send error: '.$e->getMessage());
            wp_send_json_error(['message' => __('An internal error occurred. Please try again.', 'phpclaw')], 500);
        }
    }

    /**
     * AJAX handler - stream a chat turn via Server-Sent Events.
     *
     * @return void Terminates the request - never returns to the caller.
     */
    public function handleAjaxStream(): void
    {
        check_ajax_referer('phpclaw_stream', 'nonce');

        if (! current_user_can('phpclaw_use_chat')) {
            $this->emitSseError('Insufficient permissions.', 403);

            return;
        }

        $message = sanitize_text_field(wp_unslash((string) ($_POST['message'] ?? '')));
        $conversationId = sanitize_text_field(wp_unslash((string) ($_POST['conversation_id'] ?? '')));

        if ($message === '') {
            $this->emitSseError('Message is required.', 400);

            return;
        }

        $this->sendSseHeaders();
        $isNew = ($conversationId === '');

        try {
            $engine = $this->engine();
            $conversation = $engine->conversation($conversationId);

            $collectedToolCalls = [];
            $emit = function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.wp_json_encode($data)."\n\n";
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            HookRegistry::on(LifecycleEvent::ToolBefore->value, static function (array $ctx) use ($emit): void {
                $emit('tool_before', [
                    'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                    'tool_input' => (array) ($ctx['tool_input'] ?? []),
                ]);
            });

            HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use ($emit, &$collectedToolCalls): void {
                $entry = [
                    'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                    'tool_input' => (array) ($ctx['tool_input'] ?? []),
                    'tool_result' => (string) ($ctx['tool_result'] ?? ''),
                ];
                if ($entry['tool_name'] !== '') {
                    $collectedToolCalls[] = $entry;
                    $emit('tool_after', $entry);
                }
            });

            HookRegistry::on(LifecycleEvent::ProviderToken->value, static function (array $ctx) use ($emit): void {
                $token = (string) ($ctx['token'] ?? '');
                if ($token !== '') {
                    $emit('chunk', ['text' => $token]);
                }
            });

            $title = '';
            $turn = $engine->streamInConversation(
                $conversation,
                $message,
                static function (string $token): void {
                    unset($token);
                },
                function (array $payload) use (&$collectedToolCalls, $isNew, $message, &$title): array {
                    $toolCalls = $collectedToolCalls;

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
                'tool_calls' => $collectedToolCalls,
            ]);
        } catch (ConversationAccessDeniedException $e) {
            $this->emitSseError(__('You do not have permission to access this resource.', 'phpclaw'), 403);
        } catch (GuardException $e) {
            error_log('phpClaw: guard blocked stream: '.$e->getMessage());
            $this->emitSseError(__('Your message was blocked by a security guard. Rephrase your prompt and try again.', 'phpclaw'));
        } catch (\Throwable $e) {
            error_log('phpClaw: stream error: '.$e->getMessage());
            $this->emitSseError(__('An internal error occurred. Please try again.', 'phpclaw'));
        }

        exit;
    }

    /**
     * AJAX handler - load a conversation's message history for the chat UI.
     *
     * @return void
     */
    public function handleAjaxLoadConversation(): void
    {
        $this->assertLoadConversationPermission();

        $convId = sanitize_text_field(wp_unslash((string) ($_POST['conversation_id'] ?? '')));
        if ($convId === '') {
            wp_send_json_error(['message' => 'conversation_id required.'], 400);
        }

        try {
            $data = $this->engine()->memory()->get($convId, 'conversations');
            if (! is_array($data)) {
                wp_send_json_error(['message' => 'Conversation not found.'], 404);
            }

            wp_send_json_success([
                'title' => (string) ($data['title'] ?? ''),
                'messages' => $this->normaliseConversationHistory((array) ($data['history'] ?? [])),
            ]);
        } catch (ConversationAccessDeniedException $e) {
            wp_send_json_error(['message' => __('You do not have permission to access this resource.', 'phpclaw')], 403);
        } catch (\Throwable $e) {
            error_log('phpClaw: AJAX load-conversation error: '.$e->getMessage());
            wp_send_json_error(['message' => __('An internal error occurred. Please try again.', 'phpclaw')], 500);
        }
    }

    /**
     * AJAX handler - test provider connection from the Settings page.
     *
     * @return void
     */
    public function handleAjaxTestConnection(): void
    {
        check_ajax_referer('phpclaw_nonce', 'nonce');

        if (! current_user_can('phpclaw_use_admin_chat')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $saved = (array) get_option('phpclaw_settings', []);
        $provider = (string) ($saved['provider'] ?? '');
        $apiKey = (string) ($saved['api_key'] ?? '');

        if ($provider === '') {
            wp_send_json_error(['message' => __('No provider selected. Choose a provider and save settings first.', 'phpclaw')]);
        }

        if ($provider !== 'ollama' && $apiKey === '') {
            wp_send_json_error(['message' => __('API key is missing. Add your API key and save settings first.', 'phpclaw')]);
        }

        try {
            $response = $this->engine()->send('Reply with exactly: OK');
            wp_send_json_success([
                'provider' => $response->provider,
                'model' => $response->model,
                'text' => $response->text,
            ]);
        } catch (\Throwable $e) {
            error_log('phpClaw: AJAX test-connection error: '.$e->getMessage());
            wp_send_json_error(['message' => __('Connection test failed. Check your API key and provider settings.', 'phpclaw')]);
        }
    }

    /**
     * Plugin activation callback.
     *
     * @return void
     */
    public static function activate(): void
    {
        require_once __DIR__.'/../database/migration.php';
        phpclaw_run_migration();

        if (get_option('phpclaw_settings') === false) {
            update_option('phpclaw_settings', [
                'provider' => '',
                'model' => '',
                'api_key' => '',
                'base_url' => '',
                'cloud_key' => '',
                'cloud_signing_secret' => '',
                'cloud_disable' => [],
                'store_messages' => '1',
                'max_iterations' => ClawConfig::DEFAULT_MAX_ITERATIONS,
                'system_prompt' => '',
                'remote_skill_urls' => [],
            ]);
        }
    }

    /**
     * Boot all registries: guards, memory drivers, hooks, skills, and cloud manager.
     *
     * @return void
     */
    private function bootRegistries(): void
    {
        GuardRegistry::registerDefaults();

        MemoryRegistry::register('wp_options', fn () => new WpOptionsMemory);
        MemoryRegistry::register('wpdb', fn () => new WpDbMemory);
        MemoryRegistry::register('wpdb_conversation', fn () => new WpDbConversationMemory);
        MemoryRegistry::register('wp_transient', fn () => new WpTransientMemory);
        MemoryRegistry::register('wpdb_router', fn () => new WpRouterMemory(
            new WpDbConversationMemory,
            new WpDbMemory,
        ));
        MemoryRegistry::register('file', fn () => new FileRouterMemory(
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR.self::FILE_STORAGE_SUBPATH : null,
        ));

        Bootstrap::boot();

        $this->registerConfigGuards();
        $this->registerConfigHooks();
        $this->registerSkills();

        $saved = (array) get_option('phpclaw_settings', []);
        $cloudKey = (string) ($saved['cloud_key'] ?? '');
        $cloudDisable = (array) ($saved['cloud_disable'] ?? []);
        $cloudSecret = (string) ($saved['cloud_signing_secret'] ?? '');
        $storeMessages = self::storeMessagesEnabled($saved, true);

        SkillCatalogue::activateDefaults();

        Bootstrap::activateFromSettings($saved);

        if ($storeMessages && class_exists(CloudManager::class)) {
            CloudManager::boot($cloudKey, $cloudDisable, $cloudSecret);
        }
    }

    /**
     * Register any guards declared in the config 'guards' array.
     *
     * @return void
     */
    private function registerConfigGuards(): void
    {
        foreach ((array) ($this->config['guards'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            GuardRegistry::register(new $entry['class'], $priority);
        }
    }

    /**
     * Register any HookRegistry listeners declared in the config 'hooks' array.
     *
     * @return void
     */
    private function registerConfigHooks(): void
    {
        foreach ((array) ($this->config['hooks'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            HookRegistry::on($entry['event'], $entry['handler'], $priority);
        }
    }

    /**
     * Register all resolved skills into the SkillRegistry.
     *
     * @return void
     */
    private function registerSkills(): void
    {
        foreach ($this->resolveSkills() as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Resolve skills from the config 'skills' array via the canonical resolver.
     *
     * @return array<int, SkillInterface>
     */
    private function resolveSkills(): array
    {
        return SkillResolver::resolve((array) ($this->config['skills'] ?? []));
    }

    /**
     * Delegate engine construction to EngineFactory.
     *
     * @return PhpClaw
     */
    private function buildEngine(): PhpClaw
    {
        $saved = (array) get_option('phpclaw_settings', []);

        return EngineFactory::build(
            config: $this->config,
            saved: $saved,
            skills: $this->resolveSkills(),
            extraToolClasses: self::extraToolClasses(),
        );
    }

    /**
     * Register all WordPress action and filter hooks for the plugin.
     *
     * @return void
     */
    private function registerWordPressHooks(): void
    {
        $this->registerAdminHooks();
        $this->registerRestHooks();
        $this->registerHookEventBridge();
        $this->registerAjaxHooks();
        $this->registerSettingsResetHook();
        $this->registerCliIfAvailable();
        $this->registerAutoUpdater();
        $this->registerWp7AiHooks();
        $this->registerConversationCapability();
    }

    /**
     * Derive phpclaw_use_admin_chat, phpclaw_manage_all_conversations and phpclaw_use_chat via filter.
     *
     * @return void
     */
    private function registerConversationCapability(): void
    {
        add_filter(
            'user_has_cap',
            static function (array $caps, array $requiredCaps): array {
                if (in_array('phpclaw_use_admin_chat', $requiredCaps, true)
                    && ! empty($caps['manage_options'])
                ) {
                    $caps['phpclaw_use_admin_chat'] = true;
                }

                if (in_array('phpclaw_manage_all_conversations', $requiredCaps, true)
                    && ! empty($caps['manage_options'])
                ) {
                    $caps['phpclaw_manage_all_conversations'] = true;
                }

                if (in_array('phpclaw_use_chat', $requiredCaps, true)
                    && (! empty($caps['manage_options']) || ! empty($caps['edit_posts']))
                ) {
                    $caps['phpclaw_use_chat'] = true;
                }

                return $caps;
            },
            10,
            2,
        );
    }

    /**
     * Hook into WordPress 7.0 AI Client infrastructure.
     *
     * @return void
     */
    private function registerWp7AiHooks(): void
    {
        if (! function_exists('wp_ai_client_prompt')) {
            return;
        }

        if (! apply_filters('phpclaw_enable_wp7_bridge', false)) {
            return;
        }

        add_action(
            'wp_ai_client_before_generate_result',
            function (object $event): void {
                $text = $this->extractTextFromWp7Event($event);

                if ($text === '') {
                    return;
                }

                try {
                    $this->engine()->send($text);
                } catch (GuardException $e) {
                    error_log('phpClaw: wp_ai_client prompt blocked: '.$e->getMessage());
                    do_action('phpclaw_wp7_prompt_blocked', $text, 'guard_blocked');
                } catch (\Throwable) {
                }
            },
            10,
            1,
        );

        add_action(
            'wp_ai_client_after_generate_result',
            function (object $event): void {
                $response = '';
                if (method_exists($event, 'getResult')) {
                    $result = $event->getResult();
                    if (method_exists($result, 'toText')) {
                        $response = (string) $result->toText();
                    }
                }

                do_action('phpclaw_wp7_response', [
                    'prompt' => $this->extractTextFromWp7Event($event),
                    'response' => $response,
                ]);
            },
            10,
            1,
        );
    }

    /**
     * Extracts the most recent user-role prompt text from a WP 7.0 AI client event.
     *
     * @param  object  $event  BeforeGenerateResultEvent or AfterGenerateResultEvent.
     * @return string Prompt text, or empty string if not extractable.
     */
    private function extractTextFromWp7Event(object $event): string
    {
        if (! method_exists($event, 'getMessages')) {
            return '';
        }

        $messages = array_reverse($event->getMessages());

        foreach ($messages as $message) {
            if (! method_exists($message, 'getRole') || ! method_exists($message, 'getParts')) {
                continue;
            }

            $role = $message->getRole();
            if (method_exists($role, 'getValue') && $role->getValue() !== 'user') {
                continue;
            }

            foreach ($message->getParts() as $part) {
                if (method_exists($part, 'getText')) {
                    $text = $part->getText();
                    if (is_string($text) && $text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Register the admin-area hooks: menu, settings, assets, notices and the dashboard widget.
     *
     * @return void
     */
    private function registerAdminHooks(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_init', [SettingsPage::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);

        AdminNotice::register();
        DashboardWidget::register();

        add_filter('plugin_action_links_'.plugin_basename(PHPCLAW_PLUGIN_FILE), [self::class, 'pluginActionLinks']);
        add_filter('plugin_row_meta', [self::class, 'pluginRowMeta'], 10, 2);

        SiteHealth::register();
    }

    /**
     * Register the send and chat-stream REST controllers, both gated by the configured
     * capability, on the rest_api_init hook.
     *
     * @return void
     */
    private function registerRestHooks(): void
    {
        add_action('rest_api_init', function (): void {
            try {
                $controller = new PhpClawRestController($this->engine(), $this->config);
            } catch (\Throwable) {
                $controller = new PhpClawRestController(null, $this->config);
            }
            $controller->register();
        });

        add_action('rest_api_init', function (): void {
            try {
                $controller = new PhpClawAdminController($this->engine(), $this->config);
            } catch (\Throwable) {
                $controller = new PhpClawAdminController(null, $this->config);
            }
            $controller->register();
        });
    }

    /**
     * Bridge phpClaw lifecycle events to WordPress's native action API.
     *
     * @return void
     */
    private function registerHookEventBridge(): void
    {
        if (! (bool) ($this->config['events_bridge'] ?? true)) {
            return;
        }

        (new HookEventBridge(
            static fn (string $event, array $ctx) => do_action('phpclaw_'.$event, $ctx),
        ))->register();
    }

    /**
     * Register the authenticated AJAX handlers: send, stream, test connection and load
     * conversation. Each handler gates on phpclaw_use_chat, not on an admin role.
     *
     * @return void
     */
    private function registerAjaxHooks(): void
    {
        add_action('wp_ajax_phpclaw_send', [$this, 'handleAjaxSend']);
        add_action('wp_ajax_phpclaw_stream', [$this, 'handleAjaxStream']);
        add_action('wp_ajax_phpclaw_test_connection', [$this, 'handleAjaxTestConnection']);
        add_action('wp_ajax_phpclaw_load_conversation', [$this, 'handleAjaxLoadConversation']);
    }

    /**
     * Reset the lazy engine cache when settings are saved, so the next call rebuilds the
     * engine from the new configuration.
     *
     * @return void
     */
    private function registerSettingsResetHook(): void
    {
        add_action('update_option_phpclaw_settings', function (): void {
            $this->engine = null;
            $this->engineError = null;
        });
    }

    /**
     * Register WP-CLI commands when running in a CLI context.
     *
     * @return void
     */
    private function registerCliIfAvailable(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCliCommands();
        }
    }

    /**
     * Wire the one-click auto-updater that handles in-place upgrades from the update server.
     *
     * @return void
     */
    private function registerAutoUpdater(): void
    {
        $updateUrl = (string) ($this->config['update_server'] ?? 'https://phpclaw.ai/api/wp-update');

        AutoUpdater::init(
            pluginFile: PHPCLAW_PLUGIN_FILE,
            currentVersion: defined('PHPCLAW_VERSION') ? PHPCLAW_VERSION : '0.0.0',
            updateUrl: $updateUrl,
        );
    }

    /**
     * Register all WP-CLI commands: send and mcp-server.
     *
     * @return void
     */
    private function registerCliCommands(): void
    {
        \WP_CLI::add_command(
            'phpclaw send',
            PhpClawCommand::class,
            [
                'shortdesc' => 'Send a prompt to the phpClaw AI agent.',
                'synopsis' => [
                    [
                        'type' => 'positional',
                        'name' => 'message',
                        'description' => 'The prompt to send to the AI agent.',
                        'optional' => false,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'provider',
                        'description' => sprintf(
                            'Provider override (%s).',
                            implode(', ', ProviderCatalogue::keys()),
                        ),
                        'optional' => true,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'model',
                        'description' => 'Model override.',
                        'optional' => true,
                    ],
                    [
                        'type' => 'flag',
                        'name' => 'stream',
                        'description' => 'Stream the response token-by-token.',
                        'optional' => true,
                    ],
                ],
            ],
        );

        \WP_CLI::add_command('phpclaw mcp-server', McpServerCommand::class, [
            'shortdesc' => 'Start the phpClaw MCP server (stdio transport).',
        ]);

    }

    /**
     * Verify the AJAX request carries a valid nonce and the caller holds phpclaw_use_chat.
     *
     * @return void
     */
    private function assertSendPermission(): void
    {
        check_ajax_referer('phpclaw_send', 'nonce');

        if (! current_user_can('phpclaw_use_chat')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }
    }

    /**
     * Attach a hook listener that appends every tool.after event to the given array.
     *
     * @param  array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>  $calls
     *                                                                                                              Reference array - appended to as each tool finishes.
     * @return void
     */
    private function registerToolCallCollector(array &$calls): void
    {
        HookRegistry::on(LifecycleEvent::ToolAfter->value, function (array $ctx) use (&$calls): void {
            $calls[] = [
                'tool_name' => (string) ($ctx['tool_name'] ?? ''),
                'tool_input' => (array) ($ctx['tool_input'] ?? []),
                'tool_result' => (string) ($ctx['tool_result'] ?? ''),
            ];
        });
    }

    /**
     * Drop tool calls that did not carry a tool name (defensive filter).
     *
     * @param  array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>  $calls
     * @return array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>
     */
    private function filterNamedToolCalls(array $calls): array
    {
        return array_values(array_filter(
            $calls,
            static fn (array $c): bool => $c['tool_name'] !== '',
        ));
    }

    /**
     * Shape the AJAX success payload for the chat UI.
     *
     * @param  AgentResponse  $response
     * @param  string  $conversationId
     * @param  array<int, array{tool_name: string, tool_input: array<string, mixed>, tool_result: string}>  $toolCalls
     * @return array<string, mixed>
     */
    private function buildSendResponse(AgentResponse $response, string $conversationId, array $toolCalls): array
    {
        $text = $response->text;
        if ($text === '') {
            $text = PhpClawRestController::EMPTY_RESPONSE_TEXT;
        }

        return [
            'response' => $text,
            'provider' => $response->provider,
            'model' => $response->model,
            'tokens' => ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0),
            'iterations' => $response->iterations,
            'conversation_id' => $conversationId,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Emit SSE response headers and drain the output buffer.
     *
     * @return void
     */
    private function sendSseHeaders(): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
    }

    /**
     * Emit a one-shot SSE error frame and terminate.
     *
     * @param  string  $message  Human-readable error message for the client.
     * @param  int  $httpCode  Optional HTTP status code (0 = leave headers alone).
     * @return void Terminates the request via die().
     */
    private function emitSseError(string $message, int $httpCode = 0): void
    {
        if ($httpCode > 0 && ! headers_sent()) {
            status_header($httpCode);
        }
        if (! headers_sent()) {
            $this->sendSseHeaders();
        }
        echo "event: error\n";
        echo 'data: '.wp_json_encode(['message' => $message])."\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
        exit;
    }

    /**
     * Verify the load-conversation AJAX request carries a valid nonce and the caller holds
     * phpclaw_use_chat.
     *
     * @return void
     */
    private function assertLoadConversationPermission(): void
    {
        check_ajax_referer('phpclaw_load_conversation', 'nonce');

        if (! current_user_can('phpclaw_use_chat')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }
    }

    /**
     * Normalise stored history rows into the shape the chat UI consumes.
     *
     * @param  array<int, mixed>  $history
     * @return list<array<string, mixed>>
     */
    private function normaliseConversationHistory(array $history): array
    {
        $messages = [];

        foreach ($history as $msg) {
            if (! is_array($msg)) {
                continue;
            }

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

            $messages[] = [
                'role' => $role,
                'content' => (string) ($msg['content'] ?? ''),
            ];
        }

        return $messages;
    }

    /**
     * Run the DB migration if the schema version is missing or outdated.
     *
     * @return void
     */
    private function maybeRunMigration(): void
    {
        global $wpdb;

        require_once __DIR__.'/../database/migration.php';

        $primaryTable = $wpdb->prefix.'phpclaw_memory';
        $tableExists = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $primaryTable),
        );

        $installed = (string) get_option('phpclaw_db_version', '');

        if ($tableExists && $installed !== '' && version_compare($installed, PHPCLAW_DB_VERSION, '>=')) {
            return;
        }

        phpclaw_run_migration();
    }

    /**
     * Find the index of the last assistant message - tool rows are spliced before it.
     *
     * @param  array<int, mixed>  $messages  Message history in chronological order.
     * @return int Index of the last assistant message, or -1 if none found.
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
}
