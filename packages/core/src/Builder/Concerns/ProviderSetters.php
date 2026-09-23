<?php

declare(strict_types=1);

namespace PhpClaw\Builder\Concerns;

use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Tools\WebSearch;

/**
 * Provider selection, authentication, and generation setters for ClawBuilder.
 */
trait ProviderSetters
{
    private string $apiKey = '';

    private string $provider = '';

    private string $model = '';

    private string $systemPrompt = '';

    private int $maxTokens = 0;

    private bool $promptCache = true;

    private int $thinkingBudget = 0;

    private ?ProviderInterface $providerOverride = null;

    private array $providerTools = [];

    /**
     * Set the API key. Empty = auto-detect from env.
     *
     * @param  string  $key  Provider API key, or '' to fall back to the matching env var.
     * @return static Builder instance for fluent chaining.
     */
    public function apiKey(string $key): static
    {
        $this->apiKey = $key;

        return $this;
    }

    /**
     * Set the provider name (anthropic, openai, groq, gemini, mistral, ollama). Empty = auto-detect.
     *
     * @param  string  $name  Provider slug, or '' to auto-detect from the first matching API-key env var.
     * @return static Builder instance for fluent chaining.
     */
    public function provider(string $name): static
    {
        $this->provider = $name;

        return $this;
    }

    /**
     * Override the default model for the selected provider.
     *
     * @param  string  $model  Upstream model id (e.g. 'claude-haiku-4-5-20251001', 'gpt-4o-mini').
     * @return static Builder instance for fluent chaining.
     */
    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * System prompt injected into every request.
     *
     * @param  string  $prompt  Free-form system prompt; combined with AGENTIC_DOCTRINE when tools are registered.
     * @return static Builder instance for fluent chaining.
     */
    public function systemPrompt(string $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    /**
     * Max output tokens. 0 = use per-provider smart default.
     *
     * @param  int  $count  Hard ceiling on the LLM response token count; 0 lets the provider pick its own default.
     * @return static Builder instance for fluent chaining.
     */
    public function maxTokens(int $count): static
    {
        $this->maxTokens = $count;

        return $this;
    }

    /**
     * Enable Anthropic prompt caching (no-op for other providers).
     *
     * @param  bool  $flag  True to enable the Anthropic prompt-cache header on every request.
     * @return static Builder instance for fluent chaining.
     */
    public function promptCache(bool $flag = true): static
    {
        $this->promptCache = $flag;

        return $this;
    }

    /**
     * Extended thinking budget tokens (Anthropic only). 0 = disabled. Min 1024.
     *
     * @param  int  $budget  Reasoning-token budget for Sonnet/Opus extended thinking; 0 disables, anything 1-1023 is clamped up to 1024.
     * @return static Builder instance for fluent chaining.
     */
    public function thinkingBudget(int $budget): static
    {
        $this->thinkingBudget = $budget;

        return $this;
    }

    /**
     * Inject a custom provider (bypasses auto-detection: useful in tests).
     *
     * @param  ProviderInterface  $provider  Pre-built provider instance to use verbatim, bypassing the auto-detect chain.
     * @return static Builder instance for fluent chaining.
     */
    public function providerOverride(ProviderInterface $provider): static
    {
        $this->providerOverride = $provider;

        return $this;
    }

    /**
     * Append a provider-native tool config, e.g. a customized WebSearch.
     *
     * @param  WebSearch  $tool  Provider tool config; appended to the existing providerTools array.
     * @return static Builder instance for fluent chaining.
     */
    public function withProviderTool(WebSearch $tool): static
    {
        $this->providerTools[] = $tool;

        return $this;
    }
}
