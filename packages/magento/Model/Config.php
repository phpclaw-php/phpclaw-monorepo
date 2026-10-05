<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PhpClaw\ClawConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Magento\Service\CsvList;

/**
 * Reads phpClaw configuration from Magento's system config store.
 */
// non-final: Magento interceptor required
class Config
{
    private const MEMORY_DRIVER = 'router';

    private const MAGENTO_SHELL_ALLOWLIST = [];

    private const XML_PATH_API_KEY = 'phpclaw/general/api_key';

    private const XML_PATH_BASE_URL = 'phpclaw/general/base_url';

    private const XML_PATH_PROVIDER = 'phpclaw/general/provider';

    private const XML_PATH_MODEL = 'phpclaw/general/model';

    private const XML_PATH_MAX_ITERATIONS = 'phpclaw/general/max_iterations';

    private const XML_PATH_STORE_MESSAGES = 'phpclaw/general/store_messages';

    private const XML_PATH_SHELL_ALLOWLIST = 'phpclaw/security/shell_allowlist';

    private const XML_PATH_TOOL_DENY = 'phpclaw/security/tool_deny';

    private const XML_PATH_CLOUD_KEY = 'phpclaw/general/cloud_key';

    private const XML_PATH_CLOUD_SIGNING_SECRET = 'phpclaw/general/cloud_signing_secret';

    private const XML_PATH_CLOUD_DISABLE = 'phpclaw/general/cloud_disable';

    private const XML_PATH_SYSTEM_PROMPT = 'phpclaw/general/system_prompt';

    private const XML_PATH_REMOTE_SKILL_URLS = 'phpclaw/general/remote_skill_urls';

    private const XML_PATH_FALLBACK_PROVIDER = 'phpclaw/general/fallback_provider';

    private const XML_PATH_FALLBACK_MODEL = 'phpclaw/general/fallback_model';

    private const XML_PATH_FALLBACK_API_KEY = 'phpclaw/general/fallback_api_key';

    private const XML_PATH_RATE_LIMIT_RPM = 'phpclaw/general/rate_limit_rpm';

    private const XML_PATH_RESPONSE_CACHE = 'phpclaw/general/response_cache';

    private const XML_PATH_RESPONSE_CACHE_TTL = 'phpclaw/general/response_cache_ttl';

    private const XML_PATH_MAX_TOKEN_BUDGET = 'phpclaw/general/max_token_budget';

    private const MAX_RATE_LIMIT_RPM = 600;

    private const MIN_CACHE_TTL = 60;

    private const MAX_CACHE_TTL = 86400;

    private const DEFAULT_CACHE_TTL = 3600;

    private const MAX_TOKEN_BUDGET = 10000000;

    /**
     * Bind Magento's config reader and encryptor this accessor resolves settings through.
     *
     * @param  ScopeConfigInterface  $scopeConfig  Magento's config reader for core_config_data values.
     * @param  EncryptorInterface  $encryptor  Magento's encryption service used to decrypt api_key and cloud_key.
     * @return void
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {}

    /**
     * Return the provider API key, decrypted from Magento's encrypted storage.
     *
     * @return string Plaintext API key, or empty string when not configured.
     */
    public function getApiKey(): string
    {
        return $this->decryptIfNeeded((string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY));
    }

    /**
     * Return the custom OpenAI-compatible base URL (e.g. "https://openrouter.ai/api/v1/chat/completions").
     *
     * @return string Configured base URL, or empty string when not set.
     */
    public function getBaseUrl(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_BASE_URL);
    }

    /**
     * Return the selected AI provider slug (e.g. "anthropic", "openai", "ollama").
     *
     * @return string Provider identifier as stored in core_config_data.
     */
    public function getProvider(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_PROVIDER);
    }

    /**
     * Return the model identifier for the selected provider (e.g. "claude-haiku-4-5-20251001").
     *
     * @return string Model identifier as stored in core_config_data.
     */
    public function getModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_MODEL);
    }

    /**
     * Return the maximum number of agentic iterations allowed per request.
     *
     * @return int Maximum iterations, at least 1.
     */
    public function getMaxIterations(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_MAX_ITERATIONS);
        if ($value === null || $value === '') {
            return ClawConfig::DEFAULT_MAX_ITERATIONS;
        }

        return max(1, (int) $value);
    }

    /**
     * Return whether full message content should be persisted to the database.
     *
     * @return bool True when message storage is enabled, false when privacy mode is active.
     */
    public function isStoreMessages(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_STORE_MESSAGES);
    }

    /**
     * Return the phpClaw Cloud licence key, decrypted from Magento's encrypted storage.
     *
     * @return string Plaintext cloud key, or empty string when not configured.
     */
    public function getCloudKey(): string
    {
        return $this->decryptIfNeeded((string) $this->scopeConfig->getValue(self::XML_PATH_CLOUD_KEY));
    }

    /**
     * Return the phpClaw Cloud signing secret, decrypted from Magento's encrypted storage.
     *
     * @return string Plaintext signing secret, or empty string when not configured.
     */
    public function getCloudSigningSecret(): string
    {
        return $this->decryptIfNeeded((string) $this->scopeConfig->getValue(self::XML_PATH_CLOUD_SIGNING_SECRET));
    }

    /**
     * Return the memory driver identifier.
     *
     * @return string Memory driver key.
     */
    public function getMemoryDriver(): string
    {
        return self::MEMORY_DRIVER;
    }

    /**
     * Return the custom system prompt injected before every agent run.
     *
     * @return string System prompt text, or empty string when not set.
     */
    public function getSystemPrompt(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_SYSTEM_PROMPT);
    }

    /**
     * Return the comma-separated list of cloud features to disable.
     *
     * @return string Comma-separated feature slugs, or empty string when nothing is disabled.
     */
    public function getCloudDisable(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_CLOUD_DISABLE);
    }

    /**
     * Return the list of remote SKILL.md / JSON URLs to load as always-on skills.
     *
     * @return array<int, string> Ordered list of HTTPS URLs.
     */
    public function getRemoteSkillUrls(): array
    {
        return CsvList::parse((string) $this->scopeConfig->getValue(self::XML_PATH_REMOTE_SKILL_URLS));
    }

    /**
     * Return the list of shell commands the ShellTool is permitted to execute.
     *
     * @return string[] Ordered list of allowed command prefixes.
     */
    public function getShellAllowlist(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_SHELL_ALLOWLIST);
        if ($value === '') {
            return array_values(array_unique(array_merge(
                ToolConfig::DEFAULT_SHELL_ALLOWLIST,
                self::MAGENTO_SHELL_ALLOWLIST,
            )));
        }

        return CsvList::parse($value);
    }

    /**
     * Return the tool names or group references to exclude from the tool registry.
     *
     * @return string[] Ordered list of denied tool names or group references.
     */
    public function getToolDeny(): array
    {
        return CsvList::parse((string) $this->scopeConfig->getValue(self::XML_PATH_TOOL_DENY));
    }

    /**
     * Return the fallback provider slug tried when the main provider fails, or '' when fallback is off.
     *
     * @return string
     */
    public function getFallbackProvider(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_FALLBACK_PROVIDER);
    }

    /**
     * Return the fallback model, or '' for the fallback provider's default model.
     *
     * @return string
     */
    public function getFallbackModel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_FALLBACK_MODEL);
    }

    /**
     * Return the fallback provider's API key, decrypted from Magento's encrypted storage.
     *
     * @return string Plaintext key, or empty string when not configured.
     */
    public function getFallbackApiKey(): string
    {
        return $this->decryptIfNeeded((string) $this->scopeConfig->getValue(self::XML_PATH_FALLBACK_API_KEY));
    }

    /**
     * Return the most provider calls allowed per minute, clamped to 0 to 600; 0 turns the limit off.
     *
     * @return int
     */
    public function getRateLimitRpm(): int
    {
        return self::clampRateLimitRpm($this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT_RPM));
    }

    /**
     * Return whether an identical request is answered from the response cache.
     *
     * @return bool
     */
    public function isResponseCache(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_RESPONSE_CACHE);
    }

    /**
     * Return how long a cached answer is kept, clamped to 60 to 86400 seconds; unset is 3600.
     *
     * @return int
     */
    public function getResponseCacheTtl(): int
    {
        return self::clampResponseCacheTtl($this->scopeConfig->getValue(self::XML_PATH_RESPONSE_CACHE_TTL));
    }

    /**
     * Return the token budget per run, clamped to 0 to 10000000; 0 turns the budget off.
     *
     * @return int
     */
    public function getMaxTokenBudget(): int
    {
        return self::clampMaxTokenBudget($this->scopeConfig->getValue(self::XML_PATH_MAX_TOKEN_BUDGET));
    }

    /**
     * Hold a requests-per-minute value inside 0 to 600.
     *
     * @param  mixed  $raw  Stored or submitted value.
     * @return int
     */
    public static function clampRateLimitRpm(mixed $raw): int
    {
        return max(0, min(self::MAX_RATE_LIMIT_RPM, (int) $raw));
    }

    /**
     * Hold a cache TTL inside 60 to 86400 seconds; an empty value is the 3600-second default.
     *
     * @param  mixed  $raw  Stored or submitted value.
     * @return int
     */
    public static function clampResponseCacheTtl(mixed $raw): int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return self::DEFAULT_CACHE_TTL;
        }

        return max(self::MIN_CACHE_TTL, min(self::MAX_CACHE_TTL, (int) $raw));
    }

    /**
     * Hold a token budget inside 0 to 10000000.
     *
     * @param  mixed  $raw  Stored or submitted value.
     * @return int
     */
    public static function clampMaxTokenBudget(mixed $raw): int
    {
        return max(0, min(self::MAX_TOKEN_BUDGET, (int) $raw));
    }

    /**
     * Detect Magento ciphertext and decrypt it; return plaintext values unchanged.
     *
     * @param  string  $value  Raw value from core_config_data.
     * @return string Decrypted plaintext, or the original value when it is not ciphertext.
     */
    private function decryptIfNeeded(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d+:\d+:/', $value) === 1) {
            return (string) $this->encryptor->decrypt($value);
        }

        return $value;
    }
}
