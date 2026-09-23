<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

use Configuration;
use PhpClaw\ClawConfig;
use PhpClaw\Providers\ProviderCatalogue;

/**
 * Settings page helper: field definitions, validation, and default merging for the admin settings form.
 */
final class SettingsPage
{
    /**
     * Supported AI providers for the provider dropdown.
     *
     * @return array<string, string> slug => display label.
     */
    public static function providers(): array
    {
        if (! class_exists(ProviderCatalogue::class)) {
            return [
                'anthropic' => 'Anthropic (Claude)',
                'openai' => 'OpenAI (GPT)',
                'groq' => 'Groq (Llama)',
                'gemini' => 'Google Gemini',
                'ollama' => 'Ollama (local)',
                'deepseek' => 'DeepSeek',
                'mistral' => 'Mistral',
                'custom' => 'Custom (OpenAI-compatible)',
            ];
        }

        $out = [];
        foreach (ProviderCatalogue::all() as $slug => $info) {
            $out[(string) $slug] = (string) $info['label'];
        }

        return $out;
    }

    /**
     * Validate and sanitize raw POST data.
     *
     * @param  array<string, mixed>  $post
     * @return array{errors: array<string, string>, data: array<string, mixed>}
     */
    public static function validate(array $post): array
    {
        $errors = [];
        $data = [];

        $data['provider'] = trim((string) ($post['provider'] ?? ''));
        $data['model'] = trim((string) ($post['model'] ?? ''));
        $data['api_key'] = self::preserveSecret('PHPCLAW_API_KEY', $post['api_key'] ?? '');
        $data['system_prompt'] = mb_substr(trim((string) ($post['system_prompt'] ?? '')), 0, 8000);

        $maxIter = (int) ($post['max_iterations'] ?? ClawConfig::DEFAULT_MAX_ITERATIONS);
        if ($maxIter < 1 || $maxIter > 50) {
            $errors['max_iterations'] = 'Max iterations must be between 1 and 50.';
            $maxIter = 10;
        }
        $data['max_iterations'] = $maxIter;

        $data['store_messages'] = ! empty($post['store_messages']) ? '1' : '0';

        $baseUrl = trim((string) ($post['base_url'] ?? ''));
        if ($baseUrl !== '' && ! preg_match('~^https?://~i', $baseUrl)) {
            $errors['base_url'] = 'Base URL must be a valid http:// or https:// URL.';
            $baseUrl = '';
        }
        $data['base_url'] = $baseUrl;

        $data['cloud_key'] = self::preserveSecret('PHPCLAW_CLOUD_KEY', $post['cloud_key'] ?? '');
        $data['cloud_signing_secret'] = self::preserveSecret('PHPCLAW_CLOUD_SIGNING_SECRET', $post['cloud_signing_secret'] ?? '');

        $rawDisable = $post['cloud_disable'] ?? '';
        $disableItems = is_array($rawDisable) ? $rawDisable : explode(',', (string) $rawDisable);
        $data['cloud_disable'] = array_values(array_filter(
            array_map(static fn ($s): string => trim((string) $s), $disableItems),
            static fn (string $s): bool => $s !== ''
        ));

        $data['remote_skill_urls'] = self::filterRemoteSkillUrls($post['remote_skill_urls'] ?? '');

        return ['errors' => $errors, 'data' => $data];
    }

    /**
     * Merge saved settings with config defaults.
     *
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function merge(array $saved, array $config): array
    {
        return [
            'provider' => (string) (($saved['provider'] ?? '') ?: ($config['provider'] ?? '')),
            'model' => (string) (($saved['model'] ?? '') ?: ($config['model'] ?? '')),
            'api_key' => (string) (($saved['api_key'] ?? '') ?: ($config['api_key'] ?? '')),
            'max_iterations' => (int) (($saved['max_iterations'] ?? 0) ?: ($config['max_iterations'] ?? ClawConfig::DEFAULT_MAX_ITERATIONS)),
            'store_messages' => (bool) ($saved['store_messages'] ?? ($config['store_messages'] ?? true)),
            'base_url' => (string) ($saved['base_url'] ?? ''),
            'system_prompt' => (string) ($saved['system_prompt'] ?? ''),
            'cloud_key' => (string) ($saved['cloud_key'] ?? ''),
            'cloud_signing_secret' => (string) ($saved['cloud_signing_secret'] ?? ''),
            'cloud_disable' => array_map('strval', (array) ($saved['cloud_disable'] ?? [])),
            'remote_skill_urls' => array_map('strval', (array) ($saved['remote_skill_urls'] ?? [])),
        ];
    }

    /**
     * Filter a raw remote-skill-URL list to valid HTTPS entries whose path ends in .md or .json.
     *
     * @param  mixed  $raw  Raw value: an array of URLs or a newline-separated string.
     * @return list<string> Sanitised HTTPS .md/.json URLs.
     */
    public static function filterRemoteSkillUrls(mixed $raw): array
    {
        $lines = is_array($raw) ? $raw : (preg_split('/[\r\n]+/', (string) $raw) ?: []);

        $valid = array_values(array_filter(
            array_map(static fn ($u): string => trim((string) $u), $lines),
            static function (string $u): bool {
                if ($u === '' || ! preg_match('~^https://~i', $u)) {
                    return false;
                }

                $path = (string) parse_url($u, PHP_URL_PATH);

                return (bool) preg_match('~\.(md|json)$~i', $path);
            }
        ));

        return array_slice($valid, 0, 50);
    }

    /**
     * Keep the stored secret when the form submits the field blank.
     *
     * @param  string  $configKey  PrestaShop Configuration key holding the secret.
     * @param  mixed  $submitted  Raw submitted value.
     * @return string The submitted secret, or the stored one when nothing was submitted.
     */
    private static function preserveSecret(string $configKey, mixed $submitted): string
    {
        $value = trim((string) $submitted);

        if ($value !== '') {
            return $value;
        }

        return (string) Configuration::get($configKey);
    }
}
