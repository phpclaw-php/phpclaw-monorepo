<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Queue;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\Queue\RunAgentMessage;
use PhpClaw\Symfony\Queue\RunAgentMessageHandler;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PHPUnit\Framework\TestCase;

final class QueuedIdentityTest extends TestCase
{
    use BuildsIdentity;

    public function test_the_run_acts_as_the_dispatching_user(): void
    {
        $identity = $this->buildIdentity('');
        $seen = null;

        $handler = new RunAgentMessageHandler(
            $this->agentRunning(static function () use ($identity, &$seen): void {
                $seen = $identity->actingUserId();
            }),
            new ArrayMemory,
            null,
            $identity,
        );

        self::assertSame('', $identity->actingUserId());

        $handler(new RunAgentMessage('job-1', 'anything', 3600, 'alice'));

        self::assertSame('alice', $seen);
    }

    public function test_the_worker_stops_acting_after_the_run(): void
    {
        $identity = $this->buildIdentity('');

        $handler = new RunAgentMessageHandler(
            $this->agentRunning(static function (): void {}),
            new ArrayMemory,
            null,
            $identity,
        );

        $handler(new RunAgentMessage('job-2', 'anything', 3600, 'alice'));

        self::assertFalse($identity->isImpersonating());
        self::assertSame('', $identity->actingUserId());
    }

    public function test_the_identity_is_dropped_even_when_the_run_fails(): void
    {
        $identity = $this->buildIdentity('');

        $handler = new RunAgentMessageHandler(
            $this->agentRunning(static function (): void {
                throw new \RuntimeException('provider exploded');
            }),
            new ArrayMemory,
            null,
            $identity,
        );

        try {
            $handler(new RunAgentMessage('job-3', 'anything', 3600, 'alice'));
            self::fail('the handler should have rethrown');
        } catch (\Throwable) {
            self::assertFalse($identity->isImpersonating());
        }
    }

    public function test_a_queued_run_holds_no_roles(): void
    {
        $identity = $this->buildIdentity('root', manageAll: true);

        self::assertTrue($identity->manageAll());

        $identity->actAs('root');

        self::assertSame('root', $identity->actingUserId());
        self::assertFalse(
            $identity->manageAll(),
            'a queued run must never carry a role, so it can never exceed the synchronous run',
        );

        $identity->stopActing();

        self::assertTrue($identity->manageAll());
    }

    public function test_a_console_dispatched_job_carries_the_empty_sentinel(): void
    {
        $identity = $this->buildIdentity('');
        $seen = null;

        $handler = new RunAgentMessageHandler(
            $this->agentRunning(static function () use ($identity, &$seen): void {
                $seen = $identity->actingUserId();
            }),
            new ArrayMemory,
            null,
            $identity,
        );

        $handler(new RunAgentMessage('job-4', 'anything', 3600, ''));

        self::assertSame('', $seen);
    }

    public function test_the_stored_result_carries_the_owner(): void
    {
        $memory = new ArrayMemory;

        $handler = new RunAgentMessageHandler(
            $this->agentRunning(static function (): void {}),
            $memory,
            null,
            $this->buildIdentity(''),
        );

        $handler(new RunAgentMessage('job-5', 'anything', 3600, 'alice'));

        $stored = $memory->get('job-5', RunAgentMessage::NAMESPACE);

        self::assertIsArray($stored);
        self::assertSame('alice', $stored[RunAgentMessage::OWNER_KEY]);
    }

    private function agentRunning(callable $onSend): ClawInterface
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturnCallback(static function () use ($onSend): AgentResponse {
            $onSend();

            return new AgentResponse(text: 'ok', provider: 'test', model: 'test', iterations: 1);
        });

        return $agent;
    }
}
