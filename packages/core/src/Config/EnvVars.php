<?php

declare(strict_types=1);

namespace PhpClaw\Config;

/**
 * Catalog of every environment variable the phpClaw core reads.
 */
final class EnvVars
{
    public const PHPCLAW_PROVIDER = 'PHPCLAW_PROVIDER';

    public const PHPCLAW_MODEL = 'PHPCLAW_MODEL';

    public const ANTHROPIC_API_KEY = 'ANTHROPIC_API_KEY';

    public const OPENAI_API_KEY = 'OPENAI_API_KEY';

    public const GROQ_API_KEY = 'GROQ_API_KEY';

    public const GEMINI_API_KEY = 'GEMINI_API_KEY';

    public const MISTRAL_API_KEY = 'MISTRAL_API_KEY';

    public const DEEPSEEK_API_KEY = 'DEEPSEEK_API_KEY';

    public const OLLAMA_HOST = 'OLLAMA_HOST';

    public const OPENAI_BASE_URL = 'OPENAI_BASE_URL';

    public const PHPCLAW_CLOUD_SIGNING_SECRET = 'PHPCLAW_CLOUD_SIGNING_SECRET';

    public const PHPCLAW_HTTP_TIMEOUT = 'PHPCLAW_HTTP_TIMEOUT';

    public const PHPCLAW_HTTP_CONNECT_TIMEOUT = 'PHPCLAW_HTTP_CONNECT_TIMEOUT';

    public const PHPCLAW_CACHE_DIR = 'PHPCLAW_CACHE_DIR';

    /**
     * Return all env-var names this catalog tracks.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PHPCLAW_PROVIDER,
            self::PHPCLAW_MODEL,
            self::ANTHROPIC_API_KEY,
            self::OPENAI_API_KEY,
            self::GROQ_API_KEY,
            self::GEMINI_API_KEY,
            self::MISTRAL_API_KEY,
            self::DEEPSEEK_API_KEY,
            self::OLLAMA_HOST,
            self::OPENAI_BASE_URL,
            self::PHPCLAW_CLOUD_SIGNING_SECRET,
            self::PHPCLAW_HTTP_TIMEOUT,
            self::PHPCLAW_HTTP_CONNECT_TIMEOUT,
            self::PHPCLAW_CACHE_DIR,
        ];
    }

    /**
     * Read an environment variable from $_ENV, $_SERVER, or getenv() in that order.
     *
     * @param  string  $name  Environment variable name.
     * @param  string  $default  Value returned when the variable is unset or empty.
     * @return string Resolved value, or $default when not found.
     */
    public static function get(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? '';
        if ($value !== '') {
            return (string) $value;
        }

        $value = $_SERVER[$name] ?? '';
        if ($value !== '') {
            return (string) $value;
        }

        $value = getenv($name);

        return ($value !== false && $value !== '') ? $value : $default;
    }
}
