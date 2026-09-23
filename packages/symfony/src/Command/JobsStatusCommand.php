<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Symfony\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the current status of a queued phpClaw job; exit code reflects job state.
 */
#[AsCommand(
    name: 'phpclaw:jobs:status',
    description: 'Show the status of a queued phpClaw job.',
)]
final class JobsStatusCommand extends Command
{
    public const STATUS_DONE = 0;

    public const STATUS_PENDING = 1;

    public const STATUS_FAILED = 2;

    public const STATUS_NOT_FOUND = 3;

    public const STATUS_UNRECOGNISED_STATE = 4;

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
     * Declares the jobId argument.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED, 'ULID returned by QueueManager::dispatchSend().');
    }

    /**
     * Polls the job result and exits with the appropriate status code.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobId = (string) $input->getArgument('jobId');
        $result = $this->queue->pollResult($jobId);

        if ($result === null) {
            $all = $this->queue->listJobs();
            if (! array_key_exists($jobId, $all)) {
                $io->warning("Unknown job '{$jobId}' not found in memory.");

                return self::STATUS_NOT_FOUND;
            }

            $io->warning("Job '{$jobId}' is pending.");

            return self::STATUS_PENDING;
        }

        $status = (string) ($result['status'] ?? 'unknown');

        if ($status === 'failed') {
            $io->error("Job '{$jobId}' failed: ".(string) ($result['error'] ?? 'unknown'));

            return self::STATUS_FAILED;
        }

        if ($status !== 'done') {
            $io->warning("Job '{$jobId}' has unexpected status '{$status}'.");

            return self::STATUS_UNRECOGNISED_STATE;
        }

        $io->success("Job '{$jobId}' is done.");
        if (isset($result['text'])) {
            $io->writeln((string) $result['text']);
        }

        return self::STATUS_DONE;
    }
}
