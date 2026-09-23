<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AgentApprovalGateTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_denied_tool_is_not_executed_and_returns_denied_result(): void
    {
        $tool = $this->spyTool();
        $agent = $this->agentWith($tool, $this->denyingGate());

        $results = $this->runBatch($agent, 'tu1', $tool->name());
        $decoded = json_decode($results['tu1'] ?? '', true);

        $this->assertFalse($tool->executed, 'denied tool must never execute');
        $this->assertIsArray($decoded);
        $this->assertSame('denied', $decoded['status']);
        $this->assertSame($tool->name(), $decoded['tool']);
    }

    public function test_null_gate_executes_the_tool_normally(): void
    {
        $tool = $this->spyTool();
        $agent = $this->agentWith($tool, null);

        $results = $this->runBatch($agent, 'tu2', $tool->name());

        $this->assertTrue($tool->executed, 'tool must run when no gate is installed');
        $this->assertSame('EXECUTED', $results['tu2']);
    }

    public function test_tool_after_hook_still_fires_on_denial_for_audit(): void
    {
        $captured = [];
        HookRegistry::on('tool.after', function (array $ctx) use (&$captured): void {
            $captured[] = $ctx;
        });

        $tool = $this->spyTool();
        $agent = $this->agentWith($tool, $this->denyingGate());
        $this->runBatch($agent, 'tu3', $tool->name());

        $this->assertCount(1, $captured, 'tool.after must fire even when the call was denied');
        $this->assertStringContainsString('denied', (string) $captured[0]['tool_result']);
    }

    public function test_denial_keeps_the_loop_safe_until_max_iterations(): void
    {
        $tool = $this->spyTool();
        $registry = new ToolRegistry;
        $registry->register([$tool]);

        $provider = new class implements ProviderInterface
        {
            public function name(): string
            {
                return 'loop';
            }

            public function model(): string
            {
                return 'loop-model';
            }

            public function send(array $messages, array $tools = []): array
            {
                return ['type' => 'tool_use_batch', 'calls' => [[
                    'tool_use_id' => 'tu', 'tool_name' => 'file_write', 'tool_input' => [],
                ]]];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }
        };

        $agent = new Agent(
            provider: $provider,
            tools: $registry,
            maxIterations: 3,
            approvalGate: $this->denyingGate(),
        );

        try {
            $agent->run('write a file');
            self::fail('expected MaxIterationsException');
        } catch (MaxIterationsException) {
        }

        $this->assertFalse($tool->executed, 'a denied tool must never execute, even across loop iterations');
    }

    private function runBatch(Agent $agent, string $useId, string $toolName): array
    {
        $method = new ReflectionMethod($agent, 'executeToolBatch');
        $toolsCalled = [];

        return $method->invokeArgs($agent, [
            [['tool_use_id' => $useId, 'tool_name' => $toolName, 'tool_input' => []]],
            0, 'run', 'iter', &$toolsCalled,
        ]);
    }

    private function agentWith(ToolInterface $tool, ?ApprovalGateInterface $gate): Agent
    {
        $registry = new ToolRegistry;
        $registry->register([$tool]);

        return new Agent(provider: $this->stubProvider(), tools: $registry, approvalGate: $gate);
    }

    private function denyingGate(): ApprovalGateInterface
    {
        return new class implements ApprovalGateInterface
        {
            public function check(string $toolName, array $toolInput, ?ToolInterface $tool = null): void
            {
                throw new HumanDeniedException($toolName, $toolInput);
            }
        };
    }

    private function spyTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public bool $executed = false;

            public function name(): string
            {
                return 'file_write';
            }

            public function description(): string
            {
                return 'writes a file';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                $this->executed = true;

                return 'EXECUTED';
            }
        };
    }

    private function stubProvider(): ProviderInterface
    {
        return new class implements ProviderInterface
        {
            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-model';
            }

            public function send(array $messages, array $tools = []): array
            {
                return ['type' => 'text', 'content' => ''];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }
        };
    }
}
