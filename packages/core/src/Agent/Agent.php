<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Config\LoopConfig;
use PhpClaw\Exceptions\ApprovalPendingException;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Guards\ToolOutputGuard;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ToolRouter;

/**
 * ReAct agent loop: orchestrates provider, tools, and message history.
 */
final class Agent
{
    public const DEFAULT_MAX_ITERATIONS = LoopConfig::DEFAULT_MAX_ITERATIONS;

    public const DEFAULT_MAX_RETRIES = 0;

    public const DEFAULT_MAX_HISTORY_LENGTH = 0;

    private const CHARS_PER_TOKEN_ESTIMATE = 4;

    private const STREAM_CHUNK_BYTES = 10;

    private const NANOS_PER_MILLISECOND = 1_000_000;

    private const RESPONSE_TYPE_TEXT = 'text';

    private const RESPONSE_TYPE_TOOL_BATCH = 'tool_use_batch';

    private const DENIED_BY_HUMAN = 'Action denied by human. Propose an alternative approach or ask what to do instead.';

    private const DENIED_CANNOT_PAUSE = 'This action needs human approval and this run cannot pause for it. Propose an alternative approach or ask what to do instead.';

    private readonly ProviderInterface $provider;

    private readonly ToolRegistry $tools;

    private readonly int $maxIterations;

    private readonly int $maxRetries;

    private readonly int $maxHistoryLength;

    private readonly ?ApprovalGateInterface $approvalGate;

    private readonly ?ToolRouter $toolRouter;

    private readonly int $maxToolResultTokens;

    private readonly bool $leanToolSchemas;

    private readonly int $requestBudgetTokens;

    private readonly int $fixedPromptTokens;

    private readonly int $maxTokenBudget;

    private readonly ToolOutputGuard $toolOutputGuard;

    private readonly OutputSanitiser $outputSanitiser;

    private readonly HistoryCompactor $historyCompactor;

    private readonly ProviderRetryLoop $retryLoop;

    /**
     * Build the ReAct agent with its provider, tool registry, and runtime limits.
     *
     * @param  ProviderInterface  $provider  LLM provider that backs each iteration.
     * @param  ToolRegistry  $tools  Registered tools available to the loop.
     * @param  int  $maxIterations  ReAct loop cap before throwing MaxIterationsException.
     * @param  int  $maxRetries  Retry transient provider failures up to N times.
     * @param  int  $maxHistoryLength  Compact history when it exceeds this message count. 0 = disabled.
     * @param  ToolOutputGuard|null  $toolOutputGuard  Optional. Tool-output sanitiser; defaults to a new ToolOutputGuard.
     * @param  OutputSanitiser|null  $outputSanitiser  Optional. Final-text sanitiser; defaults to a new OutputSanitiser.
     * @param  bool  $compactHistory  When true, history is compacted via HistoryCompactor once it exceeds the limits.
     * @param  ?ApprovalGateInterface  $approvalGate  Optional human-approval gate consulted before mutating tools run.
     * @param  ?ToolRouter  $toolRouter  Optional router that selects the tool subset offered per iteration.
     * @param  int  $maxHistoryTokens  Compact history when its token estimate exceeds this value. 0 = disabled.
     * @param  int  $maxToolResultTokens  Cut a single tool result at this estimated-token ceiling and append a marker. 0 = disabled.
     * @param  bool  $leanToolSchemas  When true, tool schemas are sent in their lean form.
     * @param  int  $requestBudgetTokens  Drop the lowest-ranked routed tools until the estimated request fits this budget. 0 = disabled.
     * @param  int  $fixedPromptTokens  Estimated tokens of the fixed prompt parts, counted against the request budget.
     * @param  int  $maxTokenBudget  Total input+output token spend ceiling across the run; checked between provider calls only, so one reply can overshoot; compaction summaries are not counted; the stream fast path (no tools) is not budgeted. 0 = unlimited.
     */
    public function __construct(
        ProviderInterface $provider,
        ToolRegistry $tools,
        int $maxIterations = self::DEFAULT_MAX_ITERATIONS,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $maxHistoryLength = self::DEFAULT_MAX_HISTORY_LENGTH,
        ?ToolOutputGuard $toolOutputGuard = null,
        ?OutputSanitiser $outputSanitiser = null,
        bool $compactHistory = true,
        ?ApprovalGateInterface $approvalGate = null,
        ?ToolRouter $toolRouter = null,
        int $maxHistoryTokens = 0,
        int $maxToolResultTokens = 0,
        bool $leanToolSchemas = false,
        int $requestBudgetTokens = 0,
        int $fixedPromptTokens = 0,
        int $maxTokenBudget = 0,
    ) {
        $this->provider = $provider;
        $this->tools = $tools;
        $this->maxIterations = $maxIterations;
        $this->maxRetries = $maxRetries;
        $this->maxHistoryLength = $maxHistoryLength;
        $this->toolOutputGuard = $toolOutputGuard ?? new ToolOutputGuard;
        $this->outputSanitiser = $outputSanitiser ?? new OutputSanitiser;
        $this->approvalGate = $approvalGate;
        $this->toolRouter = $toolRouter;
        $this->maxToolResultTokens = $maxToolResultTokens;
        $this->leanToolSchemas = $leanToolSchemas;
        $this->requestBudgetTokens = $requestBudgetTokens;
        $this->fixedPromptTokens = $fixedPromptTokens;
        $this->maxTokenBudget = $maxTokenBudget;
        $this->historyCompactor = new HistoryCompactor($this->provider, $this->maxHistoryLength, $compactHistory, $maxHistoryTokens);
        $this->retryLoop = new ProviderRetryLoop($this->provider, $this->maxRetries);
    }

    /**
     * Run the ReAct loop for a single user message.
     *
     * @param  string  $message  The user message to send.
     * @param  Message[]  $history  Prior conversation history (empty for single-turn).
     * @param  string  $runId  Optional run identifier propagated to hooks for run correlation.
     * @param  string  $originalMessage  The user message before memory and skill context were prepended; used for tool routing. Empty falls back to $message.
     * @return AgentResponse The terminal text response produced by the loop.
     *
     * @throws MaxIterationsException When the loop exceeds maxIterations without a text response.
     * @throws ToolException When a tool execution or hallucination cannot be recovered.
     * @throws ProviderException When the upstream provider fails after all retries.
     * @throws TokenBudgetExceededException When the accumulated token spend would exceed maxTokenBudget.
     */
    public function run(string $message, array $history = [], string $runId = '', string $originalMessage = ''): AgentResponse
    {
        return $this->executeIterationLoop(RunState::start($runId, $message, self::resolveRoutingMessage($message, $originalMessage), $history), onToken: null);
    }

    /**
     * Stream a response, calling $onToken for each text chunk.
     *
     * @param  string  $message  The user message to send.
     * @param  callable(string): void  $onToken  Called with each text chunk as it arrives.
     * @param  Message[]  $history  Prior conversation history (empty for single-turn).
     * @param  string  $runId  Optional run identifier propagated to hooks for run correlation.
     * @param  string  $originalMessage  The user message before memory and skill context were prepended; used for tool routing. Empty falls back to $message.
     * @return AgentResponse The terminal AgentResponse once streaming completes.
     *
     * @throws MaxIterationsException When the loop exceeds maxIterations without a text response.
     * @throws ToolException When a tool execution fails fatally.
     * @throws ProviderException When the upstream provider fails after all retries.
     * @throws TokenBudgetExceededException When tools are registered and the accumulated token spend would exceed maxTokenBudget; the no-tools stream fast path is never budgeted.
     */
    public function stream(string $message, callable $onToken, array $history = [], string $runId = '', string $originalMessage = ''): AgentResponse
    {
        HookDispatcher::streamStart($message, $this->provider->name(), $this->provider->model(), $runId);

        if (empty($this->tools->schemas($this->provider->name()))) {
            $startNs = hrtime(true);
            $history[] = Message::user($message);

            return $this->streamFastPath($message, $onToken, $history, $startNs, $runId);
        }

        return $this->executeIterationLoop(RunState::start($runId, $message, self::resolveRoutingMessage($message, $originalMessage), $history), $onToken);
    }

    /**
     * Run or continue a durable run, saving it after every step.
     *
     * @param  RunState  $state  A new run from RunState::start(), or a saved run that RunState::assertResumable() accepted.
     * @param  RunCheckpoint  $checkpoint  Saves the run and holds this process's budget.
     * @param  (callable(string): void)|null  $onToken  Receives the final answer's chunks; null for a plain run.
     * @return AgentResponse The terminal text response produced by the loop.
     *
     * @throws RunSuspendedException When the run pauses for approval, spends its budget, or finds itself cancelled.
     * @throws RunConflictException When another process saved the run meanwhile.
     * @throws MaxIterationsException When the loop exceeds maxIterations without a text response.
     * @throws ToolException When a tool execution or hallucination cannot be recovered.
     * @throws ProviderException When the upstream provider fails after all retries.
     * @throws TokenBudgetExceededException When the accumulated token spend would exceed maxTokenBudget.
     */
    public function runDurable(RunState $state, RunCheckpoint $checkpoint, ?callable $onToken = null): AgentResponse
    {
        if ($onToken !== null) {
            HookDispatcher::streamStart($state->task->message, $this->provider->name(), $this->provider->model(), $state->runId());
        }

        return $this->executeIterationLoop($state, $onToken, $checkpoint);
    }

    /**
     * Drop the lowest-ranked tools until the estimated request fits the profile budget; never below one tool.
     *
     * @param  array<int, array<string, mixed>>  $toolSchemas  Routed schemas.
     * @param  Message[]  $history  History including the current user message.
     * @return array<int, array<string, mixed>> Schemas that fit, in the router's alphabetical order.
     */
    private function fitToolsToBudget(array $toolSchemas, array $history): array
    {
        if ($this->requestBudgetTokens <= 0 || $this->toolRouter === null) {
            return $toolSchemas;
        }

        $order = array_flip($this->toolRouter->lastRankedNames());
        $byName = [];
        foreach ($toolSchemas as $schema) {
            $byName[strtolower((string) ($schema['name'] ?? ($schema['function']['name'] ?? '')))] = $schema;
        }
        uksort($byName, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        $base = $this->fixedPromptTokens + $this->historyCompactor->estimateTokens($history);
        while (count($byName) > 1) {
            $estimate = $base + (int) ceil(strlen((string) json_encode(array_values($byName))) / self::CHARS_PER_TOKEN_ESTIMATE);
            if ($estimate <= $this->requestBudgetTokens) {
                break;
            }
            array_pop($byName);
        }

        $kept = array_values($byName);
        usort($kept, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? $a['function']['name']), (string) ($b['name'] ?? $b['function']['name'])));

        return $kept;
    }

    /**
     * Run the ReAct loop from $state; with a $checkpoint the run is saved between steps.
     *
     * @param  RunState  $state  Run to start or continue.
     * @param  (callable(string): void)|null  $onToken  null = blocking mode, callable = streaming mode.
     * @param  RunCheckpoint|null  $checkpoint  Saves a durable run and holds this process's budget; null = not durable.
     * @return AgentResponse The terminal text response produced by the loop.
     *
     * @throws MaxIterationsException When the loop exhausts all iterations without a text response.
     * @throws ToolException When a tool is not registered or fails fatally.
     * @throws ProviderException When the provider fails after all retries.
     * @throws TokenBudgetExceededException When the accumulated token spend would exceed maxTokenBudget.
     * @throws RunSuspendedException When a durable run pauses, spends its budget, or finds itself cancelled.
     * @throws RunConflictException When another process saved the durable run meanwhile.
     */
    private function executeIterationLoop(RunState $state, ?callable $onToken, ?RunCheckpoint $checkpoint = null): AgentResponse
    {
        $streaming = $onToken !== null;
        $startNs = hrtime(true);
        $runId = $state->runId();
        $message = $state->task->message;
        $refusal = null;
        $progress = $this->prepareProgress($state, $checkpoint, $refusal);

        if ($refusal !== null) {
            return $this->buildTextResponse(['text' => $refusal], $progress->iteration, $startNs, $progress->tools->called, $runId);
        }

        $history = $progress->messages;
        $toolsCalled = $progress->tools->called;
        $failedCalls = $progress->tools->failed;
        $tokensSpent = $progress->tokensSpent;
        $isHallucinationRetry = $progress->isHallucinationRetry;
        $toolSchemas = $isHallucinationRetry ? [] : $this->resolveToolSchemas($state->task->routingMessage, $history);
        $firstIteration = $progress->iteration + 1;

        for ($iteration = $firstIteration; $iteration <= $this->maxIterations; $iteration++) {
            if ($checkpoint !== null && $iteration > $firstIteration) {
                $this->saveCheckpoint($checkpoint, $state, new RunProgress($history, $iteration - 1, $tokensSpent, isHallucinationRetry: $isHallucinationRetry, tools: self::buildToolLog($toolsCalled, $failedCalls)), $iteration - $firstIteration, $startNs);
            }

            $iterationId = $this->buildIterationId($runId, $iteration);

            HookDispatcher::agentIteration($iteration, $message, $this->provider->name(), $this->provider->model(), streaming: $streaming, runId: $runId, history: $history, parentRunId: $iterationId);

            $history = $this->historyCompactor->applyIfOversized($history, $message, $runId, $iterationId);

            $this->enforceTokenBudget($tokensSpent, $history, $runId, $iterationId);

            $response = $this->requestProvider($history, $toolSchemas, $isHallucinationRetry, $iteration, $streaming, $runId, $iterationId);

            if ($response === null) {
                continue;
            }

            $this->fireProviderResponseHooks($response, streaming: $streaming, runId: $runId, iterationId: $iterationId);
            $tokensSpent += (int) ($response['input_tokens'] ?? 0) + (int) ($response['output_tokens'] ?? 0);

            if ($this->shouldRetryWithoutTools($response, $toolSchemas, $isHallucinationRetry, $runId, $iterationId)) {
                continue;
            }

            if ($response['type'] === self::RESPONSE_TYPE_TEXT) {
                return $this->finaliseTextResponse($response, $message, $iteration, $startNs, $toolsCalled, $runId, $onToken);
            }

            if ($response['type'] === self::RESPONSE_TYPE_TOOL_BATCH) {
                $calls = array_values($response['calls'] ?? []);
                $refusal = null;
                $results = $this->executeToolBatch($calls, $iteration, $runId, $iterationId, $toolsCalled, $failedCalls, $refusal, canPause: $checkpoint !== null);

                if ($checkpoint !== null && count($results) < count($calls)) {
                    $this->pauseForApproval($checkpoint, $state, new RunProgress($history, $iteration, $tokensSpent, isHallucinationRetry: $isHallucinationRetry, tools: self::buildToolLog($toolsCalled, $failedCalls)), PausedBatch::now($calls, $results, count($results)));
                }

                if ($refusal !== null) {
                    return $this->buildTextResponse(['text' => $refusal], $iteration, $startNs, $toolsCalled, $runId);
                }

                $history[] = Message::toolBatch($calls, $results);
            }
        }

        $this->throwMaxIterations($message, $runId);
    }

    /**
     * Send one provider request; null when the provider rejected a hallucinated tool and the run retries without tools.
     *
     * @param  Message[]  $history  Conversation history for this request.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Tool schemas offered; emptied on the retry.
     * @param  bool  $isHallucinationRetry  Whether the one retry without tools is already spent; set on the retry.
     * @param  int  $iteration  Current iteration number.
     * @param  bool  $streaming  Whether the run streams.
     * @param  string  $runId  Run id.
     * @param  string  $iterationId  Id of this iteration.
     * @return array<string, mixed>|null The provider response, or null to retry.
     *
     * @throws ProviderException When the request fails for any other reason.
     */
    private function requestProvider(array $history, array &$toolSchemas, bool &$isHallucinationRetry, int $iteration, bool $streaming, string $runId, string $iterationId): ?array
    {
        HookDispatcher::providerRequest(
            provider: $this->provider->name(),
            model: $this->provider->model(),
            historyLen: count($history),
            toolCount: count($toolSchemas),
            streaming: $streaming,
            runId: $runId,
            parentRunId: $iterationId,
        );

        try {
            return $this->retryLoop->send($history, $toolSchemas, $iteration, streaming: $streaming, runId: $runId, parentRunId: $iterationId);
        } catch (ProviderException $e) {
            if (! $this->retryLoop->isHallucinationRejection($e) || $isHallucinationRetry) {
                throw $e;
            }

            $this->triggerHallucinationRetry(
                $isHallucinationRetry,
                $toolSchemas,
                '(provider-rejected)',
                'Provider rejected request due to hallucinated tool; retrying once with no tools: '.$e->getMessage(),
                $runId,
                $iterationId,
            );

            return null;
        }
    }

    /**
     * Whether to spend the one retry without tools: an empty answer that lost its tool call, or calls to unregistered tools.
     *
     * @param  array<string, mixed>  $response  Provider response.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Tool schemas offered; emptied on the retry.
     * @param  bool  $isHallucinationRetry  Whether the retry is already spent; set when it starts.
     * @param  string  $runId  Run id.
     * @param  string  $iterationId  Id of this iteration.
     * @return bool True when the run retries without tools.
     */
    private function shouldRetryWithoutTools(array $response, array &$toolSchemas, bool &$isHallucinationRetry, string $runId, string $iterationId): bool
    {
        if ($isHallucinationRetry) {
            return false;
        }

        if ($response['type'] === self::RESPONSE_TYPE_TEXT && $this->isLostToolCall($response, $toolSchemas)) {
            $this->triggerHallucinationRetry($isHallucinationRetry, $toolSchemas, '(empty-response)', 'Model spent output tokens but returned no text and no tool call; retrying once with no tools.', $runId, $iterationId);

            return true;
        }

        $hallucinated = $response['type'] === self::RESPONSE_TYPE_TOOL_BATCH ? $this->detectHallucinatedToolNames(array_values($response['calls'] ?? [])) : [];

        if ($hallucinated === []) {
            return false;
        }

        $this->triggerHallucinationRetry($isHallucinationRetry, $toolSchemas, implode(',', $hallucinated), 'Model hallucinated unregistered tool(s); retrying once with no tools.', $runId, $iterationId);

        return true;
    }

    /**
     * Restore a saved run's tool state and finish its paused batch once decided.
     *
     * @param  RunState  $state  Run to start or continue.
     * @param  RunCheckpoint|null  $checkpoint  Saves a durable run; null = not durable.
     * @param  string|null  $refusal  Set to the failure message when the finished batch repeats a failure verbatim.
     * @return RunProgress Progress the loop continues from.
     *
     * @throws RunSuspendedException When a later call in the paused batch needs approval too.
     * @throws RunConflictException When another process saved the run meanwhile.
     * @throws ToolException When the approved call names a tool that is not registered.
     */
    private function prepareProgress(RunState $state, ?RunCheckpoint $checkpoint, ?string &$refusal): RunProgress
    {
        $this->tools->resetRunState();
        self::restoreReadPaths($state->progress->tools->readPaths);

        if ($checkpoint === null || $state->paused === null || $state->paused->decision === null) {
            return $state->progress;
        }

        return $this->finishPausedBatch($state, $state->paused, $checkpoint, $refusal);
    }

    /**
     * Tool schemas for the provider, routed and fitted to the request budget the same way on a fresh run and on a resume.
     *
     * @param  string  $routingMessage  Original user message the router ranks tools against.
     * @param  Message[]  $history  History including the current user message.
     * @return array<int, array<string, mixed>> Schemas offered on each request.
     */
    private function resolveToolSchemas(string $routingMessage, array $history): array
    {
        $toolSchemas = $this->tools->schemas($this->provider->name(), $this->leanToolSchemas);

        if ($this->toolRouter === null) {
            return $toolSchemas;
        }

        $toolSchemas = $this->toolRouter->filter($toolSchemas, $routingMessage, $this->provider->model(), $this->tools->routingMetadata());

        return $this->fitToolsToBudget($toolSchemas, $history);
    }

    /**
     * Save a step of a durable run, and stop this process when the run was cancelled or the budget is spent.
     *
     * @param  RunCheckpoint  $checkpoint  Saves the run and holds this process's budget.
     * @param  RunState  $state  The run, for its id and task.
     * @param  RunProgress  $progress  Progress after the last completed step.
     * @param  int  $stepsRun  Steps run in this process so far.
     * @param  int  $startNs  hrtime(true) when this process started the loop.
     * @return void
     *
     * @throws RunSuspendedException When the run was cancelled or this process spent its budget.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    private function saveCheckpoint(RunCheckpoint $checkpoint, RunState $state, RunProgress $progress, int $stepsRun, int $startNs): void
    {
        $shouldSuspend = $checkpoint->isBudgetSpent($stepsRun, $startNs);
        $stored = $checkpoint->save(new RunState($state->task, $shouldSuspend ? RunStatus::Suspended : RunStatus::Running, $progress));

        if ($stored === RunStatus::Cancelled || $shouldSuspend) {
            throw new RunSuspendedException($state->runId(), $stored === RunStatus::Cancelled ? RunStatus::Cancelled : RunStatus::Suspended);
        }
    }

    /**
     * Save a run paused on a call that needs approval, then stop this process.
     *
     * @param  RunCheckpoint  $checkpoint  Saves the run.
     * @param  RunState  $state  The run, for its id and task.
     * @param  RunProgress  $progress  Progress up to the paused batch.
     * @param  PausedBatch  $paused  The batch and the call waiting for a decision.
     * @return never
     *
     * @throws RunSuspendedException Always: AwaitingApproval, or Cancelled when the run was cancelled meanwhile.
     * @throws RunConflictException When another process saved the run meanwhile.
     */
    private function pauseForApproval(RunCheckpoint $checkpoint, RunState $state, RunProgress $progress, PausedBatch $paused): never
    {
        $stored = $checkpoint->save(new RunState($state->task, RunStatus::AwaitingApproval, $progress, $paused));

        throw new RunSuspendedException($state->runId(), $stored === RunStatus::Cancelled ? RunStatus::Cancelled : RunStatus::AwaitingApproval);
    }

    /**
     * Finish the batch a run paused in, pausing again if a later call needs approval.
     *
     * @param  RunState  $state  Saved run, AwaitingApproval with a decision recorded.
     * @param  PausedBatch  $paused  The decided batch.
     * @param  RunCheckpoint  $checkpoint  Saves the run if it pauses again.
     * @param  string|null  $refusal  Set to the failure message when a call repeats a failure verbatim.
     * @return RunProgress Progress with the finished batch appended.
     *
     * @throws RunSuspendedException When a later call in the batch needs approval too.
     * @throws RunConflictException When another process saved the run meanwhile.
     * @throws ToolException When the approved call names a tool that is not registered.
     */
    private function finishPausedBatch(RunState $state, PausedBatch $paused, RunCheckpoint $checkpoint, ?string &$refusal): RunProgress
    {
        $progress = $state->progress;
        $toolsCalled = $progress->tools->called;
        $failedCalls = $progress->tools->failed;
        $iteration = $progress->iteration;

        $iterationId = $this->buildIterationId($state->runId(), $iteration);
        $result = $this->runDecidedCall($paused, $iteration, $state->runId(), $iterationId);
        $this->recordToolFailure($paused->toolName(), $paused->input(), $result, $failedCalls, $refusal);

        $rest = array_slice($paused->calls, $paused->index + 1);
        $later = $this->executeToolBatch($rest, $iteration, $state->runId(), $iterationId, $toolsCalled, $failedCalls, $refusal, canPause: true);
        $results = $paused->results + [$paused->callId() => $result] + $later;

        if (count($later) < count($rest)) {
            $done = new RunProgress($progress->messages, $iteration, $progress->tokensSpent, isHallucinationRetry: $progress->isHallucinationRetry, tools: self::buildToolLog($toolsCalled, $failedCalls));
            $this->pauseForApproval($checkpoint, $state, $done, PausedBatch::now($paused->calls, $results, $paused->index + 1 + count($later)));
        }

        return new RunProgress(
            [...$progress->messages, Message::toolBatch($paused->calls, $results)],
            $iteration,
            $progress->tokensSpent,
            isHallucinationRetry: $progress->isHallucinationRetry,
            tools: self::buildToolLog($toolsCalled, $failedCalls),
        );
    }

    /**
     * Run the paused call when it was approved, or hand back the denial when it was denied, and fire tool.after for it.
     *
     * @param  PausedBatch  $paused  The decided batch.
     * @param  int  $iteration  Iteration the batch belongs to.
     * @param  string  $runId  Run id, for hooks.
     * @param  string  $iterationId  Parent run id for nesting the hook under the iteration.
     * @return string The call's result.
     *
     * @throws ToolException When the approved call names a tool that is not registered.
     */
    private function runDecidedCall(PausedBatch $paused, int $iteration, string $runId, string $iterationId): string
    {
        $result = $paused->decision === PausedBatch::APPROVED
            ? $this->executeTool($paused->toolName(), $paused->input(), $runId)
            : $this->buildDeniedResult($paused->toolName(), self::DENIED_BY_HUMAN);

        HookDispatcher::toolAfter($paused->toolName(), $paused->input(), $result, $iteration, $runId, parentRunId: $iterationId);

        return $result;
    }

    /**
     * The tool log a saved run carries: the given counters plus every file read so far.
     *
     * @param  list<string>  $called  Tools called so far.
     * @param  array<string, bool>  $failed  Failure signatures so far.
     * @return RunToolLog
     */
    private static function buildToolLog(array $called, array $failed): RunToolLog
    {
        return new RunToolLog($called, $failed, FileReadLog::all());
    }

    /**
     * Mark every file a saved run had read as read again, after the per-run reset, so the read-before-edit gate holds.
     *
     * @param  array<string, list<string>>  $readPaths  Canonical paths read, keyed by workspace root.
     * @return void
     */
    private static function restoreReadPaths(array $readPaths): void
    {
        foreach ($readPaths as $workspaceRoot => $paths) {
            foreach ($paths as $path) {
                FileReadLog::markRead((string) $workspaceRoot, (string) $path);
            }
        }
    }

    /**
     * The message tools are routed against: the original user message, or the sent message when there is none.
     *
     * @param  string  $message  Message sent to the model.
     * @param  string  $originalMessage  User message before context was prepended; empty when not given.
     * @return string
     */
    private static function resolveRoutingMessage(string $message, string $originalMessage): string
    {
        return $originalMessage === '' ? $message : $originalMessage;
    }

    /**
     * Build the terminal text AgentResponse.
     *
     * @param  array<string, mixed>  $response  Raw provider response (must be type=text).
     * @param  string  $message  Original user message; passed to stream.end for context.
     * @param  int  $iterations  Loop iteration count that produced this response.
     * @param  int  $startNs  hrtime(true) marker captured before the loop started.
     * @param  list<string>  $toolsCalled  Names of every tool invoked this run, in call order.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  (callable(string): void)|null  $onToken  Streaming callback; null in blocking mode.
     * @return AgentResponse The terminal response, sanitised and timestamped.
     */
    private function finaliseTextResponse(
        array $response,
        string $message,
        int $iterations,
        int $startNs,
        array $toolsCalled,
        string $runId,
        ?callable $onToken,
    ): AgentResponse {
        if ($onToken === null) {
            return $this->buildTextResponse($response, $iterations, $startNs, $toolsCalled, $runId);
        }

        $text = $this->outputSanitiser->sanitise((string) ($response['text'] ?? ''));
        $providerName = $this->provider->name();
        $providerModel = $this->provider->model();

        foreach (mb_str_split($text, self::STREAM_CHUNK_BYTES) as $chunk) {
            HookDispatcher::providerToken($chunk, $providerName, $providerModel);
            $onToken($chunk);
        }

        $durationMs = $this->elapsedMilliseconds($startNs);
        HookDispatcher::streamEnd($message, $providerName, $providerModel, $durationMs, mb_strlen($text), $runId);

        return $this->buildTextResponse($response, $iterations, $startNs, $toolsCalled, $runId, sanitisedText: $text);
    }

    /**
     * Stream a response directly from the provider when no tools are registered (single round-trip, no ReAct loop).
     *
     * @param  string  $message  Original user message; passed to stream.end for context.
     * @param  callable(string): void  $onToken  Called with each text chunk as it arrives.
     * @param  Message[]  $history  Prior conversation history (with current user message already appended).
     * @param  int  $startNs  hrtime(true) marker captured before streaming started.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @return AgentResponse Single-iteration response carrying the full streamed text.
     */
    private function streamFastPath(string $message, callable $onToken, array $history, int $startNs, string $runId): AgentResponse
    {
        HookDispatcher::providerRequest(
            provider: $this->provider->name(),
            model: $this->provider->model(),
            historyLen: count($history),
            toolCount: 0,
            streaming: true,
            runId: $runId,
        );

        $providerName = $this->provider->name();
        $providerModel = $this->provider->model();

        $wrappedOnToken = static function (string $token) use ($onToken, $providerName, $providerModel): void {
            HookDispatcher::providerToken($token, $providerName, $providerModel);
            $onToken($token);
        };

        try {
            $fullText = $this->provider->stream($history, $wrappedOnToken);
        } catch (\Throwable $e) {
            HookDispatcher::streamAbort($this->provider->name(), $this->provider->model(), $e->getMessage(), $runId);
            throw $e;
        }

        HookDispatcher::providerResponse(
            provider: $this->provider->name(),
            model: $this->provider->model(),
            type: 'text',
            streaming: true,
            runId: $runId,
        );

        $durationMs = $this->elapsedMilliseconds($startNs);
        HookDispatcher::streamEnd($message, $this->provider->name(), $this->provider->model(), $durationMs, mb_strlen($fullText), $runId);

        return new AgentResponse(
            text: $fullText,
            provider: $this->provider->name(),
            model: $this->provider->model(),
            iterations: 1,
            durationMs: $durationMs,
            runId: $runId,
        );
    }

    /**
     * Compose the per-iteration ID used for nested hook parent_run_id tracking.
     *
     * @param  string  $runId  Root run identifier; empty disables nesting.
     * @param  int  $iteration  Iteration number (1-based).
     * @return string "{runId}_I{iteration}" when runId is non-empty, otherwise ''.
     */
    private function buildIterationId(string $runId, int $iteration): string
    {
        return $runId !== '' ? $runId.'_I'.$iteration : '';
    }

    /**
     * Fire the provider.response hook + cache.hit hook (if applicable).
     *
     * @param  array<string, mixed>  $response  Raw provider response (text or tool_use_batch shape).
     * @param  bool  $streaming  Whether this iteration is in streaming mode.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $iterationId  Parent run id for nesting these hooks under the current iteration.
     * @return void
     */
    private function fireProviderResponseHooks(array $response, bool $streaming, string $runId, string $iterationId): void
    {
        HookDispatcher::providerResponse(
            provider: $this->provider->name(),
            model: $this->provider->model(),
            type: $response['type'] ?? 'unknown',
            inputTokens: $response['input_tokens'] ?? null,
            outputTokens: $response['output_tokens'] ?? null,
            cacheReadTokens: $response['cache_read_tokens'] ?? null,
            cacheWriteTokens: $response['cache_write_tokens'] ?? null,
            streaming: $streaming,
            runId: $runId,
            parentRunId: $iterationId,
        );

        if (($response['cache_read_tokens'] ?? 0) > 0) {
            HookDispatcher::providerCacheHit(
                provider: $this->provider->name(),
                model: $this->provider->model(),
                cacheReadTokens: (int) $response['cache_read_tokens'],
                cacheWriteTokens: $response['cache_write_tokens'] ?? null,
                runId: $runId,
                parentRunId: $iterationId,
            );
        }
    }

    /**
     * Throw when the run's spent tokens plus this iteration's estimated request would exceed maxTokenBudget; fires budget.exceeded first. A budget of 0 disables the check.
     *
     * @param  int  $tokensSpent  Tokens spent so far this run, from prior iterations' input_tokens + output_tokens.
     * @param  Message[]  $history  History that will be sent this iteration.
     * @param  string  $runId  Run identifier propagated to the hook.
     * @param  string  $iterationId  Parent run id for nesting the hook under this iteration.
     * @return void
     *
     * @throws TokenBudgetExceededException When the projected spend exceeds maxTokenBudget.
     */
    private function enforceTokenBudget(int $tokensSpent, array $history, string $runId, string $iterationId): void
    {
        if ($this->maxTokenBudget <= 0) {
            return;
        }

        $projected = $tokensSpent + $this->historyCompactor->estimateTokens($history);

        if ($projected <= $this->maxTokenBudget) {
            return;
        }

        HookDispatcher::budgetExceeded($tokensSpent, $this->maxTokenBudget, $runId, $iterationId);

        throw new TokenBudgetExceededException($tokensSpent, $this->maxTokenBudget);
    }

    /**
     * Arm the once-per-run hallucination retry: clear the tool schemas so the next attempt runs with no tools, and report the trigger via the tool.error hook.
     *
     * @param  bool  $isHallucinationRetry  Loop-local retry-armed flag, set true.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Loop-local tool schema list, cleared.
     * @param  string  $toolName  Hallucinated tool name(s), or a placeholder when the provider rejected the request outright.
     * @param  string  $error  Error message describing why retry-without-tools was triggered.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $iterationId  Parent run id for nesting this hook under the current iteration.
     * @return void
     */
    private function triggerHallucinationRetry(
        bool &$isHallucinationRetry,
        array &$toolSchemas,
        string $toolName,
        string $error,
        string $runId,
        string $iterationId,
    ): void {
        $isHallucinationRetry = true;
        $toolSchemas = [];
        HookDispatcher::toolError(
            toolName: $toolName,
            toolInput: [],
            error: $error,
            runId: $runId,
            parentRunId: $iterationId,
        );
    }

    /**
     * Report whether a text reply is empty although the model spent output tokens while tools were offered.
     *
     * @param  array<string, mixed>  $response  Parsed provider response of type text.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Tool schemas offered on this request.
     * @return bool True when the reply looks like a tool call the provider dropped.
     */
    private function isLostToolCall(array $response, array $toolSchemas): bool
    {
        return $toolSchemas !== []
            && trim((string) ($response['text'] ?? '')) === ''
            && (int) ($response['output_tokens'] ?? 0) > 0;
    }

    /**
     * Return list of tool names from $calls that the registry does not know about.
     *
     * @param  array<int, array<string, mixed>>  $calls  Tool-call descriptors from the provider response.
     * @return list<string> Names that have no matching registered tool.
     */
    private function detectHallucinatedToolNames(array $calls): array
    {
        $hallucinated = [];
        foreach ($calls as $call) {
            $name = (string) ($call['tool_name'] ?? '');
            if ($name !== '' && ! $this->tools->has($name)) {
                $hallucinated[] = $name;
            }
        }

        return $hallucinated;
    }

    /**
     * Execute every tool call in the batch, firing before/after hooks for each.
     *
     * @param  array<int, array<string, mixed>>  $calls  Tool-call descriptors to execute.
     * @param  int  $iteration  Current ReAct loop iteration number (passed to hooks).
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $iterationId  Parent run id for nesting tool hooks under this iteration.
     * @param  list<string>  $toolsCalled  Accumulator (by-reference) appended with each tool name as it runs.
     * @param  array<string, bool>  $failedCalls  Signatures of calls that already failed in this run.
     * @param  string|null  $refusal  Set to the failure message when a call repeats a failure verbatim.
     * @param  bool  $canPause  Whether the run can pause for an approval; when false a call that needs one is denied.
     * @return array<string, string> Map of toolUseId → result string; fewer results than calls means the batch paused at the first call without one.
     */
    private function executeToolBatch(array $calls, int $iteration, string $runId, string $iterationId, array &$toolsCalled, array &$failedCalls = [], ?string &$refusal = null, bool $canPause = false): array
    {
        $results = [];

        foreach ($calls as $call) {
            $toolUseId = (string) ($call['tool_use_id'] ?? '');
            $toolName = (string) ($call['tool_name'] ?? '');
            $toolInput = (array) ($call['tool_input'] ?? []);

            $toolsCalled[] = $toolName;

            HookDispatcher::toolBefore($toolName, $toolInput, $iteration, $runId, parentRunId: $iterationId);

            try {
                $result = $this->runWithApproval($toolName, $toolInput, $runId, canPause: $canPause);
            } catch (ApprovalPendingException) {
                return $results;
            }

            HookDispatcher::toolAfter($toolName, $toolInput, $result, $iteration, $runId, parentRunId: $iterationId);

            $this->recordToolFailure($toolName, $toolInput, $result, $failedCalls, $refusal);

            $results[$toolUseId] = $result;
        }

        return $results;
    }

    /**
     * Execute a tool, consulting the approval gate first when one is configured.
     *
     * @param  string  $toolName  Name of the tool to execute.
     * @param  array<string, mixed>  $toolInput  Arguments passed by the model.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  bool  $canPause  Whether a call that needs approval may pause the run; when false it is denied instead.
     * @return string Tool output, or a JSON denial envelope when the gate rejects the call.
     *
     * @throws ApprovalPendingException When the gate asks for a later decision and the run can pause.
     */
    private function runWithApproval(string $toolName, array $toolInput, string $runId, bool $canPause = false): string
    {
        if ($this->approvalGate === null) {
            return $this->executeTool($toolName, $toolInput, $runId);
        }

        try {
            $tool = $this->tools->has($toolName) ? $this->tools->get($toolName) : null;
            $this->approvalGate->check($toolName, $toolInput, $tool);

            return $this->executeTool($toolName, $toolInput, $runId);
        } catch (HumanDeniedException $e) {
            return $this->buildDeniedResult($e->toolName, self::DENIED_BY_HUMAN);
        } catch (ApprovalPendingException $e) {
            if ($canPause) {
                throw $e;
            }

            return $this->buildDeniedResult($e->toolName, self::DENIED_CANNOT_PAUSE);
        }
    }

    /**
     * The JSON result the model sees for a call that did not run for lack of approval.
     *
     * @param  string  $toolName  Tool that was not run.
     * @param  string  $message  Why, and what the model should do instead.
     * @return string
     */
    private function buildDeniedResult(string $toolName, string $message): string
    {
        return (string) json_encode([
            'status' => 'denied',
            'tool' => $toolName,
            'message' => $message,
        ]);
    }

    /**
     * Track a failed tool call; set $refusal when the identical call has already failed this run.
     *
     * @param  string  $toolName  Name of the tool that produced the result.
     * @param  array<string, mixed>  $toolInput  Arguments that were passed to the tool.
     * @param  string  $result  Tool output to inspect for a failure payload.
     * @param  array<string, bool>  $failedCalls  Accumulated failure signatures (by-reference).
     * @param  string|null  $refusal  Set to the failure message when the same call fails twice (by-reference).
     * @return void
     */
    private function recordToolFailure(string $toolName, array $toolInput, string $result, array &$failedCalls, ?string &$refusal): void
    {
        $failure = $this->failureMessage($result);

        if ($failure === null) {
            return;
        }

        $signature = $toolName.'|'.json_encode($toolInput);

        if (isset($failedCalls[$signature])) {
            $refusal = $failure;
        } else {
            $failedCalls[$signature] = true;
        }
    }

    /**
     * Compute elapsed wall-clock milliseconds from a hrtime(true) start marker.
     *
     * @param  int  $startNs  hrtime(true) value captured at the start of the measured interval.
     * @return int Elapsed duration in whole milliseconds.
     */
    private function elapsedMilliseconds(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / self::NANOS_PER_MILLISECOND);
    }

    /**
     * Return the failure message a tool result carries, or null when the call succeeded.
     *
     * @param  string  $result  JSON tool result.
     * @return string|null
     */
    private function failureMessage(string $result): ?string
    {
        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (is_string($decoded['error'] ?? null)) {
            return $decoded['error'];
        }

        if (($decoded['success'] ?? null) === false) {
            return (string) ($decoded['error']['message'] ?? 'The tool refused this request.');
        }

        return null;
    }

    /**
     * Build the terminal AgentResponse for a text-shape provider response.
     *
     * @param  array<string, mixed>  $response  Raw provider response.
     * @param  int  $iterations  Loop iteration count that produced this response.
     * @param  int  $startNs  hrtime(true) marker captured before the loop started.
     * @param  list<string>  $toolsCalled  Names of every tool invoked this run.
     * @param  string  $runId  Run identifier surfaced on the final AgentResponse.
     * @param  string|null  $sanitisedText  Already-sanitised text (stream path) or null to sanitise here.
     * @return AgentResponse Fully assembled, sanitised, timestamped response.
     */
    private function buildTextResponse(
        array $response,
        int $iterations,
        int $startNs,
        array $toolsCalled,
        string $runId,
        ?string $sanitisedText = null,
    ): AgentResponse {
        $text = $sanitisedText ?? $this->outputSanitiser->sanitise((string) ($response['text'] ?? ''));
        $durationMs = $this->elapsedMilliseconds($startNs);

        return new AgentResponse(
            text: $text,
            provider: $this->provider->name(),
            model: $this->provider->model(),
            iterations: $iterations,
            inputTokens: $response['input_tokens'] ?? null,
            outputTokens: $response['output_tokens'] ?? null,
            durationMs: $durationMs,
            toolsCalled: $toolsCalled,
            cacheReadTokens: $response['cache_read_tokens'] ?? null,
            cacheWriteTokens: $response['cache_write_tokens'] ?? null,
            thinking: $response['thinking'] ?? null,
            runId: $runId,
        );
    }

    /**
     * Fire the agent.max_iterations hook and throw MaxIterationsException.
     *
     * @param  string  $message  Original user message; included in the hook payload.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @return never Always throws.
     *
     * @throws MaxIterationsException Always thrown after the hook fires.
     */
    private function throwMaxIterations(string $message, string $runId): never
    {
        HookDispatcher::agentMaxIterations(
            message: $message,
            maxIterations: $this->maxIterations,
            provider: $this->provider->name(),
            model: $this->provider->model(),
            runId: $runId,
        );

        throw new MaxIterationsException(
            "Agent hit the maximum iteration limit of {$this->maxIterations} without returning a text response."
        );
    }

    /**
     * Cut a tool result that exceeds the configured token ceiling and append a marker telling the model how to recover.
     *
     * @param  string  $result  Sanitised tool output.
     * @return string The result unchanged, or cut at the ceiling with the marker appended.
     */
    private function capToolResult(string $result): string
    {
        if ($this->maxToolResultTokens <= 0) {
            return $result;
        }

        $limitChars = $this->maxToolResultTokens * self::CHARS_PER_TOKEN_ESTIMATE;

        if (strlen($result) <= $limitChars) {
            return $result;
        }

        return substr($result, 0, $limitChars)
            ."\n[phpClaw: tool output cut at {$this->maxToolResultTokens} tokens. Narrow the request, or page with offset if the tool supports it.]";
    }

    /**
     * Execute a tool by name; on failure fires tool.error and returns a JSON error string for graceful LLM recovery.
     *
     * @param  string  $toolName  Name of the registered tool to invoke.
     * @param  array<string, mixed>  $toolInput  Arguments passed to the tool's execute() method.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @return string Tool output (already sanitised), or a JSON error envelope on failure.
     *
     * @throws ToolException When the tool is not registered (hallucinated tool name).
     */
    private function executeTool(string $toolName, array $toolInput, string $runId): string
    {
        if (! $this->tools->has($toolName)) {
            $error = "Tool '{$toolName}' is not registered. The model likely hallucinated a tool name; cannot continue the ReAct loop because the provider will reject history referencing an undefined tool.";

            HookDispatcher::toolNotFound($toolName, $toolInput, $this->tools->names(), $runId);

            throw new ToolException($error);
        }

        try {
            $tool = $this->tools->get($toolName);
            $result = $tool->execute(self::tidyToolInput($toolInput, $tool->inputSchema()));

            return $this->capToolResult($this->toolOutputGuard->sanitise($result, $toolName));
        } catch (ToolException $e) {
            HookDispatcher::toolError($toolName, $toolInput, $e->getMessage(), $runId);

            return (string) json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Drop optional arguments the model sent as null, blank or a "*"/"%" wildcard, and cap numbers to the schema range.
     *
     * @param  array<string, mixed>  $input  Arguments as sent by the model.
     * @param  array<string, mixed>  $schema  The tool's input schema.
     * @return array<string, mixed> Arguments passed to the tool.
     */
    private static function tidyToolInput(array $input, array $schema): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);

        foreach ($input as $key => $value) {
            $spec = $properties[$key] ?? null;
            if (! is_array($spec)) {
                continue;
            }

            if (! in_array($key, $required, true) && self::isEmptyArgument($value, (array) ($spec['enum'] ?? []))) {
                unset($input[$key]);

                continue;
            }

            if (in_array($spec['type'] ?? '', ['integer', 'number'], true) && is_numeric($value)) {
                $input[$key] = self::capToRange($spec['type'] === 'integer' ? (int) $value : (float) $value, $spec);
            }
        }

        return $input;
    }

    /**
     * Report whether an optional argument carries no real value: null, blank, or a wildcard that is not one of its enum values.
     *
     * @param  mixed  $value  Argument value.
     * @param  array<int, mixed>  $enum  Allowed values from the schema, if any.
     * @return bool True when the argument should be dropped.
     */
    private static function isEmptyArgument(mixed $value, array $enum): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }

        $text = trim($value);

        return $text === '' || (in_array($text, ['*', '%'], true) && ! in_array($text, $enum, true));
    }

    /**
     * Cap a number to the schema's minimum and maximum when they are set.
     *
     * @param  int|float  $number  Number sent by the model.
     * @param  array<string, mixed>  $spec  Property schema.
     * @return int|float The number within range.
     */
    private static function capToRange(int|float $number, array $spec): int|float
    {
        if (isset($spec['minimum']) && $number < $spec['minimum']) {
            return $spec['minimum'];
        }
        if (isset($spec['maximum']) && $number > $spec['maximum']) {
            return $spec['maximum'];
        }

        return $number;
    }
}
