<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Queue;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\Queue\RunAgentMessage;
use PhpClaw\Symfony\Queue\RunAgentMessageHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RunAgentMessageHandlerTest extends TestCase
{
    private ArrayMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memory = new ArrayMemory;
    }

    private function response(): AgentResponse
    {
        return new AgentResponse(
            text: 'the agent answer',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 2,
            inputTokens: 30,
            outputTokens: 12,
        );
    }

    private function agentReturning(AgentResponse $response): ClawInterface
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn($response);

        return $agent;
    }

    public function test_a_successful_run_persists_a_done_record_under_the_jobs_namespace(): void
    {
        $handler = new RunAgentMessageHandler($this->agentReturning($this->response()), $this->memory);

        $handler(new RunAgentMessage('job_1', 'what is revenue?'));

        $stored = $this->memory->get('job_1', RunAgentMessage::NAMESPACE);

        self::assertSame('done', $stored['status']);
        self::assertSame('the agent answer', $stored['text']);
        self::assertSame('anthropic', $stored['provider']);
        self::assertSame('claude-haiku-4-5-20251001', $stored['model']);
        self::assertSame(2, $stored['iterations']);
    }

    public function test_tokens_are_the_sum_of_input_and_output(): void
    {
        $handler = new RunAgentMessageHandler($this->agentReturning($this->response()), $this->memory);

        $handler(new RunAgentMessage('job_1', 'hello'));

        self::assertSame(42, $this->memory->get('job_1', RunAgentMessage::NAMESPACE)['tokens']);
    }

    public function test_the_message_is_forwarded_to_the_agent_verbatim(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->expects(self::once())
            ->method('send')
            ->with('count the orders')
            ->willReturn($this->response());

        (new RunAgentMessageHandler($agent, $this->memory))(
            new RunAgentMessage('job_1', 'count the orders'),
        );
    }

    public function test_a_failed_run_persists_a_generic_error_and_rethrows(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException(new \RuntimeException('mysql://root:hunter2@db/app is down'));

        $handler = new RunAgentMessageHandler($agent, $this->memory);

        $thrown = null;

        try {
            $handler(new RunAgentMessage('job_2', 'boom'));
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'the handler must re-throw so Messenger can retry');
        self::assertSame('mysql://root:hunter2@db/app is down', $thrown->getMessage());

        $stored = $this->memory->get('job_2', RunAgentMessage::NAMESPACE);

        self::assertSame('failed', $stored['status']);
        self::assertSame('Job failed, see application log.', $stored['error']);
        self::assertStringNotContainsString('hunter2', $stored['error']);
    }

    public function test_a_failure_is_logged_with_the_job_id_and_the_exception(): void
    {
        $exception = new \RuntimeException('boom');
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException($exception);

        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context = []) use (&$logged): void {
                $logged[] = ['message' => $message, 'context' => $context];
            },
        );

        try {
            (new RunAgentMessageHandler($agent, $this->memory, $logger))(
                new RunAgentMessage('job_3', 'boom'),
            );
        } catch (\RuntimeException) {
        }

        self::assertSame('RunAgentMessageHandler failed.', $logged[0]['message']);
        self::assertSame('job_3', $logged[0]['context']['job_id']);
        self::assertSame($exception, $logged[0]['context']['exception']);
    }

    public function test_a_failure_without_a_logger_still_persists_and_rethrows(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        try {
            (new RunAgentMessageHandler($agent, $this->memory))(new RunAgentMessage('job_4', 'boom'));
        } finally {
            self::assertSame('failed', $this->memory->get('job_4', RunAgentMessage::NAMESPACE)['status']);
        }
    }
}
