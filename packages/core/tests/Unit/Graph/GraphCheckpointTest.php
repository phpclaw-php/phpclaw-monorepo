<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Graph;

use PhpClaw\Exceptions\GraphException;
use PhpClaw\Exceptions\GraphInterruptedException;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Graph\Graph;
use PhpClaw\Graph\GraphRuntime;
use PhpClaw\Graph\GraphSnapshot;
use PhpClaw\Graph\GraphState;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\FileMemory;
use PHPUnit\Framework\TestCase;

final class GraphCheckpointTest extends TestCase
{
    private array $events = [];

    private string $storageDir;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->events = [];
        HookRegistry::onAny(function (array $context): void {
            $this->events[] = $context;
        });
        $this->storageDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_graph_test_'.uniqid();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        foreach (glob($this->storageDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->storageDir)) {
            rmdir($this->storageDir);
        }
    }

    private function eventsNamed(string $name): array
    {
        return array_values(array_filter($this->events, static fn (array $context): bool => $context['event'] === $name));
    }

    private function refundGraph(ArrayMemory|FileMemory $memory, array &$log): Graph
    {
        return (new Graph)
            ->setCheckpointer($memory)
            ->addNode('draft', static function (GraphState $state) use (&$log): GraphState {
                $log[] = 'draft';

                return $state->set('draft', 'Refund 40 dollars to order '.$state->get('order'));
            })
            ->addNode('approve', static function (GraphState $state, GraphRuntime $runtime) use (&$log): GraphState {
                $log[] = 'approve';

                return $state->set('approved', $runtime->interrupt(['question' => 'Approve this refund?', 'draft' => $state->get('draft')]));
            })
            ->addNode('send', static function (GraphState $state) use (&$log): GraphState {
                $log[] = 'send';

                return $state->set('sent', $state->get('approved') === 'yes');
            })
            ->setEntry('draft')
            ->addEdge('draft', 'approve')
            ->addEdge('approve', 'send');
    }

    public function test_a_run_with_a_thread_saves_a_snapshot_after_every_node(): void
    {
        $memory = new ArrayMemory;
        $graph = (new Graph)
            ->setCheckpointer($memory)
            ->addNode('a', static fn (GraphState $state): GraphState => $state->set('a', 1))
            ->addNode('b', static fn (GraphState $state): GraphState => $state->set('b', 2))
            ->setEntry('a')
            ->addEdge('a', 'b');

        $graph->run(new GraphState, threadId: 'thread-1');

        $history = $graph->getStateHistory('thread-1');
        $this->assertCount(2, $history);
        $this->assertSame([1, 'b', GraphSnapshot::STATUS_RUNNING, ['a' => 1]], [$history[0]->step, $history[0]->next, $history[0]->status, $history[0]->values]);
        $this->assertSame([2, null, GraphSnapshot::STATUS_DONE, ['a' => 1, 'b' => 2]], [$history[1]->step, $history[1]->next, $history[1]->status, $history[1]->values]);
        $this->assertEquals($history[1], $graph->getState('thread-1'));
    }

    public function test_get_state_of_an_unknown_thread_is_null(): void
    {
        $graph = (new Graph)->setCheckpointer(new ArrayMemory);

        $this->assertNull($graph->getState('missing'));
        $this->assertSame([], $graph->getStateHistory('missing'));
    }

    public function test_an_interrupt_stops_the_run_and_saves_the_question_and_the_state_before_the_node(): void
    {
        $log = [];
        $graph = $this->refundGraph(new ArrayMemory, $log);

        try {
            $graph->run(new GraphState(['order' => 'A-1042']), threadId: 'refund-1');
            $this->fail('Expected a GraphInterruptedException.');
        } catch (GraphInterruptedException $paused) {
            $this->assertSame('refund-1', $paused->threadId);
            $this->assertSame('approve', $paused->node);
            $this->assertSame(['question' => 'Approve this refund?', 'draft' => 'Refund 40 dollars to order A-1042'], $paused->value);
            $this->assertStringNotContainsString('Refund 40', $paused->getMessage());
            $this->assertInstanceOf(PhpClawException::class, $paused);
        }

        $snapshot = $graph->getState('refund-1');
        $this->assertSame(GraphSnapshot::STATUS_INTERRUPTED, $snapshot->status);
        $this->assertSame('approve', $snapshot->next);
        $this->assertSame(['order' => 'A-1042', 'draft' => 'Refund 40 dollars to order A-1042'], $snapshot->values);
        $this->assertSame('Approve this refund?', $snapshot->interrupt['question']);
        $this->assertSame(['draft', 'approve'], $log);
        $this->assertCount(1, $this->eventsNamed('graph.start'));
        $this->assertSame([], $this->eventsNamed('graph.end'));
    }

    public function test_resume_runs_the_paused_node_again_with_the_answer_and_finishes(): void
    {
        $log = [];
        $graph = $this->refundGraph(new ArrayMemory, $log);
        try {
            $graph->run(new GraphState(['order' => 'A-1042']), threadId: 'refund-1');
        } catch (GraphInterruptedException) {
        }

        $final = $graph->resume('refund-1', 'yes');

        $this->assertSame('yes', $final->get('approved'));
        $this->assertTrue($final->get('sent'));
        $this->assertSame(['draft', 'approve', 'approve', 'send'], $log);
        $this->assertSame(GraphSnapshot::STATUS_DONE, $graph->getState('refund-1')->status);
        $starts = $this->eventsNamed('graph.start');
        $this->assertCount(2, $starts);
        $this->assertSame('approve', $starts[1]['entry_node']);
        $this->assertNotSame($starts[0]['run_id'], $starts[1]['run_id']);
    }

    public function test_a_new_graph_instance_resumes_a_thread_saved_to_disk(): void
    {
        $log = [];
        try {
            $this->refundGraph(new FileMemory($this->storageDir), $log)->run(new GraphState(['order' => 'B-7']), threadId: 'refund-2');
        } catch (GraphInterruptedException) {
        }

        $final = $this->refundGraph(new FileMemory($this->storageDir), $log)->resume('refund-2', 'no');

        $this->assertSame('Refund 40 dollars to order B-7', $final->get('draft'));
        $this->assertFalse($final->get('sent'));
    }

    public function test_a_node_with_two_interrupts_pauses_twice_and_gets_each_answer_in_order(): void
    {
        $graph = (new Graph)
            ->setCheckpointer(new ArrayMemory)
            ->addNode('ask', static function (GraphState $state, GraphRuntime $runtime): GraphState {
                $state->set('name', $runtime->interrupt('Name?'));

                return $state->set('city', $runtime->interrupt('City?'));
            })
            ->setEntry('ask');

        try {
            $graph->run(new GraphState, threadId: 'form');
        } catch (GraphInterruptedException $first) {
            $this->assertSame('Name?', $first->value);
        }
        try {
            $graph->resume('form', 'Ava');
            $this->fail('Expected the second interrupt.');
        } catch (GraphInterruptedException $second) {
            $this->assertSame('City?', $second->value);
        }

        $this->assertSame(['name' => 'Ava', 'city' => 'Toronto'], $graph->resume('form', 'Toronto')->all());
    }

    public function test_interrupt_before_pauses_without_running_the_node_and_resume_runs_it(): void
    {
        $log = [];
        $graph = (new Graph)
            ->setCheckpointer(new ArrayMemory)
            ->addNode('draft', static fn (GraphState $state): GraphState => $state->set('draft', 'v1'))
            ->addNode('publish', static function (GraphState $state) use (&$log): GraphState {
                $log[] = 'publish';

                return $state->set('published', true);
            })
            ->setEntry('draft')
            ->addEdge('draft', 'publish')
            ->interruptBefore('publish');

        try {
            $graph->run(new GraphState, threadId: 'post');
            $this->fail('Expected a GraphInterruptedException.');
        } catch (GraphInterruptedException $paused) {
            $this->assertSame('publish', $paused->node);
            $this->assertNull($paused->value);
        }
        $this->assertSame([], $log);

        $this->assertTrue($graph->resume('post')->get('published'));
        $this->assertSame(['publish'], $log);
    }

    public function test_interrupt_after_pauses_once_the_node_ran_and_resume_goes_on_to_the_next_node(): void
    {
        $graph = (new Graph)
            ->setCheckpointer(new ArrayMemory)
            ->addNode('draft', static fn (GraphState $state): GraphState => $state->set('draft', 'v1'))
            ->addNode('publish', static fn (GraphState $state): GraphState => $state->set('published', true))
            ->setEntry('draft')
            ->addEdge('draft', 'publish')
            ->interruptAfter('draft');

        try {
            $graph->run(new GraphState, threadId: 'post');
            $this->fail('Expected a GraphInterruptedException.');
        } catch (GraphInterruptedException $paused) {
            $this->assertSame('draft', $paused->node);
        }
        $this->assertSame(['draft' => 'v1'], $graph->getState('post')->values);
        $this->assertSame('publish', $graph->getState('post')->next);

        $this->assertSame(['draft' => 'v1', 'published' => true], $graph->resume('post')->all());
    }

    public function test_update_state_changes_the_saved_values_a_resume_continues_with(): void
    {
        $log = [];
        $graph = $this->refundGraph(new ArrayMemory, $log);
        try {
            $graph->run(new GraphState(['order' => 'A-1042']), threadId: 'refund-1');
        } catch (GraphInterruptedException) {
        }

        $graph->updateState('refund-1', ['draft' => 'Refund 25 dollars to order A-1042']);

        $this->assertSame('Refund 25 dollars to order A-1042', $graph->getState('refund-1')->values['draft']);
        $this->assertSame('Refund 25 dollars to order A-1042', $graph->resume('refund-1', 'yes')->get('draft'));
    }

    public function test_update_state_of_an_unknown_thread_throws(): void
    {
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph thread 'missing' has no saved state.");

        (new Graph)->setCheckpointer(new ArrayMemory)->updateState('missing', ['a' => 1]);
    }

    public function test_resuming_a_finished_thread_throws(): void
    {
        $graph = (new Graph)->setCheckpointer(new ArrayMemory)->addNode('a', static fn (GraphState $state): GraphState => $state)->setEntry('a');
        $graph->run(new GraphState, threadId: 'done-thread');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph thread 'done-thread' is not paused, so it cannot be resumed.");

        $graph->resume('done-thread');
    }

    public function test_resuming_an_unknown_thread_throws(): void
    {
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph thread 'missing' has no saved state.");

        (new Graph)->setCheckpointer(new ArrayMemory)->addNode('a', static fn (GraphState $state): GraphState => $state)->setEntry('a')->resume('missing');
    }

    public function test_state_holding_an_object_throws_before_anything_is_saved(): void
    {
        $memory = new ArrayMemory;
        $graph = (new Graph)
            ->setCheckpointer($memory)
            ->addNode('a', static fn (GraphState $state): GraphState => $state->set('response', new \stdClass))
            ->setEntry('a');

        try {
            $graph->run(new GraphState, threadId: 'objects');
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertSame("Graph state key 'response' holds a value that cannot be saved; use strings, numbers, booleans, null or arrays of them.", $exception->getMessage());
        }

        $this->assertNull($graph->getState('objects'));
    }

    public function test_a_checkpointer_without_a_thread_id_throws(): void
    {
        $graph = (new Graph)->setCheckpointer(new ArrayMemory)->addNode('a', static fn (GraphState $state): GraphState => $state)->setEntry('a');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('A graph with a checkpointer needs a thread id: call run($state, threadId: ...).');

        $graph->run(new GraphState);
    }

    public function test_a_thread_id_without_a_checkpointer_throws(): void
    {
        $graph = (new Graph)->addNode('a', static fn (GraphState $state): GraphState => $state)->setEntry('a');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('Saving, pausing or resuming a graph needs a checkpointer: call setCheckpointer() first.');

        $graph->run(new GraphState, threadId: 'thread-1');
    }

    public function test_an_interrupt_without_a_checkpointer_throws(): void
    {
        $graph = (new Graph)
            ->addNode('ask', static fn (GraphState $state, GraphRuntime $runtime): GraphState => $state->set('answer', $runtime->interrupt('Continue?')))
            ->setEntry('ask');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('Saving, pausing or resuming a graph needs a checkpointer: call setCheckpointer() first.');

        $graph->run(new GraphState);
    }

    public function test_an_interrupt_is_never_retried_or_handled_as_an_error(): void
    {
        $calls = 0;
        $handled = false;
        $graph = (new Graph)
            ->setCheckpointer(new ArrayMemory)
            ->addNode('ask', static function (GraphState $state, GraphRuntime $runtime) use (&$calls): GraphState {
                $calls++;

                return $state->set('answer', $runtime->interrupt('Continue?'));
            }, attempts: 3, retryOn: [PhpClawException::class], onError: static function (GraphState $state) use (&$handled): GraphState {
                $handled = true;

                return $state;
            })
            ->setEntry('ask');

        try {
            $graph->run(new GraphState, threadId: 'ask');
            $this->fail('Expected a GraphInterruptedException.');
        } catch (GraphInterruptedException) {
            $this->assertSame(1, $calls);
            $this->assertFalse($handled);
        }
    }

    public function test_a_node_error_saves_no_snapshot_for_that_node(): void
    {
        $graph = (new Graph)
            ->setCheckpointer(new ArrayMemory)
            ->addNode('a', static fn (GraphState $state): GraphState => $state->set('a', 1))
            ->addNode('b', static function (): never {
                throw new ProviderException('down');
            })
            ->setEntry('a')
            ->addEdge('a', 'b');

        try {
            $graph->run(new GraphState, threadId: 'failing');
            $this->fail('Expected the provider error.');
        } catch (ProviderException) {
            $snapshot = $graph->getState('failing');
            $this->assertSame([1, 'b', GraphSnapshot::STATUS_RUNNING], [$snapshot->step, $snapshot->next, $snapshot->status]);
        }
    }
}
