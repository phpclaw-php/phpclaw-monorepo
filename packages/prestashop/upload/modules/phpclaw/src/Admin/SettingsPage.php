<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

use Configuration;
use PhpClaw\ClawConfig;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Tools\ToolRegistry;

/**
 * Settings page helper: field definitions, validation, and default merging for the admin settings form.
 */
final class SettingsPage
{
    private const PROVIDER_CUSTOM = 'custom';

    private const MAX_RATE_LIMIT_RPM = 600;

    private const MIN_CACHE_TTL = 60;

    private const MAX_CACHE_TTL = 86400;

    private const DEFAULT_CACHE_TTL = 3600;

    private const MAX_TOKEN_BUDGET = 10000000;

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

        $fallback = trim((string) ($post['fallback_provider'] ?? ''));
        if ($fallback !== '' && ! array_key_exists($fallback, self::fallbackProviders($data['provider']))) {
            $errors['fallback_provider'] = 'Fallback provider must use the same tool format as the primary provider, and cannot be Custom.';
            $fallback = '';
        }
        $data['fallback_provider'] = $fallback;
        $data['fallback_model'] = trim((string) ($post['fallback_model'] ?? ''));
        $data['fallback_api_key'] = self::preserveSecret('PHPCLAW_FALLBACK_API_KEY', $post['fallback_api_key'] ?? '');
        $data['rate_limit_rpm'] = self::clamp($post['rate_limit_rpm'] ?? 0, 0, self::MAX_RATE_LIMIT_RPM);
        $data['response_cache'] = ! empty($post['response_cache']) ? '1' : '0';
        $ttl = trim((string) ($post['response_cache_ttl'] ?? ''));
        $data['response_cache_ttl'] = $ttl === '' ? self::DEFAULT_CACHE_TTL : self::clamp($ttl, self::MIN_CACHE_TTL, self::MAX_CACHE_TTL);
        $data['max_token_budget'] = self::clamp($post['max_token_budget'] ?? 0, 0, self::MAX_TOKEN_BUDGET);

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
            'fallback_provider' => (string) ($saved['fallback_provider'] ?? ''),
            'fallback_model' => (string) ($saved['fallback_model'] ?? ''),
            'fallback_api_key' => (string) ($saved['fallback_api_key'] ?? ''),
            'rate_limit_rpm' => (int) ($saved['rate_limit_rpm'] ?? 0),
            'response_cache' => (bool) ($saved['response_cache'] ?? false),
            'response_cache_ttl' => (int) (($saved['response_cache_ttl'] ?? 0) ?: self::DEFAULT_CACHE_TTL),
            'max_token_budget' => (int) ($saved['max_token_budget'] ?? 0),
        ];
    }

    /**
     * Fallback provider dropdown options: an "Off" entry plus every provider except Custom that shares the primary's tool format.
     *
     * @param  string  $primaryProvider  The primary provider slug, or '' when it is auto-detected.
     * @return array<string, string> slug => display label.
     */
    public static function fallbackProviders(string $primaryProvider): array
    {
        $data = self::fallbackScriptData();
        $primaryFormat = $primaryProvider !== '' ? ToolRegistry::toolFormat($primaryProvider) : $data['autoFormat'];
        $options = ['' => $data['offLabel']];

        foreach ($data['providers'] as $slug => $label) {
            if ($data['formats'][$slug] === $primaryFormat) {
                $options[$slug] = $label;
            }
        }

        return $options;
    }

    /**
     * Data the settings page script needs to rebuild the fallback dropdown when the primary provider changes: every
     * provider except Custom, every provider's tool format, and the format of the auto-detected primary.
     *
     * @return array{offLabel: string, providers: array<string, string>, formats: array<string, string>, autoFormat: string}
     */
    public static function fallbackScriptData(): array
    {
        $providers = [];
        $formats = [];

        foreach (self::providers() as $slug => $label) {
            $slug = (string) $slug;
            if ($slug === '') {
                continue;
            }
            $formats[$slug] = ToolRegistry::toolFormat($slug);
            if ($slug !== self::PROVIDER_CUSTOM) {
                $providers[$slug] = $label;
            }
        }

        return [
            'offLabel' => 'Off',
            'providers' => $providers,
            'formats' => $formats,
            'autoFormat' => ToolRegistry::toolFormat((new ClawConfig)->providerName),
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
     * Read a submitted number as an integer held inside the given bounds.
     *
     * @param  mixed  $raw  Raw submitted value.
     * @param  int  $min  Lowest allowed value.
     * @param  int  $max  Highest allowed value.
     * @return int
     */
    private static function clamp(mixed $raw, int $min, int $max): int
    {
        return max($min, min($max, (int) $raw));
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
