<?php

declare(strict_types=1);

namespace PhpClaw;

use PhpClaw\Agent\Agent;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Agent\InvocationPipeline;
use PhpClaw\Agent\MessageAugmenter;
use PhpClaw\Agent\OutputSanitiser;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Providers\Contracts\SupportsWebSearchInterface;
use PhpClaw\Providers\Tools\WebSearch;
use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ToolRouter;

/**
 * Universal AI agent engine for PHP, the entry point for send(), stream() and conversation flows.
 */
final class Claw implements ClawInterface
{
    public const AGENTIC_DOCTRINE = <<<'DOCTRINE'
        You have REAL tools that execute on a real server.
        For simple questions (math, facts, general knowledge, explanations), answer directly from your own knowledge. Do NOT use tools for these.
        Use tools when the user's request needs external data or an action: database queries, site content, server logs, HTTP calls, file reads, or shell commands.
        When a registered tool fits, CALL it and respond from the actual output. Never describe what a tool would do; invoke it.
        You work in a THINK -> ACT -> OBSERVE loop. THINK: decide the next concrete step toward the goal. ACT: call the tool that performs it. OBSERVE: read the real output, then decide the next step.
        Repeat this loop as many times as the task needs. Multi-step tasks require multiple tool calls, so do NOT stop after one tool.
        If a tool returns an error or partial result, adjust and try again. Read current state before acting on it.
        Only write your final plain-text answer when the goal is fully achieved and no step remains. Then summarise what you did.
        DOCTRINE;

    private readonly Agent $agent;

    private readonly ?MemoryInterface $memory;

    private readonly bool $storeMessages;

    private readonly InvocationPipeline $pipeline;

    private bool $cloudBooted = false;

    /**
     * Assemble the engine from an immutable configuration value object.
     *
     * @param  ClawConfig  $config  Immutable configuration produced by ClawBuilder::build().
     */
    public function __construct(private readonly ClawConfig $config)
    {
        $this->memory = $config->memory;
        $this->storeMessages = $config->storeMessages;
        $this->agent = $this->buildAgent();
        $this->pipeline = new InvocationPipeline(
            new MessageAugmenter($this->memory, $this->config->skillMatchLimit),
            $this->memory,
        );

        $this->registerSkills();

        foreach ($this->config->remoteSkillUrls as $url) {
            RemoteSkillLoader::load($url);
        }

        $this->registerDefaultGuards();
    }

    /**
     * Entry point for the fluent configuration API.
     *
     * @return ClawBuilder A fresh builder; chain setters then call build() to get a PhpClaw instance.
     */
    public static function builder(): ClawBuilder
    {
        return new ClawBuilder;
    }

    /**
     * Send a message to the AI agent and return the response.
     *
     * @param  string  $message  User message; scanned by every registered guard before reaching the LLM.
     * @return AgentResponse The result.
     *
     * @throws GuardException If prompt injection is detected.
     * @throws MaxIterationsException If the ReAct loop cap is hit.
     * @throws ProviderException If the LLM API call fails.
     */
    public function send(string $message): AgentResponse
    {
        $this->ensureCloudBooted();

        return $this->pipeline->execute(
            message: $message,
            streaming: false,
            invoke: fn (string $augmented, string $runId, string $original): AgentResponse => $this->agent->run($augmented, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Stream a response token-by-token, calling $onToken for each chunk.
     *
     * @param  string  $message  User message; scanned by every registered guard before reaching the LLM.
     * @param  callable(string): void  $onToken  Called with each text token as it arrives.
     * @return AgentResponse The result.
     *
     * @throws GuardException If prompt injection is detected.
     * @throws MaxIterationsException If the ReAct loop cap is hit.
     * @throws ProviderException If the LLM API call fails.
     */
    public function stream(string $message, callable $onToken): AgentResponse
    {
        $this->ensureCloudBooted();

        return $this->pipeline->execute(
            message: $message,
            streaming: true,
            invoke: fn (string $augmented, string $runId, string $original): AgentResponse => $this->agent->stream($augmented, $onToken, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Load an existing conversation by ID, or start a new one.
     *
     * @param  string  $id  Existing conversation id to load from memory; empty starts a new conversation.
     * @param  array<string, mixed>  $metadata  Application metadata attached to new conversations.
     * @return Conversation The result.
     */
    public function conversation(string $id = '', array $metadata = []): Conversation
    {
        $this->ensureCloudBooted();
        if ($id !== '' && $this->memory !== null) {
            $stored = $this->memory->get($id, Conversation::MEMORY_NAMESPACE);

            if (is_array($stored)) {
                return Conversation::fromArray($stored);
            }
        }

        $conv = Conversation::start($metadata);

        if ($this->memory !== null) {
            $this->memory->set($conv->id, $conv->toArray(), Conversation::MEMORY_NAMESPACE);
        }

        return $conv;
    }

    /**
     * Send a message within the context of an existing conversation.
     *
     * @param  Conversation  $conversation  Conversation.
     * @param  string  $message  User message.
     * @return ConversationTurn The result.
     *
     * @throws GuardException If prompt injection is detected.
     * @throws MaxIterationsException If the ReAct loop cap is hit.
     * @throws ProviderException If the LLM API call fails.
     */
    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        $this->ensureCloudBooted();

        return $this->pipeline->executeInConversation(
            conversation: $conversation,
            message: $message,
            streaming: false,
            beforePersist: null,
            invoke: fn (string $augmented, array $history, string $runId, string $original): AgentResponse => $this->agent->run($augmented, $history, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Stream a message within the context of an existing conversation, running the full ReAct tool loop, then streaming the final assistant text through $onToken.
     *
     * @param  Conversation  $conversation  Conversation whose history is fed back to the LLM as context.
     * @param  string  $message  User message; scanned by every registered guard before reaching the LLM.
     * @param  callable(string): void  $onToken  Called with each text chunk as it arrives.
     * @param  (callable(array<string,mixed>): (array<string,mixed>|mixed))|null  $beforePersist  Optional. Mutator for the conversation payload before persistence.
     * @return ConversationTurn The result.
     *
     * @throws GuardException If prompt injection is detected.
     * @throws MaxIterationsException If the ReAct loop cap is hit.
     * @throws ProviderException If the LLM API call fails.
     */
    public function streamInConversation(
        Conversation $conversation,
        string $message,
        callable $onToken,
        ?callable $beforePersist = null,
    ): ConversationTurn {
        $this->ensureCloudBooted();

        return $this->pipeline->executeInConversation(
            conversation: $conversation,
            message: $message,
            streaming: true,
            beforePersist: $beforePersist,
            invoke: fn (string $augmented, array $history, string $runId, string $original): AgentResponse => $this->agent->stream($augmented, $onToken, $history, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Return the configured memory driver (if any).
     *
     * @return MemoryInterface|null The result.
     */
    public function memory(): ?MemoryInterface
    {
        return $this->memory;
    }

    /**
     * Whether message content should be persisted.
     *
     * @return bool True on success.
     */
    public function storeMessages(): bool
    {
        return $this->storeMessages;
    }

    /**
     * Return the immutable config produced by the Builder.
     *
     * @return ClawConfig The result.
     */
    public function config(): ClawConfig
    {
        return $this->config;
    }

    /**
     * Build the underlying Agent from config: provider + tools + retry/history settings.
     *
     * @return Agent The result.
     */
    private function buildAgent(): Agent
    {
        $provider = $this->config->providerOverride ?? $this->config->buildProvider();

        if ($provider instanceof SupportsWebSearchInterface) {
            $provider = $provider->withProviderTools(
                $this->config->providerTools !== [] ? $this->config->providerTools : [new WebSearch]
            );
        }

        $toolRegistry = new ToolRegistry;
        if (! empty($this->config->tools)) {
            $toolRegistry->register($this->config->tools);
        }

        return new Agent(
            provider: $provider,
            tools: $toolRegistry,
            maxIterations: $this->config->maxIterations,
            maxRetries: $this->config->maxRetries,
            maxHistoryLength: $this->config->maxHistoryLength,
            outputSanitiser: new OutputSanitiser($this->config->sanitiseOutput),
            compactHistory: $this->config->compactHistory,
            approvalGate: $this->config->approvalGate,
            toolRouter: new ToolRouter($this->config->maxToolsPerTurn),
            maxHistoryTokens: $this->config->maxHistoryTokens,
            maxToolResultTokens: $this->config->maxToolResultTokens,
        );
    }

    /**
     * Register every configured skill into the global SkillRegistry.
     *
     * @return void
     */
    private function registerSkills(): void
    {
        foreach ($this->config->skills as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Register the default guard chain when enabled.
     *
     * @return void
     */
    private function registerDefaultGuards(): void
    {
        if ($this->config->useDefaultGuards) {
            GuardRegistry::registerDefaults();
        }
    }

    /**
     * Boot cloud exactly once, on the first public method call, not at construction.
     *
     * @return void
     */
    private function ensureCloudBooted(): void
    {
        if ($this->cloudBooted) {
            return;
        }
        $this->bootCloud();
        $this->cloudBooted = true;
    }

    /**
     * Boot phpClaw Cloud features when a cloud key is configured AND the optional `phpclaw/phpclaw-cloud` package is installed.
     *
     * @return void
     */
    private function bootCloud(): void
    {
        if (! $this->config->isCloudEnabled()) {
            return;
        }

        if (! class_exists(CloudManager::class)) {
            return;
        }

        CloudManager::boot(
            $this->config->cloudKey,
            $this->config->cloudDisable,
            $this->config->cloudSigningSecret,
        );
    }
}
