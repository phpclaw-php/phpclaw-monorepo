<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

/**
 * Admin notice shown when phpClaw is not configured.
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
        if (($saved['provider'] ?? '') === 'ollama') {
            return false;
        }

        if (! empty($saved['api_key'])) {
            return false;
        }

        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GROQ_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY', 'PHPCLAW_API_KEY'] as $name) {
            if (defined($name) && constant($name) !== '') {
                return false;
            }
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
