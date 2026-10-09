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
use PhpClaw\Agent\PausedBatch;
use PhpClaw\Agent\ResumeReport;
use PhpClaw\Agent\RunBudget;
use PhpClaw\Agent\RunCheckpoint;
use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Agent\RunStore;
use PhpClaw\Agent\StructuredOutputRunner;
use PhpClaw\Agent\StructuredResponse;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Exceptions\UnsupportedSchemaException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\PiiDetectionGuard;
use PhpClaw\Guards\ToolOutputGuard;
use PhpClaw\Hooks\Dispatchers\AgentEventDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\CachedProvider;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsWebSearchInterface;
use PhpClaw\Providers\ProviderChain;
use PhpClaw\Providers\ThrottledProvider;
use PhpClaw\Providers\Tools\WebSearch;
use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Support\JsonSchemaValidator;
use PhpClaw\Support\Log;
use PhpClaw\Tools\LoadSkillTool;
use PhpClaw\Tools\ToolProfileResolver;
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

    private const DEFAULT_PENDING_APPROVALS = 50;

    private const DEFAULT_RESUME_LIMIT = 5;

    private const DEFAULT_RESUME_SECONDS = 20;

    private readonly ClawConfig $config;

    private readonly Agent $agent;

    private readonly ?MemoryInterface $memory;

    private readonly bool $storeMessages;

    private readonly InvocationPipeline $pipeline;

    private readonly ProviderInterface $provider;

    private bool $isCloudBooted = false;

    /**
     * Assemble the engine from an immutable configuration value object.
     *
     * @param  ClawConfig  $config  Immutable configuration produced by ClawBuilder::build().
     */
    public function __construct(ClawConfig $config)
    {
        $this->config = $config;
        $this->storeMessages = $config->storeMessages;
        $this->memory = $this->gatedMemory($config->memory);
        $this->registerSkills();

        foreach ($this->config->remoteSkillUrls as $url) {
            RemoteSkillLoader::load($url);
        }

        [$this->provider, $this->agent] = $this->buildProviderAndAgent();
        $this->pipeline = new InvocationPipeline(
            new MessageAugmenter(
                $this->memory,
                $this->config->skillMatchLimit,
                ToolProfileResolver::skillContextChars($this->profile()),
                $this->config->longTermMemoryTopK,
            ),
            $this->memory,
        );

        $this->registerDefaultGuards();
    }

    /**
     * Entry point for the fluent configuration API.
     *
     * @return ClawBuilder A fresh builder; chain setters then call build() to get a Claw instance.
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
     * @throws ToolException If the model calls an unregistered tool after the no-tools retry.
     * @throws TokenBudgetExceededException If the run's token spend would pass maxTokenBudget.
     * @throws RunSuspendedException With durable runs on, when the run pauses for approval or spends its step or time budget.
     * @throws RunConflictException With durable runs on, when another process saved the run meanwhile.
     */
    public function send(string $message): AgentResponse
    {
        $this->ensureCloudBooted();

        return $this->pipeline->execute(
            message: $message,
            streaming: false,
            invoke: fn (string $augmented, string $runId, string $original): AgentResponse => $this->isDurable()
                ? $this->durableInvoke(RunState::start($runId, $augmented, $original))
                : $this->agent->run($augmented, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Send a message and return a reply validated against the given JSON Schema.
     *
     * @param  string  $message  User message; scanned by every registered guard before reaching the LLM.
     * @param  array<string, mixed>  $schema  JSON Schema the reply must satisfy.
     * @return StructuredResponse The validated data, paired with the underlying run.
     *
     * @throws GuardException If prompt injection is detected.
     * @throws ProviderException If the LLM API call fails.
     * @throws UnsupportedSchemaException If the schema uses a keyword this library does not enforce.
     * @throws StructuredOutputException If the reply still fails validation after every repair attempt.
     */
    public function sendStructured(string $message, array $schema): StructuredResponse
    {
        $this->ensureCloudBooted();

        $structured = null;

        $this->pipeline->execute(
            message: $message,
            streaming: false,
            invoke: function (string $augmented, string $runId, string $original) use (&$structured, $schema): AgentResponse {
                $runner = new StructuredOutputRunner(
                    $this->provider,
                    new JsonSchemaValidator,
                    $this->config->maxParseRetries,
                    $this->config->maxRetries,
                );
                $structured = $runner->run($augmented, $schema, $runId);

                return $structured->raw;
            },
        );

        return $structured;
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
     * @throws ToolException If the model calls an unregistered tool after the no-tools retry.
     */
    public function stream(string $message, callable $onToken): AgentResponse
    {
        $this->ensureCloudBooted();

        return $this->pipeline->execute(
            message: $message,
            streaming: true,
            invoke: fn (string $augmented, string $runId, string $original): AgentResponse => $this->isDurable()
                ? $this->durableInvoke(RunState::start($runId, $augmented, $original), $onToken)
                : $this->agent->stream($augmented, $onToken, runId: $runId, originalMessage: $original),
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

        $conversation = Conversation::start($metadata);

        if ($this->memory !== null) {
            $this->memory->set($conversation->id, $conversation->toArray(), Conversation::MEMORY_NAMESPACE);
        }

        return $conversation;
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
     * @throws ToolException If the model calls an unregistered tool after the no-tools retry.
     */
    public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
    {
        $this->ensureCloudBooted();

        return $this->pipeline->executeInConversation(
            conversation: $conversation,
            message: $message,
            streaming: false,
            beforePersist: null,
            invoke: fn (string $augmented, array $history, string $runId, string $original): AgentResponse => $this->isDurable()
                ? $this->durableInvoke(RunState::start($runId, $augmented, $original, $history, $conversation->id))
                : $this->agent->run($augmented, $history, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Continue a saved durable run, claiming it first so a second process cannot repeat its work.
     *
     * @param  string  $runId  Id from RunSuspendedException.
     * @return AgentResponse The run's final answer.
     *
     * @throws RunStateException When the run is missing, malformed, finished, cancelled, already running elsewhere, or still waiting for a decision.
     * @throws RunSuspendedException When the run pauses again or spends this process's budget.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    public function resume(string $runId): AgentResponse
    {
        $this->ensureCloudBooted();
        $store = $this->requireRunStore();
        $state = $store->load($runId);
        $state->assertResumable();

        $claimed = $state->withStatus(RunStatus::Running);
        $store->save($claimed);
        $claimed = $claimed->withVersion($claimed->version + 1);

        AgentEventDispatcher::runResumed($runId, $state->status->value, $state->progress->iteration);

        return $this->pipeline->resume(
            $runId,
            $state->task->routingMessage,
            fn (): AgentResponse => $this->durableInvoke($claimed),
            $this->loadConversation($state->task->conversationId),
        );
    }

    /**
     * Saved runs waiting for a human decision on a paused call.
     *
     * @param  int  $limit  Most runs to return.
     * @return list<RunState>
     *
     * @throws RunStateException When no memory driver is configured.
     */
    public function pendingApprovals(int $limit = self::DEFAULT_PENDING_APPROVALS): array
    {
        return $this->requireRunStore()->findPending($limit);
    }

    /**
     * Approve the call a saved run is paused on; resume() then runs it.
     *
     * @param  string  $runId  Saved run id.
     * @param  string  $callId  Id of the paused call.
     * @return void
     *
     * @throws RunStateException When the run has no undecided paused call with that id.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    public function approve(string $runId, string $callId): void
    {
        $this->decide($runId, $callId, PausedBatch::APPROVED, '');
    }

    /**
     * Deny the call a saved run is paused on; resume() then hands the model the denial instead of running it.
     *
     * @param  string  $runId  Saved run id.
     * @param  string  $callId  Id of the paused call.
     * @param  string  $reason  Reason given with the decision, carried on the run.approved event.
     * @return void
     *
     * @throws RunStateException When the run has no undecided paused call with that id.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    public function deny(string $runId, string $callId, string $reason = ''): void
    {
        $this->decide($runId, $callId, PausedBatch::DENIED, $reason);
    }

    /**
     * Cancel a saved run; a running one stops at its next step and a finished one is left as it is.
     *
     * @param  string  $runId  Saved run id.
     * @param  string  $reason  Reason recorded on the event.
     * @return void
     *
     * @throws RunStateException When the run is missing or malformed.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    public function cancel(string $runId, string $reason = ''): void
    {
        $store = $this->requireRunStore();
        $state = $store->load($runId);

        if ($state->status->isTerminal()) {
            return;
        }

        $store->save($state->withStatus(RunStatus::Cancelled));
        AgentEventDispatcher::runCancelled($runId, $reason);
    }

    /**
     * Resume the saved runs that are due: suspended, decided, or left running by a stopped process.
     *
     * @param  int  $limit  Most runs to resume in this call.
     * @param  int  $timeBudgetSeconds  Seconds after which no further run is started; 0 = no limit.
     * @param  (callable(RunState, \Closure(): AgentResponse): mixed)|null  $around  Wraps each resume, for example to act as the run's owner; it must call the closure.
     * @return ResumeReport Counts of the runs resumed, by outcome.
     *
     * @throws RunStateException When no memory driver is configured.
     */
    public function resumeDue(int $limit = self::DEFAULT_RESUME_LIMIT, int $timeBudgetSeconds = self::DEFAULT_RESUME_SECONDS, ?callable $around = null): ResumeReport
    {
        $store = $this->requireRunStore();

        try {
            $due = $store->findDue($limit);
        } catch (\Throwable $e) {
            Log::warning('[phpClaw] Saved runs could not be listed: '.$e::class);

            return new ResumeReport;
        }

        $startedAt = microtime(as_float: true);
        $counts = [ResumeReport::COMPLETED => 0, ResumeReport::SUSPENDED => 0, ResumeReport::FAILED => 0, ResumeReport::SKIPPED => 0];

        foreach ($due as $state) {
            if ($timeBudgetSeconds > 0 && microtime(as_float: true) - $startedAt >= $timeBudgetSeconds) {
                break;
            }

            $counts[$this->resumeSavedRun($store, $state, $around)]++;
        }

        return new ResumeReport(...$counts);
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
     * @throws ToolException If the model calls an unregistered tool after the no-tools retry.
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
            invoke: fn (string $augmented, array $history, string $runId, string $original): AgentResponse => $this->isDurable()
                ? $this->durableInvoke(RunState::start($runId, $augmented, $original, $history, $conversation->id), $onToken)
                : $this->agent->stream($augmented, $onToken, $history, runId: $runId, originalMessage: $original),
        );
    }

    /**
     * Return the memory driver in use (if any), write-gated when message storage is off.
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
     * @return bool True when message content should be persisted to memory and cloud payloads.
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
     * Tool profile for the configured provider and model, the same resolution the adapters use for the tool cap.
     *
     * @return string One of the ToolProfileResolver PROFILE_* constants.
     */
    private function profile(): string
    {
        return ToolProfileResolver::resolve($this->config->providerName, $this->config->model);
    }

    /**
     * Build the final decorated provider and the underlying Agent from config: provider + tools + retry/history settings.
     *
     * @return array{0: ProviderInterface, 1: Agent} The provider Claw::sendStructured() reuses, and the Agent send()/stream() use.
     */
    private function buildProviderAndAgent(): array
    {
        $hasSkills = SkillRegistry::count() > 0;
        $config = $hasSkills
            ? $this->config->withSystemPrompt($this->config->systemPrompt.LoadSkillTool::systemPromptSection())
            : $this->config;
        $primary = $this->applyWebSearchTools($this->config->providerOverride ?? $config->buildProvider());
        $fallbacks = array_map(
            fn (ProviderInterface|array $fallback): ProviderInterface => $this->applyWebSearchTools($this->buildFallbackProvider($config, $fallback)),
            $this->config->fallbacks,
        );
        $provider = $fallbacks === [] ? $primary : new ProviderChain([$primary, ...$fallbacks]);
        $provider = $this->decorateProvider($provider, $config, $primary, $fallbacks);

        $toolRegistry = new ToolRegistry;
        if (! empty($this->config->tools)) {
            $toolRegistry->register($this->config->tools);
        }
        if ($hasSkills) {
            $toolRegistry->register([new LoadSkillTool]);
        }

        $agent = new Agent(
            provider: $provider,
            tools: $toolRegistry,
            maxIterations: $this->config->maxIterations,
            maxRetries: $this->config->maxRetries,
            maxHistoryLength: $this->config->maxHistoryLength,
            toolOutputGuard: new ToolOutputGuard(
                piiPatterns: $this->config->redactToolResultPii ? (new PiiDetectionGuard)->patterns() : [],
                piiExemptTools: $this->config->piiExemptTools,
            ),
            outputSanitiser: new OutputSanitiser($this->config->sanitiseOutput),
            compactHistory: $this->config->compactHistory,
            approvalGate: $this->config->approvalGate,
            toolRouter: new ToolRouter($this->config->maxToolsPerTurn, $this->profile() === ToolProfileResolver::PROFILE_MINIMAL ? 0.2 : 0.0),
            maxHistoryTokens: $this->config->maxHistoryTokens,
            maxToolResultTokens: $this->config->maxToolResultTokens,
            leanToolSchemas: $this->profile() === ToolProfileResolver::PROFILE_MINIMAL,
            requestBudgetTokens: ToolProfileResolver::requestBudget($this->profile()),
            fixedPromptTokens: (int) ceil(strlen($config->systemPrompt) / 4),
            maxTokenBudget: $this->config->maxTokenBudget,
        );

        return [$provider, $agent];
    }

    /**
     * Wrap the provider in the throttle, then the response cache outermost, so a cache hit takes no token.
     *
     * @param  ProviderInterface  $provider  Primary provider, or the ProviderChain built from it and its fallbacks.
     * @param  ClawConfig  $config  Configuration used to build the response-cache fingerprint (may carry the skills-augmented system prompt).
     * @param  ProviderInterface  $primary  Primary provider (with web-search tools already applied), used for the cache fingerprint.
     * @param  ProviderInterface[]  $fallbacks  Fallback providers (with web-search tools already applied), used for the cache fingerprint.
     * @return ProviderInterface The same provider, or a decorated chain.
     */
    private function decorateProvider(ProviderInterface $provider, ClawConfig $config, ProviderInterface $primary, array $fallbacks): ProviderInterface
    {
        if ($this->config->requestsPerMinute > 0) {
            $provider = new ThrottledProvider(
                $provider,
                $this->config->requestsPerMinute,
                $this->config->maxWaitMs,
                store: $this->config->rateLimitStore,
            );
        }

        if ($this->config->responseCache !== null) {
            $fingerprint = $this->buildProviderCacheFingerprint($config, $primary, $fallbacks);
            $provider = new CachedProvider($provider, $this->config->responseCache, $fingerprint, $this->config->responseCacheTtl);
        }

        return $provider;
    }

    /**
     * Build the response-cache fingerprint from the provider chain, the system prompt used and the generation settings.
     *
     * @param  ClawConfig  $config  Configuration carrying the system prompt actually used (with the skills section when present).
     * @param  ProviderInterface  $primary  Primary provider.
     * @param  ProviderInterface[]  $fallbacks  Fallback providers, in order.
     * @return string
     */
    private function buildProviderCacheFingerprint(ClawConfig $config, ProviderInterface $primary, array $fallbacks): string
    {
        $providers = array_map(
            static fn (ProviderInterface $p): array => [$p->name(), $p->model()],
            [$primary, ...$fallbacks],
        );

        return (string) json_encode([
            'providers' => $providers,
            'system_prompt' => $config->systemPrompt,
            'max_tokens' => $this->config->maxTokens,
            'thinking_budget' => $this->config->thinkingBudget,
            'provider_tool_classes' => $this->providerToolClassesForFingerprint($primary, $fallbacks),
        ]);
    }

    /**
     * Class names of the provider-native tools applyWebSearchTools() attached, or none without web search support.
     *
     * @param  ProviderInterface  $primary  Primary provider.
     * @param  ProviderInterface[]  $fallbacks  Fallback providers, in order.
     * @return list<string>
     */
    private function providerToolClassesForFingerprint(ProviderInterface $primary, array $fallbacks): array
    {
        $webSearchAttached = false;
        foreach ([$primary, ...$fallbacks] as $provider) {
            if ($provider instanceof SupportsWebSearchInterface) {
                $webSearchAttached = true;

                break;
            }
        }

        if (! $webSearchAttached) {
            return [];
        }

        if ($this->config->providerTools !== []) {
            return array_map(static fn (object $tool): string => $tool::class, $this->config->providerTools);
        }

        return [WebSearch::class];
    }

    /**
     * Attach the configured (or default) web-search provider tools when the provider supports them.
     *
     * @param  ProviderInterface  $provider  Provider to attach tools to.
     * @return ProviderInterface The same provider when unsupported, or a clone carrying the tools.
     */
    private function applyWebSearchTools(ProviderInterface $provider): ProviderInterface
    {
        if (! $provider instanceof SupportsWebSearchInterface) {
            return $provider;
        }

        return $provider->withProviderTools(
            $this->config->providerTools !== [] ? $this->config->providerTools : [new WebSearch]
        );
    }

    /**
     * Resolve one configured fallback entry into a real provider instance.
     *
     * @param  ClawConfig  $config  Configuration used to build a string fallback's provider.
     * @param  ProviderInterface|array{provider: string, model: string, apiKey: string}  $fallback  Pre-built provider used as given, or a provider/model/apiKey triple.
     * @return ProviderInterface
     */
    private function buildFallbackProvider(ClawConfig $config, ProviderInterface|array $fallback): ProviderInterface
    {
        if ($fallback instanceof ProviderInterface) {
            return $fallback;
        }

        return $config->withProvider($fallback['provider'], $fallback['model'], $fallback['apiKey'])->buildProvider();
    }

    /**
     * Whether runs are saved as they go (durable runs or suspendable approval switched on at build time).
     *
     * @return bool
     */
    private function isDurable(): bool
    {
        return $this->config->durableRuns !== null;
    }

    /**
     * Run or continue a durable run in this process, from the version it was read at.
     *
     * @param  RunState  $state  A new run from RunState::start(), or a saved run ready to continue.
     * @param  (callable(string): void)|null  $onToken  Receives the final answer's chunks; null for a plain run.
     * @return AgentResponse
     *
     * @throws RunSuspendedException When the run pauses, spends its budget, or is cancelled.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    private function durableInvoke(RunState $state, ?callable $onToken = null): AgentResponse
    {
        return $this->runDurably(
            $state->runId(),
            fn (RunCheckpoint $checkpoint): AgentResponse => $this->agent->runDurable($state, $checkpoint, $onToken),
            $state->version,
        );
    }

    /**
     * The stored conversation a run belongs to, or null for a send() run or a conversation deleted meanwhile.
     *
     * @param  string|null  $conversationId  Conversation id carried on the run.
     * @return Conversation|null
     */
    private function loadConversation(?string $conversationId): ?Conversation
    {
        if ($conversationId === null || $this->memory === null) {
            return null;
        }

        $stored = $this->memory->get($conversationId, Conversation::MEMORY_NAMESPACE);

        return is_array($stored) ? Conversation::fromArray($stored) : null;
    }

    /**
     * Run a durable invocation in this process and record whether it completed or failed.
     *
     * @param  string  $runId  Run id.
     * @param  \Closure(RunCheckpoint): AgentResponse  $start  Runs the agent with the checkpoint.
     * @param  int  $version  Version of the saved run this process starts from; 0 for a new run.
     * @return AgentResponse
     *
     * @throws RunSuspendedException When the run pauses, spends its budget, or is cancelled.
     * @throws RunConflictException When another process saved the run meanwhile.
     * @throws RunStateException When no memory driver is configured.
     */
    private function runDurably(string $runId, \Closure $start, int $version = 0): AgentResponse
    {
        $store = $this->requireRunStore();

        try {
            $response = $start(new RunCheckpoint($store, $this->config->durableRuns ?? new RunBudget, $version));
        } catch (RunSuspendedException|RunConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $store->finish($runId, RunStatus::Failed);
            throw $e;
        }

        $store->finish($runId, RunStatus::Completed);

        return $response;
    }

    /**
     * Record a decision on the call a saved run is paused on.
     *
     * @param  string  $runId  Saved run id.
     * @param  string  $callId  Id of the paused call.
     * @param  string  $decision  PausedBatch::APPROVED or DENIED.
     * @param  string  $reason  Reason given with the decision, carried on the run.approved event.
     * @return void
     *
     * @throws RunStateException When the run has no undecided paused call with that id.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    private function decide(string $runId, string $callId, string $decision, string $reason): void
    {
        $store = $this->requireRunStore();
        $state = $store->load($runId);
        $paused = $state->paused;

        if (! $state->isAwaitingDecision() || $paused === null || $paused->callId() !== $callId) {
            throw new RunStateException("Run {$runId} has no undecided paused call {$callId}.");
        }

        $store->save($state->withStatus(RunStatus::AwaitingApproval, $paused->withDecision($decision)));
        AgentEventDispatcher::runApproved($runId, $callId, $paused->toolName(), $decision, $reason);
    }

    /**
     * Resume one due run and return its outcome, skipping a run another process still owns.
     *
     * @param  RunStore  $store  Store the run was listed from.
     * @param  RunState  $state  Due run, as listed.
     * @param  (callable(RunState, \Closure(): AgentResponse): mixed)|null  $around  Wraps the resume when given.
     * @return string One of the ResumeReport outcome constants.
     */
    private function resumeSavedRun(RunStore $store, RunState $state, ?callable $around): string
    {
        try {
            if ($state->status === RunStatus::Running) {
                $store->save($state->withStatus(RunStatus::Suspended));
            }

            $resume = fn (): AgentResponse => $this->resume($state->runId());
            $around === null ? $resume() : $around($state, $resume);

            return ResumeReport::COMPLETED;
        } catch (RunSuspendedException) {
            return ResumeReport::SUSPENDED;
        } catch (RunConflictException|RunStateException) {
            return ResumeReport::SKIPPED;
        } catch (\Throwable $e) {
            Log::warning("[phpClaw] Saved run {$state->runId()} failed on resume: ".$e::class);

            return ResumeReport::FAILED;
        }
    }

    /**
     * Return the store durable runs are saved in, or refuse when no memory driver is configured.
     *
     * @return RunStore
     *
     * @throws RunStateException When no memory driver is configured.
     */
    private function requireRunStore(): RunStore
    {
        if ($this->memory === null) {
            throw new RunStateException('Durable runs need a memory driver: none is configured.');
        }

        return new RunStore($this->memory);
    }

    /**
     * Wrap the memory driver so writes are dropped when message storage is off; reads still pass through.
     *
     * @param  MemoryInterface|null  $memory  Configured memory driver.
     * @return MemoryInterface|null The driver to use.
     */
    private function gatedMemory(?MemoryInterface $memory): ?MemoryInterface
    {
        if ($memory === null || $this->storeMessages) {
            return $memory;
        }

        if ($memory instanceof PrivacyAwareMemory && ! $memory->storeMessages()) {
            return $memory;
        }

        return new PrivacyAwareMemory($memory, storeMessages: false);
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
        if ($this->isCloudBooted) {
            return;
        }
        $this->bootCloud();
        $this->isCloudBooted = true;
    }

    /**
     * Boot phpClaw Cloud features when a cloud key is configured, storeMessages is on, and the optional `phpclaw/phpclaw-cloud` package is installed.
     *
     * @return void
     */
    private function bootCloud(): void
    {
        if (! $this->config->isCloudEnabled() || ! $this->config->storeMessages) {
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
