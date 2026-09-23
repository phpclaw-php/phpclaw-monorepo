<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Laravel\Enums\JobStatus;
use PhpClaw\Laravel\QueueManager;

/**
 * Artisan command `php artisan phpclaw:jobs:status {jobId}`, prints a queued job's status (exit 0 done, 2 failed, 1/3 pending/unknown).
 */
final class JobsStatusCommand extends Command
{
    protected $signature = 'phpclaw:jobs:status
        {jobId : ULID returned by QueueManager::dispatchSend()}';

    protected $description = 'Show the status and result of a queued phpClaw agent job';

    /**
     * Print and exit-code the status of a single queued job.
     *
     * @param  QueueManager  $queue  The resolved queue manager.
     * @return int Artisan exit code (0 done, 2 failed, 1/3 pending/unknown).
     */
    public function handle(QueueManager $queue): int
    {
        $jobId = (string) $this->argument('jobId');

        $result = $queue->pollResult($jobId);

        if ($result === null) {
            $this->warn("Job '{$jobId}' is pending or unknown, no result stored yet.");

            return 1;
        }

        $status = (string) ($result['status'] ?? 'unknown');

        match ($status) {
            JobStatus::Done->value => $this->printDoneResult($result),
            JobStatus::Failed->value => $this->printFailureResult($result),
            default => $this->error("Unknown status '{$status}' for job '{$jobId}'."),
        };

        return match ($status) {
            JobStatus::Done->value => self::SUCCESS,
            JobStatus::Failed->value => 2,
            default => 3,
        };
    }

    /**
     * Print a completed job's metadata and response text.
     *
     * @param  array<string, mixed>  $result  The stored job result payload.
     * @return void
     */
    private function printDoneResult(array $result): void
    {
        $this->info('Status:    done');
        $this->line('Provider:  '.(string) ($result['provider'] ?? '?'));
        $this->line('Model:     '.(string) ($result['model'] ?? '?'));
        $this->line('Tokens:    '.(string) ($result['tokens'] ?? '?'));
        $this->line('Duration:  '.(string) ($result['duration_ms'] ?? '?').' ms');
        $this->line('At:        '.(string) ($result['at'] ?? '?'));
        $this->line('');
        $this->line('Text:');
        $this->line((string) ($result['text'] ?? ''));
    }

    /**
     * Print a failed job's error and timestamp.
     *
     * @param  array<string, mixed>  $result  The stored job result payload.
     * @return void
     */
    private function printFailureResult(array $result): void
    {
        $this->error('Status:    failed');
        $this->line('Error:     '.(string) ($result['error'] ?? '?'));
        $this->line('At:        '.(string) ($result['at'] ?? '?'));
    }
}
