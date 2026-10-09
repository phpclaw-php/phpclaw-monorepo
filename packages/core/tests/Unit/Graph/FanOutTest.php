<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Graph;

use PhpClaw\Exceptions\GraphException;
use PhpClaw\Exceptions\GraphInterruptedException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Graph\Graph;
use PhpClaw\Graph\GraphCheckpointer;
use PhpClaw\Graph\GraphRuntime;
use PhpClaw\Graph\GraphSnapshot;
use PhpClaw\Graph\GraphState;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PHPUnit\Framework\TestCase;

final class FanOutTest extends TestCase
{
    private const LANGUAGES = ['fr', 'de', 'es', 'it'];

    private array $events = [];

    private array $translated = [];

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->events = [];
        $this->translated = [];
        HookRegistry::onAny(function (array $context): void {
            $this->events[] = $context;
        });
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function eventsNamed(string $name): array
    {
        return array_values(array_filter($this->events, static fn (array $context): bool => $context['event'] === $name));
    }

    private function translateBranch(array $failFor = []): Graph
    {
        return (new Graph('translate-one'))
            ->addNode('translate', function (GraphState $state) use ($failFor): GraphState {
                $language = $state->get('lang');
                $this->translated[] = $language;
                if (in_array($language, $failFor, true)) {
                    throw new ProviderException('provider down for '.$language);
                }

                return $state->set('text', strtoupper($language).': '.$state->get('source'));
            })
            ->setEntry('translate');
    }

    private function translateAll(Graph $branch, ?ArrayMemory $memory = null, string $name = 'translate-all', int $maxBranches = Graph::DEFAULT_MAX_BRANCHES): Graph
    {
        $graph = (new Graph($name))
            ->addFanOut(
                'split',
                static fn (GraphState $state): array => array_map(
                    static fn (string $language): GraphState => new GraphState(['lang' => $language, 'source' => $state->get('source')]),
                    self::LANGUAGES,
                ),
                $branch,
                join: 'collect',
                maxBranches: $maxBranches,
            )
            ->addNode('collect', static fn (GraphState $state): GraphState => $state->set('collected', count($state->get('split'))))
            ->setEntry('split');

        return $memory === null ? $graph : $graph->setCheckpointer($memory);
    }

    private function startQueued(ArrayMemory $memory, Graph $branch, string $threadId = 'product-9'): Graph
    {
        $graph = $this->translateAll($branch, $memory);

        try {
            $graph->run(new GraphState(['source' => 'Blue mug']), threadId: $threadId);
            $this->fail('Expected the run to wait for its branches.');
        } catch (GraphInterruptedException $paused) {
            $this->assertSame('split', $paused->node);
        }

        return $graph;
    }

    private function threadStatus(ArrayMemory $memory, string $threadId): ?string
    {
        return (new GraphCheckpointer($memory))->latest($threadId)?->status;
    }

    public function test_inline_mode_runs_every_branch_and_the_join_gets_the_results_in_declared_order(): void
    {
        $final = $this->translateAll($this->translateBranch())->run(new GraphState(['source' => 'Blue mug']));

        $this->assertSame(self::LANGUAGES, $this->translated);
        $this->assertSame(4, $final->get('collected'));
        $this->assertSame(
            ['FR: Blue mug', 'DE: Blue mug', 'ES: Blue mug', 'IT: Blue mug'],
            array_map(static fn (array $result): string => $result['values']['text'], $final->get('split')),
        );
        $this->assertTrue($final->get('split')[0]['ok']);
    }

    public function test_inline_mode_hands_a_failed_branch_to_the_join_with_its_error(): void
    {
        $final = $this->translateAll($this->translateBranch(failFor: ['de']))->run(new GraphState(['source' => 'Blue mug']));

        $results = $final->get('split');
        $this->assertSame(['ok' => false, 'error' => ProviderException::class, 'message' => 'provider down for de'], $results[1]);
        $this->assertTrue($results[2]['ok']);
        $this->assertSame(4, $final->get('collected'));
    }

    public function test_queued_mode_saves_one_queued_child_per_branch_and_a_waiting_parent_without_running_any_branch(): void
    {
        $memory = new ArrayMemory;
        $this->startQueued($memory, $this->translateBranch());

        $this->assertSame([], $this->translated);
        $this->assertSame(GraphSnapshot::STATUS_WAITING, $this->threadStatus($memory, 'product-9'));
        foreach (array_keys(self::LANGUAGES) as $index) {
            $child = (new GraphCheckpointer($memory))->latest('product-9:'.$index);
            $this->assertSame(GraphSnapshot::STATUS_QUEUED, $child->status);
            $this->assertSame('product-9', $child->parentThreadId);
            $this->assertSame(self::LANGUAGES[$index], $child->values['lang']);
        }
    }

    public function test_drain_never_runs_a_waiting_parent_and_releases_it_only_after_the_last_child(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->startQueued($memory, $this->translateBranch());

        foreach ([1, 2, 3] as $done) {
            $graph->drain(limit: 1);
            $this->assertCount($done, $this->translated);
            $this->assertSame(GraphSnapshot::STATUS_WAITING, $this->threadStatus($memory, 'product-9'));
        }

        $graph->drain(limit: 1);
        $this->assertSame(GraphSnapshot::STATUS_QUEUED, $this->threadStatus($memory, 'product-9'));

        $graph->drain(limit: 1);
        $this->assertSame(GraphSnapshot::STATUS_DONE, $this->threadStatus($memory, 'product-9'));
        $this->assertSame(4, $graph->getState('product-9')->values['collected']);
    }

    public function test_two_workers_taking_turns_finish_four_branches_and_the_join_sees_all_four(): void
    {
        $memory = new ArrayMemory;
        $this->startQueued($memory, $this->translateBranch());
        $workers = [$this->translateAll($this->translateBranch(), $memory), $this->translateAll($this->translateBranch(), $memory)];

        for ($turn = 0; $turn < 10 && $this->threadStatus($memory, 'product-9') !== GraphSnapshot::STATUS_DONE; $turn++) {
            $workers[$turn % 2]->drain(limit: 1);
        }

        $final = (new GraphCheckpointer($memory))->latest('product-9');
        $this->assertSame(GraphSnapshot::STATUS_DONE, $final->status);
        $this->assertSame(
            ['FR: Blue mug', 'DE: Blue mug', 'ES: Blue mug', 'IT: Blue mug'],
            array_map(static fn (array $result): string => $result['values']['text'], $final->values['split']),
        );
    }

    public function test_drain_reports_what_it_ran(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->startQueued($memory, $this->translateBranch(failFor: ['es']));

        $report = $graph->drain(limit: 10);

        $this->assertSame(4, $report->completed);
        $this->assertSame(1, $report->failed);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(5, $report->total());
        $results = (new GraphCheckpointer($memory))->latest('product-9')->values['split'];
        $this->assertSame(['ok' => false, 'error' => ProviderException::class, 'message' => 'provider down for es'], $results[2]);
    }

    public function test_a_released_parent_runs_its_join_only_once(): void
    {
        $memory = new ArrayMemory;
        $joins = 0;
        $graph = (new Graph('translate-all'))
            ->setCheckpointer($memory)
            ->addFanOut('split', static fn (): array => [new GraphState(['lang' => 'fr', 'source' => 'Mug'])], $this->translateBranch(), join: 'collect')
            ->addNode('collect', static function (GraphState $state) use (&$joins): GraphState {
                $joins++;

                return $state;
            })
            ->setEntry('split');
        try {
            $graph->run(new GraphState, threadId: 'once');
        } catch (GraphInterruptedException) {
        }

        $graph->drain(limit: 1);
        $graph->drain(limit: 5);
        $second = $graph->drain(limit: 5);

        $this->assertSame(1, $joins);
        $this->assertSame(0, $second->total());
    }

    public function test_a_drain_on_a_graph_with_another_name_takes_none_of_the_work(): void
    {
        $memory = new ArrayMemory;
        $this->startQueued($memory, $this->translateBranch());

        $report = $this->translateAll($this->translateBranch(), $memory, name: 'something-else')->drain(limit: 10);

        $this->assertSame(0, $report->total());
        $this->assertSame([], $this->translated);
    }

    public function test_an_expired_child_counts_as_a_failed_branch_and_still_releases_the_parent(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->startQueued($memory, $this->translateBranch());
        $memory->forget('product-9:3', GraphCheckpointer::NAMESPACE);

        $graph->drain(limit: 10);

        $results = (new GraphCheckpointer($memory))->latest('product-9')->values['split'];
        $this->assertSame(GraphSnapshot::STATUS_DONE, $this->threadStatus($memory, 'product-9'));
        $this->assertSame(['ok' => false, 'error' => GraphException::class, 'message' => "Graph thread 'product-9:3' has no saved state."], $results[3]);
    }

    public function test_an_interrupt_inside_a_queued_branch_fails_that_branch(): void
    {
        $memory = new ArrayMemory;
        $branch = (new Graph('ask'))
            ->addNode('ask', static fn (GraphState $state, GraphRuntime $runtime): GraphState => $state->set('answer', $runtime->interrupt('Continue?')))
            ->setEntry('ask');
        $graph = (new Graph('asking-all'))
            ->setCheckpointer($memory)
            ->addFanOut('split', static fn (): array => [new GraphState], $branch, join: 'collect')
            ->addNode('collect', static fn (GraphState $state): GraphState => $state)
            ->setEntry('split');
        try {
            $graph->run(new GraphState, threadId: 'asking');
        } catch (GraphInterruptedException) {
        }

        $graph->drain(limit: 10);

        $result = (new GraphCheckpointer($memory))->latest('asking')->values['split'][0];
        $this->assertFalse($result['ok']);
        $this->assertSame(GraphException::class, $result['error']);
        $this->assertSame('A fan-out branch cannot pause for a human; its interrupt() call failed the branch.', $result['message']);
    }

    public function test_a_branch_graph_that_fans_out_again_is_rejected(): void
    {
        $nested = $this->translateAll($this->translateBranch(), name: 'inner');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Fan-out node 'split' runs a branch graph that fans out again; nested fan-out is not supported.");

        $this->translateAll($nested)->run(new GraphState(['source' => 'Mug']));
    }

    public function test_more_branches_than_the_limit_throws_before_any_child_is_saved(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->translateAll($this->translateBranch(), $memory, maxBranches: 3);

        try {
            $graph->run(new GraphState(['source' => 'Mug']), threadId: 'too-wide');
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertSame("Fan-out node 'split' produced 4 branches; the limit is 3.", $exception->getMessage());
        }

        $this->assertNull((new GraphCheckpointer($memory))->latest('too-wide:0'));
        $this->assertSame([], $this->translated);
    }

    public function test_drain_and_a_queued_fan_out_need_a_graph_name(): void
    {
        $unnamed = (new Graph)
            ->setCheckpointer(new ArrayMemory)
            ->addFanOut('split', static fn (): array => [new GraphState], $this->translateBranch(), join: 'collect')
            ->addNode('collect', static fn (GraphState $state): GraphState => $state)
            ->setEntry('split');

        try {
            $unnamed->run(new GraphState, threadId: 'no-name');
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertSame('A graph that queues fan-out branches or drains them needs a name: new Graph(\'my-graph\').', $exception->getMessage());
        }

        $this->expectException(GraphException::class);
        $unnamed->drain();
    }

    public function test_fan_out_and_join_events_report_counts_without_state_contents(): void
    {
        $this->translateAll($this->translateBranch(failFor: ['it']))->run(new GraphState(['source' => 'secret product name']));

        $fanOut = $this->eventsNamed('graph.fan_out');
        $join = $this->eventsNamed('graph.join');
        $this->assertCount(1, $fanOut);
        $this->assertCount(1, $join);
        $this->assertSame(['split', 4], [$fanOut[0]['node'], $fanOut[0]['branches']]);
        $this->assertSame(['split', 4, 1], [$join[0]['node'], $join[0]['branches'], $join[0]['failed']]);
        $this->assertStringNotContainsString('secret product name', (string) json_encode([$fanOut, $join]));
    }

    public function test_each_inline_branch_run_reports_the_parent_graph_run_as_its_parent(): void
    {
        $this->translateAll($this->translateBranch())->run(new GraphState(['source' => 'Mug']));

        $starts = $this->eventsNamed('graph.start');
        $this->assertCount(5, $starts);
        foreach (array_slice($starts, 1) as $branchStart) {
            $this->assertSame($starts[0]['run_id'], $branchStart['parent_run_id']);
        }
    }

    public function test_drain_does_nothing_while_the_only_unfinished_branch_is_held_by_another_worker(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->startQueued($memory, $this->translateBranch());
        $graph->drain(limit: 3);
        $checkpointer = new GraphCheckpointer($memory);
        $checkpointer->save($checkpointer->latest('product-9:3')->withStatus(GraphSnapshot::STATUS_RUNNING));

        $report = $graph->drain(limit: 10);

        $this->assertSame(0, $report->total());
        $this->assertSame(GraphSnapshot::STATUS_WAITING, $this->threadStatus($memory, 'product-9'));
    }

    public function test_a_thread_another_worker_claims_between_the_scan_and_the_claim_is_skipped(): void
    {
        $inner = new ArrayMemory;
        $memory = new class($inner) implements MemoryInterface
        {
            public ?\Closure $afterGet = null;

            public function __construct(private readonly ArrayMemory $inner) {}

            public function get(string $key, string $namespace = 'default'): mixed
            {
                $value = $this->inner->get($key, $namespace);
                if ($this->afterGet !== null) {
                    ($this->afterGet)($key);
                }

                return $value;
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
            {
                $this->inner->set($key, $value, $namespace, $ttl);
            }

            public function forget(string $key, string $namespace = 'default'): void
            {
                $this->inner->forget($key, $namespace);
            }

            public function flush(string $namespace = 'default'): void
            {
                $this->inner->flush($namespace);
            }

            public function all(string $namespace = 'default'): array
            {
                return $this->inner->all($namespace);
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return $this->inner->has($key, $namespace);
            }
        };
        $joins = 0;
        $graph = (new Graph('translate-all'))
            ->setCheckpointer($memory)
            ->addFanOut('split', static fn (): array => [new GraphState(['lang' => 'fr', 'source' => 'Mug'])], $this->translateBranch(), join: 'collect')
            ->addNode('collect', static function (GraphState $state) use (&$joins): GraphState {
                $joins++;

                return $state;
            })
            ->setEntry('split');
        try {
            $graph->run(new GraphState, threadId: 'race');
        } catch (GraphInterruptedException) {
        }
        $graph->drain(limit: 1);
        $this->assertSame(GraphSnapshot::STATUS_QUEUED, $this->threadStatus($inner, 'race'));

        $parentReads = 0;
        $memory->afterGet = static function (string $key) use (&$parentReads, $inner): void {
            if ($key !== 'race' || ++$parentReads !== 2) {
                return;
            }
            $checkpointer = new GraphCheckpointer($inner);
            $checkpointer->save($checkpointer->latest('race')->withStatus(GraphSnapshot::STATUS_RUNNING));
        };
        $report = $graph->drain(limit: 1);

        $this->assertGreaterThanOrEqual(3, $parentReads);
        $this->assertSame(1, $report->skipped);
        $this->assertSame(0, $joins);
    }

    private function graphWithJoin(ArrayMemory $memory, \Closure $join): Graph
    {
        $graph = (new Graph('translate-all'))
            ->setCheckpointer($memory)
            ->addFanOut('split', static fn (): array => [new GraphState(['lang' => 'fr', 'source' => 'Mug'])], $this->translateBranch(), join: 'collect')
            ->addNode('collect', $join)
            ->setEntry('split');
        try {
            $graph->run(new GraphState, threadId: 'joining');
        } catch (GraphInterruptedException) {
        }

        return $graph;
    }

    public function test_a_join_that_fails_marks_the_parent_failed_and_reports_it(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->graphWithJoin($memory, static function (): never {
            throw new ProviderException('summary provider down');
        });

        $report = $graph->drain(limit: 10);

        $parent = (new GraphCheckpointer($memory))->latest('joining');
        $this->assertSame([1, 1], [$report->completed, $report->failed]);
        $this->assertSame(GraphSnapshot::STATUS_FAILED, $parent->status);
        $this->assertSame(['class' => ProviderException::class, 'message' => 'summary provider down'], $parent->error);
    }

    public function test_a_join_that_pauses_for_a_human_is_reported_as_suspended_and_can_be_resumed(): void
    {
        $memory = new ArrayMemory;
        $graph = $this->graphWithJoin($memory, static fn (GraphState $state, GraphRuntime $runtime): GraphState => $state->set('approved', $runtime->interrupt('Publish all translations?')));

        $report = $graph->drain(limit: 10);

        $this->assertSame([1, 1], [$report->completed, $report->suspended]);
        $this->assertSame(GraphSnapshot::STATUS_INTERRUPTED, $graph->getState('joining')->status);
        $this->assertSame('yes', $graph->resume('joining', 'yes')->get('approved'));
    }
}
