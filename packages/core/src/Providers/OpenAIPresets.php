<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Config\EnvVars;

/**
 * Pure-data catalog of OpenAI-compatible providers sharing the v1/chat/completions wire format.
 */
final class OpenAIPresets
{
    public const AUTH_BEARER = 'bearer';

    public const AUTH_NONE = 'none';

    /**
     * Every OpenAI-compatible preset, in auto-detection priority order.
     *
     * @return array<string, array{label: string, baseUrl: string, model: string, auth: string, keyEnv: string}>
     */
    public static function all(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI',
                'baseUrl' => 'https://api.openai.com/v1/chat/completions',
                'model' => 'gpt-4o-mini',
                'auth' => self::AUTH_BEARER,
                'keyEnv' => EnvVars::OPENAI_API_KEY,
            ],
            'groq' => [
                'label' => 'Groq',
                'baseUrl' => 'https://api.groq.com/openai/v1/chat/completions',
                'model' => 'llama-3.1-8b-instant',
                'auth' => self::AUTH_BEARER,
                'keyEnv' => EnvVars::GROQ_API_KEY,
            ],
            'deepseek' => [
                'label' => 'DeepSeek',
                'baseUrl' => 'https://api.deepseek.com/v1/chat/completions',
                'model' => 'deepseek-flash',
                'auth' => self::AUTH_BEARER,
                'keyEnv' => EnvVars::DEEPSEEK_API_KEY,
            ],
            'mistral' => [
                'label' => 'Mistral',
                'baseUrl' => 'https://api.mistral.ai/v1/chat/completions',
                'model' => 'mistral-small-latest',
                'auth' => self::AUTH_BEARER,
                'keyEnv' => EnvVars::MISTRAL_API_KEY,
            ],
            'ollama' => [
                'label' => 'Ollama (Local)',
                'baseUrl' => 'http://127.0.0.1:11434/v1/chat/completions',
                'model' => 'qwen2.5:7b',
                'auth' => self::AUTH_NONE,
                'keyEnv' => '',
            ],
            'custom' => [
                'label' => 'Custom (OpenAI-compatible)',
                'baseUrl' => '',
                'model' => '',
                'auth' => self::AUTH_BEARER,
                'keyEnv' => EnvVars::OPENAI_API_KEY,
            ],
        ];
    }

    /**
     * Whether a preset is registered under the given slug.
     *
     * @param  string  $slug  Provider slug to look up.
     * @return bool
     */
    public static function has(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    /**
     * Look up a single preset row by slug.
     *
     * @param  string  $slug  Provider slug.
     * @return array{label: string, baseUrl: string, model: string, auth: string, keyEnv: string}|null
     */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
