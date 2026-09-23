<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

/**
 * Shows a 3-step onboarding card when phpClaw is installed but not yet configured.
 */
final class AdminNotice
{
    private const DISMISS_OPTION = 'phpclaw_notice_dismissed';

    private const SETTINGS_OPTION = 'phpclaw_settings';

    /**
     * Register admin_notices and AJAX dismiss action hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('wp_ajax_phpclaw_dismiss_notice', [self::class, 'handleDismiss']);
    }

    /**
     * Render the onboarding admin notice for users holding phpclaw_use_admin_chat, when phpClaw is
     * installed but not yet configured.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can('phpclaw_use_admin_chat')) {
            return;
        }

        $saved = (array) get_option(self::SETTINGS_OPTION, []);
        if (! empty($saved['provider'])) {
            return;
        }

        if (get_option(self::DISMISS_OPTION)) {
            return;
        }

        wp_enqueue_style(
            'phpclaw-admin',
            plugins_url('resources/admin/css/phpclaw-admin.css', PHPCLAW_PLUGIN_FILE),
            [],
            defined('PHPCLAW_VERSION') ? PHPCLAW_VERSION : '0.0.0',
        );

        $settingsUrl = admin_url('admin.php?page=phpclaw');
        $docsUrl = 'https://phpclaw.ai/docs';
        $nonce = wp_create_nonce('phpclaw_dismiss_notice');

        $version = defined('PHPCLAW_VERSION') ? PHPCLAW_VERSION : '0.0.0';
        wp_register_script('phpclaw-notice', false, [], $version, true);
        wp_enqueue_script('phpclaw-notice');
        wp_add_inline_script(
            'phpclaw-notice',
            'var phpClawNoticeData = '.wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => $nonce,
            ]).';',
            'before',
        );
        ?>
        <div id="phpclaw-setup-notice" class="pc-onboarding">
            <div class="pc-onboarding-inner">

                <div class="pc-onboarding-header">
                    <strong class="pc-onboarding-title">
                        🤖 <?= esc_html__('phpClaw is installed. 3 steps to get started', 'phpclaw') ?>
                    </strong>
                    <button type="button" id="phpclaw-dismiss-btn"
                        class="pc-onboarding-dismiss"
                        title="<?= esc_attr__('Dismiss this notice', 'phpclaw') ?>">&#x2715;</button>
                </div>

                <div class="pc-onboarding-steps">

                    <div class="pc-onboarding-step">
                        <div class="pc-onboarding-step-num">1</div>
                        <div class="pc-onboarding-step-title">
                            <?= esc_html__('Choose a provider', 'phpclaw') ?>
                        </div>
                        <div class="pc-onboarding-step-desc">
                            <?= esc_html__('Anthropic Claude, OpenAI GPT, Groq, Google Gemini, Mistral AI, Ollama (local, no API key), and more.', 'phpclaw') ?>
                        </div>
                    </div>

                    <div class="pc-onboarding-step">
                        <div class="pc-onboarding-step-num">2</div>
                        <div class="pc-onboarding-step-title">
                            <?= esc_html__('Enter your credentials', 'phpclaw') ?>
                        </div>
                        <div class="pc-onboarding-step-desc">
                            <?= esc_html__('Paste your API key from your provider\'s dashboard. No API key needed for Ollama, just select it as your provider.', 'phpclaw') ?>
                        </div>
                    </div>

                    <div class="pc-onboarding-step">
                        <div class="pc-onboarding-step-num">3</div>
                        <div class="pc-onboarding-step-title">
                            <?= esc_html__('Save & Test Connection', 'phpclaw') ?>
                        </div>
                        <div class="pc-onboarding-step-desc">
                            <?= esc_html__('Save your settings, then click Test Connection to confirm everything is working.', 'phpclaw') ?>
                        </div>
                    </div>

                </div>

                <div class="pc-onboarding-footer">
                    <a href="<?= esc_url($settingsUrl) ?>" class="button button-primary">
                        <?= esc_html__('Open Settings →', 'phpclaw') ?>
                    </a>
                    <a href="<?= esc_url($docsUrl) ?>" target="_blank" rel="noopener noreferrer" class="pc-onboarding-docs">
                        <?= esc_html__('Read the docs', 'phpclaw') ?>
                    </a>
                </div>

            </div>
        </div>

        <script>
        (function () {
            var btn    = document.getElementById('phpclaw-dismiss-btn');
            var notice = document.getElementById('phpclaw-setup-notice');
            if (! btn || ! notice) { return; }

            var data = (typeof phpClawNoticeData !== 'undefined') ? phpClawNoticeData : {};

            function dismiss() {
                notice.style.display = 'none';
                fetch(data.ajaxUrl || '', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers:     {'Content-Type': 'application/x-www-form-urlencoded'},
                    body:        'action=phpclaw_dismiss_notice&nonce=' + encodeURIComponent(data.nonce || ''),
                });
            }

            btn.addEventListener('click', dismiss);
        })();
        </script>
        <?php
    }

    /**
     * Handle the AJAX request to permanently dismiss the onboarding notice.
     *
     * @return void
     */
    public static function handleDismiss(): void
    {
        check_ajax_referer('phpclaw_dismiss_notice', 'nonce');

        if (current_user_can('phpclaw_use_admin_chat')) {
            update_option(self::DISMISS_OPTION, '1', autoload: false);
        }

        wp_die();
    }
}
