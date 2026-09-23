<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Laravel\QueueManager;

/**
 * Artisan command `php artisan phpclaw:jobs:list`, prints a table of all stored phpClaw agent job results.
 */
final class JobsListCommand extends Command
{
    protected $signature = 'phpclaw:jobs:list';

    protected $description = 'List all stored phpClaw agent job results';

    /**
     * Print every stored job result as a table.
     *
     * @param  QueueManager  $queue  The resolved queue manager.
     * @return int Artisan exit code.
     */
    public function handle(QueueManager $queue): int
    {
        $jobs = $queue->listJobs();

        if ($jobs === []) {
            $this->info('No job results stored yet.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($jobs as $jobId => $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            $rows[] = [
                $jobId,
                $status,
                (string) ($result['provider'] ?? '-'),
                (string) ($result['model'] ?? '-'),
                (string) ($result['at'] ?? '-'),
            ];
        }

        $this->table(
            headers: ['Job ID', 'Status', 'Provider', 'Model', 'At'],
            rows: $rows,
        );

        return self::SUCCESS;
    }
}
