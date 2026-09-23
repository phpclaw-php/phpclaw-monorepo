<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Symfony\Command\AboutCommand;
use PhpClaw\Symfony\Command\JobsStatusCommand;
use PhpClaw\Symfony\Command\PhpClawCommand;
use PhpClaw\Symfony\Command\StatsCommand;
use PhpClaw\Symfony\Queue\QueueManager;
use PhpClaw\Symfony\Queue\RunAgentMessage;
use PhpClaw\Symfony\Queue\RunAgentMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FailurePathsTest extends TestCase
{
    public function test_the_agent_command_reports_an_unexpected_failure_without_a_stack_trace(): void
    {
        $agent = $this->createMock(PhpClawInterface::class);
        $agent->method('conversation')->willReturn(new Conversation('01H', [], new \DateTimeImmutable));
        $agent->method('sendInConversation')->willThrowException(
            new \RuntimeException('DSN=mysql://root:hunter2@db/app is unreachable'),
        );

        $tester = new CommandTester(new PhpClawCommand($agent));
        $exit = $tester->execute(['message' => 'hi']);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('An internal error occurred', $display);
        self::assertStringNotContainsString('hunter2', $display);
        self::assertStringNotContainsString('RuntimeException', $display);
    }

    public function test_stats_reports_an_unreadable_count_instead_of_zero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('SQLSTATE[42S02]: table missing'));

        $tester = new CommandTester(new StatsCommand('doctrine', $connection));
        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('unavailable', $display);
        self::assertStringNotContainsString('Conversations:  0', $display);
        self::assertStringNotContainsString('42S02', $display);
    }

    public function test_stats_still_prints_real_counts_when_the_database_answers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(7);

        $tester = new CommandTester(new StatsCommand('doctrine', $connection));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Conversations:  7', $tester->getDisplay());
        self::assertStringNotContainsString('unavailable', $tester->getDisplay());
    }

    public function test_stats_refuses_a_key_value_driver_that_holds_no_conversations(): void
    {
        $tester = new CommandTester(new StatsCommand('doctrine_kv', $this->createMock(Connection::class)));
        $tester->execute([]);

        self::assertStringContainsString('require a Doctrine memory driver', $tester->getDisplay());
    }

    public function test_a_key_shorter_than_the_mask_is_never_printed(): void
    {
        $tester = new CommandTester($this->aboutCommand('ab'));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('connected (key: ****)', $display);
        self::assertStringNotContainsString('****ab', $display);
    }

    public function test_a_key_exactly_at_the_mask_boundary_is_never_printed(): void
    {
        $tester = new CommandTester($this->aboutCommand('abcd1234'));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('connected (key: ****)', $display);
        self::assertStringNotContainsString('1234', $display);
    }

    public function test_a_long_cloud_key_shows_only_its_last_four_characters(): void
    {
        $tester = new CommandTester($this->aboutCommand('sk-live-0123456789wxyz'));
        $tester->execute([]);

        self::assertStringContainsString('****wxyz', $tester->getDisplay());
        self::assertStringNotContainsString('0123456789', $tester->getDisplay());
    }

    public function test_an_absent_job_exits_not_found_not_the_odd_state_code(): void
    {
        $tester = new CommandTester(new JobsStatusCommand(new QueueManager(new ArrayMemory)));
        $exit = $tester->execute(['jobId' => '01HNOSUCHJOB0000000000000']);

        self::assertSame(JobsStatusCommand::STATUS_NOT_FOUND, $exit);
        self::assertNotSame(JobsStatusCommand::STATUS_UNRECOGNISED_STATE, $exit);
    }

    public function test_a_job_stored_in_an_unrecognised_state_exits_unknown(): void
    {
        $memory = new ArrayMemory;
        $memory->set('01HODD', ['status' => 'wedged'], RunAgentMessage::NAMESPACE);

        $tester = new CommandTester(new JobsStatusCommand(new QueueManager($memory)));
        $exit = $tester->execute(['jobId' => '01HODD']);

        self::assertSame(JobsStatusCommand::STATUS_UNRECOGNISED_STATE, $exit);
    }

    public function test_about_names_the_driver_actually_in_use_when_it_is_not_the_configured_one(): void
    {
        $tester = new CommandTester($this->aboutCommand('', 'doctrine', new FileMemory));
        $tester->execute([]);

        self::assertStringContainsString('doctrine requested, FileMemory in use', $tester->getDisplay());
        self::assertStringNotContainsString('doctrine driver', $tester->getDisplay());
    }

    public function test_about_reports_the_plain_driver_name_when_it_did_resolve(): void
    {
        $tester = new CommandTester($this->aboutCommand('', 'file', new FileMemory));
        $tester->execute([]);

        self::assertStringContainsString('Memory:         file driver', $tester->getDisplay());
        self::assertStringNotContainsString('in use', $tester->getDisplay());
    }

    public function test_about_says_so_when_no_driver_was_wired_at_all(): void
    {
        $tester = new CommandTester($this->aboutCommand('', 'doctrine', null));
        $tester->execute([]);

        self::assertStringContainsString('doctrine requested, none wired', $tester->getDisplay());
    }

    public function test_queued_job_timestamps_are_utc_whatever_the_server_timezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $memory = new ArrayMemory;
            $agent = $this->createMock(PhpClawInterface::class);
            $agent->method('send')->willReturn(new AgentResponse('ok', 'openai', 'gpt-4o', 1));

            (new RunAgentMessageHandler($agent, $memory))(new RunAgentMessage('01HJOB', 'hi'));

            $stored = $memory->get('01HJOB', RunAgentMessage::NAMESPACE);

            self::assertIsArray($stored);
            self::assertStringEndsWith('+00:00', (string) $stored['at']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    private function aboutCommand(string $cloudKey, string $memDriver = 'doctrine', ?MemoryInterface $memory = null): AboutCommand
    {
        $agent = $this->createMock(PhpClawInterface::class);
        $agent->method('send')->willReturn(new AgentResponse('ok', 'openai', 'gpt-4o', 1));

        return new AboutCommand($agent, 'openai', 'gpt-4o', false, 5, $memDriver, $cloudKey, $memory);
    }
}
