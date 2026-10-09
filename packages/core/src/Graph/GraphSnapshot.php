<?php

declare(strict_types=1);

namespace PhpClaw\Graph;

/**
 * One saved point of a graph thread: the state values, the node that runs next, its status, and its fan-out links.
 */
final class GraphSnapshot
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_INTERRUPTED = 'interrupted';

    public const STATUS_DONE = 'done';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_FAILED = 'failed';

    public const PAUSE_NONE = '';

    public const PAUSE_NODE = 'node';

    public const PAUSE_BEFORE = 'before';

    public const PAUSE_AFTER = 'after';

    /**
     * Hold one saved point.
     *
     * @param  string  $threadId  Thread the snapshot belongs to.
     * @param  int  $step  Nodes completed in this thread so far, across resumes.
     * @param  string|null  $next  Node that runs next; null once the thread reached Graph::END.
     * @param  array<string, mixed>  $values  State values at this point.
     * @param  string  $status  One of the STATUS_* constants.
     * @param  mixed  $interrupt  What the paused node passed to GraphRuntime::interrupt(), or null.
     * @param  list<mixed>  $answers  Answers already given to the paused node's earlier interrupts.
     * @param  string  $pause  One of the PAUSE_* constants: why the thread is paused.
     * @param  string  $graph  Name of the graph whose drain() takes this thread; set on queued fan-out threads.
     * @param  string|null  $parentThreadId  Parent thread of a fan-out branch, or null.
     * @param  string  $fanOut  Fan-out node this branch belongs to, or the parent is waiting on.
     * @param  list<string>  $children  Branch thread ids of a waiting parent, in declared order.
     * @param  array{class: string, message: string}|null  $error  Why a failed thread failed, or null.
     */
    public function __construct(
        public readonly string $threadId,
        public readonly int $step,
        public readonly ?string $next,
        public readonly array $values,
        public readonly string $status,
        public readonly mixed $interrupt = null,
        public readonly array $answers = [],
        public readonly string $pause = self::PAUSE_NONE,
        public readonly string $graph = '',
        public readonly ?string $parentThreadId = null,
        public readonly string $fanOut = '',
        public readonly array $children = [],
        public readonly ?array $error = null,
    ) {}

    /**
     * Return a copy with another status, keeping every other field.
     *
     * @param  string  $status  One of the STATUS_* constants.
     * @param  array{class: string, message: string}|null  $error  Why the thread failed, for STATUS_FAILED.
     * @return self
     */
    public function withStatus(string $status, ?array $error = null): self
    {
        return new self(
            $this->threadId,
            $this->step,
            $this->next,
            $this->values,
            $status,
            $this->interrupt,
            $this->answers,
            $this->pause,
            $this->graph,
            $this->parentThreadId,
            $this->fanOut,
            $this->children,
            $error,
        );
    }

    /**
     * Return the snapshot as a plain array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'next' => $this->next,
            'values' => $this->values,
            'status' => $this->status,
            'interrupt' => $this->interrupt,
            'answers' => $this->answers,
            'pause' => $this->pause,
            'graph' => $this->graph,
            'parent_thread_id' => $this->parentThreadId,
            'fan_out' => $this->fanOut,
            'children' => $this->children,
            'error' => $this->error,
        ];
    }

    /**
     * Rebuild a snapshot from the array toArray() produced.
     *
     * @param  string  $threadId  Thread the snapshot belongs to.
     * @param  array<string, mixed>  $row  Stored array.
     * @return self
     */
    public static function fromArray(string $threadId, array $row): self
    {
        return new self(
            threadId: $threadId,
            step: (int) ($row['step'] ?? 0),
            next: isset($row['next']) ? (string) $row['next'] : null,
            values: (array) ($row['values'] ?? []),
            status: (string) ($row['status'] ?? self::STATUS_RUNNING),
            interrupt: $row['interrupt'] ?? null,
            answers: array_values((array) ($row['answers'] ?? [])),
            pause: (string) ($row['pause'] ?? self::PAUSE_NONE),
            graph: (string) ($row['graph'] ?? ''),
            parentThreadId: isset($row['parent_thread_id']) ? (string) $row['parent_thread_id'] : null,
            fanOut: (string) ($row['fan_out'] ?? ''),
            children: array_values(array_map('strval', (array) ($row['children'] ?? []))),
            error: self::readError($row['error'] ?? null),
        );
    }

    /**
     * Read a stored error entry, or null when there is none.
     *
     * @param  mixed  $error  Stored value.
     * @return array{class: string, message: string}|null
     */
    private static function readError(mixed $error): ?array
    {
        if (! is_array($error)) {
            return null;
        }

        return ['class' => (string) ($error['class'] ?? ''), 'message' => (string) ($error['message'] ?? '')];
    }
}
