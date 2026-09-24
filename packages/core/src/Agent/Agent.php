<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Guards\ToolOutputGuard;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ToolRouter;

/**
 * ReAct agent loop: orchestrates provider, tools, and message history.
 */
final class Agent
{
    public const DEFAULT_MAX_ITERATIONS = 20;

    public const DEFAULT_MAX_RETRIES = 0;

    public const DEFAULT_MAX_HISTORY_LENGTH = 0;

    private const CHARS_PER_TOKEN_ESTIMATE = 4;

    private const STREAM_CHUNK_BYTES = 10;

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
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly ToolRegistry $tools,
        private readonly int $maxIterations = self::DEFAULT_MAX_ITERATIONS,
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        private readonly int $maxHistoryLength = self::DEFAULT_MAX_HISTORY_LENGTH,
        ?ToolOutputGuard $toolOutputGuard = null,
        ?OutputSanitiser $outputSanitiser = null,
        bool $compactHistory = true,
        private readonly ?ApprovalGateInterface $approvalGate = null,
        private readonly ?ToolRouter $toolRouter = null,
        int $maxHistoryTokens = 0,
        private readonly int $maxToolResultTokens = 0,
    ) {
        $this->toolOutputGuard = $toolOutputGuard ?? new ToolOutputGuard;
        $this->outputSanitiser = $outputSanitiser ?? new OutputSanitiser;
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
     */
    public function run(string $message, array $history = [], string $runId = '', string $originalMessage = ''): AgentResponse
    {
        return $this->executeIterationLoop($message, $history, $runId, onToken: null, originalMessage: $originalMessage);
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
     */
    public function stream(string $message, callable $onToken, array $history = [], string $runId = '', string $originalMessage = ''): AgentResponse
    {
        HookDispatcher::streamStart($message, $this->provider->name(), $this->provider->model(), $runId);

        if (empty($this->tools->schemas($this->provider->name()))) {
            $startNs = hrtime(true);
            $history[] = Message::user($message);

            return $this->streamFastPath($message, $onToken, $history, $startNs, $runId);
        }

        return $this->executeIterationLoop($message, $history, $runId, $onToken, $originalMessage);
    }

    /**
     * Shared ReAct loop body. When $onToken is non-null, hooks fire with streaming:true, the final text is chunked through $onToken, and stream.end fires at completion.
     *
     * @param  string  $message  Current user message to append before the loop runs.
     * @param  Message[]  $history  Prior conversation history (may be empty).
     * @param  string  $runId  Optional run identifier propagated to hooks for run correlation.
     * @param  (callable(string): void)|null  $onToken  null = blocking mode, callable = streaming mode.
     * @param  string  $originalMessage  The user message before memory and skill context were prepended; used for tool routing. Empty falls back to $message.
     * @return AgentResponse The terminal text response produced by the loop.
     *
     * @throws MaxIterationsException
     * @throws ToolException
     * @throws ProviderException
     */
    private function executeIterationLoop(string $message, array $history, string $runId, ?callable $onToken, string $originalMessage = ''): AgentResponse
    {
        $streaming = $onToken !== null;
        $startNs = hrtime(true);
        $toolsCalled = [];
        $failedCalls = [];

        $this->tools->resetRunState();

        $history[] = Message::user($message);
        $toolSchemas = $this->tools->schemas($this->provider->name());
        if ($this->toolRouter !== null) {
            $toolSchemas = $this->toolRouter->filter($toolSchemas, $originalMessage !== '' ? $originalMessage : $message, $this->provider->model(), $this->tools->routingMetadata());
        }
        $hallucinationRetry = false;

        for ($i = 1; $i <= $this->maxIterations; $i++) {
            $iterationId = $this->buildIterationId($runId, $i);

            HookDispatcher::agentIteration($i, $message, $this->provider->name(), $this->provider->model(), streaming: $streaming, runId: $runId, history: $history, parentRunId: $iterationId);

            $history = $this->historyCompactor->applyIfOversized($history, $message, $runId, $iterationId);

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
                $response = $this->retryLoop->send($history, $toolSchemas, $i, streaming: $streaming, runId: $runId, parentRunId: $iterationId);
            } catch (ProviderException $e) {
                if ($this->retryLoop->isHallucinationRejection($e) && ! $hallucinationRetry) {
                    $this->triggerHallucinationRetry(
                        $hallucinationRetry,
                        $toolSchemas,
                        '(provider-rejected)',
                        'Provider rejected request due to hallucinated tool; retrying once with no tools: '.$e->getMessage(),
                        $runId,
                        $iterationId,
                    );

                    continue;
                }
                throw $e;
            }

            $this->fireProviderResponseHooks($response, streaming: $streaming, runId: $runId, iterationId: $iterationId);

            if ($response['type'] === 'text') {
                return $this->finaliseTextResponse($response, $message, $i, $startNs, $toolsCalled, $runId, $onToken);
            }

            if ($response['type'] === 'tool_use_batch') {
                $calls = $response['calls'] ?? [];
                $hallucinated = $this->detectHallucinatedToolNames($calls);

                if (! empty($hallucinated) && ! $hallucinationRetry) {
                    $this->triggerHallucinationRetry(
                        $hallucinationRetry,
                        $toolSchemas,
                        implode(',', $hallucinated),
                        'Model hallucinated unregistered tool(s); retrying once with no tools.',
                        $runId,
                        $iterationId,
                    );

                    continue;
                }

                $refusal = null;
                $results = $this->executeToolBatch($calls, $i, $runId, $iterationId, $toolsCalled, $failedCalls, $refusal);

                if ($refusal !== null) {
                    return $this->buildTextResponse(['text' => $refusal], $i, $startNs, $toolsCalled, $runId);
                }

                $history[] = Message::toolBatch($calls, $results);
            }
        }

        $this->throwMaxIterations($message, $runId);
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

        $durationMs = (int) round((hrtime(true) - $startNs) / 1000000);
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

        $durationMs = (int) round((hrtime(true) - $startNs) / 1000000);
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
     * @param  int  $i  Iteration number (1-based).
     * @return string "{runId}_I{i}" when runId is non-empty, otherwise ''.
     */
    private function buildIterationId(string $runId, int $i): string
    {
        return $runId !== '' ? $runId.'_I'.$i : '';
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
     * Fire the tool.error hook used by the hallucination-retry recovery path.
     *
     * @param  string  $toolName  Tool name (or sentinel like '(provider-rejected)') for the hook payload.
     * @param  string  $error  Error message describing why retry-without-tools was triggered.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $iterationId  Parent run id for nesting this hook under the current iteration.
     * @return void
     */
    private function fireHallucinationRetry(string $toolName, string $error, string $runId, string $iterationId): void
    {
        HookDispatcher::toolError(
            toolName: $toolName,
            toolInput: [],
            error: $error,
            runId: $runId,
            parentRunId: $iterationId,
        );
    }

    /**
     * Arm the once-per-run hallucination retry: clear the tool schemas so the next attempt runs with no tools, and report the trigger via the tool.error hook.
     *
     * @param  bool  $hallucinationRetry  Loop-local retry-armed flag, set true.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Loop-local tool schema list, cleared.
     * @param  string  $toolName  Hallucinated tool name(s), or a placeholder when the provider rejected the request outright.
     * @param  string  $error  Error message describing why retry-without-tools was triggered.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $iterationId  Parent run id for nesting this hook under the current iteration.
     * @return void
     */
    private function triggerHallucinationRetry(
        bool &$hallucinationRetry,
        array &$toolSchemas,
        string $toolName,
        string $error,
        string $runId,
        string $iterationId,
    ): void {
        $hallucinationRetry = true;
        $toolSchemas = [];
        $this->fireHallucinationRetry($toolName, $error, $runId, $iterationId);
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
            $n = (string) ($call['tool_name'] ?? '');
            if ($n !== '' && ! $this->tools->has($n)) {
                $hallucinated[] = $n;
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
     * @return array<string, string> Map of toolUseId → result string.
     */
    private function executeToolBatch(array $calls, int $iteration, string $runId, string $iterationId, array &$toolsCalled, array &$failedCalls = [], ?string &$refusal = null): array
    {
        $results = [];

        foreach ($calls as $call) {
            $toolUseId = (string) ($call['tool_use_id'] ?? '');
            $toolName = (string) ($call['tool_name'] ?? '');
            $toolInput = (array) ($call['tool_input'] ?? []);

            $toolsCalled[] = $toolName;

            HookDispatcher::toolBefore($toolName, $toolInput, $iteration, $runId, parentRunId: $iterationId);

            if ($this->approvalGate !== null) {
                try {
                    $tool = $this->tools->has($toolName) ? $this->tools->get($toolName) : null;
                    $this->approvalGate->check($toolName, $toolInput, $tool);
                    $result = $this->executeTool($toolName, $toolInput, $runId);
                } catch (HumanDeniedException $e) {
                    $result = (string) json_encode([
                        'status' => 'denied',
                        'tool' => $e->toolName,
                        'message' => 'Action denied by human. Propose an alternative approach or ask what to do instead.',
                    ]);
                }
            } else {
                $result = $this->executeTool($toolName, $toolInput, $runId);
            }

            HookDispatcher::toolAfter($toolName, $toolInput, $result, $iteration, $runId, parentRunId: $iterationId);

            $failure = $this->failureMessage($result);

            if ($failure !== null) {
                $signature = $toolName.'|'.json_encode($toolInput);

                if (isset($failedCalls[$signature])) {
                    $refusal = $failure;
                } else {
                    $failedCalls[$signature] = true;
                }
            }

            $results[$toolUseId] = $result;
        }

        return $results;
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
        $durationMs = (int) round((hrtime(true) - $startNs) / 1000000);

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
     * @return never The result.
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
    private function executeTool(string $toolName, array $toolInput, string $runId = ''): string
    {
        if (! $this->tools->has($toolName)) {
            $error = "Tool '{$toolName}' is not registered. The model likely hallucinated a tool name; cannot continue the ReAct loop because the provider will reject history referencing an undefined tool.";

            HookDispatcher::toolNotFound($toolName, $toolInput, $this->tools->names(), $runId);

            throw new ToolException($error);
        }

        try {
            $result = $this->tools->get($toolName)->execute($toolInput);

            return $this->capToolResult($this->toolOutputGuard->sanitise($result, $toolName));
        } catch (ToolException $e) {
            HookDispatcher::toolError($toolName, $toolInput, $e->getMessage(), $runId);

            return (string) json_encode(['error' => $e->getMessage()]);
        }
    }
}
