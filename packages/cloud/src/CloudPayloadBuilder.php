<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

use PhpClaw\Hooks\LifecycleEvent;

/**
 * Builds the cloud-bound JSON payload for every phpClaw lifecycle event.
 *
 * @internal
 */
final class CloudPayloadBuilder
{
    private const EVENT_NAME_UNKNOWN = 'unknown';

    private const MAX_PAYLOAD_STRING_BYTES = 8192;

    private const MAX_PAYLOAD_ARRAY_SIZE = 100;

    private const MAX_TOTAL_PAYLOAD_BYTES = 65536;

    private const REDACTED_MARKER = '[redacted]';

    private const TRUNCATED_MARKER = '…[truncated]';

    private const ARRAY_TOO_LARGE_MARKER = '[array-too-large:%d]';

    private const SECRET_KEYS = [
        'password', 'passwd', 'secret', 'secret_key', 'private_key',
        'api_key', 'apikey', 'api_token', 'access_token', 'auth_token',
        'token', 'authorization',
    ];

    public const HIDE_INPUTS = 'hide_inputs';

    public const HIDE_OUTPUTS = 'hide_outputs';

    public const HIDE_METADATA = 'hide_metadata';

    private const HIDDEN_MARKER = '[hidden]';

    private const HIDDEN_FIELDS = [
        self::HIDE_INPUTS => ['message', 'prompt', 'message_excerpt', 'tool_input', 'command'],
        self::HIDE_OUTPUTS => ['text', 'response', 'tool_result'],
        self::HIDE_METADATA => ['metadata'],
    ];

    private readonly array $hidden;

    /**
     * Resolve which payload fields this site keeps off the cloud, from its cloud_disable names.
     *
     * @param  list<string>  $disable  The site's cloud_disable names.
     * @return void
     */
    public function __construct(array $disable = [])
    {
        $hidden = [];
        foreach (self::HIDDEN_FIELDS as $name => $fields) {
            if (in_array($name, $disable, strict: true)) {
                array_push($hidden, ...$fields);
            }
        }
        $this->hidden = $hidden;
    }

    /**
     * Build the full cloud-bound payload for one lifecycle event.
     *
     * @param  string  $event  Resolved event name (never `__any__`).
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload envelope.
     */
    public function build(string $event, array $context): array
    {
        if ($event === '') {
            $event = self::EVENT_NAME_UNKNOWN;
        }

        $base = [
            'event' => $event,
            'ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        $payload = match ($event) {
            LifecycleEvent::AgentBefore->value => $base + $this->fromAgentBefore($context),
            LifecycleEvent::AgentIteration->value => $base + $this->fromAgentIteration($context),
            LifecycleEvent::AgentAfter->value,
            LifecycleEvent::AgentMaxIterations->value => $base + $this->fromAgentAfter($context),
            LifecycleEvent::AgentError->value => $base + $this->fromAgentError($context),
            LifecycleEvent::ProviderRequest->value => $base + $this->fromProviderRequest($context),
            LifecycleEvent::ProviderResponse->value => $base + $this->fromProviderResponse($context),
            LifecycleEvent::ProviderRetry->value => $base + $this->fromProviderRetry($context),
            LifecycleEvent::ProviderCacheHit->value => $base + $this->fromProviderCacheHit($context),
            LifecycleEvent::ProviderError->value => $base + $this->fromProviderError($context),
            LifecycleEvent::ProviderToken->value => $base + $this->fromProviderToken($context),
            LifecycleEvent::ToolBefore->value => $base + $this->fromToolBefore($context),
            LifecycleEvent::ToolAfter->value => $base + $this->fromToolAfter($context),
            LifecycleEvent::ToolError->value => $base + $this->fromToolError($context),
            LifecycleEvent::ToolNotFound->value => $base + $this->fromToolNotFound($context),
            LifecycleEvent::ContextOverflow->value => $base + $this->fromContextOverflow($context),
            LifecycleEvent::GuardBlocked->value => $base + $this->fromGuardBlocked($context),
            LifecycleEvent::GuardRateLimitExceeded->value => $base + $this->fromGuardRateLimit($context),
            LifecycleEvent::GuardToolOutputRedacted->value => $base + $this->fromGuardToolOutputRedacted($context),
            LifecycleEvent::GuardOutputPhpTagRemoved->value => $base + $this->fromGuardPhpTagRemoved($context),
            LifecycleEvent::GuardOutputFunctionRedacted->value => $base + $this->fromGuardFunctionRedacted($context),
            LifecycleEvent::ConversationStart->value => $base + $this->fromConversationStart($context),
            LifecycleEvent::ConversationEnd->value => $base + $this->fromConversationEnd($context),
            LifecycleEvent::MemoryRead->value,
            LifecycleEvent::MemoryWrite->value,
            LifecycleEvent::MemoryForget->value => $base + $this->fromMemory($context),
            LifecycleEvent::StreamStart->value => $base + $this->fromStreamStart($context),
            LifecycleEvent::StreamEnd->value => $base + $this->fromStreamEnd($context),
            LifecycleEvent::StreamAbort->value => $base + $this->fromStreamAbort($context),
            LifecycleEvent::ShellDenied->value => $base + $this->fromShellDenied($context),
            LifecycleEvent::ShellExec->value => $base + $this->fromShellExec($context),
            LifecycleEvent::JobStarted->value => $base + $this->fromJobStarted($context),
            LifecycleEvent::JobCompleted->value => $base + $this->fromJobCompleted($context),
            LifecycleEvent::JobFailed->value => $base + $this->fromJobFailed($context),
            LifecycleEvent::SkillRegistered->value => $base + $this->fromSkillRegistered($context),
            LifecycleEvent::SkillLoaded->value => $base + $this->fromSkillLoaded($context),
            LifecycleEvent::SkillMatched->value => $base + $this->fromSkillMatched($context),
            LifecycleEvent::SkillNotMatched->value => $base + $this->fromSkillNotMatched($context),
            default => $base + $this->fromGenericEvent($context),
        };

        return self::capTotalSize($base, self::sanitizePayload($this->hide($payload)));
    }

    /**
     * Replace every hidden field's non-null value with a marker at any depth, so a custom event's
     * nested `payload` is covered and "hidden" stays distinguishable from "never recorded".
     *
     * @param  array<string, mixed>  $payload  Built payload.
     * @return array<string, mixed> The payload with hidden fields replaced.
     */
    private function hide(array $payload): array
    {
        if ($this->hidden === []) {
            return $payload;
        }
        foreach ($payload as $key => $value) {
            if ($value !== null && in_array(strtolower((string) $key), $this->hidden, strict: true)) {
                $payload[$key] = self::HIDDEN_MARKER;
            } elseif (is_array($value)) {
                $payload[$key] = $this->hide($value);
            }
        }

        return $payload;
    }

    /**
     * Collapse an over-large payload to a minimal envelope so a single event can never flood the transport or memory.
     *
     * @param  array<string, mixed>  $base  The event/timestamp envelope to retain when collapsing.
     * @param  array<string, mixed>  $payload  The sanitized payload to size-check.
     * @return array<string, mixed> The payload unchanged, or a minimal envelope when it exceeds the byte cap.
     */
    private static function capTotalSize(array $base, array $payload): array
    {
        $encoded = json_encode($payload);

        if ($encoded === false || strlen($encoded) <= self::MAX_TOTAL_PAYLOAD_BYTES) {
            return $payload;
        }

        return $base + [
            'run_id' => $payload['run_id'] ?? null,
            'oversized' => true,
            'original_bytes' => strlen($encoded),
        ];
    }

    /**
     * Build the cloud payload for the `agent.before` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromAgentBefore(array $context): array
    {
        $payload = [
            'run_id' => self::str($context, 'run_id'),
            'conversation_id' => self::str($context, 'conversation_id'),
            'has_conversation' => self::hasConversation($context),
            'streaming' => self::bool($context, 'streaming'),
        ];

        return $payload + self::messageAliases($context);
    }

    /**
     * Build the cloud payload for the `agent.iteration` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromAgentIteration(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'iteration' => self::int($context, 'iteration'),
        ];
    }

    /**
     * Build the cloud payload for the `agent.after` and `agent.max_iterations` events.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromAgentAfter(array $context): array
    {
        $payload = [
            'run_id' => self::str($context, 'run_id'),
            'conversation_id' => self::str($context, 'conversation_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'iterations' => self::int($context, 'iterations'),
            'duration_ms' => self::int($context, 'duration_ms'),
            'tools_called' => $context['tools_called'] ?? null,
            'cache_read_tokens' => self::int($context, 'cache_read_tokens'),
            'cache_write_tokens' => self::int($context, 'cache_write_tokens'),
            'streaming' => self::bool($context, 'streaming'),
            'has_conversation' => self::hasConversation($context),
        ];

        return $payload
            + self::textAliases($context, 'text')
            + ['message' => self::str($context, 'message')];
    }

    /**
     * Build the cloud payload for the `agent.error` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromAgentError(array $context): array
    {
        $payload = [
            'run_id' => self::str($context, 'run_id'),
            'error' => self::str($context, 'error'),
            'class' => self::str($context, 'class'),
            'streaming' => self::bool($context, 'streaming'),
            'has_conversation' => self::hasConversation($context),
        ];

        return $payload + self::messageAliases($context);
    }

    /**
     * Build the cloud payload for the `provider.request` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromProviderRequest(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'history_len' => self::int($context, 'history_len'),
            'tool_count' => self::int($context, 'tool_count'),
            'streaming' => self::bool($context, 'streaming'),
        ];
    }

    /**
     * Build the cloud payload for the `provider.response` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromProviderResponse(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'type' => self::str($context, 'type'),
            'input_tokens' => self::int($context, 'input_tokens'),
            'output_tokens' => self::int($context, 'output_tokens'),
            'cache_read_tokens' => self::int($context, 'cache_read_tokens'),
            'cache_write_tokens' => self::int($context, 'cache_write_tokens'),
            'duration_ms' => self::int($context, 'duration_ms'),
            'streaming' => self::bool($context, 'streaming'),
        ];
    }

    /**
     * Build the cloud payload for the `provider.retry` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromProviderRetry(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'attempt' => self::int($context, 'attempt'),
            'error' => self::str($context, 'error'),
            'iteration' => self::int($context, 'iteration'),
        ];
    }

    /**
     * Build the cloud payload for the `provider.cache_hit` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromProviderCacheHit(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'cache_read_tokens' => self::int($context, 'cache_read_tokens'),
            'cache_write_tokens' => self::int($context, 'cache_write_tokens'),
        ];
    }

    /**
     * Build the cloud payload for the `provider.error` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromProviderError(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'error' => self::str($context, 'error'),
            'iteration' => self::int($context, 'iteration'),
        ];
    }

    /**
     * Token-level event: emit provider and model only.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready token-event payload.
     */
    private function fromProviderToken(array $context): array
    {
        return [
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
        ];
    }

    /**
     * Build the cloud payload for the `tool.before` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromToolBefore(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'tool_name' => self::str($context, 'tool_name'),
            'tool_input' => $context['tool_input'] ?? null,
            'iteration' => self::int($context, 'iteration'),
        ];
    }

    /**
     * Build the cloud payload for the `tool.after` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromToolAfter(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'tool_name' => self::str($context, 'tool_name'),
            'tool_input' => $context['tool_input'] ?? null,
            'tool_result' => self::str($context, 'tool_result'),
            'iteration' => self::int($context, 'iteration'),
            'duration_ms' => self::int($context, 'duration_ms'),
        ];
    }

    /**
     * Build the cloud payload for the `tool.error` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromToolError(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'tool_name' => self::str($context, 'tool_name'),
            'tool_input' => $context['tool_input'] ?? null,
            'error' => self::str($context, 'error'),
        ];
    }

    /**
     * Build the cloud payload for the `tool.not_found` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromToolNotFound(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'tool_name' => self::str($context, 'tool_name'),
            'tool_input' => $context['tool_input'] ?? null,
            'available_tools' => $context['available_tools'] ?? null,
        ];
    }

    /**
     * Build the cloud payload for the `context.overflow` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromContextOverflow(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'history_length' => self::int($context, 'history_length'),
            'max_history_length' => self::int($context, 'max_history_length'),
        ];
    }

    /**
     * Build the cloud payload for the `guard.blocked` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromGuardBlocked(array $context): array
    {
        return ['reason' => self::str($context, 'reason')];
    }

    /**
     * Build the cloud payload for the `guard.rate_limit_exceeded` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromGuardRateLimit(array $context): array
    {
        return [
            'caller_id' => self::str($context, 'caller_id'),
            'count' => self::int($context, 'count'),
            'max_requests' => self::int($context, 'max_requests'),
            'window_seconds' => self::int($context, 'window_seconds'),
        ];
    }

    /**
     * Build the cloud payload for the `guard.tool_output_redacted` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromGuardToolOutputRedacted(array $context): array
    {
        return [
            'tool_name' => self::str($context, 'tool_name'),
            'pattern' => self::str($context, 'pattern'),
        ];
    }

    /**
     * Build the cloud payload for the `guard.output_php_tag_removed` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromGuardPhpTagRemoved(array $context): array
    {
        return ['tag' => self::str($context, 'tag')];
    }

    /**
     * Build the cloud payload for the `guard.output_function_redacted` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromGuardFunctionRedacted(array $context): array
    {
        return ['function' => self::str($context, 'function')];
    }

    /**
     * Build the cloud payload for the `conversation.start` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromConversationStart(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'conversation_id' => self::str($context, 'conversation_id'),
            'metadata' => $context['metadata'] ?? null,
        ];
    }

    /**
     * Build the cloud payload for the `conversation.end` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromConversationEnd(array $context): array
    {
        $payload = [
            'run_id' => self::str($context, 'run_id'),
            'conversation_id' => self::str($context, 'conversation_id'),
            'turn_count' => self::int($context, 'turn_count'),
            'duration_ms' => self::int($context, 'duration_ms'),
        ];

        return $payload
            + self::messageAliases($context)
            + self::textAliases($context, 'response');
    }

    /**
     * Build the cloud payload for the `memory.read`, `memory.write`, and `memory.forget` events.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromMemory(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'namespace' => self::str($context, 'namespace'),
            'key' => self::str($context, 'key'),
            'driver' => self::str($context, 'driver'),
            'ttl' => self::int($context, 'ttl'),
            'hit' => isset($context['hit']) ? self::bool($context, 'hit') : null,
        ];
    }

    /**
     * Build the cloud payload for the `stream.start` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromStreamStart(array $context): array
    {
        $payload = [
            'run_id' => self::str($context, 'run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
        ];

        return $payload + self::messageAliases($context);
    }

    /**
     * Build the cloud payload for the `stream.end` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromStreamEnd(array $context): array
    {
        $payload = [
            'run_id' => self::str($context, 'run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'duration_ms' => self::int($context, 'duration_ms'),
            'chars' => self::int($context, 'chars'),
        ];

        return $payload + self::messageAliases($context);
    }

    /**
     * Build the cloud payload for the `stream.abort` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromStreamAbort(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'error' => self::str($context, 'error'),
        ];
    }

    /**
     * Build the cloud payload for the `shell.denied` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromShellDenied(array $context): array
    {
        return [
            'command' => self::str($context, 'command'),
            'cmd_name' => self::str($context, 'cmd_name'),
            'reason' => self::str($context, 'reason'),
            'file' => self::str($context, 'file'),
        ];
    }

    /**
     * Build the cloud payload for the `shell.exec` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromShellExec(array $context): array
    {
        return [
            'command' => self::str($context, 'command'),
            'cmd_name' => self::str($context, 'cmd_name'),
        ];
    }

    /**
     * Build the cloud payload for the `job.started` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromJobStarted(array $context): array
    {
        $payload = ['job_id' => self::str($context, 'job_id')];

        return $payload + self::messageAliases($context);
    }

    /**
     * Build the cloud payload for the `job.completed` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromJobCompleted(array $context): array
    {
        return [
            'job_id' => self::str($context, 'job_id'),
            'run_id' => self::str($context, 'run_id'),
            'provider' => self::str($context, 'provider'),
            'model' => self::str($context, 'model'),
            'iterations' => self::int($context, 'iterations'),
            'duration_ms' => self::int($context, 'duration_ms'),
            'tokens' => self::int($context, 'tokens'),
        ];
    }

    /**
     * Build the cloud payload for the `job.failed` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromJobFailed(array $context): array
    {
        return [
            'job_id' => self::str($context, 'job_id'),
            'error' => self::str($context, 'error'),
            'class' => self::str($context, 'class'),
        ];
    }

    /**
     * Build the cloud payload for the `skill.registered` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromSkillRegistered(array $context): array
    {
        return [
            'key' => self::str($context, 'key'),
            'class' => self::str($context, 'class'),
            'label' => self::str($context, 'label'),
        ];
    }

    /**
     * Build the cloud payload for the `skill.loaded` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromSkillLoaded(array $context): array
    {
        return [
            'skill_name' => self::str($context, 'skill_name'),
            'skill_class' => self::str($context, 'skill_class'),
        ];
    }

    /**
     * Build the cloud payload for the `skill.matched` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromSkillMatched(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'matched_skills' => $context['matched_skills'] ?? null,
            'message_excerpt' => self::str($context, 'message_excerpt'),
            'matched_count' => self::int($context, 'matched_count'),
        ];
    }

    /**
     * Build the cloud payload for the `skill.not_matched` event.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready payload fields for this event.
     */
    private function fromSkillNotMatched(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'message_excerpt' => self::str($context, 'message_excerpt'),
            'available_skills' => $context['available_skills'] ?? null,
        ];
    }

    /**
     * Build the sanitised generic envelope for unknown or custom events.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, mixed> Cloud-ready generic envelope with a sanitised nested `payload`.
     */
    private function fromGenericEvent(array $context): array
    {
        return [
            'run_id' => self::str($context, 'run_id'),
            'parent_run_id' => self::str($context, 'parent_run_id'),
            'payload' => self::sanitizePayload($context),
        ];
    }

    /**
     * Recursively redact secret keys, truncate large strings, and bound array sizes.
     *
     * @param  array<string, mixed>  $payload  Payload.
     * @return array<string, mixed>
     */
    private static function sanitizePayload(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SECRET_KEYS, strict: true)) {
                $out[$key] = self::REDACTED_MARKER;

                continue;
            }

            if (is_string($value) && strlen($value) > self::MAX_PAYLOAD_STRING_BYTES) {
                $out[$key] = substr($value, 0, self::MAX_PAYLOAD_STRING_BYTES).self::TRUNCATED_MARKER;

                continue;
            }

            if (is_array($value)) {
                if (count($value) > self::MAX_PAYLOAD_ARRAY_SIZE) {
                    $out[$key] = sprintf(self::ARRAY_TOO_LARGE_MARKER, count($value));

                    continue;
                }
                $out[$key] = self::sanitizePayload($value);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Whether the event context carries a non-empty conversation id.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return bool True when a non-empty `conversation_id` is present.
     */
    private static function hasConversation(array $context): bool
    {
        return isset($context['conversation_id']) && $context['conversation_id'] !== '';
    }

    /**
     * The `message`/`prompt` alias pair every prompt-bearing event emits.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @return array<string, string|null> Both alias keys carrying the context `message`.
     */
    private static function messageAliases(array $context): array
    {
        $msg = self::str($context, 'message');

        return ['message' => $msg, 'prompt' => $msg];
    }

    /**
     * The `text`/`response` alias pair every completion-bearing event emits.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @param  string  $key  Context key holding the completion text.
     * @return array<string, string|null> Both alias keys carrying that value.
     */
    private static function textAliases(array $context, string $key): array
    {
        $txt = self::str($context, $key);

        return ['text' => $txt, 'response' => $txt];
    }

    /**
     * Read a context value coerced to string, or null when the key is absent.
     *
     * @param  array<string, mixed>  $ctx  Event context to read from.
     * @param  string  $key  Key to look up.
     * @return string|null String value, or null when the key is unset.
     */
    private static function str(array $ctx, string $key): ?string
    {
        return isset($ctx[$key]) ? (string) $ctx[$key] : null;
    }

    /**
     * Read a context value coerced to int, or null when the key is absent.
     *
     * @param  array<string, mixed>  $ctx  Event context to read from.
     * @param  string  $key  Key to look up.
     * @return int|null Integer value, or null when the key is unset.
     */
    private static function int(array $ctx, string $key): ?int
    {
        return isset($ctx[$key]) ? (int) $ctx[$key] : null;
    }

    /**
     * Read a context value coerced to bool, falling back to a default when absent.
     *
     * @param  array<string, mixed>  $ctx  Event context to read from.
     * @param  string  $key  Key to look up.
     * @param  bool  $default  Value returned when the key is unset.
     * @return bool Boolean value, or the default when the key is unset.
     */
    private static function bool(array $ctx, string $key, bool $default = false): bool
    {
        return (bool) ($ctx[$key] ?? $default);
    }
}
