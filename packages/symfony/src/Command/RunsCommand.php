<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\RunState;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Exceptions\RunStateException;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Symfony\PhpClawFactory;
use PhpClaw\Symfony\RunApprovals;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Console command `bin/console phpclaw:runs {action}`: list, approve, deny, resume or resume-due durable runs.
 */
#[AsCommand(
    name: 'phpclaw:runs',
    description: 'List, approve, deny or resume phpClaw durable runs.',
)]
final class RunsCommand extends Command
{
    private const ACTION_LIST = 'list';

    private const ACTION_APPROVE = 'approve';

    private const ACTION_DENY = 'deny';

    private const ACTION_RESUME = 'resume';

    private const ACTION_RESUME_DUE = 'resume-due';

    private const DEFAULT_RESUME_LIMIT = 5;

    /**
     * Bind the durable engine, which always pauses for approval, and the run rules.
     *
     * @param  PhpClawInterface  $engine  The durable engine.
     * @param  RunApprovals  $approvals  Owner lookup and decisions.
     */
    public function __construct(
        #[Autowire(service: PhpClawFactory::DURABLE_ENGINE)]
        private readonly PhpClawInterface $engine,
        private readonly RunApprovals $approvals,
    ) {
        parent::__construct();
    }

    /**
     * Declare the action, the run and call ids, and the deny reason and resume-due limit options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'list, approve, deny, resume or resume-due');
        $this->addArgument('run', InputArgument::OPTIONAL, 'Run id, for approve, deny and resume');
        $this->addArgument('call', InputArgument::OPTIONAL, 'Paused call id, for approve and deny');
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason given with a denial', '');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most runs resume-due finishes in one go', (string) self::DEFAULT_RESUME_LIMIT);
    }

    /**
     * Dispatch the action on the durable engine.
     *
     * @param  InputInterface  $input  Console input.
     * @param  OutputInterface  $output  Console output.
     * @return int Command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! $this->engine instanceof Claw) {
            $io->error('Durable runs are not available on this engine.');

            return self::FAILURE;
        }

        $action = (string) $input->getArgument('action');

        return match ($action) {
            self::ACTION_LIST => $this->listPending($this->engine, $io),
            self::ACTION_RESUME_DUE => $this->resumeDue($this->engine, $input, $io),
            self::ACTION_APPROVE, self::ACTION_DENY, self::ACTION_RESUME => $this->continueRun($this->engine, $action, $input, $io),
            default => $this->fail($io, 'Unknown action. Use list, approve, deny, resume or resume-due.'),
        };
    }

    /**
     * Print every run waiting for a decision, with its owner and the paused call.
     *
     * @param  Claw  $claw  Durable engine.
     * @param  SymfonyStyle  $io  Console style.
     * @return int
     */
    private function listPending(Claw $claw, SymfonyStyle $io): int
    {
        $rows = array_map(function (RunState $state): array {
            $run = $this->approvals->describe($state);

            return [
                $run['run_id'],
                $this->approvals->ownerOf($state) ?? '-',
                $run['pending']['tool_name'] ?? '-',
                $run['pending']['call_id'] ?? '-',
                (string) json_encode($run['pending']['tool_input'] ?? []),
            ];
        }, $claw->pendingApprovals());

        $io->table(['Run', 'Owner', 'Tool', 'Call', 'Input'], $rows);

        return self::SUCCESS;
    }

    /**
     * Finish every due run, each as its owner, and print the counts.
     *
     * @param  Claw  $claw  Durable engine.
     * @param  InputInterface  $input  Console input.
     * @param  SymfonyStyle  $io  Console style.
     * @return int
     */
    private function resumeDue(Claw $claw, InputInterface $input, SymfonyStyle $io): int
    {
        $report = $claw->resumeDue(
            limit: max(1, (int) $input->getOption('limit')),
            around: fn (RunState $state, \Closure $resume): mixed => $this->approvals->asOwner($state, $resume),
        );

        $io->writeln(sprintf('completed=%d suspended=%d failed=%d skipped=%d', $report->completed, $report->suspended, $report->failed, $report->skipped));

        return self::SUCCESS;
    }

    /**
     * Approve, deny or resume one run as its owner and print the outcome.
     *
     * @param  Claw  $claw  Durable engine.
     * @param  string  $action  approve, deny or resume.
     * @param  InputInterface  $input  Console input.
     * @param  SymfonyStyle  $io  Console style.
     * @return int
     */
    private function continueRun(Claw $claw, string $action, InputInterface $input, SymfonyStyle $io): int
    {
        $runId = (string) $input->getArgument('run');
        $callId = (string) $input->getArgument('call');

        if ($runId === '' || ($action !== self::ACTION_RESUME && $callId === '')) {
            return $this->fail($io, 'Give the run id, and the call id for approve or deny.');
        }

        try {
            $state = $this->approvals->load($claw, $runId);
            $response = $this->approvals->asOwner($state, fn (): AgentResponse => match ($action) {
                self::ACTION_RESUME => $claw->resume($runId),
                self::ACTION_DENY => $this->approvals->decide($claw, $state, $callId, (string) $input->getOption('reason')),
                default => $this->approvals->decide($claw, $state, $callId, denyReason: null),
            });
        } catch (RunSuspendedException $e) {
            $io->warning("Run {$e->runId} stopped again: {$e->status->value}.");

            return self::SUCCESS;
        } catch (RunStateException) {
            return $this->fail($io, 'No saved run with that id, or no paused call with that id waiting for a decision.');
        } catch (RunConflictException) {
            return $this->fail($io, 'Another process changed this run first. Try again.');
        }

        $io->writeln($response->text);

        return self::SUCCESS;
    }

    /**
     * Print an error and return the failure code.
     *
     * @param  SymfonyStyle  $io  Console style.
     * @param  string  $message  Error shown to the operator.
     * @return int
     */
    private function fail(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return self::FAILURE;
    }
}
