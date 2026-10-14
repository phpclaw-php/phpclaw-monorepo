<?php

declare(strict_types=1);

namespace PhpClaw\Cloud;

use PhpClaw\Hooks\LifecycleEvent;

/**
 * Builds the cloud-bound JSON payload for every phpClaw lifecycle event.
 */
final class CloudPayloadBuilder
{
    public const HIDE_INPUTS = 'hide_inputs';

    public const HIDE_OUTPUTS = 'hide_outputs';

    public const HIDE_METADATA = 'hide_metadata';

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

    private const HIDDEN_MARKER = '[hidden]';

    private const HIDDEN_FIELDS = [
        self::HIDE_INPUTS => ['message', 'prompt', 'message_excerpt', 'tool_input', 'command'],
        self::HIDE_OUTPUTS => ['text', 'response', 'tool_result'],
        self::HIDE_METADATA => ['metadata'],
    ];

    private const FIELD_STRING = 'string';

    private const FIELD_INT = 'int';

    private const FIELD_BOOL = 'bool';

    private const FIELD_NULLABLE_BOOL = 'nullable_bool';

    private const FIELD_NON_EMPTY = 'non_empty';

    private const FIELD_RAW = 'raw';

    private const RUN_FIELDS = [
        'run_id' => self::FIELD_STRING,
        'parent_run_id' => self::FIELD_STRING,
    ];

    private const MODEL_FIELDS = [
        'provider' => self::FIELD_STRING,
        'model' => self::FIELD_STRING,
    ];

    private const MESSAGE_FIELDS = [
        'message' => self::FIELD_STRING,
        'prompt' => [self::FIELD_STRING, 'message'],
    ];

    private const HAS_CONVERSATION_FIELD = [
        'has_conversation' => [self::FIELD_NON_EMPTY, 'conversation_id'],
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

        $fields = self::fieldsFor($event);
        $payload = $fields === null
            ? $base + self::readFields($context, self::RUN_FIELDS) + ['payload' => self::sanitizePayload($context)]
            : $base + self::readFields($context, $fields);

        return self::capTotalSize($base, self::sanitizePayload($this->hide($payload)));
    }

    /**
     * Return the payload field map for a known lifecycle event, or null for a custom event.
     *
     * @param  string  $event  Resolved event name.
     * @return array<string, string|array{0: string, 1: string}>|null Output field to field type, or [type, context key] when the key differs.
     */
    private static function fieldsFor(string $event): ?array
    {
        return match ($event) {
            LifecycleEvent::AgentBefore->value => [
                ...self::RUN_FIELDS,
                'conversation_id' => self::FIELD_STRING,
                ...self::HAS_CONVERSATION_FIELD,
                'streaming' => self::FIELD_BOOL,
                ...self::MESSAGE_FIELDS,
            ],
            LifecycleEvent::AgentIteration->value => [...self::RUN_FIELDS, 'iteration' => self::FIELD_INT],
            LifecycleEvent::AgentAfter->value,
            LifecycleEvent::AgentMaxIterations->value => [
                ...self::RUN_FIELDS,
                'conversation_id' => self::FIELD_STRING,
                ...self::MODEL_FIELDS,
                'iterations' => self::FIELD_INT,
                'duration_ms' => self::FIELD_INT,
                'tools_called' => self::FIELD_RAW,
                'cache_read_tokens' => self::FIELD_INT,
                'cache_write_tokens' => self::FIELD_INT,
                'streaming' => self::FIELD_BOOL,
                ...self::HAS_CONVERSATION_FIELD,
                'text' => self::FIELD_STRING,
                'response' => [self::FIELD_STRING, 'text'],
                'message' => self::FIELD_STRING,
            ],
            LifecycleEvent::AgentError->value => [
                'run_id' => self::FIELD_STRING,
                'error' => self::FIELD_STRING,
                'class' => self::FIELD_STRING,
                'streaming' => self::FIELD_BOOL,
                ...self::HAS_CONVERSATION_FIELD,
                ...self::MESSAGE_FIELDS,
            ],
            LifecycleEvent::ProviderRequest->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'history_len' => self::FIELD_INT,
                'tool_count' => self::FIELD_INT,
                'streaming' => self::FIELD_BOOL,
            ],
            LifecycleEvent::ProviderResponse->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'type' => self::FIELD_STRING,
                'input_tokens' => self::FIELD_INT,
                'output_tokens' => self::FIELD_INT,
                'cache_read_tokens' => self::FIELD_INT,
                'cache_write_tokens' => self::FIELD_INT,
                'duration_ms' => self::FIELD_INT,
                'streaming' => self::FIELD_BOOL,
            ],
            LifecycleEvent::ProviderRetry->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'attempt' => self::FIELD_INT,
                'error' => self::FIELD_STRING,
                'iteration' => self::FIELD_INT,
            ],
            LifecycleEvent::ProviderCacheHit->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'cache_read_tokens' => self::FIELD_INT,
                'cache_write_tokens' => self::FIELD_INT,
            ],
            LifecycleEvent::ProviderFallback->value => [
                ...self::RUN_FIELDS,
                'from' => self::FIELD_STRING,
                'to' => self::FIELD_STRING,
                'status' => self::FIELD_INT,
            ],
            LifecycleEvent::ProviderResponseCached->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'key_hash' => self::FIELD_STRING,
            ],
            LifecycleEvent::BudgetExceeded->value => [
                ...self::RUN_FIELDS,
                'tokens_spent' => self::FIELD_INT,
                'budget' => self::FIELD_INT,
            ],
            LifecycleEvent::StructuredRepair->value => [
                ...self::RUN_FIELDS,
                'attempt' => self::FIELD_INT,
                'error_count' => self::FIELD_INT,
            ],
            LifecycleEvent::ProviderError->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'error' => self::FIELD_STRING,
                'iteration' => self::FIELD_INT,
            ],
            LifecycleEvent::ProviderToken->value => self::MODEL_FIELDS,
            LifecycleEvent::ToolBefore->value => [
                ...self::RUN_FIELDS,
                'tool_name' => self::FIELD_STRING,
                'tool_input' => self::FIELD_RAW,
                'iteration' => self::FIELD_INT,
            ],
            LifecycleEvent::ToolAfter->value => [
                ...self::RUN_FIELDS,
                'tool_name' => self::FIELD_STRING,
                'tool_input' => self::FIELD_RAW,
                'tool_result' => self::FIELD_STRING,
                'iteration' => self::FIELD_INT,
                'duration_ms' => self::FIELD_INT,
            ],
            LifecycleEvent::ToolError->value => [
                ...self::RUN_FIELDS,
                'tool_name' => self::FIELD_STRING,
                'tool_input' => self::FIELD_RAW,
                'error' => self::FIELD_STRING,
            ],
            LifecycleEvent::ToolNotFound->value => [
                ...self::RUN_FIELDS,
                'tool_name' => self::FIELD_STRING,
                'tool_input' => self::FIELD_RAW,
                'available_tools' => self::FIELD_RAW,
            ],
            LifecycleEvent::ContextOverflow->value => [
                ...self::RUN_FIELDS,
                ...self::MODEL_FIELDS,
                'history_length' => self::FIELD_INT,
                'max_history_length' => self::FIELD_INT,
            ],
            LifecycleEvent::GuardBlocked->value => [...self::RUN_FIELDS, 'reason' => self::FIELD_STRING],
            LifecycleEvent::GuardRateLimitExceeded->value => [
                ...self::RUN_FIELDS,
                'caller_id' => self::FIELD_STRING,
                'count' => self::FIELD_INT,
                'max_requests' => self::FIELD_INT,
                'window_seconds' => self::FIELD_INT,
            ],
            LifecycleEvent::GuardToolOutputRedacted->value => [
                ...self::RUN_FIELDS,
                'tool_name' => self::FIELD_STRING,
                'pattern' => self::FIELD_STRING,
            ],
            LifecycleEvent::GuardOutputPhpTagRemoved->value => [...self::RUN_FIELDS, 'tag' => self::FIELD_STRING],
            LifecycleEvent::GuardOutputFunctionRedacted->value => [...self::RUN_FIELDS, 'function' => self::FIELD_STRING],
            LifecycleEvent::ConversationStart->value => [
                ...self::RUN_FIELDS,
                'conversation_id' => self::FIELD_STRING,
                'metadata' => self::FIELD_RAW,
            ],
            LifecycleEvent::ConversationEnd->value => [
                'run_id' => self::FIELD_STRING,
                'conversation_id' => self::FIELD_STRING,
                'turn_count' => self::FIELD_INT,
                'duration_ms' => self::FIELD_INT,
                ...self::MESSAGE_FIELDS,
                'text' => [self::FIELD_STRING, 'response'],
                'response' => self::FIELD_STRING,
            ],
            LifecycleEvent::MemoryRead->value,
            LifecycleEvent::MemoryWrite->value,
            LifecycleEvent::MemoryForget->value => [
                ...self::RUN_FIELDS,
                'namespace' => self::FIELD_STRING,
                'key' => self::FIELD_STRING,
                'driver' => self::FIELD_STRING,
                'ttl' => self::FIELD_INT,
                'hit' => self::FIELD_NULLABLE_BOOL,
            ],
            LifecycleEvent::StreamStart->value => [
                'run_id' => self::FIELD_STRING,
                ...self::MODEL_FIELDS,
                ...self::MESSAGE_FIELDS,
            ],
            LifecycleEvent::StreamEnd->value => [
                'run_id' => self::FIELD_STRING,
                ...self::MODEL_FIELDS,
                'duration_ms' => self::FIELD_INT,
                'chars' => self::FIELD_INT,
                ...self::MESSAGE_FIELDS,
            ],
            LifecycleEvent::StreamAbort->value => [
                'run_id' => self::FIELD_STRING,
                ...self::MODEL_FIELDS,
                'error' => self::FIELD_STRING,
            ],
            LifecycleEvent::ShellDenied->value => [
                ...self::RUN_FIELDS,
                'command' => self::FIELD_STRING,
                'cmd_name' => self::FIELD_STRING,
                'reason' => self::FIELD_STRING,
                'file' => self::FIELD_STRING,
            ],
            LifecycleEvent::ShellExec->value => [
                ...self::RUN_FIELDS,
                'command' => self::FIELD_STRING,
                'cmd_name' => self::FIELD_STRING,
            ],
            LifecycleEvent::JobStarted->value => ['job_id' => self::FIELD_STRING, ...self::MESSAGE_FIELDS],
            LifecycleEvent::JobCompleted->value => [
                'job_id' => self::FIELD_STRING,
                'run_id' => self::FIELD_STRING,
                ...self::MODEL_FIELDS,
                'iterations' => self::FIELD_INT,
                'duration_ms' => self::FIELD_INT,
                'tokens' => self::FIELD_INT,
            ],
            LifecycleEvent::JobFailed->value => [
                'job_id' => self::FIELD_STRING,
                'error' => self::FIELD_STRING,
                'class' => self::FIELD_STRING,
            ],
            LifecycleEvent::SkillRegistered->value => [
                'key' => self::FIELD_STRING,
                'class' => self::FIELD_STRING,
                'label' => self::FIELD_STRING,
            ],
            LifecycleEvent::SkillLoaded->value => [
                'skill_name' => self::FIELD_STRING,
                'skill_class' => self::FIELD_STRING,
            ],
            LifecycleEvent::SkillMatched->value => [
                ...self::RUN_FIELDS,
                'matched_skills' => self::FIELD_RAW,
                'message_excerpt' => self::FIELD_STRING,
                'matched_count' => self::FIELD_INT,
            ],
            LifecycleEvent::SkillNotMatched->value => [
                ...self::RUN_FIELDS,
                'message_excerpt' => self::FIELD_STRING,
                'available_skills' => self::FIELD_RAW,
            ],
            LifecycleEvent::GraphStart->value => [...self::RUN_FIELDS, 'entry_node' => self::FIELD_STRING],
            LifecycleEvent::GraphEnd->value => [
                ...self::RUN_FIELDS,
                'steps' => self::FIELD_INT,
                'last_node' => self::FIELD_STRING,
            ],
            LifecycleEvent::GraphFanOut->value => [
                ...self::RUN_FIELDS,
                'node' => self::FIELD_STRING,
                'branches' => self::FIELD_INT,
            ],
            LifecycleEvent::GraphJoin->value => [
                ...self::RUN_FIELDS,
                'node' => self::FIELD_STRING,
                'branches' => self::FIELD_INT,
                'failed' => self::FIELD_INT,
            ],
            default => null,
        };
    }

    /**
     * Read every field of a field map from the event context, in map order.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @param  array<string, string|array{0: string, 1: string}>  $fields  Output field to field type, or [type, context key].
     * @return array<string, mixed> Output field to typed value.
     */
    private static function readFields(array $context, array $fields): array
    {
        $payload = [];

        foreach ($fields as $name => $field) {
            [$type, $key] = is_array($field) ? $field : [$field, $name];
            $payload[$name] = self::readField($context, $key, $type);
        }

        return $payload;
    }

    /**
     * Read one context value cast to its field type; an absent key gives null, or false for the bool types.
     *
     * @param  array<string, mixed>  $context  Raw event context from the hook dispatcher.
     * @param  string  $key  Context key to read.
     * @param  string  $type  One of the FIELD_* types; FIELD_RAW returns the value uncast.
     * @return mixed The typed value.
     */
    private static function readField(array $context, string $key, string $type): mixed
    {
        if ($type === self::FIELD_BOOL) {
            return (bool) ($context[$key] ?? false);
        }

        if ($type === self::FIELD_NON_EMPTY) {
            return isset($context[$key]) && $context[$key] !== '';
        }

        if (! isset($context[$key])) {
            return null;
        }

        return match ($type) {
            self::FIELD_STRING => (string) $context[$key],
            self::FIELD_INT => (int) $context[$key],
            self::FIELD_NULLABLE_BOOL => (bool) $context[$key],
            default => $context[$key],
        };
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
     * Recursively redact secret keys, truncate large strings, and bound array sizes.
     *
     * @param  array<string, mixed>  $payload  Payload to sanitise.
     * @return array<string, mixed> The sanitised payload, same keys.
     */
    private static function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $sanitized[$key] = in_array(strtolower((string) $key), self::SECRET_KEYS, strict: true)
                ? self::REDACTED_MARKER
                : self::sanitizeValue($value);
        }

        return $sanitized;
    }

    /**
     * Truncate a long string, replace an over-large array with a marker, and recurse into other arrays.
     *
     * @param  mixed  $value  One payload value.
     * @return mixed The sanitised value.
     */
    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > self::MAX_PAYLOAD_STRING_BYTES) {
            return substr($value, 0, self::MAX_PAYLOAD_STRING_BYTES).self::TRUNCATED_MARKER;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (count($value) > self::MAX_PAYLOAD_ARRAY_SIZE) {
            return sprintf(self::ARRAY_TOO_LARGE_MARKER, count($value));
        }

        return self::sanitizePayload($value);
    }
}
