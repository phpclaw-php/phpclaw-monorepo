<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console\Concerns;

use Closure;
use Illuminate\Console\Command;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Prints one line per tool call while a console run is traced; refuses to trace a project workspace in production.
 *
 * @mixin Command
 */
trait RendersToolTrace
{
    private ?Closure $toolTrace = null;

    /**
     * Start printing tool calls, unless the workspace is the project and the app runs in production.
     *
     * @return bool False when the session is refused; the reason is printed.
     */
    private function startToolTrace(): bool
    {
        if ($this->isProjectWorkspaceInProduction()) {
            $this->error('Refused: the workspace is outside storage/phpclaw and APP_ENV is production.');

            return false;
        }

        if ($this->toolTrace === null) {
            $this->toolTrace = function (array $context): void {
                $this->line('<fg=gray>  > '.$this->summarizeToolCall((string) ($context['tool_name'] ?? '?'), (array) ($context['tool_input'] ?? [])).'</>');
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
     * Whether the file tools reach beyond storage/phpclaw while the app runs in production.
     *
     * @return bool
     */
    private function isProjectWorkspaceInProduction(): bool
    {
        if (! $this->laravel->environment('production', 'prod')) {
            return false;
        }

        $scratch = rtrim(realpath(storage_path('phpclaw')) ?: storage_path('phpclaw'), '/');
        $configured = (string) config('phpclaw.workspace_root', storage_path('phpclaw'));
        $root = rtrim(realpath($configured) ?: $configured, '/');

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
