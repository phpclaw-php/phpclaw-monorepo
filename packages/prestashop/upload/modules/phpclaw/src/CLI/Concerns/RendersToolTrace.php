<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\CLI\Concerns;

use Closure;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Prints one line per tool call while a phpClaw CLI run is active.
 */
trait RendersToolTrace
{
    private ?Closure $toolTrace = null;

    /**
     * Start printing one line per tool call; a listener already registered is kept unchanged.
     *
     * @param  callable(string): void  $line  Prints one tool-call line.
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
     * Stop printing tool calls; only this command's listener is removed, never one another caller added.
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
     * One line for a tool call: the tool name plus a short path or command for the tools that carry one; never a result.
     *
     * @param  string  $toolName  Tool being called.
     * @param  array<string, mixed>  $toolInput  Input the model sent.
     * @param  int  $maxLength  Longest label kept before it is trimmed with "...".
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
