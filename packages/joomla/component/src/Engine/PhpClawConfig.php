<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Engine;

use Joomla\Registry\Registry;
use PhpClaw\ClawConfig;
use PhpClaw\Config\ToolConfig;

/**
 * Typed configuration value object for the phpClaw Joomla adapter.
 */
final class PhpClawConfig
{
    private const MAX_RATE_LIMIT_RPM = 600;

    private const DEFAULT_RESPONSE_CACHE_TTL = 3600;

    private const MIN_RESPONSE_CACHE_TTL = 60;

    private const MAX_RESPONSE_CACHE_TTL = 86400;

    private const MAX_TOKEN_BUDGET = 10_000_000;

    /**
     * Create a new PhpClawConfig instance.
     *
     * @param  string  $provider  LLM provider slug (anthropic, openai, ollama, …)
     * @param  string  $model  Model identifier
     * @param  string  $apiKey  Provider API key (empty for Ollama)
     * @param  string  $baseUrl  Custom-provider OpenAI-compatible endpoint (empty unless provider=custom)
     * @param  bool  $storeMessages  Whether conversation history is persisted
     * @param  string  $systemPrompt  Optional system-level prompt injected before user input
     * @param  int  $maxIterations  Agent reasoning loop cap
     * @param  string[]  $shellAllowlist  Commands the ShellTool may execute
     * @param  string  $cloudKey  phpClaw Cloud ingestion key (empty = cloud disabled)
     * @param  string  $cloudSigningSecret  Shared secret for verifying signed cloud scan responses (empty = skip)
     * @param  string[]  $cloudDisable  Cloud feature slugs to suppress
     * @param  string[]  $remoteSkillUrls  Remote skill collection URLs (HTTPS .md/.json) loaded at build
     * @param  string  $guards  JSON-encoded guard config list
     * @param  string  $hooks  JSON-encoded hook config list
     * @param  string  $skills  JSON-encoded inline skill config list
     * @param  string[]  $toolDeny  Tool names or group references to exclude from the registry (developer-only, no Settings-page field)
     * @param  string  $fallbackProvider  Provider slug tried when the primary fails; empty turns fallback off
     * @param  string  $fallbackModel  Fallback provider model (empty = the provider's default)
     * @param  string  $fallbackApiKey  Fallback provider API key (empty = the provider's env key)
     * @param  int  $rateLimitRpm  Outbound requests per minute, 0-600; 0 turns the limit off
     * @param  bool  $responseCache  Whether identical requests are answered from the cache
     * @param  int  $responseCacheTtl  Response cache lifetime in seconds, 60-86400
     * @param  int  $maxTokenBudget  Token spend ceiling per run, 0-10000000; 0 turns the budget off
     */
    public function __construct(
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly string $apiKey = '',
        public readonly string $baseUrl = '',
        public readonly bool $storeMessages = true,
        public readonly string $systemPrompt = '',
        public readonly int $maxIterations = ClawConfig::DEFAULT_MAX_ITERATIONS,
        public readonly array $shellAllowlist = [],
        public readonly string $cloudKey = '',
        public readonly string $cloudSigningSecret = '',
        public readonly array $cloudDisable = [],
        public readonly array $remoteSkillUrls = [],
        public readonly string $guards = '[]',
        public readonly string $hooks = '[]',
        public readonly string $skills = '[]',
        public readonly array $toolDeny = [],
        public readonly string $fallbackProvider = '',
        public readonly string $fallbackModel = '',
        public readonly string $fallbackApiKey = '',
        public readonly int $rateLimitRpm = 0,
        public readonly bool $responseCache = false,
        public readonly int $responseCacheTtl = self::DEFAULT_RESPONSE_CACHE_TTL,
        public readonly int $maxTokenBudget = 0,
    ) {}

    /**
     * Construct from a Joomla plugin params Registry.
     *
     * @param  Registry  $params
     * @return self
     */
    public static function fromRegistry(Registry $params): self
    {
        $rawAllowlist = (string) $params->get('shell_allowlist', '');
        $shellAllowlist = array_values(array_filter(
            array_map('trim', explode(',', $rawAllowlist)),
            static fn (string $s): bool => $s !== '',
        ));
        if ($shellAllowlist === []) {
            $shellAllowlist = ToolConfig::DEFAULT_SHELL_ALLOWLIST;
        }

        $rawToolDeny = (string) $params->get('tool_deny', '');
        $toolDeny = array_values(array_filter(
            array_map('trim', explode(',', $rawToolDeny)),
            static fn (string $s): bool => $s !== '',
        ));

        $rawDisable = $params->get('cloud_disable', '');
        $cloudDisable = is_array($rawDisable)
            ? array_values(array_filter(
                array_map(static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $rawDisable),
                static fn (string $v): bool => $v !== '',
            ))
            : array_values(array_filter(
                array_map('trim', explode(',', (string) $rawDisable)),
                static fn (string $s): bool => $s !== '',
            ));

        $baseUrl = (string) $params->get('base_url', '');
        $rawTtl = $params->get('response_cache_ttl', '');
        $ttl = $rawTtl === '' || $rawTtl === null ? self::DEFAULT_RESPONSE_CACHE_TTL : (int) $rawTtl;

        return new self(
            provider: (string) $params->get('provider', ''),
            model: (string) $params->get('model', ''),
            apiKey: self::resolveApiKey($params),
            baseUrl: self::isAllowedProviderUrl($baseUrl) ? $baseUrl : '',
            storeMessages: (bool) $params->get('store_messages', true),
            systemPrompt: mb_substr((string) $params->get('system_prompt', ''), 0, 8000),
            maxIterations: max(1, (int) $params->get('max_iterations', ClawConfig::DEFAULT_MAX_ITERATIONS)),
            shellAllowlist: $shellAllowlist,
            cloudKey: (string) $params->get('cloud_key', ''),
            cloudSigningSecret: (string) $params->get('cloud_signing_secret', ''),
            cloudDisable: $cloudDisable,
            remoteSkillUrls: self::filterRemoteSkillUrls($params->get('remote_skill_urls', '')),
            guards: (string) $params->get('guards', '[]'),
            hooks: (string) $params->get('hooks', '[]'),
            skills: (string) $params->get('skills', '[]'),
            toolDeny: $toolDeny,
            fallbackProvider: (string) $params->get('fallback_provider', ''),
            fallbackModel: (string) $params->get('fallback_model', ''),
            fallbackApiKey: (string) $params->get('fallback_api_key', ''),
            rateLimitRpm: max(0, min(self::MAX_RATE_LIMIT_RPM, (int) $params->get('rate_limit_rpm', 0))),
            responseCache: (string) $params->get('response_cache', '0') === '1',
            responseCacheTtl: max(self::MIN_RESPONSE_CACHE_TTL, min(self::MAX_RESPONSE_CACHE_TTL, $ttl)),
            maxTokenBudget: max(0, min(self::MAX_TOKEN_BUDGET, (int) $params->get('max_token_budget', 0))),
        );
    }

    /**
     * Whether a custom-provider base_url is allowed: HTTPS anywhere, plain HTTP only for a loopback host.
     *
     * @param  string  $baseUrl  Trimmed custom-provider endpoint URL.
     * @return bool True for https URLs and for http URLs targeting localhost/127.0.0.1/::1.
     */
    public static function isAllowedProviderUrl(string $baseUrl): bool
    {
        if (preg_match('~^https://~i', $baseUrl) === 1) {
            return true;
        }

        if (preg_match('~^http://~i', $baseUrl) !== 1) {
            return false;
        }

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $host = trim($host, '[]');

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Filter a raw remote-skill-URL value to valid HTTPS entries whose path ends in .md or .json.
     *
     * @param  mixed  $raw  Raw value (array or newline-separated string) from settings or import.
     * @return string[] Sanitised HTTPS .md/.json URLs.
     */
    public static function filterRemoteSkillUrls(mixed $raw): array
    {
        $lines = is_array($raw) ? $raw : (preg_split('/[\r\n]+/', (string) $raw) ?: []);

        $valid = array_values(array_filter(
            array_map(static fn (mixed $u): string => trim((string) $u), $lines),
            static function (string $u): bool {
                if ($u === '' || preg_match('~^https://~i', $u) !== 1) {
                    return false;
                }

                $path = (string) parse_url($u, PHP_URL_PATH);

                return preg_match('~\.(md|json)$~i', $path) === 1;
            },
        ));

        return array_slice($valid, 0, 50);
    }

    /**
     * Resolve the API key from the plugin params.
     *
     * @param  Registry  $params
     * @return string
     */
    private static function resolveApiKey(Registry $params): string
    {
        $key = (string) $params->get('api_key', '');
        if ($key !== '') {
            return $key;
        }

        foreach (['anthropic_api_key', 'openai_api_key', 'groq_api_key', 'gemini_api_key', 'mistral_api_key', 'deepseek_api_key'] as $legacyKey) {
            $v = (string) $params->get($legacyKey, '');
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
