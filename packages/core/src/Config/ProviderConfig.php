<?php

declare(strict_types=1);

namespace PhpClaw\Config;

use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Tools\WebSearch;

/**
 * Provider selection, authentication, and generation settings for ClawConfig.
 */
final class ProviderConfig
{
    public readonly string $apiKey;

    public readonly string $provider;

    public readonly string $model;

    public readonly string $systemPrompt;

    public readonly int $maxTokens;

    public readonly bool $promptCache;

    public readonly int $thinkingBudget;

    public readonly ?ProviderInterface $providerOverride;

    public readonly array $providerTools;

    /**
     * Group and validate the provider-facing configuration.
     *
     * @param  string  $apiKey  Provider API key; empty triggers env-var auto-detection.
     * @param  string  $provider  Provider slug (anthropic, gemini, or an OpenAI-compatible preset); empty triggers env-var auto-detection.
     * @param  string  $model  Upstream model id; empty uses each provider's smart default.
     * @param  string  $systemPrompt  Free-form system prompt (may already include AGENTIC_DOCTRINE prefix from the builder).
     * @param  int  $maxTokens  Hard ceiling on response tokens; clamped to >=0, 0 lets the provider choose.
     * @param  bool  $promptCache  Enable Anthropic prompt caching (no-op for other providers).
     * @param  int  $thinkingBudget  Reasoning-token budget for Anthropic extended thinking; clamped to >=0, 0 disables.
     * @param  ProviderInterface|null  $providerOverride  Pre-built provider that bypasses auto-construction (useful in tests).
     * @param  WebSearch[]  $providerTools  Provider-native tool configs; empty = default web-search tool on supporting providers.
     * @return void
     */
    public function __construct(
        string $apiKey = '',
        string $provider = '',
        string $model = '',
        string $systemPrompt = '',
        int $maxTokens = 0,
        bool $promptCache = true,
        int $thinkingBudget = 0,
        ?ProviderInterface $providerOverride = null,
        array $providerTools = [],
    ) {
        $this->apiKey = $apiKey;
        $this->provider = $provider;
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
        $this->maxTokens = max(0, $maxTokens);
        $this->promptCache = $promptCache;
        $this->thinkingBudget = max(0, $thinkingBudget);
        $this->providerOverride = $providerOverride;
        $this->providerTools = $providerTools;
    }
}
