<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Graph;

use PhpClaw\Claw;
use PhpClaw\Exceptions\GraphException;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Graph\Graph;
use PhpClaw\Graph\GraphCommand;
use PhpClaw\Graph\GraphRuntime;
use PhpClaw\Graph\GraphState;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    private array $events = [];

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();

        $this->events = [];
        HookRegistry::onAny(function (array $context): void {
            $this->events[] = ['event' => $context['event'], 'context' => $context];
        });
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function eventsNamed(string $name): array
    {
        return array_values(array_map(
            static fn (array $fired): array => $fired['context'],
            array_filter($this->events, static fn (array $fired): bool => $fired['event'] === $name),
        ));
    }

    private function clawReplying(ScriptedHttpClient $http): Claw
    {
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'llama-3.1-8b-instant', name: 'groq');

        return Claw::builder()->provider('groq')->model('llama-3.1-8b-instant')->providerOverride($provider)->build();
    }

    private function queueReply(ScriptedHttpClient $http, string $text): void
    {
        $http->queuePostResponse([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]);
    }

    public function test_a_linear_graph_runs_each_node_once_then_ends(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state->set('draft', 'Refunds take 5 days.'))
            ->addNode('review', static fn (GraphState $state): GraphState => $state->set('approved', true))
            ->setEntry('write')
            ->addEdge('write', 'review');

        $final = $graph->run(new GraphState(['topic' => 'refunds']));

        $this->assertSame(['topic' => 'refunds', 'draft' => 'Refunds take 5 days.', 'approved' => true], $final->all());
    }

    public function test_a_conditional_edge_routes_to_the_node_its_router_names(): void
    {
        $graph = (new Graph)
            ->addNode('classify', static fn (GraphState $state): GraphState => $state)
            ->addNode('refund', static fn (GraphState $state): GraphState => $state->set('handled_by', 'refund'))
            ->addNode('shipping', static fn (GraphState $state): GraphState => $state->set('handled_by', 'shipping'))
            ->setEntry('classify')
            ->addConditionalEdge('classify', static fn (GraphState $state): string => $state->get('topic') === 'refund' ? 'refund' : 'shipping');

        $this->assertSame('refund', $graph->run(new GraphState(['topic' => 'refund']))->get('handled_by'));
        $this->assertSame('shipping', $graph->run(new GraphState(['topic' => 'late parcel']))->get('handled_by'));
    }

    public function test_a_conditional_edge_wins_over_a_plain_edge_from_the_same_node(): void
    {
        $graph = (new Graph)
            ->addNode('start', static fn (GraphState $state): GraphState => $state)
            ->addNode('plain', static fn (GraphState $state): GraphState => $state->set('path', 'plain'))
            ->addNode('routed', static fn (GraphState $state): GraphState => $state->set('path', 'routed'))
            ->setEntry('start')
            ->addEdge('start', 'plain')
            ->addConditionalEdge('start', static fn (): string => 'routed');

        $this->assertSame('routed', $graph->run(new GraphState)->get('path'));
    }

    public function test_a_router_returning_end_stops_the_graph(): void
    {
        $graph = (new Graph)
            ->addNode('check', static fn (GraphState $state): GraphState => $state->set('checked', true))
            ->addNode('never', static fn (GraphState $state): GraphState => $state->set('never', true))
            ->setEntry('check')
            ->addEdge('check', 'never')
            ->addConditionalEdge('check', static fn (): string => Graph::END);

        $this->assertSame(['checked' => true], $graph->run(new GraphState)->all());
    }

    public function test_a_graph_loops_back_until_the_router_approves(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state->set('drafts', $state->get('drafts', 0) + 1))
            ->addNode('review', static fn (GraphState $state): GraphState => $state->set('approved', $state->get('drafts') >= 3))
            ->setEntry('write')
            ->addEdge('write', 'review')
            ->addConditionalEdge('review', static fn (GraphState $state): string => $state->get('approved') ? Graph::END : 'write');

        $final = $graph->run(new GraphState);

        $this->assertSame(3, $final->get('drafts'));
        $this->assertTrue($final->get('approved'));
        $this->assertSame(6, $this->eventsNamed('graph.end')[0]['steps']);
    }

    public function test_a_graph_that_never_reaches_end_throws_after_max_steps(): void
    {
        $visits = 0;
        $graph = (new Graph)
            ->addNode('spin', static function (GraphState $state) use (&$visits): GraphState {
                $visits++;

                return $state;
            })
            ->setEntry('spin')
            ->addEdge('spin', 'spin');

        try {
            $graph->run(new GraphState, maxSteps: 4);
            $this->fail('Expected a GraphException.');
        } catch (PhpClawException $exception) {
            $this->assertInstanceOf(GraphException::class, $exception);
            $this->assertSame('Graph stopped after 4 steps without reaching Graph::END.', $exception->getMessage());
        }

        $this->assertSame(4, $visits);
        $this->assertCount(1, $this->eventsNamed('graph.start'));
        $this->assertSame([], $this->eventsNamed('graph.end'));
    }

    public function test_the_default_step_limit_is_fifty(): void
    {
        $visits = 0;
        $graph = (new Graph)
            ->addNode('spin', static function (GraphState $state) use (&$visits): GraphState {
                $visits++;

                return $state;
            })
            ->setEntry('spin')
            ->addEdge('spin', 'spin');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected a GraphException.');
        } catch (GraphException) {
            $this->assertSame(50, $visits);
        }
    }

    public function test_a_failed_attempt_leaves_no_partial_write_in_the_state(): void
    {
        $attempt = 0;
        $graph = (new Graph)
            ->addNode('collect', static function (GraphState $state) use (&$attempt): GraphState {
                $attempt++;
                $state->set('items', [...$state->get('items', []), 'item']);
                if ($attempt === 1) {
                    throw new ProviderException('timeout after the write');
                }

                return $state;
            }, attempts: 2)
            ->setEntry('collect');

        $this->assertSame(['item'], $graph->run(new GraphState)->get('items'));
    }

    public function test_run_throws_before_any_node_runs_when_no_entry_is_set(): void
    {
        $graph = (new Graph)->addNode('write', static fn (GraphState $state): GraphState => $state);

        try {
            $graph->run(new GraphState);
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertSame('Graph has no entry node; call setEntry() with the name of an added node.', $exception->getMessage());
        }

        $this->assertSame([], $this->eventsNamed('graph.start'));
    }

    public function test_run_throws_before_any_node_runs_when_the_entry_names_no_node(): void
    {
        $graph = (new Graph)->addNode('write', static fn (GraphState $state): GraphState => $state)->setEntry('wirte');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertSame("Graph has no node named 'wirte'.", $exception->getMessage());
        }

        $this->assertSame([], $this->eventsNamed('graph.start'));
    }

    public function test_an_edge_to_a_missing_node_throws_before_any_node_runs(): void
    {
        $ran = false;
        $graph = (new Graph)
            ->addNode('write', static function (GraphState $state) use (&$ran): GraphState {
                $ran = true;

                return $state;
            })
            ->setEntry('write')
            ->addEdge('write', 'reveiw');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected a GraphException.');
        } catch (GraphException $exception) {
            $this->assertSame("Graph has no node named 'reveiw'.", $exception->getMessage());
        }

        $this->assertFalse($ran);
        $this->assertSame([], $this->eventsNamed('graph.start'));
    }

    public function test_validate_rejects_an_edge_leaving_a_missing_node(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state)
            ->setEntry('write')
            ->addEdge('draft', 'write');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph has no node named 'draft'.");

        $graph->validate();
    }

    public function test_validate_rejects_a_conditional_edge_leaving_a_missing_node(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state)
            ->setEntry('write')
            ->addConditionalEdge('review', static fn (): string => Graph::END);

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph has no node named 'review'.");

        $graph->validate();
    }

    public function test_validate_accepts_a_complete_graph_with_an_edge_to_end(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state->set('done', true))
            ->setEntry('write')
            ->addEdge('write', Graph::END);

        $graph->validate();

        $this->assertTrue($graph->run(new GraphState)->get('done'));
    }

    public function test_a_router_naming_a_missing_node_throws(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state)
            ->setEntry('write')
            ->addConditionalEdge('write', static fn (): string => 'publish');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph has no node named 'publish'.");

        $graph->run(new GraphState);
    }

    public function test_a_node_returning_a_new_state_replaces_the_current_one(): void
    {
        $graph = (new Graph)
            ->addNode('reset', static fn (): GraphState => new GraphState(['fresh' => true]))
            ->setEntry('reset');

        $this->assertSame(['fresh' => true], $graph->run(new GraphState(['stale' => true]))->all());
    }

    public function test_a_node_returning_nothing_keeps_the_state_it_changed(): void
    {
        $graph = (new Graph)
            ->addNode('note', static function (GraphState $state): void {
                $state->set('noted', true);
            })
            ->setEntry('note');

        $this->assertSame(['noted' => true], $graph->run(new GraphState)->all());
    }

    public function test_graph_start_and_end_report_the_run_without_any_state_contents(): void
    {
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state->set('draft', 'card 4242 4242 4242 4242'))
            ->addNode('review', static fn (GraphState $state): GraphState => $state)
            ->setEntry('write')
            ->addEdge('write', 'review');

        $graph->run(new GraphState(['customer_email' => 'ava@example.com']));

        $start = $this->eventsNamed('graph.start');
        $end = $this->eventsNamed('graph.end');
        $this->assertCount(1, $start);
        $this->assertCount(1, $end);
        $this->assertSame('write', $start[0]['entry_node']);
        $this->assertSame(2, $end[0]['steps']);
        $this->assertSame('review', $end[0]['last_node']);
        $this->assertNotSame('', $start[0]['run_id']);
        $this->assertSame($start[0]['run_id'], $end[0]['run_id']);
        $this->assertArrayNotHasKey('parent_run_id', $start[0]);

        $serialized = (string) json_encode([$start, $end]);
        $this->assertStringNotContainsString('ava@example.com', $serialized);
        $this->assertStringNotContainsString('4242', $serialized);
    }

    public function test_each_agent_run_inside_a_graph_reports_the_graph_run_as_its_parent(): void
    {
        $http = new ScriptedHttpClient;
        $this->queueReply($http, 'Refunds take 5 business days.');
        $this->queueReply($http, 'YES');
        $writer = $this->clawReplying($http);
        $reviewer = $this->clawReplying($http);

        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => $state->set('draft', (string) $writer->send('Write about '.$state->get('topic'))))
            ->addNode('review', static fn (GraphState $state): GraphState => $state->set('ok', str_contains((string) $reviewer->send('Approve? '.$state->get('draft')), 'YES')))
            ->setEntry('write')
            ->addEdge('write', 'review')
            ->addConditionalEdge('review', static fn (GraphState $state): string => $state->get('ok') ? Graph::END : 'write');

        $final = $graph->run(new GraphState(['topic' => 'refunds']));

        $this->assertSame('Refunds take 5 business days.', $final->get('draft'));
        $graphRunId = $this->eventsNamed('graph.start')[0]['run_id'];
        $before = $this->eventsNamed('agent.before');
        $after = $this->eventsNamed('agent.after');
        $this->assertCount(2, $before);
        $this->assertCount(2, $after);
        foreach ([...$before, ...$after] as $context) {
            $this->assertSame($graphRunId, $context['parent_run_id']);
            $this->assertNotSame($graphRunId, $context['run_id']);
        }
        $this->assertNotSame($before[0]['run_id'], $before[1]['run_id']);
    }

    public function test_a_graph_run_inside_another_graph_reports_the_outer_run_as_its_parent(): void
    {
        $inner = (new Graph)
            ->addNode('step', static fn (GraphState $state): GraphState => $state->set('inner_done', true))
            ->setEntry('step');
        $outer = (new Graph)
            ->addNode('delegate', static fn (GraphState $state): GraphState => $inner->run($state))
            ->setEntry('delegate');

        $final = $outer->run(new GraphState);

        $this->assertTrue($final->get('inner_done'));
        $starts = $this->eventsNamed('graph.start');
        $this->assertCount(2, $starts);
        $this->assertArrayNotHasKey('parent_run_id', $starts[0]);
        $this->assertSame($starts[0]['run_id'], $starts[1]['parent_run_id']);
        $this->assertSame($starts[0]['run_id'], $this->eventsNamed('graph.end')[1]['run_id']);
    }

    private function flakyNode(array &$calls, array $failures): \Closure
    {
        return static function (GraphState $state) use (&$calls, &$failures): GraphState {
            $calls[] = 'call';
            $failure = array_shift($failures);
            if ($failure !== null) {
                throw $failure;
            }

            return $state->set('written', true);
        };
    }

    public function test_a_node_with_attempts_retries_a_provider_failure(): void
    {
        $calls = [];
        $graph = (new Graph)
            ->addNode('write', $this->flakyNode($calls, [new ProviderException('timeout'), new ProviderException('timeout')]), attempts: 3)
            ->setEntry('write');

        $this->assertTrue($graph->run(new GraphState)->get('written'));
        $this->assertCount(3, $calls);
    }

    public function test_a_node_is_not_retried_by_default(): void
    {
        $calls = [];
        $graph = (new Graph)->addNode('write', $this->flakyNode($calls, [new ProviderException('timeout')]))->setEntry('write');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected the provider error.');
        } catch (ProviderException) {
            $this->assertCount(1, $calls);
        }
    }

    public function test_a_node_never_retries_an_error_outside_its_retry_list(): void
    {
        $calls = [];
        $graph = (new Graph)
            ->addNode('write', $this->flakyNode($calls, [new GuardException('blocked')]), attempts: 3)
            ->setEntry('write');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected the guard error.');
        } catch (GuardException) {
            $this->assertCount(1, $calls);
        }
    }

    public function test_node_defaults_apply_to_nodes_without_their_own_settings_and_a_node_setting_wins(): void
    {
        $defaultCalls = [];
        $ownCalls = [];
        $graph = (new Graph)
            ->setNodeDefaults(attempts: 2)
            ->addNode('a', $this->flakyNode($defaultCalls, [new ProviderException('timeout')]))
            ->addNode('b', $this->flakyNode($ownCalls, [new ProviderException('timeout')]), attempts: 1)
            ->setEntry('a')
            ->addEdge('a', 'b');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected the provider error from node b.');
        } catch (ProviderException) {
            $this->assertCount(2, $defaultCalls);
            $this->assertCount(1, $ownCalls);
        }
    }

    public function test_an_error_handler_recovers_after_retries_and_routing_continues_from_the_failed_node(): void
    {
        $calls = [];
        $seen = null;
        $graph = (new Graph)
            ->addNode('write', $this->flakyNode($calls, [new ProviderException('down'), new ProviderException('down')]), attempts: 2, onError: static function (GraphState $state, \Throwable $error, string $node) use (&$seen): GraphState {
                $seen = [$node, $error->getMessage()];

                return $state->set('draft', 'fallback draft');
            })
            ->addNode('review', static fn (GraphState $state): GraphState => $state->set('reviewed', true))
            ->setEntry('write')
            ->addEdge('write', 'review');

        $final = $graph->run(new GraphState);

        $this->assertCount(2, $calls);
        $this->assertSame(['write', 'down'], $seen);
        $this->assertSame(['draft' => 'fallback draft', 'reviewed' => true], $final->all());
    }

    public function test_an_error_handler_can_reroute_with_a_command(): void
    {
        $calls = [];
        $graph = (new Graph)
            ->addNode('write', $this->flakyNode($calls, [new ProviderException('down')]), onError: static fn (): GraphCommand => new GraphCommand(goto: 'apologise'))
            ->addNode('review', static fn (GraphState $state): GraphState => $state->set('reviewed', true))
            ->addNode('apologise', static fn (GraphState $state): GraphState => $state->set('apology', true))
            ->setEntry('write')
            ->addEdge('write', 'review');

        $this->assertSame(['apology' => true], $graph->run(new GraphState)->all());
    }

    public function test_a_default_error_handler_applies_to_every_node(): void
    {
        $calls = [];
        $graph = (new Graph)
            ->setNodeDefaults(onError: static fn (GraphState $state, \Throwable $error, string $node): GraphState => $state->set('failed_node', $node))
            ->addNode('write', $this->flakyNode($calls, [new ProviderException('down')]))
            ->setEntry('write');

        $this->assertSame(['failed_node' => 'write'], $graph->run(new GraphState)->all());
    }

    public function test_an_error_handler_never_catches_a_php_error(): void
    {
        $handled = false;
        $graph = (new Graph)
            ->addNode('write', static fn (GraphState $state): GraphState => strlen(), onError: static function (GraphState $state) use (&$handled): GraphState {
                $handled = true;

                return $state;
            })
            ->setEntry('write');

        try {
            $graph->run(new GraphState);
            $this->fail('Expected an ArgumentCountError.');
        } catch (\ArgumentCountError) {
            $this->assertFalse($handled);
        }
    }

    public function test_a_command_updates_the_state_and_routes_to_its_target_over_the_plain_edge(): void
    {
        $graph = (new Graph)
            ->addNode('classify', static fn (): GraphCommand => new GraphCommand(goto: 'refund', update: ['topic' => 'refund', 'priority' => 1]))
            ->addNode('refund', static fn (GraphState $state): GraphState => $state->set('handled_by', 'refund'))
            ->addNode('shipping', static fn (GraphState $state): GraphState => $state->set('handled_by', 'shipping'))
            ->setEntry('classify')
            ->addEdge('classify', 'shipping');

        $this->assertSame(
            ['ticket' => 'T-1', 'topic' => 'refund', 'priority' => 1, 'handled_by' => 'refund'],
            $graph->run(new GraphState(['ticket' => 'T-1']))->all(),
        );
    }

    public function test_a_command_without_a_target_only_updates_and_follows_the_edges(): void
    {
        $graph = (new Graph)
            ->addNode('classify', static fn (): GraphCommand => new GraphCommand(update: ['topic' => 'shipping']))
            ->addNode('shipping', static fn (GraphState $state): GraphState => $state->set('handled_by', 'shipping'))
            ->setEntry('classify')
            ->addEdge('classify', 'shipping');

        $this->assertSame(['topic' => 'shipping', 'handled_by' => 'shipping'], $graph->run(new GraphState)->all());
    }

    public function test_a_command_can_end_the_graph(): void
    {
        $graph = (new Graph)
            ->addNode('check', static fn (): GraphCommand => new GraphCommand(goto: Graph::END, update: ['checked' => true]))
            ->addNode('never', static fn (GraphState $state): GraphState => $state->set('never', true))
            ->setEntry('check')
            ->addEdge('check', 'never');

        $this->assertSame(['checked' => true], $graph->run(new GraphState)->all());
    }

    public function test_a_command_naming_a_missing_node_throws(): void
    {
        $graph = (new Graph)
            ->addNode('check', static fn (): GraphCommand => new GraphCommand(goto: 'publish'))
            ->setEntry('check');

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage("Graph has no node named 'publish'.");

        $graph->run(new GraphState);
    }

    public function test_every_node_gets_the_run_context_store_and_run_id(): void
    {
        $store = new ArrayMemory;
        $store->set('greeting', 'Hello', 'support');
        $seen = [];
        $graph = (new Graph)
            ->setStore($store)
            ->addNode('write', static function (GraphState $state, GraphRuntime $runtime) use (&$seen): GraphState {
                $seen[] = $runtime;

                return $state->set('draft', $runtime->store?->get('greeting', 'support').' '.$runtime->context['customer']);
            })
            ->setEntry('write');

        $final = $graph->run(new GraphState, context: ['customer' => 'Ava']);

        $this->assertSame('Hello Ava', $final->get('draft'));
        $this->assertSame(['customer' => 'Ava'], $seen[0]->context);
        $this->assertSame($store, $seen[0]->store);
        $this->assertSame($this->eventsNamed('graph.start')[0]['run_id'], $seen[0]->runId);
        $this->assertArrayNotHasKey('customer', $final->all());
    }

    public function test_a_graph_without_a_store_or_context_gives_nodes_empty_values(): void
    {
        $seen = null;
        $graph = (new Graph)
            ->addNode('write', static function (GraphState $state, GraphRuntime $runtime) use (&$seen): GraphState {
                $seen = $runtime;

                return $state;
            })
            ->setEntry('write');

        $graph->run(new GraphState);

        $this->assertSame([], $seen->context);
        $this->assertNull($seen->store);
    }
}
