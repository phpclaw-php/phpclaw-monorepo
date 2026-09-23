<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Profiler;

use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\Events\PhpClawEvent;
use PhpClaw\Symfony\PhpClawBundle;
use PhpClaw\Symfony\Profiler\PhpClawDataCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PhpClawDataCollectorTest extends TestCase
{
    private PhpClawDataCollector $collector;

    protected function setUp(): void
    {
        HookRegistry::reset();
        PhpClawBundle::resetEventBridge();
        $this->collector = new PhpClawDataCollector;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        PhpClawBundle::resetEventBridge();
    }

    public function test_get_name_returns_phpclaw(): void
    {
        self::assertSame('phpclaw', $this->collector->getName());
    }

    public function test_subscribes_to_three_events(): void
    {
        self::assertSame(
            ['phpclaw.agent.after', 'phpclaw.tool.after', 'phpclaw.guard.blocked'],
            array_keys(PhpClawDataCollector::getSubscribedEvents()),
        );
    }

    public function test_on_agent_after_builds_payload(): void
    {
        $event = new PhpClawEvent('agent.after', [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'iterations' => 2,
            'duration_ms' => 420,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'tools_called' => ['db_query'],
        ]);

        $this->collector->onAgentAfter($event);
        $this->collector->collect(new Request, new Response);

        $runs = $this->collector->getAgentRuns();
        self::assertCount(1, $runs);
        self::assertSame('anthropic', $runs[0]['provider']);
        self::assertSame(2, $runs[0]['iterations']);
        self::assertSame(100, $runs[0]['input_tokens']);
        self::assertSame(50, $runs[0]['output_tokens']);
        self::assertSame(150, $runs[0]['total_tokens']);
        self::assertSame(420, $runs[0]['duration_ms']);
        self::assertSame(['db_query'], $runs[0]['tools_called']);
    }

    public function test_on_agent_after_handles_missing_keys(): void
    {
        $this->collector->onAgentAfter(new PhpClawEvent('agent.after', []));
        $this->collector->collect(new Request, new Response);

        $runs = $this->collector->getAgentRuns();
        self::assertCount(1, $runs);
        self::assertNull($runs[0]['provider']);
        self::assertSame(0, $runs[0]['total_tokens']);
    }

    public function test_on_tool_after_builds_payload(): void
    {
        $event = new PhpClawEvent('tool.after', [
            'tool_name' => 'db_query',
            'iteration' => 1,
            'tool_input' => ['query' => 'SELECT 1'],
        ]);

        $this->collector->onToolAfter($event);
        $this->collector->collect(new Request, new Response);

        $calls = $this->collector->getToolCalls();
        self::assertCount(1, $calls);
        self::assertSame('db_query', $calls[0]['tool_name']);
        self::assertSame(1, $calls[0]['iteration']);
        self::assertSame(['query' => 'SELECT 1'], $calls[0]['tool_input']);
    }

    public function test_on_tool_after_redacts_secret_tool_input(): void
    {
        $event = new PhpClawEvent('tool.after', [
            'tool_name' => 'http_request',
            'iteration' => 1,
            'tool_input' => [
                'auth' => 'Bearer sk-abcdef1234567890',
                'headers' => 'api_key=supersecretvalue',
            ],
        ]);

        $this->collector->onToolAfter($event);
        $this->collector->collect(new Request, new Response);

        $calls = $this->collector->getToolCalls();
        $input = $calls[0]['tool_input'];

        self::assertStringNotContainsString('sk-abcdef1234567890', $input['auth']);
        self::assertStringNotContainsString('supersecretvalue', $input['headers']);
        self::assertStringContainsString('[redacted]', $input['auth']);
        self::assertStringContainsString('[redacted]', $input['headers']);
    }

    public function test_on_guard_blocked_builds_payload(): void
    {
        $event = new PhpClawEvent('guard.blocked', [
            'reason' => "Prompt injection detected: contains 'ignore previous instructions'",
        ]);

        $this->collector->onGuardBlocked($event);
        $this->collector->collect(new Request, new Response);

        $blocks = $this->collector->getGuardBlocks();
        self::assertCount(1, $blocks);
        self::assertStringContainsString('injection', $blocks[0]['reason']);
    }

    public function test_get_run_count_and_get_total_tokens(): void
    {
        $this->collector->onAgentAfter(new PhpClawEvent('agent.after', [
            'input_tokens' => 200, 'output_tokens' => 100,
        ]));
        $this->collector->onAgentAfter(new PhpClawEvent('agent.after', [
            'input_tokens' => 50, 'output_tokens' => 30,
        ]));
        $this->collector->collect(new Request, new Response);

        self::assertSame(2, $this->collector->getRunCount());
        self::assertSame(380, $this->collector->getTotalTokens());
    }

    public function test_reset_clears_all_data(): void
    {
        $this->collector->onAgentAfter(new PhpClawEvent('agent.after', []));
        $this->collector->onToolAfter(new PhpClawEvent('tool.after', []));
        $this->collector->onGuardBlocked(new PhpClawEvent('guard.blocked', []));
        $this->collector->collect(new Request, new Response);

        $this->collector->reset();

        self::assertCount(0, $this->collector->getAgentRuns());
        self::assertCount(0, $this->collector->getToolCalls());
        self::assertCount(0, $this->collector->getGuardBlocks());
        self::assertSame(0, $this->collector->getRunCount());
        self::assertSame(0, $this->collector->getTotalTokens());
    }

    public function test_get_total_duration_ms_sums_across_runs(): void
    {
        $this->collector->onAgentAfter(new PhpClawEvent('agent.after', [
            'input_tokens' => 0, 'output_tokens' => 0, 'duration_ms' => 100,
        ]));
        $this->collector->onAgentAfter(new PhpClawEvent('agent.after', [
            'input_tokens' => 0, 'output_tokens' => 0, 'duration_ms' => 250,
        ]));
        $this->collector->collect(new Request, new Response);

        self::assertSame(350, $this->collector->getTotalDurationMs());
    }

    public function test_receives_events_via_dispatcher(): void
    {
        $dispatcher = new EventDispatcher;
        $dispatcher->addSubscriber($this->collector);

        (new HookEventBridge(
            static function (string $event, array $ctx) use ($dispatcher): void {
                $dispatcher->dispatch(new PhpClawEvent($event, $ctx), 'phpclaw.'.$event);
            },
        ))->register();

        HookRegistry::fire('agent.after', [
            'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'input_tokens' => 10, 'output_tokens' => 5,
        ]);

        $this->collector->collect(new Request, new Response);

        self::assertCount(1, $this->collector->getAgentRuns());
        self::assertSame('openai', $this->collector->getAgentRuns()[0]['provider']);
    }
}
