<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\Command\JobsListCommand;
use PhpClaw\Symfony\Command\JobsStatusCommand;
use PhpClaw\Symfony\Queue\QueueManager;
use PhpClaw\Symfony\Queue\RunAgentMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class JobsCommandsTest extends TestCase
{
    public function test_list_empty_returns_success(): void
    {
        $tester = new CommandTester(new JobsListCommand(new QueueManager(new ArrayMemory)));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No jobs', $tester->getDisplay());
    }

    public function test_list_with_jobs_returns_table(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j1', ['status' => 'done', 'provider' => 'openai', 'tokens' => 42, 'at' => '2026-04-15', 'text' => 'ok'], RunAgentMessage::NAMESPACE);

        $tester = new CommandTester(new JobsListCommand(new QueueManager($mem)));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('j1', $tester->getDisplay());
        self::assertStringContainsString('done', $tester->getDisplay());
    }

    public function test_status_done(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j1', ['status' => 'done', 'text' => 'all good'], RunAgentMessage::NAMESPACE);

        $tester = new CommandTester(new JobsStatusCommand(new QueueManager($mem)));
        $tester->execute(['jobId' => 'j1']);

        self::assertSame(JobsStatusCommand::STATUS_DONE, $tester->getStatusCode());
    }

    public function test_status_failed(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j1', ['status' => 'failed', 'error' => 'boom'], RunAgentMessage::NAMESPACE);

        $tester = new CommandTester(new JobsStatusCommand(new QueueManager($mem)));
        $tester->execute(['jobId' => 'j1']);

        self::assertSame(JobsStatusCommand::STATUS_FAILED, $tester->getStatusCode());
    }

    public function test_status_of_a_job_that_does_not_exist(): void
    {
        $tester = new CommandTester(new JobsStatusCommand(new QueueManager(new ArrayMemory)));
        $tester->execute(['jobId' => 'nonexistent']);

        self::assertSame(JobsStatusCommand::STATUS_NOT_FOUND, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_exit_code_constants(): void
    {
        self::assertSame(0, JobsStatusCommand::STATUS_DONE);
        self::assertSame(1, JobsStatusCommand::STATUS_PENDING);
        self::assertSame(2, JobsStatusCommand::STATUS_FAILED);
        self::assertSame(3, JobsStatusCommand::STATUS_NOT_FOUND);
    }

    public function test_status_unexpected_status_returns_unknown(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j1', ['status' => 'in-progress'], RunAgentMessage::NAMESPACE);

        $tester = new CommandTester(new JobsStatusCommand(new QueueManager($mem)));
        $tester->execute(['jobId' => 'j1']);

        self::assertSame(JobsStatusCommand::STATUS_UNRECOGNISED_STATE, $tester->getStatusCode());
    }

    public function test_status_done_prints_response_text(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j2', ['status' => 'done', 'text' => 'Agent response here'], RunAgentMessage::NAMESPACE);

        $tester = new CommandTester(new JobsStatusCommand(new QueueManager($mem)));
        $tester->execute(['jobId' => 'j2']);

        self::assertSame(JobsStatusCommand::STATUS_DONE, $tester->getStatusCode());
        self::assertStringContainsString('Agent response here', $tester->getDisplay());
    }
}
