<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\RunState;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\RunApprovals;

/**
 * Artisan command `php artisan phpclaw:runs {action}`: list, approve, deny, resume or resume-due durable runs.
 */
final class RunsCommand extends Command
{
    private const ACTION_LIST = 'list';

    private const ACTION_APPROVE = 'approve';

    private const ACTION_DENY = 'deny';

    private const ACTION_RESUME = 'resume';

    private const ACTION_RESUME_DUE = 'resume-due';

    protected $signature = 'phpclaw:runs
        {action : list, approve, deny, resume or resume-due}
        {run? : Run id, for approve, deny and resume}
        {call? : Paused call id, for approve and deny}
        {--reason= : Reason given with a denial}
        {--limit=5 : Most runs resume-due finishes in one go}';

    protected $description = 'List, approve, deny or resume phpClaw durable runs';

    /**
     * Dispatch the action on the durable engine.
     *
     * @return int Artisan exit code.
     */
    public function handle(): int
    {
        $claw = $this->laravel->make(PhpClawServiceProvider::DURABLE_ENGINE);

        if (! $claw instanceof Claw) {
            $this->error('Durable runs are not available on this engine.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            self::ACTION_LIST => $this->listPending($claw),
            self::ACTION_RESUME_DUE => $this->resumeDue($claw),
            self::ACTION_APPROVE, self::ACTION_DENY, self::ACTION_RESUME => $this->continueRun($claw, (string) $this->argument('action')),
            default => $this->reportFailure('Unknown action. Use list, approve, deny, resume or resume-due.'),
        };
    }

    /**
     * Print every run waiting for a decision, with its owner and the paused call.
     *
     * @param  Claw  $claw  Durable engine.
     * @return int
     */
    private function listPending(Claw $claw): int
    {
        $rows = array_map(static function (RunState $state): array {
            $run = RunApprovals::describe($state);

            return [
                $run['run_id'],
                RunApprovals::ownerOf($state) ?? '-',
                $run['pending']['tool_name'] ?? '-',
                $run['pending']['call_id'] ?? '-',
                (string) json_encode($run['pending']['tool_input'] ?? []),
            ];
        }, $claw->pendingApprovals());

        $this->table(['Run', 'Owner', 'Tool', 'Call', 'Input'], $rows);

        return self::SUCCESS;
    }

    /**
     * Finish every due run, each as its owner, and print the counts.
     *
     * @param  Claw  $claw  Durable engine.
     * @return int
     */
    private function resumeDue(Claw $claw): int
    {
        $report = $claw->resumeDue(
            limit: max(1, (int) $this->option('limit')),
            around: static fn (RunState $state, \Closure $resume): mixed => RunApprovals::asOwner($state, $resume),
        );

        $this->line(sprintf('completed=%d suspended=%d failed=%d skipped=%d', $report->completed, $report->suspended, $report->failed, $report->skipped));

        return self::SUCCESS;
    }

    /**
     * Approve, deny or resume one run as its owner and print the outcome.
     *
     * @param  Claw  $claw  Durable engine.
     * @param  string  $action  approve, deny or resume.
     * @return int
     */
    private function continueRun(Claw $claw, string $action): int
    {
        $runId = (string) $this->argument('run');
        $callId = (string) $this->argument('call');

        if ($runId === '' || ($action !== self::ACTION_RESUME && $callId === '')) {
            return $this->reportFailure('Give the run id, and the call id for approve or deny.');
        }

        try {
            $state = RunApprovals::load($claw, $runId);
            $response = RunApprovals::asOwner($state, fn (): AgentResponse => match ($action) {
                self::ACTION_RESUME => $claw->resume($runId),
                self::ACTION_DENY => RunApprovals::decide($claw, $state, $callId, (string) $this->option('reason')),
                default => RunApprovals::decide($claw, $state, $callId, denyReason: null),
            });
        } catch (RunSuspendedException $e) {
            $this->warn("Run {$e->runId} stopped again: {$e->status->value}.");

            return self::SUCCESS;
        } catch (RunStateException) {
            return $this->reportFailure('No saved run with that id, or no paused call with that id waiting for a decision.');
        } catch (RunConflictException) {
            return $this->reportFailure('Another process changed this run first. Try again.');
        }

        $this->info($response->text);

        return self::SUCCESS;
    }

    /**
     * Print an error and return the failure code.
     *
     * @param  string  $message  Error shown to the operator.
     * @return int
     */
    private function reportFailure(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
