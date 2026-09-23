<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Queue;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\Queue\QueueManager;
use PhpClaw\Symfony\Queue\RunAgentMessage;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class JobOwnershipTest extends TestCase
{
    use BuildsIdentity;

    public function test_dispatch_captures_the_acting_user(): void
    {
        $captured = null;
        $bus = new class($captured) implements MessageBusInterface
        {
            public function __construct(private &$captured) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->captured = $message;

                return new Envelope($message);
            }
        };

        $queue = new QueueManager(new ArrayMemory, $bus, $this->buildIdentity('alice'));
        $jobId = $queue->dispatchSend('anything');

        self::assertInstanceOf(RunAgentMessage::class, $captured);
        self::assertSame('alice', $captured->userId);
        self::assertSame($jobId, $captured->jobId);
    }

    public function test_a_user_cannot_poll_another_users_job(): void
    {
        $memory = $this->memoryWithJobs();

        self::assertNull(
            (new QueueManager($memory, null, $this->buildIdentity('bob')))->pollResult('job-alice'),
        );
    }

    public function test_a_user_can_poll_their_own_job(): void
    {
        $memory = $this->memoryWithJobs();

        $result = (new QueueManager($memory, null, $this->buildIdentity('alice')))->pollResult('job-alice');

        self::assertIsArray($result);
        self::assertSame('alice-output', $result['text']);
    }

    public function test_list_jobs_returns_only_the_acting_users_jobs(): void
    {
        $memory = $this->memoryWithJobs();

        $jobs = (new QueueManager($memory, null, $this->buildIdentity('bob')))->listJobs();

        self::assertSame(['job-bob'], array_keys($jobs));
    }

    public function test_manage_all_reaches_every_job(): void
    {
        $memory = $this->memoryWithJobs();

        $jobs = (new QueueManager($memory, null, $this->buildIdentity('root', manageAll: true)))->listJobs();

        self::assertSame(['job-alice', 'job-bob', 'job-console'], array_keys($jobs));
    }

    public function test_a_user_cannot_forget_another_users_job(): void
    {
        $memory = $this->memoryWithJobs();

        (new QueueManager($memory, null, $this->buildIdentity('bob')))->forgetJob('job-alice');

        self::assertIsArray($memory->get('job-alice', RunAgentMessage::NAMESPACE));
    }

    public function test_a_console_job_is_reachable_only_by_the_empty_sentinel(): void
    {
        $memory = $this->memoryWithJobs();

        self::assertNull(
            (new QueueManager($memory, null, $this->buildIdentity('alice')))->pollResult('job-console'),
        );
        self::assertIsArray(
            (new QueueManager($memory, null, $this->buildIdentity('')))->pollResult('job-console'),
        );
    }

    private function memoryWithJobs(): ArrayMemory
    {
        $memory = new ArrayMemory;

        $memory->set('job-alice', ['status' => 'done', 'text' => 'alice-output', RunAgentMessage::OWNER_KEY => 'alice'], RunAgentMessage::NAMESPACE);
        $memory->set('job-bob', ['status' => 'done', 'text' => 'bob-output', RunAgentMessage::OWNER_KEY => 'bob'], RunAgentMessage::NAMESPACE);
        $memory->set('job-console', ['status' => 'done', 'text' => 'console-output', RunAgentMessage::OWNER_KEY => ''], RunAgentMessage::NAMESPACE);

        return $memory;
    }

    public function test_an_interactive_console_run_lists_every_job(): void
    {
        $memory = new ArrayMemory;
        $memory->set('j_none', ['status' => 'done', 'user_id' => ''], RunAgentMessage::NAMESPACE);
        $memory->set('j_alice', ['status' => 'done', 'user_id' => 'alice@example.com'], RunAgentMessage::NAMESPACE);
        $memory->set('j_bob', ['status' => 'done', 'user_id' => 'bob@example.com'], RunAgentMessage::NAMESPACE);

        $console = new ConsoleContext;
        $console->markConsole();

        $queue = new QueueManager($memory, null, null, $console);

        self::assertSame(['j_none', 'j_alice', 'j_bob'], array_keys($queue->listJobs()));
        self::assertNotNull($queue->pollResult('j_alice'));
    }

    public function test_a_queue_worker_is_not_treated_as_console_and_stays_scoped(): void
    {
        $memory = new ArrayMemory;
        $memory->set('j_alice', ['status' => 'done', 'user_id' => 'alice@example.com'], RunAgentMessage::NAMESPACE);

        $console = new ConsoleContext;

        $queue = new QueueManager($memory, null, null, $console);

        self::assertSame([], $queue->listJobs(), 'an unmarked context must not read another user\'s job');
        self::assertNull($queue->pollResult('j_alice'));
    }
}
