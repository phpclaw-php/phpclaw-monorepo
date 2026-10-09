<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command\Concerns;

use Closure;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints one line per tool call while a console run is traced; refuses to trace a project workspace in prod.
 *
 * The using command holds `$environment`, `$workspaceRoot` and `$projectDir` (kernel and phpclaw settings).
 */
trait RendersToolTrace
{
    private ?Closure $toolTrace = null;

    /**
     * Start printing tool calls, unless the workspace reaches beyond var/phpclaw and the kernel runs in prod.
     *
     * @param  SymfonyStyle  $io  Console style the trace lines go to.
     * @return bool False when the session is refused; the reason is printed.
     */
    private function startToolTrace(SymfonyStyle $io): bool
    {
        if ($this->isProjectWorkspaceInProduction()) {
            $io->error('Refused: the workspace is outside var/phpclaw and the kernel environment is prod.');

            return false;
        }

        if ($this->toolTrace === null) {
            $this->toolTrace = function (array $context) use ($io): void {
                $io->writeln('<fg=gray>  > '.$this->summarizeToolCall((string) ($context['tool_name'] ?? '?'), (array) ($context['tool_input'] ?? [])).'</>');
            };
            HookRegistry::on(LifecycleEvent::ToolBefore->value, $this->toolTrace);
        }

        return true;
    }

    /**
     * Stop printing tool calls by removing only this command's listener, never the host application's.
     *
     * @return void
     */
    private function stopToolTrace(): void
    {
        if ($this->toolTrace !== null) {
            HookRegistry::off(LifecycleEvent::ToolBefore->value, $this->toolTrace);
            $this->toolTrace = null;
        }
    }

    /**
     * Whether the file tools reach beyond var/phpclaw while the kernel runs in prod.
     *
     * @return bool
     */
    private function isProjectWorkspaceInProduction(): bool
    {
        if (! in_array($this->environment, ['prod', 'production'], true) || $this->workspaceRoot === '') {
            return false;
        }

        $scratchPath = rtrim($this->projectDir, '/').'/var/phpclaw';
        $scratch = rtrim(realpath($scratchPath) ?: $scratchPath, '/');
        $root = rtrim(realpath($this->workspaceRoot) ?: $this->workspaceRoot, '/');

        return $root !== $scratch && ! str_starts_with($root.'/', $scratch.'/');
    }

    /**
     * One line for a tool call: the tool name, plus a short path or command for the tools that have one; never a result.
     *
     * @param  string  $toolName  Tool being called.
     * @param  array<string, mixed>  $toolInput  Input the model sent.
     * @param  int  $maxLength  Longest summary kept before it is cut and marked with "...".
     * @return string
     */
    private function summarizeToolCall(string $toolName, array $toolInput, int $maxLength = 80): string
    {
        $summary = match ($toolName) {
            'file_write' => (string) ($toolInput['path'] ?? $toolInput['file'] ?? '?').' ('.strlen((string) ($toolInput['content'] ?? '')).' bytes)',
            'file_edit', 'file_read' => (string) ($toolInput['file'] ?? $toolInput['path'] ?? '?'),
            'shell_exec' => (string) ($toolInput['command'] ?? '?'),
            default => '',
        };

        $summary = str_replace(["\r", "\n"], ' ', $summary);

        if (mb_strlen($summary) > $maxLength) {
            $summary = mb_substr($summary, 0, $maxLength).'...';
        }

        return $summary === '' ? $toolName : $toolName.': '.$summary;
    }
}
