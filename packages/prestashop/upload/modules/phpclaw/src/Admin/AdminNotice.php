<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

/**
 * Admin notice data builder for the phpClaw setup reminder shown when the module is unconfigured.
 */
final class AdminNotice
{
    /**
     * Whether to show the "not configured" notice.
     *
     * @param  array<string, mixed>  $saved  Admin-saved settings.
     * @return bool
     */
    public static function shouldShow(array $saved): bool
    {
        if (trim((string) ($saved['model'] ?? '')) === '') {
            return true;
        }

        if (trim((string) ($saved['api_key'] ?? '')) !== '') {
            return false;
        }

        if ((string) ($saved['provider'] ?? '') === 'ollama') {
            return false;
        }

        return true;
    }

    /**
     * Return notice data for the admin template.
     *
     * @param  string  $settingsUrl  URL to the phpClaw settings page.
     * @return array{message: string, url: string, label: string}
     */
    public static function data(string $settingsUrl): array
    {
        return [
            'message' => 'phpClaw is not configured. Set your AI provider and API key to start using AI agents in your store.',
            'url' => $settingsUrl,
            'label' => 'Configure phpClaw',
        ];
    }
}
