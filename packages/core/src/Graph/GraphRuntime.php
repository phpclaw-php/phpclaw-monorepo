<?php

declare(strict_types=1);

namespace PhpClaw\Graph;

use PhpClaw\Exceptions\GraphException;
use PhpClaw\Exceptions\GraphInterruptedException;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Run-scoped values every node receives as its second argument, plus interrupt() to pause for a human.
 */
final class GraphRuntime
{
    private int $interruptsAsked = 0;

    /**
     * Hold the values for one node attempt of one graph run.
     *
     * @param  string  $runId  Id of this graph run, the same id graph.start and graph.end carry.
     * @param  array<string, mixed>  $context  Values passed to Graph::run(), such as the current user id.
     * @param  MemoryInterface|null  $store  Long-term memory set with Graph::setStore(), or null.
     * @param  string|null  $threadId  Thread the run is saved under, or null when the graph has no checkpointer.
     * @param  string  $node  Node this runtime was handed to.
     * @param  list<mixed>  $answers  Answers given on resume, one per earlier interrupt() call of this node.
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $context,
        public readonly ?MemoryInterface $store,
        public readonly ?string $threadId = null,
        public readonly string $node = '',
        private readonly array $answers = [],
    ) {}

    /**
     * Pause the run for a human; on resume the node runs again and this call returns the answer given to resume().
     *
     * @param  mixed  $value  What the human should see, such as a question and a draft; saved, never logged.
     * @return mixed The answer for this call, in the order the node asks.
     *
     * @throws GraphInterruptedException When this call has no answer yet: the run is saved and stops.
     * @throws GraphException When the graph has no checkpointer to save the paused run.
     */
    public function interrupt(mixed $value): mixed
    {
        if ($this->threadId === null) {
            throw GraphException::noCheckpointer();
        }

        $index = $this->interruptsAsked++;

        if (array_key_exists($index, $this->answers)) {
            return $this->answers[$index];
        }

        throw new GraphInterruptedException($this->threadId, $this->node, $value);
    }
}
