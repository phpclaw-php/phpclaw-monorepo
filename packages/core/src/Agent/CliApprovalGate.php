<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Agent\Contracts\ApprovalGateInterface;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\PerInvocationMutabilityInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * CLI Y/n approval gate: read tools pass silently, mutating tools wait for STDIN confirmation (fail-closed with no tty).
 */
final class CliApprovalGate implements ApprovalGateInterface
{
    /**
     * Approve or deny a pending tool call, non-mutating tools pass silently; mutating tools require STDIN confirmation.
     *
     * @param  string  $toolName  Tool about to execute.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @param  ToolInterface|null  $tool  The resolved tool instance, or null if not found.
     * @return void Returning normally means approved.
     *
     * @throws HumanDeniedException When denied: a non-interactive context or a declined prompt.
     */
    public function check(string $toolName, array $toolInput, ?ToolInterface $tool = null): void
    {
        if ($tool instanceof PerInvocationMutabilityInterface) {
            if (! $tool->isMutating($toolInput)) {
                return;
            }
        } elseif (! $tool instanceof MutatingToolInterface) {
            return;
        }

        if (! $this->isInteractive()) {
            throw new HumanDeniedException($toolName, $toolInput);
        }

        $this->prompt($toolName, $toolInput);
    }

    /**
     * Whether a STDIN tty is present to read an answer from.
     *
     * @return bool True when STDIN is an interactive terminal.
     */
    private function isInteractive(): bool
    {
        return defined('STDIN')
            && is_resource(STDIN)
            && function_exists('stream_isatty')
            && @stream_isatty(STDIN);
    }

    /**
     * Print the pending action and read a Y/n answer, denying on 'n'.
     *
     * @param  string  $toolName  Tool awaiting approval.
     * @param  array<string, mixed>  $toolInput  Input the model supplied.
     * @return void
     *
     * @throws HumanDeniedException When the operator answers no.
     */
    private function prompt(string $toolName, array $toolInput): void
    {
        $line = str_repeat('-', 60);
        echo "\n{$line}\n";
        echo "[phpClaw] Action requires approval\n";
        echo "  Tool   : {$toolName}\n";
        echo '  Action : '.$this->describe($toolName, $toolInput)."\n";
        echo "{$line}\n";
        echo 'Allow? [Y/n]: ';

        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer === 'n' || $answer === 'no') {
            echo "Denied.\n\n";
            throw new HumanDeniedException($toolName, $toolInput);
        }

        echo "Approved.\n\n";
    }

    /**
     * Render a one-line human description of the pending action.
     *
     * @param  string  $toolName  Tool awaiting approval.
     * @param  array<string, mixed>  $in  Input the model supplied.
     * @return string Single-line action summary.
     */
    private function describe(string $toolName, array $in): string
    {
        return match ($toolName) {
            'file_write' => 'Write file: '.(string) ($in['path'] ?? $in['file'] ?? '?')
                           .' ('.strlen((string) ($in['content'] ?? '')).' bytes)',
            'file_edit' => 'Edit file: '.(string) ($in['file'] ?? '?')
                           ."\n            OLD: ".mb_substr((string) ($in['old_str'] ?? ''), 0, 80)
                           ."\n            NEW: ".mb_substr((string) ($in['new_str'] ?? ''), 0, 80),
            'shell_exec' => 'Run: '.(string) ($in['command'] ?? '?'),
            'zip_package' => 'Zip '.(string) ($in['source_dir'] ?? '?')
                           .' -> '.(string) ($in['output_name'] ?? '?').'.zip',
            default => (string) json_encode($in),
        };
    }
}
