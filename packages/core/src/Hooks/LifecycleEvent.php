<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

/**
 * Canonical phpClaw lifecycle event names: single source of truth for every event the core agent loop dispatches through HookRegistry.
 */
enum LifecycleEvent: string
{
    case AgentBefore = 'agent.before';
    case AgentAfter = 'agent.after';
    case AgentIteration = 'agent.iteration';
    case AgentError = 'agent.error';
    case AgentMaxIterations = 'agent.max_iterations';

    case ProviderRequest = 'provider.request';
    case ProviderResponse = 'provider.response';
    case ProviderRetry = 'provider.retry';
    case ProviderCacheHit = 'provider.cache_hit';
    case ProviderError = 'provider.error';
    case ProviderToken = 'provider.token';

    case ToolBefore = 'tool.before';
    case ToolAfter = 'tool.after';
    case ToolError = 'tool.error';
    case ToolNotFound = 'tool.not_found';

    case ContextOverflow = 'context.overflow';
    case CompactionBefore = 'compaction.before';
    case CompactionAfter = 'compaction.after';

    case GuardBlocked = 'guard.blocked';
    case GuardRateLimitExceeded = 'guard.rate_limit_exceeded';
    case GuardToolOutputRedacted = 'guard.tool_output_redacted';
    case GuardOutputPhpTagRemoved = 'guard.output_php_tag_removed';
    case GuardOutputFunctionRedacted = 'guard.output_function_redacted';

    case ConversationStart = 'conversation.start';
    case ConversationEnd = 'conversation.end';

    case StreamStart = 'stream.start';
    case StreamEnd = 'stream.end';
    case StreamAbort = 'stream.abort';

    case ShellExec = 'shell.exec';
    case ShellDenied = 'shell.denied';

    case MemoryRead = 'memory.read';
    case MemoryWrite = 'memory.write';
    case MemoryForget = 'memory.forget';

    case JobStarted = 'job.started';
    case JobCompleted = 'job.completed';
    case JobFailed = 'job.failed';

    case SkillRegistered = 'skill.registered';
    case SkillLoaded = 'skill.loaded';
    case SkillMatched = 'skill.matched';
    case SkillNotMatched = 'skill.not_matched';

    /**
     * Every canonical event name as a flat string list.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }

    /**
     * Whether this event belongs to the agent lifecycle group.
     *
     * @return bool True on success.
     */
    public function isAgent(): bool
    {
        return str_starts_with($this->value, 'agent.');
    }

    /**
     * Whether this event belongs to the provider group.
     *
     * @return bool True on success.
     */
    public function isProvider(): bool
    {
        return str_starts_with($this->value, 'provider.');
    }

    /**
     * Whether this event belongs to the tool group.
     *
     * @return bool True on success.
     */
    public function isTool(): bool
    {
        return str_starts_with($this->value, 'tool.');
    }

    /**
     * Whether this event belongs to the guard group.
     *
     * @return bool True on success.
     */
    public function isGuard(): bool
    {
        return str_starts_with($this->value, 'guard.');
    }

    /**
     * Whether this event belongs to the conversation group.
     *
     * @return bool True on success.
     */
    public function isConversation(): bool
    {
        return str_starts_with($this->value, 'conversation.');
    }

    /**
     * Whether this event belongs to the streaming group.
     *
     * @return bool True on success.
     */
    public function isStream(): bool
    {
        return str_starts_with($this->value, 'stream.');
    }

    /**
     * Whether this event belongs to the memory group.
     *
     * @return bool True on success.
     */
    public function isMemory(): bool
    {
        return str_starts_with($this->value, 'memory.');
    }

    /**
     * Whether this event belongs to the shell group.
     *
     * @return bool True on success.
     */
    public function isShell(): bool
    {
        return str_starts_with($this->value, 'shell.');
    }

    /**
     * Whether this event belongs to the job group.
     *
     * @return bool True on success.
     */
    public function isJob(): bool
    {
        return str_starts_with($this->value, 'job.');
    }

    /**
     * Whether this event belongs to the skill group.
     *
     * @return bool True on success.
     */
    public function isSkill(): bool
    {
        return str_starts_with($this->value, 'skill.');
    }
}
