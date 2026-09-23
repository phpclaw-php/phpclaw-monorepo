<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use Illuminate\Support\Facades\DB;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that reports the queue connection and pending / failed job counts (read-only).
 */
final class QueueStatusTool extends AbstractLaravelTool
{
    private const ALLOWED_KEYS = [];

    /**
     * Return the tool identifier used by the agent to invoke this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'queue_status';
    }

    /**
     * Return the human-readable description shown to the LLM for tool selection.
     *
     * @return string
     */
    public function description(): string
    {
        return 'REPORT Laravel queue status: active connection, pending job count, and failed job count. Read-only, never dispatches or modifies jobs.';
    }

    /**
     * Return the JSON Schema describing the tool's accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    /**
     * Return the platform capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::CHAT_ABILITY;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['queues', 'jobs'],
            tags: ['queue', 'queues', 'job', 'jobs', 'worker', 'workers', 'pending', 'failed', 'retry', 'backlog', 'connection', 'horizon'],
            intents: ['show queue status', 'list failed jobs', 'how big is the backlog'],
            examples: ['how many jobs are waiting in the queue'],
        );
    }

    /**
     * Guard the caller and validate input before any query runs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('report the queue status');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Query the queue connection and job table row counts.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{connection: string, pending: int|null, failed: int|null}
     */
    protected function perform(array $input): array
    {
        $connection = config('queue.default', 'sync');

        return [
            'connection' => is_string($connection) ? $connection : 'sync',
            'pending' => $this->countTable('jobs'),
            'failed' => $this->countTable('failed_jobs'),
        ];
    }

    /**
     * Verify the execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the connection field is missing or not a string.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_string($execution['connection'] ?? null)) {
            throw new ToolException('QueueStatusTool: connection field is missing or not a string.');
        }

        return ['result' => null];
    }

    /**
     * Convert a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $warnings = [];

        if ($execution['pending'] === null) {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => 'The "jobs" table was not found, so the pending count is unavailable.',
            ];
        }

        if ($execution['failed'] === null) {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => 'The "failed_jobs" table was not found, so the failed count is unavailable.',
            ];
        }

        return $this->success(
            [
                'connection' => $execution['connection'],
                'pending' => $execution['pending'],
                'failed' => $execution['failed'],
            ],
            [
                'mode' => 'query',
                'count' => 1,
                'total' => 1,
                'truncated' => false,
            ],
            $warnings,
        );
    }

    /**
     * Return row count for a table, or null when the table does not exist.
     *
     * @param  string  $table  The table name to count.
     * @return int|null
     */
    private function countTable(string $table): ?int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
