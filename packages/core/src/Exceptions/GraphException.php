<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a Graph is misconfigured, cannot save, resume or fan out a thread, or never reaches Graph::END in its step limit.
 */
final class GraphException extends PhpClawException
{
    /**
     * Build the exception for a graph run started without an entry node.
     *
     * @return self
     */
    public static function missingEntry(): self
    {
        return new self('Graph has no entry node; call setEntry() with the name of an added node.');
    }

    /**
     * Build the exception for an entry, edge or router that names a node the graph does not have.
     *
     * @param  string  $name  The node name that was not found.
     * @return self
     */
    public static function unknownNode(string $name): self
    {
        return new self(sprintf("Graph has no node named '%s'.", $name));
    }

    /**
     * Build the exception for a graph that ran its step limit without reaching Graph::END.
     *
     * @param  int  $maxSteps  The step limit that was reached.
     * @return self
     */
    public static function maxStepsReached(int $maxSteps): self
    {
        return new self(sprintf('Graph stopped after %d steps without reaching Graph::END.', $maxSteps));
    }

    /**
     * Build the exception for saving, pausing or resuming a graph that has no checkpointer.
     *
     * @return self
     */
    public static function noCheckpointer(): self
    {
        return new self('Saving, pausing or resuming a graph needs a checkpointer: call setCheckpointer() first.');
    }

    /**
     * Build the exception for a graph with a checkpointer run without a thread id.
     *
     * @return self
     */
    public static function missingThreadId(): self
    {
        return new self('A graph with a checkpointer needs a thread id: call run($state, threadId: ...).');
    }

    /**
     * Build the exception for a thread id that has no saved snapshot.
     *
     * @param  string  $threadId  The thread id that was not found.
     * @return self
     */
    public static function unknownThread(string $threadId): self
    {
        return new self(sprintf("Graph thread '%s' has no saved state.", $threadId));
    }

    /**
     * Build the exception for resuming a thread whose latest snapshot is not paused.
     *
     * @param  string  $threadId  The thread id that is not paused.
     * @return self
     */
    public static function notPaused(string $threadId): self
    {
        return new self(sprintf("Graph thread '%s' is not paused, so it cannot be resumed.", $threadId));
    }

    /**
     * Build the exception for a value a saved graph cannot hold; names where the value sits, never the value.
     *
     * @param  string  $where  Where the value sits, such as "state key 'draft'".
     * @return self
     */
    public static function unsavableValue(string $where): self
    {
        return new self(sprintf('Graph %s holds a value that cannot be saved; use strings, numbers, booleans, null or arrays of them.', $where));
    }

    /**
     * Build the exception for queuing or draining fan-out branches on a graph without a name.
     *
     * @return self
     */
    public static function missingName(): self
    {
        return new self("A graph that queues fan-out branches or drains them needs a name: new Graph('my-graph').");
    }

    /**
     * Build the exception for a fan-out whose branch graph fans out again.
     *
     * @param  string  $node  Fan-out node name.
     * @return self
     */
    public static function nestedFanOut(string $node): self
    {
        return new self(sprintf("Fan-out node '%s' runs a branch graph that fans out again; nested fan-out is not supported.", $node));
    }

    /**
     * Build the exception for a fan-out that produced more branches than its limit.
     *
     * @param  string  $node  Fan-out node name.
     * @param  int  $branches  Branches the expander returned.
     * @param  int  $limit  Most branches allowed.
     * @return self
     */
    public static function tooManyBranches(string $node, int $branches, int $limit): self
    {
        return new self(sprintf("Fan-out node '%s' produced %d branches; the limit is %d.", $node, $branches, $limit));
    }

    /**
     * Build the exception recorded for a queued fan-out branch that called interrupt().
     *
     * @return self
     */
    public static function branchInterrupted(): self
    {
        return new self('A fan-out branch cannot pause for a human; its interrupt() call failed the branch.');
    }
}
