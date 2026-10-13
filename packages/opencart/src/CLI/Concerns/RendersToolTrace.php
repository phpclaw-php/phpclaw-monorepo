<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\CLI\Concerns;

use Closure;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Prints one line per tool call while a CLI run is traced.
 */
trait RendersToolTrace
{
    private ?Closure $toolTrace = null;

    /**
     * Start printing one line per tool call; a trace already running is kept.
     *
     * @param  callable(string): void  $line  Prints one trace line.
     * @return void
     */
    private function startToolTrace(callable $line): void
    {
        if ($this->toolTrace !== null) {
            return;
        }

        $this->toolTrace = function (array $context) use ($line): void {
            $line('  > '.$this->summarizeToolCall((string) ($context['tool_name'] ?? '?'), (array) ($context['tool_input'] ?? [])));
        };
        HookRegistry::on(LifecycleEvent::ToolBefore->value, $this->toolTrace);
    }

    /**
     * Stop printing tool calls by removing only this command's listener, never one another plugin added.
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
     * One line for a tool call: the tool name plus a short path or command for the tools that have one; never a result.
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
