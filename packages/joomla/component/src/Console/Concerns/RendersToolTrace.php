<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console\Concerns;

use Closure;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints one line per tool call while a console command runs, never the tool result.
 */
trait RendersToolTrace
{
    private ?Closure $toolTrace = null;

    /**
     * Start printing a line before each tool call; a second call keeps the one listener.
     *
     * @param  OutputInterface  $output  Console output for the trace lines.
     * @return void
     */
    private function startToolTrace(OutputInterface $output): void
    {
        if ($this->toolTrace !== null) {
            return;
        }

        $this->toolTrace = function (array $context) use ($output): void {
            $output->writeln('<fg=gray>  > '.$this->summarizeToolCall((string) ($context['tool_name'] ?? '?'), (array) ($context['tool_input'] ?? [])).'</>');
        };
        HookRegistry::on(LifecycleEvent::ToolBefore->value, $this->toolTrace);
    }

    /**
     * Remove this command's trace listener, never one another module added.
     *
     * @return void
     */
    private function stopToolTrace(): void
    {
        if ($this->toolTrace === null) {
            return;
        }

        HookRegistry::off(LifecycleEvent::ToolBefore->value, $this->toolTrace);
        $this->toolTrace = null;
    }

    /**
     * The tool name plus a short path or command for the file and shell tools, cut to $maxLength characters.
     *
     * @param  string  $toolName  Name of the tool being called.
     * @param  array<string, mixed>  $toolInput  Arguments the model passed to the tool.
     * @param  int  $maxLength  Longest summary kept before it is cut.
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
