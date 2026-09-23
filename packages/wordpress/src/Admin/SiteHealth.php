<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

use PhpClaw\WordPress\Plugin;

/**
 * Integrates phpClaw status into WordPress Site Health (Tools → Site Health).
 */
final class SiteHealth
{
    /**
     * Register the phpClaw connection check with WordPress Site Health.
     *
     * @return void
     */
    public static function register(): void
    {
        add_filter('site_status_tests', [self::class, 'addTests']);
    }

    /**
     * Add the phpClaw connection test to the Site Health test array.
     *
     * @param  array<string, mixed>  $tests  WordPress Site Health tests array.
     * @return array<string, mixed>
     */
    public static function addTests(array $tests): array
    {
        $tests['direct']['phpclaw_connection'] = [
            'label' => __('phpClaw AI Engine', 'phpclaw'),
            'test' => [self::class, 'testConnection'],
        ];

        return $tests;
    }

    /**
     * Run the phpClaw connection health check and return a Site Health result.
     *
     * @return array<string, mixed>
     */
    public static function testConnection(): array
    {
        $saved = (array) get_option('phpclaw_settings', []);
        $provider = ($saved['provider'] ?? '') ?: 'auto-detect';
        $model = ($saved['model'] ?? '') ?: 'provider default';
        $apiKey = ($saved['api_key'] ?? '');
        $isOllama = $provider === 'ollama';
        $configured = $isOllama || $apiKey !== '';

        $settingsUrl = admin_url('admin.php?page=phpclaw');

        if (! $configured) {
            return [
                'label' => __('phpClaw is not configured', 'phpclaw'),
                'status' => 'recommended',
                'badge' => ['label' => 'phpClaw', 'color' => 'orange'],
                'description' => sprintf(
                    '<p>%s <a href="%s">%s</a></p>',
                    esc_html__('No API key is set. phpClaw cannot send prompts to any AI provider.', 'phpclaw'),
                    esc_url($settingsUrl),
                    esc_html__('Configure phpClaw', 'phpclaw'),
                ),
                'actions' => sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url($settingsUrl),
                    esc_html__('Open Settings', 'phpclaw'),
                ),
                'test' => 'phpclaw_connection',
            ];
        }

        try {
            Plugin::getInstance()->engine();
            $engineOk = true;
        } catch (\Throwable $e) {
            $engineOk = false;
            error_log('phpClaw SiteHealth: engine failed: '.$e->getMessage());
        }

        if (! $engineOk) {
            return [
                'label' => __('phpClaw engine failed to initialise', 'phpclaw'),
                'status' => 'critical',
                'badge' => ['label' => 'phpClaw', 'color' => 'red'],
                'description' => sprintf(
                    '<p>%s</p>',
                    esc_html__('phpClaw is configured but the engine could not start. Check your API Key and provider settings.', 'phpclaw'),
                ),
                'actions' => sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url($settingsUrl),
                    esc_html__('Open Settings', 'phpclaw'),
                ),
                'test' => 'phpclaw_connection',
            ];
        }

        return [
            'label' => __('phpClaw is configured and ready', 'phpclaw'),
            'status' => 'good',
            'badge' => ['label' => 'phpClaw', 'color' => 'green'],
            'description' => sprintf(
                '<p>%s</p><ul><li>Provider: <strong>%s</strong></li><li>Model: <strong>%s</strong></li></ul>',
                esc_html__('phpClaw AI agent engine is active and connected.', 'phpclaw'),
                esc_html($provider),
                esc_html($model),
            ),
            'actions' => '',
            'test' => 'phpclaw_connection',
        ];
    }
}
