<?php

declare(strict_types=1);

namespace PhpClaw\Config;

use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Tools\WebSearch;
use Psr\SimpleCache\CacheInterface;

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

    public readonly array $fallbacks;

    public readonly int $requestsPerMinute;

    public readonly int $maxWaitMs;

    public readonly ?CacheInterface $responseCache;

    public readonly int $responseCacheTtl;

    public readonly ?CacheInterface $rateLimitStore;

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
     * @param  array<int, ProviderInterface|array{provider: string, model: string, apiKey: string}>  $fallbacks  Providers tried in order after the primary fails; each entry is a pre-built provider or a provider/model/apiKey triple built when the Claw is built.
     * @param  int  $requestsPerMinute  Outbound token-bucket rate limit; clamped to >=0, 0 disables throttling.
     * @param  int  $maxWaitMs  Longest wait for a rate-limit token before ThrottledProvider gives up; clamped to >=0.
     * @param  CacheInterface|null  $responseCache  PSR-16 store used to cache provider send() responses; null disables response caching.
     * @param  int  $responseCacheTtl  Seconds a cached response stays valid; clamped to >=0.
     * @param  CacheInterface|null  $rateLimitStore  Optional PSR-16 store so the rate-limit bucket is shared across every Claw built with this same store; null keeps the bucket local to one built Claw.
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
        array $fallbacks = [],
        int $requestsPerMinute = 0,
        int $maxWaitMs = 30_000,
        ?CacheInterface $responseCache = null,
        int $responseCacheTtl = 3600,
        ?CacheInterface $rateLimitStore = null,
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
        $this->fallbacks = $fallbacks;
        $this->requestsPerMinute = max(0, $requestsPerMinute);
        $this->maxWaitMs = max(0, $maxWaitMs);
        $this->responseCache = $responseCache;
        $this->responseCacheTtl = max(0, $responseCacheTtl);
        $this->rateLimitStore = $rateLimitStore;
    }
}
