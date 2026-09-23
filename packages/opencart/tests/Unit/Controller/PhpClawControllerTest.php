<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Controller;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\OpenCart\Controller\PhpClawController;
use PHPUnit\Framework\TestCase;

final class PhpClawControllerTest extends TestCase
{
    private function makeResponse(string $text = 'OK'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'openai',
            model: 'gpt-4o',
            iterations: 1,
        );
    }

    public function test_run_outputs_newline_after_text(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn($this->makeResponse('response text'));

        $ctrl = new PhpClawController($agent);

        ob_start();
        $ctrl->run('message');
        $output = ob_get_clean();

        self::assertStringEndsWith(PHP_EOL, (string) $output);
    }

    public function test_run_returns_zero_on_success(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn($this->makeResponse('done'));

        $ctrl = new PhpClawController($agent);

        ob_start();
        $code = $ctrl->run('hello');
        ob_end_clean();

        self::assertSame(0, $code);
    }

    public function test_run_returns_one_when_message_is_empty_string(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->expects(self::never())->method('send');

        $ctrl = new PhpClawController($agent);
        $code = $ctrl->run('');

        self::assertSame(1, $code);
    }

    public function test_run_returns_one_on_guard_exception(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')
            ->willThrowException(new GuardException('blocked: ignore previous'));

        $ctrl = new PhpClawController($agent);
        $code = $ctrl->run('any message');

        self::assertSame(1, $code);
    }

    public function test_run_returns_one_on_provider_exception(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')
            ->willThrowException(new ProviderException('rate limited'));

        $ctrl = new PhpClawController($agent);
        $code = $ctrl->run('any message');

        self::assertSame(1, $code);
    }

    public function test_run_returns_one_on_max_iterations_exception(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')
            ->willThrowException(new MaxIterationsException('looped 20 times'));

        $ctrl = new PhpClawController($agent);
        $code = $ctrl->run('any message');

        self::assertSame(1, $code);
    }

    public function test_run_echoes_response_text_before_returning_zero(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn($this->makeResponse('the answer is 42'));

        $ctrl = new PhpClawController($agent);

        ob_start();
        $code = $ctrl->run('what is the answer');
        $stdout = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('the answer is 42', $stdout);
    }

    public function test_run_does_not_echo_on_empty_message(): void
    {
        $agent = $this->createMock(ClawInterface::class);

        $ctrl = new PhpClawController($agent);

        ob_start();
        $ctrl->run('');
        $stdout = (string) ob_get_clean();

        self::assertSame('', $stdout);
    }

    public function test_run_does_not_echo_on_guard_exception(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException(new GuardException('blocked'));

        $ctrl = new PhpClawController($agent);

        ob_start();
        $ctrl->run('any');
        $stdout = (string) ob_get_clean();

        self::assertSame('', $stdout);
    }

    public function test_run_passes_exact_message_through_to_send(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->expects(self::once())
            ->method('send')
            ->with('exact prompt text')
            ->willReturn($this->makeResponse('ok'));

        $ctrl = new PhpClawController($agent);

        ob_start();
        $ctrl->run('exact prompt text');
        ob_end_clean();
    }
}
