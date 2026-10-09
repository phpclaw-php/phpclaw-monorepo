<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a saved graph run pauses; pass the thread id to Graph::resume() to continue it.
 */
final class GraphInterruptedException extends PhpClawException
{
    public readonly string $threadId;

    public readonly string $node;

    public readonly mixed $value;

    /**
     * Record where the run paused; the value is kept on the exception, never in its message.
     *
     * @param  string  $threadId  Thread the run is saved under.
     * @param  string  $node  Node that runs next when the thread is resumed.
     * @param  mixed  $value  What the node passed to GraphRuntime::interrupt(), or null for a pause set with interruptBefore() or interruptAfter().
     */
    public function __construct(string $threadId, string $node, mixed $value = null)
    {
        $this->threadId = $threadId;
        $this->node = $node;
        $this->value = $value;
        parent::__construct(sprintf("Graph thread '%s' paused at node '%s'.", $threadId, $node));
    }
}
