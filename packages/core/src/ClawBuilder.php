<?php

declare(strict_types=1);

namespace PhpClaw;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Config\CloudSettings;
use PhpClaw\Config\LoopConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Config\RuntimeConfig;
use PhpClaw\Config\SkillConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Tools\WebSearch;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\RemoteToolActivator;

/**
 * Fluent builder for {@see Claw}: collect every configuration option via chainable setters, then build() to assemble the Claw instance.
 */
final class ClawBuilder
{
    private string $cloudKey = '';

    private string $cloudSigningSecret = '';

    private array $cloudDisable = [];

    private bool $storeMessages = true;

    private bool $useDefaultGuards = true;

    private bool $sanitiseOutput = true;

    private bool $compactHistory = true;

    private ?MemoryInterface $memory = null;

    private int $maxIterations = LoopConfig::DEFAULT_MAX_ITERATIONS;

    private int $maxRetries = 0;

    private int $maxHistoryLength = 0;

    private int $maxToolsPerTurn = 0;

    private int $maxHistoryTokens = 0;

    private int $maxToolResultTokens = LoopConfig::DEFAULT_MAX_TOOL_RESULT_TOKENS;

    private string $apiKey = '';

    private string $provider = '';

    private string $model = '';

    private string $systemPrompt = '';

    private int $maxTokens = 0;

    private bool $promptCache = true;

    private int $thinkingBudget = 0;

    private ?ProviderInterface $providerOverride = null;

    private array $providerTools = [];

    private array $skills = [];

    private int $skillMatchLimit = SkillRegistry::DEFAULT_MATCH_LIMIT;

    private array $remoteSkillUrls = [];

    private array $tools = [];

    private array $shellAllowlist = [];

    private bool $allowPhpWrite = false;

    private array $remoteToolProfileUrls = [];

    private ?ApprovalGateInterface $approvalGate = null;

    /**
     * phpClaw Cloud API key. Empty = local-only mode.
     *
     * @param  string  $key  Cloud key; '' keeps the engine in local-only mode and skips CloudManager::boot().
     * @return static Builder instance for fluent chaining.
     */
    public function cloudKey(string $key): static
    {
        $this->cloudKey = $key;

        return $this;
    }

    /**
     * Cloud features to exclude from activation.
     *
     * @param  string[]  $features  Cloud feature slugs to skip when CloudManager boots (e.g. 'scan').
     * @return static Builder instance for fluent chaining.
     */
    public function cloudDisable(array $features): static
    {
        $this->cloudDisable = $features;

        return $this;
    }

    /**
     * Shared secret used to verify signed cloud scan responses. Empty leaves verification off.
     *
     * @param  string  $secret  Signing secret matching the cloud receiver; '' disables verification.
     * @return static Builder instance for fluent chaining.
     */
    public function cloudSigningSecret(string $secret): static
    {
        $this->cloudSigningSecret = $secret;

        return $this;
    }

    /**
     * Whether to persist message content. Default true.
     *
     * @param  bool  $flag  True to persist prompt + response content; false to drop content from memory and cloud payloads.
     * @return static Builder instance for fluent chaining.
     */
    public function storeMessages(bool $flag = true): static
    {
        $this->storeMessages = $flag;

        return $this;
    }

    /**
     * Register the default guard chain. Default true.
     *
     * @param  bool  $flag  True to auto-register the eight built-in default guards (Injection, CodeInjection, RoleSwitch, Homoglyph, Unicode, MessageLength, PiiDetection, DestructiveSql) on build.
     * @return static Builder instance for fluent chaining.
     */
    public function useDefaultGuards(bool $flag = true): static
    {
        $this->useDefaultGuards = $flag;

        return $this;
    }

    /**
     * Whether to sanitise LLM output: strips PHP tags and redacts dangerous function calls. Default true.
     *
     * @param  bool  $enabled  False to return raw LLM output unchanged (useful for documentation or explanation use cases).
     * @return static Builder instance for fluent chaining.
     */
    public function sanitiseOutput(bool $enabled = true): static
    {
        $this->sanitiseOutput = $enabled;

        return $this;
    }

    /**
     * Toggle automatic history compaction: the summary LLM call fired when history overflows.
     *
     * @param  bool  $enabled  False to disable compaction entirely (no hidden summary call).
     * @return static Builder instance for fluent chaining.
     */
    public function compactHistory(bool $enabled = true): static
    {
        $this->compactHistory = $enabled;

        return $this;
    }

    /**
     * Inject a memory driver for conversation persistence.
     *
     * @param  MemoryInterface  $memory  Memory driver used for conversation history + per-turn key/value state.
     * @return static Builder instance for fluent chaining.
     */
    public function memory(MemoryInterface $memory): static
    {
        $this->memory = $memory;

        return $this;
    }

    /**
     * ReAct loop cap. Default 20.
     *
     * @param  int  $count  Maximum iterations before MaxIterationsException is thrown.
     * @return static Builder instance for fluent chaining.
     */
    public function maxIterations(int $count): static
    {
        $this->maxIterations = $count;

        return $this;
    }

    /**
     * Retry transient provider failures up to N times. Default 0.
     *
     * @param  int  $count  Number of retry attempts after a retryable provider failure.
     * @return static Builder instance for fluent chaining.
     */
    public function maxRetries(int $count): static
    {
        $this->maxRetries = $count;

        return $this;
    }

    /**
     * Fire context.overflow when history exceeds this length. 0 = disabled.
     *
     * @param  int  $length  Maximum message count in history before the overflow hook fires; 0 disables the check.
     * @return static Builder instance for fluent chaining.
     */
    public function maxHistoryLength(int $length): static
    {
        $this->maxHistoryLength = $length;

        return $this;
    }

    /**
     * Maximum tool schemas sent to the LLM per iteration. 0 = auto by model.
     *
     * @param  int  $limit  Per-turn tool cap; 0 lets ToolRouter pick a limit from the model id.
     * @return static Builder instance for fluent chaining.
     */
    public function maxToolsPerTurn(int $limit): static
    {
        $this->maxToolsPerTurn = max(0, $limit);

        return $this;
    }

    /**
     * Token-based history compaction ceiling (estimated as chars/4). 0 = count-based only.
     *
     * @param  int  $tokens  Estimated-token ceiling that triggers compaction; clamped to >=0.
     * @return static Builder instance for fluent chaining.
     */
    public function maxHistoryTokens(int $tokens): static
    {
        $this->maxHistoryTokens = max(0, $tokens);

        return $this;
    }

    /**
     * Ceiling on a single tool result (estimated as chars/4) before it is cut and marked. 0 disables the cut.
     *
     * @param  int  $tokens  Estimated-token ceiling; clamped to >=0.
     * @return static Builder instance for fluent chaining.
     */
    public function maxToolResultTokens(int $tokens): static
    {
        $this->maxToolResultTokens = max(0, $tokens);

        return $this;
    }

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
     * @param  int  $budget  Reasoning-token budget for Sonnet/Opus extended thinking; 0 disables, values below 1024 disable thinking (the provider minimum is 1024).
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

    /**
     * Replace the skills list.
     *
     * @param  SkillInterface[]  $skills  Full skill list to register on build; replaces any previously set skills.
     * @return static Builder instance for fluent chaining.
     */
    public function skills(array $skills): static
    {
        $this->skills = $skills;

        return $this;
    }

    /**
     * Append a single skill to the list.
     *
     * @param  SkillInterface  $skill  Skill to register; appended to the existing skills array.
     * @return static Builder instance for fluent chaining.
     */
    public function addSkill(SkillInterface $skill): static
    {
        $this->skills[] = $skill;

        return $this;
    }

    /**
     * Number of skills injected per turn. Default 3; clamped to at least 1.
     *
     * @param  int  $limit  Maximum matched skills injected into each request.
     * @return static Builder instance for fluent chaining.
     */
    public function skillMatchLimit(int $limit): static
    {
        $this->skillMatchLimit = max(1, $limit);

        return $this;
    }

    /**
     * Register a remote skill collection URL loaded on build.
     *
     * @param  string  $url  HTTPS URL of a phpClaw JSON collection or a single SKILL.md.
     * @return static Builder instance for fluent chaining.
     */
    public function withRemoteSkills(string $url): static
    {
        $this->remoteSkillUrls[] = $url;

        return $this;
    }

    /**
     * Replace the tools list.
     *
     * @param  ToolInterface[]  $tools  Full list of tools the agent may invoke; replaces any previously set tools.
     * @return static Builder instance for fluent chaining.
     */
    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Append a single tool to the list.
     *
     * @param  ToolInterface  $tool  Tool to register; appended to the existing tools array.
     * @return static Builder instance for fluent chaining.
     */
    public function addTool(ToolInterface $tool): static
    {
        $this->tools[] = $tool;

        return $this;
    }

    /**
     * Set the ShellTool allowlist. Empty = use ClawConfig::DEFAULT_SHELL_ALLOWLIST.
     *
     * @param  string[]  $commands  Allowed shell command names; empty array falls back to the built-in default.
     * @return static Builder instance for fluent chaining.
     */
    public function shellAllowlist(array $commands): static
    {
        $this->shellAllowlist = $commands;

        return $this;
    }

    /**
     * Allow adapters to write .php/.phtml/.phar files via FileWriteTool. Default false.
     *
     * @param  bool  $flag  True to permit PHP file writes when an adapter constructs FileWriteTool from this config.
     * @return static Builder instance for fluent chaining.
     */
    public function allowPhpWrite(bool $flag = true): static
    {
        $this->allowPhpWrite = $flag;

        return $this;
    }

    /**
     * Register a remote tool-activation profile URL that names which local tools stay active.
     *
     * @param  string  $url  HTTPS URL of a tool profile JSON.
     * @return static Builder instance for fluent chaining.
     */
    public function withRemoteToolProfile(string $url): static
    {
        $this->remoteToolProfileUrls[] = $url;

        return $this;
    }

    /**
     * Install an approval gate checked before every gated tool executes.
     *
     * @param  ApprovalGateInterface  $gate  Gate implementation.
     * @return static Builder instance for fluent chaining.
     */
    public function approvalGate(ApprovalGateInterface $gate): static
    {
        $this->approvalGate = $gate;

        return $this;
    }

    /**
     * Install the built-in CLI Y/n approval gate.
     *
     * @return static Builder instance for fluent chaining.
     */
    public function withHumanApproval(): static
    {
        $this->approvalGate = new CliApprovalGate;

        return $this;
    }

    /**
     * Assemble the final ClawConfig and return a new Claw instance.
     *
     * @return Claw Fully wired engine; default guards, skills, and cloud boot have all run.
     */
    public function build(): Claw
    {
        $tools = $this->tools;
        $maxToolsPerTurn = $this->maxToolsPerTurn;
        foreach ($this->remoteToolProfileUrls as $profileUrl) {
            $profile = RemoteToolActivator::fetch($profileUrl);
            if ($profile === null) {
                continue;
            }
            $tools = RemoteToolActivator::filter($tools, $profile['tools']);
            if ($profile['max_tools_per_turn'] > 0 && $this->maxToolsPerTurn === 0) {
                $maxToolsPerTurn = $profile['max_tools_per_turn'];
            }
        }

        $composedSystemPrompt = $this->composeSystemPrompt($tools);

        $config = new ClawConfig(
            provider: new ProviderConfig(
                apiKey: $this->apiKey,
                provider: $this->provider,
                model: $this->model,
                systemPrompt: $composedSystemPrompt,
                maxTokens: $this->maxTokens,
                promptCache: $this->promptCache,
                thinkingBudget: $this->thinkingBudget,
                providerOverride: $this->providerOverride,
                providerTools: $this->providerTools,
            ),
            limits: new LoopConfig(
                maxIterations: $this->maxIterations,
                maxRetries: $this->maxRetries,
                maxHistoryLength: $this->maxHistoryLength,
                maxHistoryTokens: $this->maxHistoryTokens,
                maxToolsPerTurn: $maxToolsPerTurn,
                maxToolResultTokens: $this->maxToolResultTokens,
            ),
            tools: new ToolConfig(
                tools: $tools,
                shellAllowlist: $this->shellAllowlist,
                allowPhpWrite: $this->allowPhpWrite,
                remoteToolProfileUrls: $this->remoteToolProfileUrls,
                approvalGate: $this->approvalGate,
            ),
            skills: new SkillConfig(
                skills: $this->skills,
                skillMatchLimit: $this->skillMatchLimit,
                remoteSkillUrls: $this->remoteSkillUrls,
            ),
            cloud: new CloudSettings(
                cloudKey: $this->cloudKey,
                cloudDisable: $this->cloudDisable,
                cloudSigningSecret: $this->cloudSigningSecret,
            ),
            flags: new RuntimeConfig(
                storeMessages: $this->storeMessages,
                useDefaultGuards: $this->useDefaultGuards,
                sanitiseOutput: $this->sanitiseOutput,
                compactHistory: $this->compactHistory,
                memory: $this->memory,
            ),
        );

        return new Claw($config);
    }

    /**
     * Prepend the agentic doctrine when tools are registered.
     *
     * @param  ToolInterface[]  $tools  The (possibly remote-profile-filtered) tool list.
     * @return string Either the raw systemPrompt or Claw::AGENTIC_DOCTRINE (optionally followed by the user prompt).
     */
    private function composeSystemPrompt(array $tools): string
    {
        if (empty($tools)) {
            return $this->systemPrompt;
        }

        return $this->systemPrompt === ''
            ? Claw::AGENTIC_DOCTRINE
            : Claw::AGENTIC_DOCTRINE."\n\n".$this->systemPrompt;
    }
}
