<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

use PhpClaw\WordPress\Plugin;

/**
 * phpClaw Chat Page - chat-style conversation interface.
 */
final class ChatPage
{
    /**
     * Register the Chat submenu page under the phpClaw admin menu.
     *
     * @return void
     */
    public static function register(): void
    {
        add_submenu_page(
            parent_slug: 'phpclaw',
            page_title: 'phpClaw Chat',
            menu_title: 'Chat',
            capability: 'phpclaw_use_chat',
            menu_slug: 'phpclaw-chat',
            callback: [self::class, 'render'],
        );
    }

    /**
     * Render the chat-style conversation admin page, refusing anyone without
     * phpclaw_use_chat.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can('phpclaw_use_chat')) {
            wp_die(esc_html__('Insufficient permissions.', 'phpclaw'));
        }

        $plugin = Plugin::getInstance();
        $saved = (array) get_option('phpclaw_settings', []);

        $provider = (string) ($saved['provider'] ?? '');
        $model = (string) ($saved['model'] ?? '');

        $storeMessages = Plugin::storeMessagesEnabled($saved, true);

        $engineError = false;
        $convs = [];
        $engine = null;

        try {
            $engine = $plugin->engine();
            $memory = $engine->memory();
            if ($storeMessages) {
                $convs = $memory->all('conversations');
            }
        } catch (\Throwable $e) {
            $engineError = true;
            error_log('phpClaw ChatPage: engine failed: '.$e->getMessage());
        }

        $settingsUrl = admin_url('admin.php?page=phpclaw');
        $ajaxUrl = admin_url('admin-ajax.php');
        $nonceSend = wp_create_nonce('phpclaw_send');
        $nonceLoad = wp_create_nonce('phpclaw_load_conversation');
        $provLabel = self::fmtProvider($provider ?: '');
        $modelLabel = $model ?: 'default';

        $initConvId = '';
        $initTitle = '';
        $initMessages = [];

        if ($engine !== null && $convs !== []) {
            $initConvId = (string) array_key_first($convs);
            $initTitle = (string) ($convs[$initConvId]['title'] ?? '');
            try {
                $stored = $engine->memory()->get($initConvId, 'conversations');
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
        ?>
        <div class="wrap pc-wrap-flush">

            <div class="pc-chat-header">
                <h1>🤖 phpClaw</h1>
                <div class="pc-chat-header__end">
                    <?php if ($engineError) { ?>
                        <span class="pc-chat-header__status--error">⚠ Engine error:
                            <a href="<?= esc_url($settingsUrl) ?>"><?= esc_html__('Configure', 'phpclaw') ?></a>
                        </span>
                    <?php } else { ?>
                        <span class="pc-chat-header__status">
                            <span class="pc-dot-green">●</span>
                            <?= esc_html($provLabel) ?> · <?= esc_html($modelLabel) ?>
                        </span>
                    <?php } ?>
                    <a href="<?= esc_url($settingsUrl) ?>" class="button">⚙ <?= esc_html__('Settings', 'phpclaw') ?></a>
                </div>
            </div>

            <?php if ($engineError) { ?>
                <div class="pc-notice-warn"><?= esc_html__('Engine failed to initialise. Check your API Key and provider settings.', 'phpclaw') ?></div>
            <?php } ?>

            <div id="phpclaw-chat-wrap" data-init-conv-id="<?= esc_attr($initConvId) ?>">

                <nav id="phpclaw-sidebar" aria-label="<?= esc_attr__('Conversations', 'phpclaw') ?>">
                    <div id="phpclaw-sidebar-top">
                        <button id="phpclaw-new-chat" class="button button-primary" aria-label="<?= esc_attr__('Start a new conversation', 'phpclaw') ?>">
                            + <?= esc_html__('New Chat', 'phpclaw') ?>
                        </button>
                        <label for="phpclaw-conv-search" class="screen-reader-text"><?= esc_html__('Search conversations', 'phpclaw') ?></label>
                        <input id="phpclaw-conv-search" type="search" placeholder="<?= esc_attr__('Search conversations…', 'phpclaw') ?>" aria-label="<?= esc_attr__('Search conversations', 'phpclaw') ?>" />
                    </div>
                    <?php if ($convs === []) { ?>
                        <p id="phpclaw-empty-sidebar" class="pc-sidebar-empty">
                            No conversations yet.<br>Send a message to start.
                        </p>
                        <div id="phpclaw-conv-list" hidden></div>
                    <?php } else { ?>
                        <div id="phpclaw-conv-list" role="list" aria-label="<?= esc_attr__('Conversation list', 'phpclaw') ?>">
                            <?php foreach ($convs as $cid => $conv) {
                                $title = (string) ($conv['title'] ?? '');
                                $display = $title !== '' ? $title : 'New conversation';
                                $when = self::relativeTime((string) ($conv['updated_at'] ?? $conv['created_at'] ?? ''));
                                $isFirst = ($cid === $initConvId);
                                ?>
                            <div class="phpclaw-conv-item <?= $isFirst ? 'active' : '' ?>"
                                 role="listitem"
                                 tabindex="0"
                                 aria-current="<?= $isFirst ? 'true' : 'false' ?>"
                                 data-conv-id="<?= esc_attr((string) $cid) ?>"
                                 data-title="<?= esc_attr(strtolower($display)) ?>">
                                <div class="phpclaw-conv-item-title"><?= esc_html($display) ?></div>
                                <div class="phpclaw-conv-item-time"><?= esc_html($when) ?></div>
                            </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </nav>

                <div id="phpclaw-chat-area" role="main">
                    <div id="phpclaw-chat-header">
                        <?php if ($initConvId !== '') { ?>
                            <?= esc_html($initTitle !== '' ? $initTitle : 'Conversation') ?>
                        <?php } else { ?>
                            New Chat
                        <?php } ?>
                    </div>

                    <div id="phpclaw-messages" role="log" aria-live="polite" aria-label="<?= esc_attr__('Chat messages', 'phpclaw') ?>" tabindex="0">
                        <?php if ($initConvId === '') { ?>
                            <div id="phpclaw-welcome">
                                <h2>What can I help you with?</h2>
                                <p>Ask anything about your WordPress site: posts, users, settings, database queries, and more.</p>
                            </div>
                        <?php } elseif ($initMessages === []) { ?>
                            <div id="phpclaw-welcome">
                                <h2>Conversation started</h2>
                                <p>No messages stored yet. Send a message below to continue.</p>
                            </div>
                        <?php } else { ?>
                            <?php foreach ($initMessages as $msg) { ?>
                                <?php self::renderBubbleHtml($msg['role'], $msg['content']); ?>
                            <?php } ?>
                        <?php } ?>
                    </div>

                    <div id="phpclaw-input-area">
                        <div id="phpclaw-input-row">
                            <label for="phpclaw-message" class="screen-reader-text"><?= esc_html__('Message', 'phpclaw') ?></label>
                            <textarea id="phpclaw-message" rows="2"
                                placeholder="<?= esc_attr__('Message phpClaw… (Enter to send, Shift+Enter for new line)', 'phpclaw') ?>"
                                aria-label="<?= esc_attr__('Type a message', 'phpclaw') ?>"></textarea>
                            <button id="phpclaw-send-btn" title="Send (Enter)" aria-label="<?= esc_attr__('Send message', 'phpclaw') ?>" <?= $engineError ? 'disabled' : '' ?>>
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                                </svg>
                            </button>
                        </div>
                        <div id="phpclaw-input-meta">
                            <?= esc_html($provLabel) ?> · <?= esc_html($modelLabel) ?> · Responses are AI-generated. Verify before acting.
                        </div>
                    </div>
                </div>

            </div>

            <?php AdminFooter::renderCommunityCard(); ?>

        </div>

        <?php
    }

    /**
     * Render a single chat message bubble for user or assistant roles.
     *
     * @param  string  $role  The message role: 'user' or 'assistant'.
     * @param  string  $content  The message text content.
     * @return void
     */
    private static function renderBubbleHtml(string $role, string $content): void
    {
        if ($role === 'user') {
            ?>
            <div class="phpclaw-bubble-wrap user">
                <div class="phpclaw-bubble user"><?= esc_html($content) ?></div>
            </div>
            <?php
        } else {
            ?>
            <div class="phpclaw-bubble-wrap assistant">
                <div class="phpclaw-avatar" aria-hidden="true">🤖</div>
                <div class="phpclaw-bubble assistant"><?= esc_html($content) ?></div>
            </div>
            <?php
        }
    }

    /**
     * Format a provider key into a human-readable display label.
     *
     * @param  string  $provider  The provider key (e.g. 'anthropic', 'openai').
     * @return string
     */
    private static function fmtProvider(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'anthropic' => 'Anthropic',
            'openai' => 'OpenAI',
            'groq' => 'Groq',
            'gemini' => 'Google Gemini',
            'mistral' => 'Mistral AI',
            'ollama' => 'Ollama',
            'deepseek' => 'DeepSeek',
            'custom' => 'Custom (OpenAI-compatible)',
            '' => 'Not configured',
            default => ucwords(str_replace(['-', '_'], ' ', $provider)),
        };
    }

    /**
     * Convert a UTC datetime string into a human-readable relative time label.
     *
     * @param  string  $datetime  A datetime string stored as gmdate() UTC.
     * @return string
     */
    private static function relativeTime(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }

        $ts = (int) strtotime($datetime.' UTC');
        $diff = time() - $ts;

        return match (true) {
            $diff < 60 => 'just now',
            $diff < 3600 => (int) ($diff / 60).'m ago',
            $diff < 86400 => (int) ($diff / 3600).'h ago',
            $diff < 604800 => (int) ($diff / 86400).'d ago',
            default => gmdate('M j', $ts),
        };
    }
}
