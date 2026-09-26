<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

use PhpClaw\Hooks\Dispatchers\AgentEventDispatcher;
use PhpClaw\Hooks\Dispatchers\ConversationEventDispatcher;
use PhpClaw\Hooks\Dispatchers\GuardEventDispatcher;
use PhpClaw\Hooks\Dispatchers\JobEventDispatcher;
use PhpClaw\Hooks\Dispatchers\MemoryEventDispatcher;
use PhpClaw\Hooks\Dispatchers\ProviderEventDispatcher;
use PhpClaw\Hooks\Dispatchers\ShellEventDispatcher;
use PhpClaw\Hooks\Dispatchers\StreamEventDispatcher;
use PhpClaw\Hooks\Dispatchers\ToolEventDispatcher;

/**
 * Thin façade over the domain-specific event dispatchers in {@see PhpClaw\Hooks\Dispatchers}.
 */
final class HookDispatcher
{
    /**
     * Run a callback with the given run and parent-run IDs set as the active hook context.
     *
     * @template T
     *
     * @param  string  $runId  Run ID to set as active for the duration of the callback.
     * @param  callable(): T  $work  Callback to execute within the run context.
     * @param  string  $parentRunId  Parent run ID to set as active, if any.
     * @return T Whatever the callback returns.
     */
    public static function withRun(string $runId, callable $work, string $parentRunId = ''): mixed
    {
        return HookRunContext::withRun($runId, $work, $parentRunId);
    }

    /**
     * Return the active run ID from the hook run context.
     *
     * @return string The active run ID, or '' when none is set.
     */
    public static function currentRunId(): string
    {
        return HookRunContext::currentRunId();
    }

    /**
     * Fire the agent.before event via AgentEventDispatcher.
     *
     * @param  string  $message  User message being sent to the agent.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function agentBefore(
        string $message,
        bool $streaming = false,
        string $conversationId = '',
        string $runId = '',
    ): void {
        AgentEventDispatcher::before($message, $streaming, $conversationId, $runId);
    }

    /**
     * Fire the agent.iteration event via AgentEventDispatcher.
     *
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  string  $message  User message driving the loop.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  array<int, mixed>  $history  Conversation history sent this iteration.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function agentIteration(
        int $iteration,
        string $message,
        string $provider,
        string $model,
        bool $streaming = false,
        string $runId = '',
        array $history = [],
        string $parentRunId = '',
    ): void {
        AgentEventDispatcher::iteration(
            $iteration, $message, $provider, $model, $streaming, $runId, $history, $parentRunId,
        );
    }

    /**
     * Fire the agent.after event via AgentEventDispatcher.
     *
     * @param  string  $message  User message that produced the response.
     * @param  string  $text  Assistant response text.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $iterations  Total iterations run.
     * @param  int  $durationMs  Run duration in milliseconds.
     * @param  array<int, mixed>  $toolsCalled  Tools invoked during the run.
     * @param  bool  $streaming  Whether the request was streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  int|null  $cacheReadTokens  Cache-read token count, if reported.
     * @param  int|null  $cacheWriteTokens  Cache-write token count, if reported.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function agentAfter(
        string $message,
        string $text,
        string $provider,
        string $model,
        int $iterations,
        int $durationMs,
        array $toolsCalled,
        bool $streaming = false,
        string $conversationId = '',
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
        string $runId = '',
    ): void {
        AgentEventDispatcher::after(
            $message, $text, $provider, $model, $iterations, $durationMs, $toolsCalled,
            $streaming, $conversationId, $cacheReadTokens, $cacheWriteTokens, $runId,
        );
    }

    /**
     * Fire the agent.error event via AgentEventDispatcher.
     *
     * @param  string  $message  User message being processed when the error occurred.
     * @param  string  $error  Error message.
     * @param  string  $class  Exception class name.
     * @param  bool  $streaming  Whether the request was streaming.
     * @param  string  $conversationId  Conversation identifier, if any.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function agentError(
        string $message,
        string $error,
        string $class,
        bool $streaming = false,
        string $conversationId = '',
        string $runId = '',
    ): void {
        AgentEventDispatcher::error($message, $error, $class, $streaming, $conversationId, $runId);
    }

    /**
     * Fire the agent.max_iterations event via AgentEventDispatcher.
     *
     * @param  string  $message  User message that hit the iteration ceiling.
     * @param  int  $maxIterations  Configured maximum iterations.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function agentMaxIterations(
        string $message,
        int $maxIterations,
        string $provider,
        string $model,
        string $runId = '',
    ): void {
        AgentEventDispatcher::maxIterations($message, $maxIterations, $provider, $model, $runId);
    }

    /**
     * Fire the context.overflow event via AgentEventDispatcher.
     *
     * @param  string  $message  User message being processed.
     * @param  int  $historyLength  Current history length.
     * @param  int  $maxHistoryLength  Configured maximum history length.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function contextOverflow(
        string $message,
        int $historyLength,
        int $maxHistoryLength,
        string $provider,
        string $model,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        AgentEventDispatcher::contextOverflow(
            $message, $historyLength, $maxHistoryLength, $provider, $model, $runId, $parentRunId,
        );
    }

    /**
     * Fire the compaction.before event via AgentEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function compactionBefore(
        string $provider,
        string $model,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        AgentEventDispatcher::compactionBefore($provider, $model, $runId, $parentRunId);
    }

    /**
     * Fire the compaction.after event via AgentEventDispatcher.
     *
     * @param  string  $summary  The generated conversation summary.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function compactionAfter(
        string $summary,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        AgentEventDispatcher::compactionAfter($summary, $runId, $parentRunId);
    }

    /**
     * Fire the provider.request event via ProviderEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $historyLen  History length sent to the provider.
     * @param  int  $toolCount  Number of tools sent to the provider.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function providerRequest(
        string $provider,
        string $model,
        int $historyLen,
        int $toolCount,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ProviderEventDispatcher::request(
            $provider, $model, $historyLen, $toolCount, $streaming, $runId, $parentRunId,
        );
    }

    /**
     * Fire the provider.response event via ProviderEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $type  Response type.
     * @param  int|null  $inputTokens  Input token count, if reported.
     * @param  int|null  $outputTokens  Output token count, if reported.
     * @param  int|null  $cacheReadTokens  Cache-read token count, if reported.
     * @param  int|null  $cacheWriteTokens  Cache-write token count, if reported.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function providerResponse(
        string $provider,
        string $model,
        string $type,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ProviderEventDispatcher::response(
            $provider, $model, $type, $inputTokens, $outputTokens, $cacheReadTokens,
            $cacheWriteTokens, $streaming, $runId, $parentRunId,
        );
    }

    /**
     * Fire the provider.retry event via ProviderEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $attempt  Retry attempt number.
     * @param  string  $error  Error that triggered the retry.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function providerRetry(
        string $provider,
        string $model,
        int $attempt,
        string $error,
        int $iteration,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ProviderEventDispatcher::retry(
            $provider, $model, $attempt, $error, $iteration, $streaming, $runId, $parentRunId,
        );
    }

    /**
     * Fire the provider.cache_hit event via ProviderEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $cacheReadTokens  Cache-read token count.
     * @param  int|null  $cacheWriteTokens  Cache-write token count, if reported.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function providerCacheHit(
        string $provider,
        string $model,
        int $cacheReadTokens,
        ?int $cacheWriteTokens = null,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ProviderEventDispatcher::cacheHit(
            $provider, $model, $cacheReadTokens, $cacheWriteTokens, $runId, $parentRunId,
        );
    }

    /**
     * Fire the provider.error event via ProviderEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $error  Error message.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function providerError(
        string $provider,
        string $model,
        string $error,
        int $iteration,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ProviderEventDispatcher::error(
            $provider, $model, $error, $iteration, $streaming, $runId, $parentRunId,
        );
    }

    /**
     * Fire the provider.token event via ProviderEventDispatcher.
     *
     * @param  string  $token  Streamed token text.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @return void
     */
    public static function providerToken(string $token, string $provider, string $model): void
    {
        ProviderEventDispatcher::token($token, $provider, $model);
    }

    /**
     * Fire the tool.not_found event via ToolEventDispatcher.
     *
     * @param  string  $toolName  Name of the tool the model requested.
     * @param  array<string, mixed>  $toolInput  Input arguments supplied for the tool.
     * @param  array<int, string>  $availableTools  Names of the registered tools.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function toolNotFound(
        string $toolName,
        array $toolInput,
        array $availableTools,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ToolEventDispatcher::notFound($toolName, $toolInput, $availableTools, $runId, $parentRunId);
    }

    /**
     * Fire the tool.before event via ToolEventDispatcher.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Input arguments for the tool.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function toolBefore(
        string $toolName,
        array $toolInput,
        int $iteration,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ToolEventDispatcher::before($toolName, $toolInput, $iteration, $runId, $parentRunId);
    }

    /**
     * Fire the tool.after event via ToolEventDispatcher.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Input arguments for the tool.
     * @param  string  $toolResult  Result returned by the tool.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function toolAfter(
        string $toolName,
        array $toolInput,
        string $toolResult,
        int $iteration,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ToolEventDispatcher::after($toolName, $toolInput, $toolResult, $iteration, $runId, $parentRunId);
    }

    /**
     * Fire the tool.error event via ToolEventDispatcher.
     *
     * @param  string  $toolName  Tool name.
     * @param  array<string, mixed>  $toolInput  Input arguments for the tool.
     * @param  string  $error  Error message.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function toolError(
        string $toolName,
        array $toolInput,
        string $error,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        ToolEventDispatcher::error($toolName, $toolInput, $error, $runId, $parentRunId);
    }

    /**
     * Fire the guard.blocked event via GuardEventDispatcher.
     *
     * @param  string  $message  Message the guard blocked.
     * @param  string  $reason  Reason the guard blocked the message.
     * @param  string|null  $guard  Short class name of the guard that blocked, when known.
     * @return void
     */
    public static function guardBlocked(string $message, string $reason, ?string $guard = null): void
    {
        GuardEventDispatcher::blocked($message, $reason, $guard);
    }

    /**
     * Fire the guard.rate_limit_exceeded event via GuardEventDispatcher.
     *
     * @param  string  $callerId  Caller identifier.
     * @param  int  $count  Current request count in the window.
     * @param  int  $maxRequests  Maximum requests allowed.
     * @param  int  $windowSeconds  Rate-limit window in seconds.
     * @return void
     */
    public static function guardRateLimitExceeded(
        string $callerId,
        int $count,
        int $maxRequests,
        int $windowSeconds,
    ): void {
        GuardEventDispatcher::rateLimitExceeded($callerId, $count, $maxRequests, $windowSeconds);
    }

    /**
     * Fire the guard.tool_output_redacted event via GuardEventDispatcher.
     *
     * @param  string  $toolName  Tool whose output was redacted.
     * @param  string  $pattern  Pattern that matched and was redacted.
     * @return void
     */
    public static function guardToolOutputRedacted(string $toolName, string $pattern): void
    {
        GuardEventDispatcher::toolOutputRedacted($toolName, $pattern);
    }

    /**
     * Fire the guard.output_php_tag_removed event via GuardEventDispatcher.
     *
     * @param  string  $tag  PHP tag removed from the output.
     * @return void
     */
    public static function guardOutputPhpTagRemoved(string $tag): void
    {
        GuardEventDispatcher::outputPhpTagRemoved($tag);
    }

    /**
     * Fire the guard.output_function_redacted event via GuardEventDispatcher.
     *
     * @param  string  $function  Function name redacted from the output.
     * @return void
     */
    public static function guardOutputFunctionRedacted(string $function): void
    {
        GuardEventDispatcher::outputFunctionRedacted($function);
    }

    /**
     * Fire the conversation.start event via ConversationEventDispatcher.
     *
     * @param  string  $conversationId  Conversation identifier.
     * @param  array<string, mixed>  $metadata  Conversation metadata.
     * @return void
     */
    public static function conversationStart(string $conversationId, array $metadata = []): void
    {
        ConversationEventDispatcher::start($conversationId, $metadata);
    }

    /**
     * Fire the conversation.end event via ConversationEventDispatcher.
     *
     * @param  string  $conversationId  Conversation identifier.
     * @param  int  $turnCount  Number of turns in the conversation.
     * @param  string  $message  Final user message.
     * @param  string  $response  Final response text.
     * @param  int  $durationMs  Conversation duration in milliseconds.
     * @return void
     */
    public static function conversationEnd(
        string $conversationId,
        int $turnCount,
        string $message,
        string $response,
        int $durationMs,
    ): void {
        ConversationEventDispatcher::end($conversationId, $turnCount, $message, $response, $durationMs);
    }

    /**
     * Fire the memory.write event via MemoryEventDispatcher.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @param  string  $driver  Memory driver name.
     * @param  int|null  $ttl  Time-to-live in seconds, if set.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function memoryWrite(
        string $key,
        string $namespace,
        string $driver,
        ?int $ttl = null,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        MemoryEventDispatcher::write($key, $namespace, $driver, $ttl, $runId, $parentRunId);
    }

    /**
     * Fire the memory.forget event via MemoryEventDispatcher.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @param  string  $driver  Memory driver name.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function memoryForget(
        string $key,
        string $namespace,
        string $driver,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        MemoryEventDispatcher::forget($key, $namespace, $driver, $runId, $parentRunId);
    }

    /**
     * Fire the memory.read event via MemoryEventDispatcher.
     *
     * @param  string  $key  Memory key.
     * @param  string  $namespace  Memory namespace.
     * @param  string  $driver  Memory driver name.
     * @param  bool  $hit  Whether the read hit an existing value.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function memoryRead(
        string $key,
        string $namespace,
        string $driver,
        bool $hit,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        MemoryEventDispatcher::read($key, $namespace, $driver, $hit, $runId, $parentRunId);
    }

    /**
     * Fire the stream.start event via StreamEventDispatcher.
     *
     * @param  string  $message  User message driving the stream.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function streamStart(string $message, string $provider, string $model, string $runId = ''): void
    {
        StreamEventDispatcher::start($message, $provider, $model, $runId);
    }

    /**
     * Fire the stream.end event via StreamEventDispatcher.
     *
     * @param  string  $message  User message that drove the stream.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $durationMs  Stream duration in milliseconds.
     * @param  int  $chars  Number of characters streamed.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function streamEnd(
        string $message,
        string $provider,
        string $model,
        int $durationMs,
        int $chars,
        string $runId = '',
    ): void {
        StreamEventDispatcher::end($message, $provider, $model, $durationMs, $chars, $runId);
    }

    /**
     * Fire the stream.abort event via StreamEventDispatcher.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $error  Error that aborted the stream.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function streamAbort(string $provider, string $model, string $error, string $runId = ''): void
    {
        StreamEventDispatcher::abort($provider, $model, $error, $runId);
    }

    /**
     * Fire the shell.denied event via ShellEventDispatcher.
     *
     * @param  string  $command  Shell command that was denied.
     * @param  string  $cmdName  Resolved command name.
     * @param  string  $reason  Reason the command was denied.
     * @param  string  $file  Offending file path, if any.
     * @return void
     */
    public static function shellDenied(
        string $command,
        string $cmdName,
        string $reason,
        string $file = '',
    ): void {
        ShellEventDispatcher::denied($command, $cmdName, $reason, $file);
    }

    /**
     * Fire the shell.exec event via ShellEventDispatcher.
     *
     * @param  string  $command  Shell command executed.
     * @param  string  $cmdName  Resolved command name.
     * @return void
     */
    public static function shellExec(string $command, string $cmdName): void
    {
        ShellEventDispatcher::exec($command, $cmdName);
    }

    /**
     * Fire the job.started event via JobEventDispatcher.
     *
     * @param  string  $jobId  Job identifier.
     * @param  string  $message  User message the job will process.
     * @return void
     */
    public static function jobStarted(string $jobId, string $message): void
    {
        JobEventDispatcher::started($jobId, $message);
    }

    /**
     * Fire the job.completed event via JobEventDispatcher.
     *
     * @param  string  $jobId  Job identifier.
     * @param  string  $message  User message the job processed.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $iterations  Total iterations run.
     * @param  int  $durationMs  Job duration in milliseconds.
     * @param  int|null  $tokens  Token count, if reported.
     * @param  string  $runId  Active run ID, if any.
     * @return void
     */
    public static function jobCompleted(
        string $jobId,
        string $message,
        string $provider,
        string $model,
        int $iterations,
        int $durationMs,
        ?int $tokens = null,
        string $runId = '',
    ): void {
        JobEventDispatcher::completed(
            $jobId, $message, $provider, $model, $iterations, $durationMs, $tokens, $runId,
        );
    }

    /**
     * Fire the job.failed event via JobEventDispatcher.
     *
     * @param  string  $jobId  Job identifier.
     * @param  string  $message  User message the job was processing.
     * @param  string  $error  Error message.
     * @param  string  $class  Exception class name.
     * @return void
     */
    public static function jobFailed(
        string $jobId,
        string $message,
        string $error,
        string $class,
    ): void {
        JobEventDispatcher::failed($jobId, $message, $error, $class);
    }
}
