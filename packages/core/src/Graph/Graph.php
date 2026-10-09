<?php

declare(strict_types=1);

namespace PhpClaw\Graph;

use Closure;
use Exception;
use PhpClaw\Agent\ResumeReport;
use PhpClaw\Exceptions\GraphException;
use PhpClaw\Exceptions\GraphInterruptedException;
use PhpClaw\Hooks\Dispatchers\AgentEventDispatcher;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Pipeline\Steps\RetryStep;
use PhpClaw\Support\Ulid;
use Throwable;

/**
 * Named nodes joined by plain and conditional edges, run one node at a time over a GraphState until a
 * route reaches Graph::END; each node attempt works on its own copy, and a checkpointer saves every step.
 */
final class Graph
{
    public const END = '__end__';

    public const DEFAULT_MAX_STEPS = 50;

    public const DEFAULT_MAX_BRANCHES = 32;

    public const DEFAULT_DRAIN_LIMIT = 5;

    public const DEFAULT_DRAIN_SECONDS = 20;

    private array $nodes = [];

    private array $edges = [];

    private array $conditionalEdges = [];

    private string $entry = '';

    private int $defaultAttempts = 1;

    private array $defaultRetryOn = RetryStep::DEFAULT_RETRY_ON;

    private ?Closure $defaultOnError = null;

    private ?MemoryInterface $store = null;

    private ?GraphCheckpointer $checkpointer = null;

    private array $interruptBefore = [];

    private array $interruptAfter = [];

    private array $fanOuts = [];

    /**
     * Start an empty graph.
     *
     * @param  string  $name  Graph name; needed to queue fan-out branches and to drain() them.
     */
    public function __construct(
        private readonly string $name = '',
    ) {}

    /**
     * Add a node; it gets the state and the run's GraphRuntime, and returns the state, a GraphCommand or nothing.
     *
     * @param  string  $name  Node name used by edges and routers.
     * @param  callable(GraphState, GraphRuntime): (GraphState|GraphCommand|void)  $node  Work done when the walk reaches this node.
     * @param  int|null  $attempts  Most runs of this node, the first included; null uses setNodeDefaults().
     * @param  list<class-string<Exception>>|null  $retryOn  Errors that cause another attempt; null uses setNodeDefaults().
     * @param  (callable(GraphState, Exception, string): (GraphState|GraphCommand|void))|null  $onError  Runs when the
     *                                                                                                   last attempt fails; null uses setNodeDefaults().
     * @return self
     */
    public function addNode(string $name, callable $node, ?int $attempts = null, ?array $retryOn = null, ?callable $onError = null): self
    {
        $this->nodes[$name] = ['run' => $node, 'attempts' => $attempts, 'retryOn' => $retryOn, 'onError' => $onError];

        return $this;
    }

    /**
     * Set the retry and error-handler settings every node uses unless it sets its own.
     *
     * @param  int  $attempts  Most runs of a node, the first included; a value below 1 counts as 1.
     * @param  list<class-string<Exception>>  $retryOn  Errors that cause another attempt.
     * @param  (callable(GraphState, Exception, string): (GraphState|GraphCommand|void))|null  $onError  Runs when a
     *                                                                                                   node's last attempt fails.
     * @return self
     */
    public function setNodeDefaults(int $attempts = 1, array $retryOn = RetryStep::DEFAULT_RETRY_ON, ?callable $onError = null): self
    {
        $this->defaultAttempts = $attempts;
        $this->defaultRetryOn = $retryOn;
        $this->defaultOnError = $onError === null ? null : $onError(...);

        return $this;
    }

    /**
     * Give every node a long-term memory store through GraphRuntime::$store.
     *
     * @param  MemoryInterface  $store  Memory driver shared by all runs of this graph.
     * @return self
     */
    public function setStore(MemoryInterface $store): self
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Save a snapshot after every node through the given memory driver, so a thread can pause and resume.
     *
     * @param  MemoryInterface  $memory  Driver the snapshots are stored through.
     * @return self
     */
    public function setCheckpointer(MemoryInterface $memory): self
    {
        $this->checkpointer = new GraphCheckpointer($memory);

        return $this;
    }

    /**
     * Pause a saved run before the named nodes run; resume() then runs them.
     *
     * @param  string  ...$nodes  Node names.
     * @return self
     */
    public function interruptBefore(string ...$nodes): self
    {
        $this->interruptBefore = [...$this->interruptBefore, ...$nodes];

        return $this;
    }

    /**
     * Pause a saved run after the named nodes ran; resume() then goes on to the next node.
     *
     * @param  string  ...$nodes  Node names.
     * @return self
     */
    public function interruptAfter(string ...$nodes): self
    {
        $this->interruptAfter = [...$this->interruptAfter, ...$nodes];

        return $this;
    }

    /**
     * Add a fan-out node: run the branch graph once per branch (inline, or queued for drain() with a checkpointer), then join.
     *
     * @param  string  $name  Fan-out node name; the join node reads the results under this key, in declared order.
     * @param  callable(GraphState): list<GraphState>  $expand  Returns one input state per branch.
     * @param  Graph  $branch  Graph each branch runs; it must not fan out again.
     * @param  string  $join  Node that runs once every branch has finished.
     * @param  int  $maxBranches  Most branches allowed; checked before any branch runs or is saved.
     * @return self
     */
    public function addFanOut(string $name, callable $expand, Graph $branch, string $join, int $maxBranches = self::DEFAULT_MAX_BRANCHES): self
    {
        $this->fanOuts[$name] = ['expand' => $expand, 'branch' => $branch, 'maxBranches' => $maxBranches];
        $this->edges[$name] = $join;

        return $this;
    }

    /**
     * Always go from one node to another, unless a conditional edge or a GraphCommand picks another node.
     *
     * @param  string  $from  Node the edge leaves.
     * @param  string  $to  Node to run next, or Graph::END.
     * @return self
     */
    public function addEdge(string $from, string $to): self
    {
        $this->edges[$from] = $to;

        return $this;
    }

    /**
     * Pick the next node at run time; the router gets the state and returns a node name or Graph::END.
     *
     * @param  string  $from  Node the edge leaves.
     * @param  callable(GraphState): string  $router  Returns the name of the node to run next.
     * @return self
     */
    public function addConditionalEdge(string $from, callable $router): self
    {
        $this->conditionalEdges[$from] = $router;

        return $this;
    }

    /**
     * Set the node the walk starts from.
     *
     * @param  string  $name  Name of an added node.
     * @return self
     */
    public function setEntry(string $name): self
    {
        $this->entry = $name;

        return $this;
    }

    /**
     * Check that the entry, every edge and every pause names an added node; a router's or command's target is checked when used.
     *
     * @return void
     *
     * @throws GraphException When no entry is set or a name does not match an added node.
     */
    public function validate(): void
    {
        if ($this->entry === '') {
            throw GraphException::missingEntry();
        }

        $sources = [$this->entry, ...array_keys($this->edges), ...array_keys($this->conditionalEdges), ...$this->interruptBefore, ...$this->interruptAfter];

        foreach ($sources as $name) {
            $this->assertNode((string) $name);
        }

        foreach ($this->edges as $target) {
            if ($target !== self::END) {
                $this->assertNode($target);
            }
        }

        foreach ($this->fanOuts as $node => $fanOut) {
            if ($fanOut['branch']->fanOuts !== []) {
                throw GraphException::nestedFanOut((string) $node);
            }

            $fanOut['branch']->validate();
        }
    }

    /**
     * Walk the graph from the entry node until a route reaches Graph::END, and return the final state.
     *
     * @param  GraphState  $state  State handed to the entry node.
     * @param  int  $maxSteps  Most nodes that may run before the walk is stopped.
     * @param  array<string, mixed>  $context  Run-scoped values every node reads from GraphRuntime::$context.
     * @param  string|null  $threadId  Thread to save every step under; required when a checkpointer is set.
     * @return GraphState
     *
     * @throws GraphException When the graph is invalid, the thread setup is wrong, a route names a missing node,
     *                        a value cannot be saved, or the step limit is reached.
     * @throws GraphInterruptedException When the saved run pauses.
     */
    public function run(GraphState $state, int $maxSteps = self::DEFAULT_MAX_STEPS, array $context = [], ?string $threadId = null): GraphState
    {
        $this->validate();
        $this->assertThreadSetup($threadId);

        $start = new GraphSnapshot((string) $threadId, 0, $this->entry, [], GraphSnapshot::STATUS_RUNNING);

        return $this->start($state, $start, $maxSteps, new GraphRuntime(Ulid::generate(), $context, $this->store, $threadId));
    }

    /**
     * Continue a paused thread from its saved snapshot; a node paused by interrupt() runs again and gets the answer.
     *
     * @param  string  $threadId  Thread to continue.
     * @param  mixed  $answer  Answer for the paused interrupt() call; ignored for a pause set with interruptBefore() or interruptAfter().
     * @param  int  $maxSteps  Most nodes that may run in this resumed run.
     * @param  array<string, mixed>  $context  Run-scoped values every node reads from GraphRuntime::$context.
     * @return GraphState
     *
     * @throws GraphException When there is no checkpointer, the thread is unknown or not paused, or the walk fails.
     * @throws GraphInterruptedException When the resumed run pauses again.
     */
    public function resume(string $threadId, mixed $answer = null, int $maxSteps = self::DEFAULT_MAX_STEPS, array $context = []): GraphState
    {
        $this->validate();
        $saved = $this->savedSnapshot($threadId);

        if ($saved->status !== GraphSnapshot::STATUS_INTERRUPTED) {
            throw GraphException::notPaused($threadId);
        }

        $answers = $saved->pause === GraphSnapshot::PAUSE_NODE ? [...$saved->answers, $answer] : [];
        $start = new GraphSnapshot($threadId, $saved->step, $saved->next, $saved->values, $saved->status, $saved->interrupt, $answers, $saved->pause);

        return $this->start(new GraphState($saved->values), $start, $maxSteps, new GraphRuntime(Ulid::generate(), $context, $this->store, $threadId));
    }

    /**
     * Return the thread's newest snapshot, or null when nothing was saved under it.
     *
     * @param  string  $threadId  Thread id.
     * @return GraphSnapshot|null
     *
     * @throws GraphException When the graph has no checkpointer.
     */
    public function getState(string $threadId): ?GraphSnapshot
    {
        return $this->requireCheckpointer()->latest($threadId);
    }

    /**
     * Return every snapshot saved under the thread, oldest first.
     *
     * @param  string  $threadId  Thread id.
     * @return list<GraphSnapshot>
     *
     * @throws GraphException When the graph has no checkpointer.
     */
    public function getStateHistory(string $threadId): array
    {
        return $this->requireCheckpointer()->history($threadId);
    }

    /**
     * Write values into the thread's newest snapshot as a new snapshot, for example a human's edit before resume().
     *
     * @param  string  $threadId  Thread id.
     * @param  array<string, mixed>  $values  Values to write, keyed by name; other keys keep their saved value.
     * @return void
     *
     * @throws GraphException When there is no checkpointer, the thread is unknown, or a value cannot be saved.
     */
    public function updateState(string $threadId, array $values): void
    {
        $saved = $this->savedSnapshot($threadId);

        $this->requireCheckpointer()->save(new GraphSnapshot(
            $threadId,
            $saved->step,
            $saved->next,
            array_replace($saved->values, $values),
            $saved->status,
            $saved->interrupt,
            $saved->answers,
            $saved->pause,
        ));
    }

    /**
     * Run queued fan-out branches and released parents of this graph, as a worker does from cron, a queue or the CLI.
     *
     * @param  int  $limit  Most threads to run in this call.
     * @param  int  $timeBudgetSeconds  Stop starting threads once this many seconds have passed.
     * @return ResumeReport Threads run, by outcome.
     *
     * @throws GraphException When the graph has no name or no checkpointer.
     */
    public function drain(int $limit = self::DEFAULT_DRAIN_LIMIT, int $timeBudgetSeconds = self::DEFAULT_DRAIN_SECONDS): ResumeReport
    {
        if ($this->name === '') {
            throw GraphException::missingName();
        }

        $this->validate();
        $deadline = time() + $timeBudgetSeconds;
        $counts = [ResumeReport::COMPLETED => 0, ResumeReport::SUSPENDED => 0, ResumeReport::FAILED => 0, ResumeReport::SKIPPED => 0];

        for ($taken = 0; $taken < $limit && time() <= $deadline; $taken++) {
            $this->releaseFinishedParents();
            $due = $this->nextQueuedThread();

            if ($due === null) {
                break;
            }

            $counts[$this->runQueuedThread($due)]++;
        }

        $this->releaseFinishedParents();

        return new ResumeReport(
            completed: $counts[ResumeReport::COMPLETED],
            suspended: $counts[ResumeReport::SUSPENDED],
            failed: $counts[ResumeReport::FAILED],
            skipped: $counts[ResumeReport::SKIPPED],
        );
    }

    /**
     * Open a graph run (its own run id, parented to any enclosing run) and walk from the start snapshot.
     *
     * @param  GraphState  $state  State handed to the first node.
     * @param  GraphSnapshot  $start  Where to start: the node, the step count, saved answers and the pause kind.
     * @param  int  $maxSteps  Most nodes that may run.
     * @param  GraphRuntime  $runtime  Values for this run.
     * @return GraphState
     *
     * @throws GraphException When a route names a missing node, a value cannot be saved, or the step limit is reached.
     * @throws GraphInterruptedException When the saved run pauses.
     */
    private function start(GraphState $state, GraphSnapshot $start, int $maxSteps, GraphRuntime $runtime): GraphState
    {
        return HookDispatcher::withRun(
            $runtime->runId,
            fn (): GraphState => $this->walk($state, $start, $maxSteps, $runtime),
            parentRunId: HookDispatcher::currentRunId(),
        );
    }

    /**
     * Run nodes one at a time from the start snapshot, saving after each node, until a route reaches Graph::END.
     *
     * @param  GraphState  $state  State handed to the first node.
     * @param  GraphSnapshot  $start  Where to start.
     * @param  int  $maxSteps  Most nodes that may run.
     * @param  GraphRuntime  $runtime  Values for this run.
     * @return GraphState
     *
     * @throws GraphException When a route names a missing node, a value cannot be saved, or the step limit is reached.
     * @throws GraphInterruptedException When a node calls interrupt() or a node is listed in interruptBefore() or interruptAfter().
     */
    private function walk(GraphState $state, GraphSnapshot $start, int $maxSteps, GraphRuntime $runtime): GraphState
    {
        $current = (string) $start->next;
        $step = $start->step;
        $answers = $start->answers;
        $passedBefore = $start->pause === GraphSnapshot::PAUSE_BEFORE;

        AgentEventDispatcher::graphStart($runtime->runId, $current);

        for ($taken = 1; $taken <= $maxSteps; $taken++) {
            if (! $passedBefore && in_array($current, $this->interruptBefore, true)) {
                $this->pause($runtime, $current, $this->snapshot($runtime, $step, $current, $state, GraphSnapshot::STATUS_INTERRUPTED, pause: GraphSnapshot::PAUSE_BEFORE));
            }

            $passedBefore = false;
            [$state, $goto] = isset($this->fanOuts[$current])
                ? [$this->fanOut($current, $state, $runtime, $step), null]
                : $this->runNodeOrPause($current, $state, $runtime, $answers, $step);
            $answers = [];
            $step++;
            $next = $goto ?? $this->nextNode($current, $state);

            if ($next === self::END) {
                $this->save($runtime, $this->snapshot($runtime, $step, null, $state, GraphSnapshot::STATUS_DONE));
                AgentEventDispatcher::graphEnd($runtime->runId, $taken, $current);

                return $state;
            }

            $this->assertNode($next);

            if (in_array($current, $this->interruptAfter, true)) {
                $this->pause($runtime, $current, $this->snapshot($runtime, $step, $next, $state, GraphSnapshot::STATUS_INTERRUPTED, pause: GraphSnapshot::PAUSE_AFTER));
            }

            $this->save($runtime, $this->snapshot($runtime, $step, $next, $state, GraphSnapshot::STATUS_RUNNING));
            $current = $next;
        }

        throw GraphException::maxStepsReached($maxSteps);
    }

    /**
     * Split the state into branches; run them now (no thread) or queue them and pause the parent (thread).
     *
     * @param  string  $name  Fan-out node name.
     * @param  GraphState  $state  State before the fan-out.
     * @param  GraphRuntime  $runtime  Values for this run.
     * @param  int  $step  Nodes completed in this thread so far.
     * @return GraphState State with the branch results under $name, for the join node.
     *
     * @throws GraphException When the expander returns more branches than the limit, or a value cannot be saved.
     * @throws GraphInterruptedException When the branches were queued: the parent waits for drain().
     */
    private function fanOut(string $name, GraphState $state, GraphRuntime $runtime, int $step): GraphState
    {
        $fanOut = $this->fanOuts[$name];
        $inputs = array_values(($fanOut['expand'])(clone $state));

        if (count($inputs) > $fanOut['maxBranches']) {
            throw GraphException::tooManyBranches($name, count($inputs), $fanOut['maxBranches']);
        }

        AgentEventDispatcher::graphFanOut($runtime->runId, $name, count($inputs));

        if ($runtime->threadId === null) {
            $results = array_map(static fn (GraphState $input): array => self::runInline($fanOut['branch'], $input), $inputs);

            return $this->join($name, $state, $results, $runtime->runId);
        }

        $this->queueBranches($name, $inputs, $state, $runtime->threadId, $step);
    }

    /**
     * Run one branch inside the current request and return its result entry.
     *
     * @param  Graph  $branch  Branch graph.
     * @param  GraphState  $input  Branch input.
     * @return array<string, mixed> `['ok' => true, 'values' => ...]` or a failure entry.
     */
    private static function runInline(Graph $branch, GraphState $input): array
    {
        try {
            return ['ok' => true, 'values' => $branch->run($input)->all()];
        } catch (Exception $error) {
            return self::failure($error);
        }
    }

    /**
     * Put the branch results on the state under the fan-out node's name and fire graph.join.
     *
     * @param  string  $name  Fan-out node name.
     * @param  GraphState  $state  State the join node gets.
     * @param  list<array<string, mixed>>  $results  One entry per branch, in declared order.
     * @param  string  $runId  Graph run id for the event.
     * @return GraphState
     */
    private function join(string $name, GraphState $state, array $results, string $runId): GraphState
    {
        $failed = count(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
        AgentEventDispatcher::graphJoin($runId, $name, count($results), $failed);

        return $state->set($name, $results);
    }

    /**
     * Save one queued child thread per branch and the waiting parent, then stop the run.
     *
     * @param  string  $name  Fan-out node name.
     * @param  list<GraphState>  $inputs  Branch inputs, in declared order.
     * @param  GraphState  $state  Parent state before the fan-out.
     * @param  string  $threadId  Parent thread id.
     * @param  int  $step  Nodes completed in the parent thread so far.
     * @return never
     *
     * @throws GraphException When a value cannot be saved.
     * @throws GraphInterruptedException Always: the parent waits for drain().
     */
    private function queueBranches(string $name, array $inputs, GraphState $state, string $threadId, int $step): never
    {
        $checkpointer = $this->requireCheckpointer();
        $branch = $this->fanOuts[$name]['branch'];
        $children = [];

        foreach ($inputs as $index => $input) {
            $children[] = $childId = $threadId.':'.$index;
            $checkpointer->save(new GraphSnapshot($childId, 0, $branch->entry, $input->all(), GraphSnapshot::STATUS_QUEUED, graph: $this->name, parentThreadId: $threadId, fanOut: $name));
        }

        $checkpointer->save(new GraphSnapshot($threadId, $step, $this->edges[$name], $state->all(), GraphSnapshot::STATUS_WAITING, graph: $this->name, fanOut: $name, children: $children));

        throw new GraphInterruptedException($threadId, $name);
    }

    /**
     * Mark every waiting parent of this graph as queued once none of its branches is still to run.
     *
     * @return void
     *
     * @throws GraphException When a value cannot be saved.
     */
    private function releaseFinishedParents(): void
    {
        $checkpointer = $this->requireCheckpointer();

        foreach ($checkpointer->threads() as $snapshot) {
            if ($snapshot->graph !== $this->name || $snapshot->status !== GraphSnapshot::STATUS_WAITING) {
                continue;
            }

            $unfinished = array_filter(
                $snapshot->children,
                static fn (string $childId): bool => in_array($checkpointer->latest($childId)?->status, [GraphSnapshot::STATUS_QUEUED, GraphSnapshot::STATUS_RUNNING], true),
            );

            if ($unfinished === []) {
                $checkpointer->save($snapshot->withStatus(GraphSnapshot::STATUS_QUEUED));
            }
        }
    }

    /**
     * Return the first queued thread of this graph: a branch to run or a released parent to join.
     *
     * @return GraphSnapshot|null
     */
    private function nextQueuedThread(): ?GraphSnapshot
    {
        foreach ($this->requireCheckpointer()->threads() as $snapshot) {
            if ($snapshot->graph === $this->name && $snapshot->status === GraphSnapshot::STATUS_QUEUED) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * Claim a queued thread and run it; returns the ResumeReport outcome key.
     *
     * @param  GraphSnapshot  $due  Thread found queued.
     * @return string
     *
     * @throws GraphException When a value cannot be saved.
     */
    private function runQueuedThread(GraphSnapshot $due): string
    {
        $checkpointer = $this->requireCheckpointer();
        $latest = $checkpointer->latest($due->threadId);

        if ($latest === null || $latest->status !== GraphSnapshot::STATUS_QUEUED) {
            return ResumeReport::SKIPPED;
        }

        $checkpointer->save($latest->withStatus(GraphSnapshot::STATUS_RUNNING));

        return $latest->parentThreadId === null ? $this->runJoin($latest) : $this->runBranch($latest);
    }

    /**
     * Run one queued branch thread with the branch graph, saving under the branch's own thread id.
     *
     * @param  GraphSnapshot  $child  Claimed branch snapshot.
     * @return string ResumeReport outcome key.
     *
     * @throws GraphException When a value cannot be saved.
     */
    private function runBranch(GraphSnapshot $child): string
    {
        $runner = clone $this->fanOuts[$child->fanOut]['branch'];
        $runner->checkpointer = $this->checkpointer;
        $start = new GraphSnapshot($child->threadId, 0, $child->next, [], GraphSnapshot::STATUS_RUNNING);

        try {
            $runner->start(new GraphState($child->values), $start, self::DEFAULT_MAX_STEPS, new GraphRuntime(Ulid::generate(), [], $runner->store, $child->threadId));

            return ResumeReport::COMPLETED;
        } catch (GraphInterruptedException) {
            $error = GraphException::branchInterrupted();
        } catch (Exception $caught) {
            $error = $caught;
        }

        $this->requireCheckpointer()->save($child->withStatus(GraphSnapshot::STATUS_FAILED, ['class' => $error::class, 'message' => $error->getMessage()]));

        return ResumeReport::FAILED;
    }

    /**
     * Run a released parent from its join node, with every branch result in declared order.
     *
     * @param  GraphSnapshot  $parent  Claimed parent snapshot.
     * @return string ResumeReport outcome key.
     *
     * @throws GraphException When a value cannot be saved.
     */
    private function runJoin(GraphSnapshot $parent): string
    {
        $results = array_map(fn (string $childId): array => $this->branchResult($childId), $parent->children);
        $runtime = new GraphRuntime(Ulid::generate(), [], $this->store, $parent->threadId);
        $state = $this->join($parent->fanOut, new GraphState($parent->values), $results, $runtime->runId);
        $start = new GraphSnapshot($parent->threadId, $parent->step + 1, $parent->next, [], GraphSnapshot::STATUS_RUNNING);

        try {
            $this->start($state, $start, self::DEFAULT_MAX_STEPS, $runtime);

            return ResumeReport::COMPLETED;
        } catch (GraphInterruptedException) {
            return ResumeReport::SUSPENDED;
        } catch (Exception $error) {
            $this->requireCheckpointer()->save($parent->withStatus(GraphSnapshot::STATUS_FAILED, ['class' => $error::class, 'message' => $error->getMessage()]));

            return ResumeReport::FAILED;
        }
    }

    /**
     * Return the result entry of one branch thread.
     *
     * @param  string  $childId  Branch thread id.
     * @return array<string, mixed>
     */
    private function branchResult(string $childId): array
    {
        $child = $this->requireCheckpointer()->latest($childId);

        if ($child === null) {
            return self::failure(GraphException::unknownThread($childId));
        }

        if ($child->status === GraphSnapshot::STATUS_DONE) {
            return ['ok' => true, 'values' => $child->values];
        }

        return ['ok' => false, 'error' => $child->error['class'] ?? '', 'message' => $child->error['message'] ?? ''];
    }

    /**
     * Build the result entry for a failed branch: the error class and message, nothing else.
     *
     * @param  Throwable  $error  Branch error.
     * @return array{ok: false, error: string, message: string}
     */
    private static function failure(Throwable $error): array
    {
        return ['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
    }

    /**
     * Run one node; when it calls interrupt() without an answer, save the paused snapshot and stop the run.
     *
     * @param  string  $name  Node to run.
     * @param  GraphState  $state  State before the node.
     * @param  GraphRuntime  $runtime  Values for this run.
     * @param  list<mixed>  $answers  Answers for the node's interrupt() calls, given on resume.
     * @param  int  $step  Nodes completed in this thread so far.
     * @return array{0: GraphState, 1: string|null}
     *
     * @throws GraphInterruptedException When the node pauses.
     * @throws Exception The node's last error, when it has no error handler.
     */
    private function runNodeOrPause(string $name, GraphState $state, GraphRuntime $runtime, array $answers, int $step): array
    {
        try {
            return $this->runNode($name, $state, $runtime, $answers);
        } catch (GraphInterruptedException $paused) {
            $this->save($runtime, $this->snapshot($runtime, $step, $name, $state, GraphSnapshot::STATUS_INTERRUPTED, $paused->value, $answers, GraphSnapshot::PAUSE_NODE));

            throw $paused;
        }
    }

    /**
     * Build a snapshot of this run's thread at the given point.
     *
     * @param  GraphRuntime  $runtime  Values for this run.
     * @param  int  $step  Nodes completed in this thread so far.
     * @param  string|null  $next  Node that runs next, or null at Graph::END.
     * @param  GraphState  $state  State at this point.
     * @param  string  $status  One of the GraphSnapshot::STATUS_* constants.
     * @param  mixed  $interrupt  What a paused node passed to interrupt(), or null.
     * @param  list<mixed>  $answers  Answers already given to the paused node.
     * @param  string  $pause  One of the GraphSnapshot::PAUSE_* constants.
     * @return GraphSnapshot
     */
    private function snapshot(
        GraphRuntime $runtime,
        int $step,
        ?string $next,
        GraphState $state,
        string $status,
        mixed $interrupt = null,
        array $answers = [],
        string $pause = GraphSnapshot::PAUSE_NONE,
    ): GraphSnapshot {
        return new GraphSnapshot((string) $runtime->threadId, $step, $next, $state->all(), $status, $interrupt, $answers, $pause);
    }

    /**
     * Save a paused snapshot and stop the run.
     *
     * @param  GraphRuntime  $runtime  Values for this run.
     * @param  string  $node  Node named in the exception: the one about to run, or the one that just ran.
     * @param  GraphSnapshot  $snapshot  Paused snapshot to save.
     * @return never
     *
     * @throws GraphInterruptedException Always.
     */
    private function pause(GraphRuntime $runtime, string $node, GraphSnapshot $snapshot): never
    {
        $this->save($runtime, $snapshot);

        throw new GraphInterruptedException((string) $runtime->threadId, $node);
    }

    /**
     * Save the snapshot when this run has a thread; a run without one saves nothing.
     *
     * @param  GraphRuntime  $runtime  Values for this run.
     * @param  GraphSnapshot  $snapshot  Snapshot to save.
     * @return void
     *
     * @throws GraphException When a value cannot be saved.
     */
    private function save(GraphRuntime $runtime, GraphSnapshot $snapshot): void
    {
        if ($runtime->threadId !== null) {
            $this->requireCheckpointer()->save($snapshot);
        }
    }

    /**
     * Return the thread's newest snapshot.
     *
     * @param  string  $threadId  Thread id.
     * @return GraphSnapshot
     *
     * @throws GraphException When there is no checkpointer or nothing was saved under the thread.
     */
    private function savedSnapshot(string $threadId): GraphSnapshot
    {
        return $this->requireCheckpointer()->latest($threadId) ?? throw GraphException::unknownThread($threadId);
    }

    /**
     * Return the checkpointer.
     *
     * @return GraphCheckpointer
     *
     * @throws GraphException When none is set.
     */
    private function requireCheckpointer(): GraphCheckpointer
    {
        return $this->checkpointer ?? throw GraphException::noCheckpointer();
    }

    /**
     * Throw when the checkpointer, the thread id and the pause lists do not fit together.
     *
     * @param  string|null  $threadId  Thread id given to run().
     * @return void
     *
     * @throws GraphException When a checkpointer has no thread id, or a thread id or pause list has no checkpointer.
     */
    private function assertThreadSetup(?string $threadId): void
    {
        if ($this->checkpointer !== null && $threadId === null) {
            throw GraphException::missingThreadId();
        }

        if ($this->checkpointer === null && ($threadId !== null || $this->interruptBefore !== [] || $this->interruptAfter !== [])) {
            throw GraphException::noCheckpointer();
        }

        if ($this->checkpointer !== null && $this->fanOuts !== [] && $this->name === '') {
            throw GraphException::missingName();
        }
    }

    /**
     * Run one node with its retries and error handler; return the new state and the node a command picked, if any.
     *
     * @param  string  $name  Node to run.
     * @param  GraphState  $state  State before the node; every attempt gets a fresh copy of it.
     * @param  GraphRuntime  $runtime  Values for this run.
     * @param  list<mixed>  $answers  Answers for the node's interrupt() calls, given on resume.
     * @return array{0: GraphState, 1: string|null}
     *
     * @throws GraphInterruptedException When the node calls interrupt() without an answer; never retried.
     * @throws Exception The last attempt's error, when the node has no error handler.
     */
    private function runNode(string $name, GraphState $state, GraphRuntime $runtime, array $answers): array
    {
        $node = $this->nodes[$name];
        $attempts = max(1, $node['attempts'] ?? $this->defaultAttempts);
        $retryOn = $node['retryOn'] ?? $this->defaultRetryOn;

        for ($attempt = 1; ; $attempt++) {
            $working = clone $state;
            $nodeRuntime = new GraphRuntime($runtime->runId, $runtime->context, $runtime->store, $runtime->threadId, $name, $answers);

            try {
                return $this->settle(($node['run'])($working, $nodeRuntime), $working);
            } catch (GraphInterruptedException $paused) {
                throw $paused;
            } catch (Exception $error) {
                if ($attempt >= $attempts || ! $this->isListed($error, $retryOn)) {
                    return $this->recover($name, $state, $error);
                }
            }
        }
    }

    /**
     * Hand a failed node to its error handler, or rethrow when it has none.
     *
     * @param  string  $name  Node that failed.
     * @param  GraphState  $state  State before the node; the handler gets a fresh copy of it.
     * @param  Exception  $error  The last attempt's error.
     * @return array{0: GraphState, 1: string|null}
     *
     * @throws Exception The same error, when no error handler is set.
     */
    private function recover(string $name, GraphState $state, Exception $error): array
    {
        $onError = $this->nodes[$name]['onError'] ?? $this->defaultOnError;

        if ($onError === null) {
            throw $error;
        }

        $working = clone $state;

        return $this->settle($onError($working, $error, $name), $working);
    }

    /**
     * Turn what a node or handler returned into the next state and the node a command picked, if any.
     *
     * @param  mixed  $result  GraphState, GraphCommand or anything else (treated as "no return").
     * @param  GraphState  $working  The copy the node worked on.
     * @return array{0: GraphState, 1: string|null}
     */
    private function settle(mixed $result, GraphState $working): array
    {
        if ($result instanceof GraphCommand) {
            foreach ($result->update as $key => $value) {
                $working->set((string) $key, $value);
            }

            return [$working, $result->goto];
        }

        return [$result instanceof GraphState ? $result : $working, null];
    }

    /**
     * Return the node after the given one: the router's answer, else the plain edge, else Graph::END.
     *
     * @param  string  $current  Node that just ran.
     * @param  GraphState  $state  State after that node ran.
     * @return string
     */
    private function nextNode(string $current, GraphState $state): string
    {
        if (isset($this->conditionalEdges[$current])) {
            return ($this->conditionalEdges[$current])($state);
        }

        return $this->edges[$current] ?? self::END;
    }

    /**
     * Whether the error is an instance of one of the listed classes.
     *
     * @param  Exception  $error  Error a node threw.
     * @param  list<class-string<Exception>>  $classes  Error classes to match.
     * @return bool
     */
    private function isListed(Exception $error, array $classes): bool
    {
        return array_filter($classes, static fn (string $class): bool => $error instanceof $class) !== [];
    }

    /**
     * Throw when the graph has no node with the given name.
     *
     * @param  string  $name  Node name to check.
     * @return void
     *
     * @throws GraphException When no node has that name.
     */
    private function assertNode(string $name): void
    {
        if (! isset($this->nodes[$name]) && ! isset($this->fanOuts[$name])) {
            throw GraphException::unknownNode($name);
        }
    }
}
