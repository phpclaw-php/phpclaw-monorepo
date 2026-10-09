<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\ClassifierApprovalGate;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Agent\Message;
use PhpClaw\Agent\SuspendableApprovalGate;
use PhpClaw\Exceptions\ApprovalPendingException;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tests\Unit\Agent\Durable\CountingMutatingTool;
use PhpClaw\Tests\Unit\Agent\Durable\CountingTool;
use PhpClaw\Tools\ShellTool;
use PHPUnit\Framework\TestCase;

final class ClassifierApprovalGateTest extends TestCase
{
    private function classifier(string|ProviderException $reply): ProviderInterface
    {
        return new class($reply) implements ProviderInterface
        {
            public array $questions = [];

            public function __construct(private readonly string|ProviderException $reply) {}

            public function send(array $messages, array $tools = []): array
            {
                $this->questions[] = $messages;

                if ($this->reply instanceof ProviderException) {
                    throw $this->reply;
                }

                return ['type' => 'text', 'text' => $this->reply];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-classifier';
            }
        };
    }

    private function gate(ApprovalGateInterface $inner, ProviderInterface $classifier): ClassifierApprovalGate
    {
        return new ClassifierApprovalGate($inner, $classifier, threshold: 0.8);
    }

    public function test_a_dangerous_answer_on_a_non_mutating_call_pauses_a_durable_run(): void
    {
        $gate = $this->gate(new SuspendableApprovalGate, $this->classifier('{"value": "dangerous", "confidence": 0.95}'));

        try {
            $gate->check('http_request', ['method' => 'POST', 'url' => 'https://example.test'], new CountingTool('http_request'));
            $this->fail('A call rated dangerous must reach the pause.');
        } catch (ApprovalPendingException $e) {
            $this->assertSame('http_request', $e->toolName);
            $this->assertSame(['method' => 'POST', 'url' => 'https://example.test'], $e->toolInput);
        }
    }

    public function test_a_dangerous_answer_on_a_non_mutating_call_makes_the_terminal_gate_ask(): void
    {
        $gate = $this->gate(new CliApprovalGate, $this->classifier('{"value": "dangerous", "confidence": 0.95}'));

        $this->expectException(HumanDeniedException::class);

        $gate->check('http_request', ['method' => 'POST'], new CountingTool('http_request'));
    }

    public function test_a_dangerous_answer_escalates_a_per_invocation_tool_its_own_check_lets_through(): void
    {
        $gate = $this->gate(new SuspendableApprovalGate, $this->classifier('{"value": "dangerous", "confidence": 0.95}'));

        $this->expectException(ApprovalPendingException::class);

        $gate->check('shell_exec', ['command' => 'ls'], new ShellTool);
    }

    public function test_a_safe_answer_lets_a_non_mutating_call_run(): void
    {
        $gate = $this->gate(new SuspendableApprovalGate, $this->classifier('{"value": "safe", "confidence": 0.99}'));

        $gate->check('http_request', ['method' => 'GET'], new CountingTool('http_request'));

        $this->addToAssertionCount(1);
    }

    public function test_a_dangerous_answer_below_the_threshold_lets_the_call_run(): void
    {
        $gate = $this->gate(new SuspendableApprovalGate, $this->classifier('{"value": "dangerous", "confidence": 0.5}'));

        $gate->check('http_request', ['method' => 'POST'], new CountingTool('http_request'));

        $this->addToAssertionCount(1);
    }

    public function test_an_unparseable_answer_counts_as_unsure(): void
    {
        foreach (['dangerous', '{"value": "dangerous"}', '{"value": "risky", "confidence": 1}', '{"value": "dangerous", "confidence": "high"}'] as $reply) {
            $this->gate(new SuspendableApprovalGate, $this->classifier($reply))
                ->check('http_request', ['method' => 'POST'], new CountingTool('http_request'));
        }

        $this->addToAssertionCount(1);
    }

    public function test_a_provider_error_lets_the_call_run_unchanged(): void
    {
        $gate = $this->gate(new SuspendableApprovalGate, $this->classifier(new ProviderException('down', 503)));

        $gate->check('http_request', ['method' => 'POST'], new CountingTool('http_request'));

        $this->addToAssertionCount(1);
    }

    public function test_a_call_the_static_check_already_gates_reaches_the_inner_gate_without_asking_the_classifier(): void
    {
        $classifier = $this->classifier('{"value": "safe", "confidence": 1}');

        try {
            $this->gate(new SuspendableApprovalGate, $classifier)->check('file_write', ['path' => 'a'], new CountingMutatingTool('file_write'));
            $this->fail('A safe answer must never remove the approval a mutating call needs.');
        } catch (ApprovalPendingException) {
            $this->assertSame([], $classifier->questions);
        }
    }

    public function test_an_unknown_tool_reaches_the_inner_gate_unchanged(): void
    {
        $classifier = $this->classifier('{"value": "dangerous", "confidence": 1}');

        $this->gate(new SuspendableApprovalGate, $classifier)->check('missing', [], null);

        $this->assertSame([], $classifier->questions);
    }

    public function test_the_classifier_is_asked_about_the_tool_name_and_input(): void
    {
        $classifier = $this->classifier('{"value": "safe", "confidence": 1}');

        $this->gate(new SuspendableApprovalGate, $classifier)->check('http_request', ['url' => 'https://example.test'], new CountingTool('http_request'));

        $this->assertCount(1, $classifier->questions);
        $this->assertInstanceOf(Message::class, $classifier->questions[0][0]);
        $this->assertStringContainsString('http_request', $classifier->questions[0][0]->content);
        $this->assertStringContainsString('"url":"https:\/\/example.test"', $classifier->questions[0][0]->content);
    }
}
