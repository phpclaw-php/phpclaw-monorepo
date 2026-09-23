<?php

declare(strict_types=1);

namespace PhpClaw;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Config\CloudSettings;
use PhpClaw\Config\EnvVars;
use PhpClaw\Config\LoopConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Config\RuntimeConfig;
use PhpClaw\Config\SkillConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderRegistry;

/**
 * Immutable configuration value object: produced by ClawBuilder and consumed by PhpClaw.
 */
final class ClawConfig
{
    public const DEFAULT_MAX_ITERATIONS = LoopConfig::DEFAULT_MAX_ITERATIONS;

    public const DEFAULT_SHELL_ALLOWLIST = ToolConfig::DEFAULT_SHELL_ALLOWLIST;

    public readonly string $apiKey;

    public readonly string $providerName;

    public readonly string $model;

    public readonly bool $storeMessages;

    public readonly int $maxIterations;

    public readonly int $maxRetries;

    public readonly int $maxHistoryLength;

    public readonly string $systemPrompt;

    public readonly int $maxTokens;

    public readonly bool $promptCache;

    public readonly int $thinkingBudget;

    public readonly bool $useDefaultGuards;

    public readonly array $shellAllowlist;

    public readonly array $tools;

    public readonly array $skills;

    public readonly array $providerTools;

    public readonly ?ProviderInterface $providerOverride;

    public readonly ?MemoryInterface $memory;

    public readonly string $cloudKey;

    public readonly array $cloudDisable;

    public readonly string $cloudSigningSecret;

    public readonly bool $sanitiseOutput;

    public readonly bool $compactHistory;

    public readonly bool $allowPhpWrite;

    public readonly int $skillMatchLimit;

    public readonly int $maxToolsPerTurn;

    public readonly array $remoteSkillUrls;

    public readonly array $remoteToolProfileUrls;

    public readonly ?ApprovalGateInterface $approvalGate;

    public readonly int $maxHistoryTokens;

    public readonly int $maxToolResultTokens;

    /**
     * Build the immutable configuration from six grouped setting objects.
     *
     * @param  ProviderConfig|null  $provider  Provider selection, auth, and generation settings; null uses defaults + env detection.
     * @param  LoopConfig|null  $limits  ReAct loop, retry, and history-window limits; null uses defaults.
     * @param  ToolConfig|null  $tools  Tool registration, shell allowlist, and approval gating; null uses defaults.
     * @param  SkillConfig|null  $skills  Skill registration and matching settings; null uses defaults.
     * @param  CloudSettings|null  $cloud  phpClaw Cloud key and feature settings; null keeps local-only mode.
     * @param  RuntimeConfig|null  $flags  Behavioural toggles and the memory driver; null uses defaults.
     * @return void
     */
    public function __construct(
        ?ProviderConfig $provider = null,
        ?LoopConfig $limits = null,
        ?ToolConfig $tools = null,
        ?SkillConfig $skills = null,
        ?CloudSettings $cloud = null,
        ?RuntimeConfig $flags = null,
    ) {
        $provider ??= new ProviderConfig;
        $limits ??= new LoopConfig;
        $tools ??= new ToolConfig;
        $skills ??= new SkillConfig;
        $cloud ??= new CloudSettings;
        $flags ??= new RuntimeConfig;

        $this->providerName = $provider->provider !== ''
            ? strtolower($provider->provider)
            : $this->detectProviderName();

        $this->apiKey = $provider->apiKey !== ''
            ? $provider->apiKey
            : $this->resolveApiKey($this->providerName);

        $this->model = $provider->model !== '' ? $provider->model : $this->env(EnvVars::PHPCLAW_MODEL);
        $this->systemPrompt = $provider->systemPrompt;
        $this->maxTokens = $provider->maxTokens;
        $this->promptCache = $provider->promptCache;
        $this->thinkingBudget = $provider->thinkingBudget;
        $this->providerOverride = $provider->providerOverride;
        $this->providerTools = $provider->providerTools;

        $this->maxIterations = $limits->maxIterations;
        $this->maxRetries = $limits->maxRetries;
        $this->maxHistoryLength = $limits->maxHistoryLength;
        $this->maxHistoryTokens = $limits->maxHistoryTokens;
        $this->maxToolResultTokens = $limits->maxToolResultTokens;
        $this->maxToolsPerTurn = $limits->maxToolsPerTurn;

        $this->tools = $tools->tools;
        $this->shellAllowlist = $tools->shellAllowlist;
        $this->allowPhpWrite = $tools->allowPhpWrite;
        $this->remoteToolProfileUrls = $tools->remoteToolProfileUrls;
        $this->approvalGate = $tools->approvalGate;

        $this->skills = $skills->skills;
        $this->skillMatchLimit = $skills->skillMatchLimit;
        $this->remoteSkillUrls = $skills->remoteSkillUrls;

        $this->cloudKey = $cloud->cloudKey;
        $this->cloudDisable = $cloud->cloudDisable;
        $this->cloudSigningSecret = $cloud->cloudSigningSecret;

        $this->storeMessages = $flags->storeMessages;
        $this->useDefaultGuards = $flags->useDefaultGuards;
        $this->sanitiseOutput = $flags->sanitiseOutput;
        $this->compactHistory = $flags->compactHistory;
        $this->memory = $flags->memory;
    }

    /**
     * Whether a phpClaw Cloud key is configured.
     *
     * @return bool True when a non-empty cloud key was supplied; false for local-only mode.
     */
    public function isCloudEnabled(): bool
    {
        return $this->cloudKey !== '';
    }

    /**
     * Build and return the configured provider instance.
     *
     * @return ProviderInterface Fully wired provider matching $providerName, ready to send/stream.
     *
     * @throws AdapterException If no API key is found (built-in) or provider is unknown.
     */
    public function buildProvider(): ProviderInterface
    {
        if (ProviderRegistry::has($this->providerName)) {
            return ProviderRegistry::build($this->providerName, $this);
        }

        $preset = OpenAIPresets::find($this->providerName);
        if ($preset !== null) {
            return $this->buildPresetProvider($this->providerName, $preset);
        }

        if ($this->apiKey === '') {
            throw new AdapterException(
                'No API key found. Set ANTHROPIC_API_KEY or GEMINI_API_KEY, choose an OpenAI-compatible provider (OPENAI_API_KEY, GROQ_API_KEY, DEEPSEEK_API_KEY, MISTRAL_API_KEY, OLLAMA_HOST), or register a custom provider via ProviderRegistry::register().'
            );
        }

        $class = Bootstrap::providers()[$this->providerName] ?? null;

        if ($class === null || ! method_exists($class, 'fromConfig')) {
            throw new AdapterException("Unknown provider: {$this->providerName}");
        }

        $instance = $class::fromConfig($this);

        if (! $instance instanceof ProviderInterface) {
            throw new AdapterException(
                "Provider {$class}::fromConfig() did not return a ProviderInterface."
            );
        }

        return $instance;
    }

    /**
     * Build and return the configured memory driver instance.
     *
     * @param  string  $driver  Memory driver slug registered in MemoryRegistry (defaults to 'file').
     * @return MemoryInterface Constructed memory driver ready for read/write.
     *
     * @throws MemoryException If the driver is not registered in MemoryRegistry.
     */
    public function buildMemory(string $driver = 'file'): MemoryInterface
    {
        return MemoryRegistry::build($driver);
    }

    /**
     * Build the shared OpenAIProvider engine from a preset row.
     *
     * @param  string  $slug  Preset slug.
     * @param  array<string, string>  $preset  Preset row from OpenAIPresets.
     * @return ProviderInterface Configured OpenAIProvider.
     *
     * @throws AdapterException When a key-authed preset has no API key, or `custom` lacks a base URL.
     */
    private function buildPresetProvider(string $slug, array $preset): ProviderInterface
    {
        $endpoint = $this->resolvePresetEndpoint($slug, $preset);

        if ($preset['auth'] !== OpenAIPresets::AUTH_NONE && $this->apiKey === '') {
            $hint = $preset['keyEnv'] !== '' ? $preset['keyEnv'] : 'the provider API key';
            throw new AdapterException("No API key found for provider '{$slug}'. Set {$hint} in your environment.");
        }

        return new OpenAIProvider(
            apiKey: $this->apiKey,
            model: $this->model !== '' ? $this->model : $preset['model'],
            systemPrompt: $this->systemPrompt,
            maxTokens: $this->maxTokens,
            endpoint: $endpoint,
            name: $slug,
            authStyle: $preset['auth'],
        );
    }

    /**
     * Resolve a preset's effective endpoint, applying runtime overrides.
     *
     * @param  string  $slug  Preset slug.
     * @param  array<string, string>  $preset  Preset row.
     * @return string Full /chat/completions endpoint.
     *
     * @throws AdapterException When `custom` has no OPENAI_BASE_URL set.
     */
    private function resolvePresetEndpoint(string $slug, array $preset): string
    {
        if ($slug === 'custom') {
            $base = $this->env(EnvVars::OPENAI_BASE_URL);
            if ($base === '') {
                throw new AdapterException(
                    'Custom provider requires a base URL. Set OPENAI_BASE_URL to the full /chat/completions endpoint.'
                );
            }

            return $base;
        }

        if ($slug === 'ollama') {
            $host = $this->env(EnvVars::OLLAMA_HOST);
            if ($host !== '') {
                return rtrim($host, '/').'/v1/chat/completions';
            }
        }

        return $preset['baseUrl'];
    }

    /**
     * Resolve which provider slug to use from PHPCLAW_PROVIDER or the first API-key env var present.
     *
     * @return string Lowercase provider slug; falls back to 'anthropic' if nothing matches.
     */
    private function detectProviderName(): string
    {
        $override = $this->env(EnvVars::PHPCLAW_PROVIDER);
        if ($override !== '') {
            return strtolower($override);
        }

        if ($this->env(EnvVars::ANTHROPIC_API_KEY) !== '') {
            return 'anthropic';
        }
        if ($this->env(EnvVars::OPENAI_API_KEY) !== '') {
            return 'openai';
        }
        if ($this->env(EnvVars::GROQ_API_KEY) !== '') {
            return 'groq';
        }
        if ($this->env(EnvVars::GEMINI_API_KEY) !== '') {
            return 'gemini';
        }
        if ($this->env(EnvVars::MISTRAL_API_KEY) !== '') {
            return 'mistral';
        }
        if ($this->env(EnvVars::DEEPSEEK_API_KEY) !== '') {
            return 'deepseek';
        }
        if ($this->env(EnvVars::OLLAMA_HOST) !== '') {
            return 'ollama';
        }

        return 'anthropic';
    }

    /**
     * Resolve the API key for the given provider slug from its conventional env var.
     *
     * @param  string  $providerName  Lowercase provider slug.
     * @return string API key from env, or '' when not found (Ollama always returns '').
     */
    private function resolveApiKey(string $providerName): string
    {
        if ($providerName === 'anthropic') {
            return $this->env(EnvVars::ANTHROPIC_API_KEY);
        }
        if ($providerName === 'gemini') {
            return $this->env(EnvVars::GEMINI_API_KEY);
        }

        $preset = OpenAIPresets::find($providerName);
        if ($preset !== null) {
            return $preset['keyEnv'] !== '' ? $this->env($preset['keyEnv']) : '';
        }

        return '';
    }

    /**
     * Read an environment variable via EnvVars::get() ($_ENV, $_SERVER, then getenv()).
     *
     * @param  string  $name  Environment variable name.
     * @return string Non-empty value when found, otherwise ''.
     */
    private function env(string $name): string
    {
        return EnvVars::get($name);
    }
}
