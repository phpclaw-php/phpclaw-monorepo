<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Symfony\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists all recorded phpClaw queue jobs and their outcomes in a table.
 */
#[AsCommand(
    name: 'phpclaw:jobs:list',
    description: 'List phpClaw queue jobs and their outcomes.',
)]
final class JobsListCommand extends Command
{
    /**
     * Constructs the command with the queue manager.
     *
     * @param  QueueManager  $queue  The queue manager for job result polling.
     */
    public function __construct(
        private readonly QueueManager $queue,
    ) {
        parent::__construct();
    }

    /**
     * Lists all recorded queue jobs and their outcomes in a table.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobs = $this->queue->listJobs();

        if ($jobs === []) {
            $io->info('No jobs recorded yet.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($jobs as $jobId => $result) {
            $error = (string) ($result['error'] ?? '');
            $rows[] = [
                $jobId,
                (string) ($result['status'] ?? 'unknown'),
                (string) ($result['provider'] ?? '-'),
                (string) ($result['tokens'] ?? '-'),
                (string) ($result['at'] ?? '-'),
                mb_strlen($error) > 60 ? mb_substr($error, 0, 57).'...' : $error,
            ];
        }

        $io->table(['Job ID', 'Status', 'Provider', 'Tokens', 'At', 'Error'], $rows);

        return Command::SUCCESS;
    }
}
