<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

/**
 * Adds a phpClaw summary widget to the WordPress dashboard.
 */
final class DashboardWidget
{
    private const WIDGET_ID = 'phpclaw_dashboard_widget';

    private const SETTINGS_URL = 'admin.php?page=phpclaw';

    private const CHAT_URL = 'admin.php?page=phpclaw-chat';

    /**
     * Register the dashboard widget hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'addWidget']);
    }

    /**
     * Register the widget with WordPress, for users holding phpclaw_use_admin_chat only.
     *
     * @return void
     */
    public static function addWidget(): void
    {
        if (! current_user_can('phpclaw_use_admin_chat')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('phpClaw AI Agent', 'phpclaw'),
            [self::class, 'render'],
        );
    }

    /**
     * Render the dashboard widget HTML.
     *
     * @return void
     */
    public static function render(): void
    {
        $saved = (array) get_option('phpclaw_settings', []);
        $provider = (string) ($saved['provider'] ?? '');
        $model = (string) ($saved['model'] ?? '');
        $apiKey = (string) ($saved['api_key'] ?? '');
        $isOllama = $provider === 'ollama';
        $isReady = $isOllama || $apiKey !== '';

        [$conversations, $messages] = self::getCounts();

        $settingsUrl = admin_url(self::SETTINGS_URL);
        $chatUrl = admin_url(self::CHAT_URL);
        ?>
        <div class="pc-widget">

            <?php if ($isReady) { ?>
                <div class="pc-widget-status pc-widget-status--ok">
                    <span class="pc-widget-dot pc-widget-dot--green"></span>
                    <?= esc_html__('Connected', 'phpclaw') ?>
                </div>
            <?php } else { ?>
                <div class="pc-widget-status pc-widget-status--warn">
                    <span class="pc-widget-dot pc-widget-dot--yellow"></span>
                    <?= esc_html__('Not configured', 'phpclaw') ?>
                </div>
            <?php } ?>

            <?php if ($provider !== '') { ?>
                <div class="pc-widget-provider">
                    <span class="pc-widget-label"><?= esc_html__('Provider', 'phpclaw') ?></span>
                    <span class="pc-widget-value"><?= esc_html(ucfirst($provider)) ?><?= $model !== '' ? ' &middot; '.esc_html($model) : '' ?></span>
                </div>
            <?php } ?>

            <div class="pc-widget-stats">
                <div class="pc-widget-stat">
                    <div class="pc-widget-stat-num"><?= esc_html((string) $conversations) ?></div>
                    <div class="pc-widget-stat-lbl"><?= esc_html__('Conversations', 'phpclaw') ?></div>
                </div>
                <div class="pc-widget-stat">
                    <div class="pc-widget-stat-num"><?= esc_html((string) $messages) ?></div>
                    <div class="pc-widget-stat-lbl"><?= esc_html__('Messages', 'phpclaw') ?></div>
                </div>
            </div>

            <div class="pc-widget-actions">
                <?php if ($isReady) { ?>
                    <a href="<?= esc_url($chatUrl) ?>" class="button button-primary pc-widget-btn">
                        <?= esc_html__('Open AI Chat', 'phpclaw') ?>
                    </a>
                <?php } ?>
                <a href="<?= esc_url($settingsUrl) ?>" class="button pc-widget-btn">
                    <?= esc_html($isReady ? __('Settings', 'phpclaw') : __('Configure phpClaw →', 'phpclaw')) ?>
                </a>
            </div>

        </div>
        <?php
    }

    /**
     * Return [conversation_count, message_count] from the DB.
     *
     * @return array{int, int}
     */
    private static function getCounts(): array
    {
        $cached = wp_cache_get('dashboard_counts', 'phpclaw');
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $wpdb->suppress_errors(true);

        $convTable = $wpdb->prefix.'phpclaw_conversations';
        $msgTable = $wpdb->prefix.'phpclaw_messages';

        $conversations = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $convTable)
        );

        $messages = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $msgTable)
        );

        $wpdb->suppress_errors(false);

        $result = [$conversations, $messages];
        wp_cache_set('dashboard_counts', $result, 'phpclaw', 300);

        return $result;
    }
}
