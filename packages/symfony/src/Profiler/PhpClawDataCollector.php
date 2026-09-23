<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Profiler;

use PhpClaw\Symfony\Events\PhpClawEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Symfony Web Profiler data collector recording phpClaw agent, tool, and guard events.
 */
final class PhpClawDataCollector extends DataCollector implements EventSubscriberInterface
{
    private array $agentRuns = [];

    private array $toolCalls = [];

    private array $guardBlocks = [];

    /**
     * Snapshots buffered events into $this->data for profiler storage.
     *
     * @param  Request  $request
     * @param  Response  $response
     * @param  ?\Throwable  $exception
     * @return void
     */
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'agent_runs' => $this->agentRuns,
            'tool_calls' => $this->toolCalls,
            'guard_blocks' => $this->guardBlocks,
        ];
    }

    /**
     * Returns the profiler panel identifier.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'phpclaw';
    }

    /**
     * Clears all buffered events and stored data.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->agentRuns = [];
        $this->toolCalls = [];
        $this->guardBlocks = [];
        $this->data = [];
    }

    /**
     * Returns all agent run payloads collected during the request.
     *
     * @return list<array<string, mixed>>
     */
    public function getAgentRuns(): array
    {
        return $this->data['agent_runs'] ?? [];
    }

    /**
     * Returns all tool call payloads collected during the request.
     *
     * @return list<array<string, mixed>>
     */
    public function getToolCalls(): array
    {
        return $this->data['tool_calls'] ?? [];
    }

    /**
     * Returns all guard block payloads collected during the request.
     *
     * @return list<array<string, mixed>>
     */
    public function getGuardBlocks(): array
    {
        return $this->data['guard_blocks'] ?? [];
    }

    /**
     * Returns the total number of agent runs collected.
     *
     * @return int
     */
    public function getRunCount(): int
    {
        return count($this->getAgentRuns());
    }

    /**
     * Returns the total token count across all collected agent runs.
     *
     * @return int
     */
    public function getTotalTokens(): int
    {
        return (int) array_sum(array_column($this->getAgentRuns(), 'total_tokens'));
    }

    /**
     * Returns the total wall-clock duration across all collected agent runs.
     *
     * @return int
     */
    public function getTotalDurationMs(): int
    {
        return (int) array_sum(array_column($this->getAgentRuns(), 'duration_ms'));
    }

    /**
     * Returns the Symfony event names this collector subscribes to.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'phpclaw.agent.after' => 'onAgentAfter',
            'phpclaw.tool.after' => 'onToolAfter',
            'phpclaw.guard.blocked' => 'onGuardBlocked',
        ];
    }

    /**
     * Records an agent run payload from the phpclaw.agent.after event.
     *
     * @param  PhpClawEvent  $event
     * @return void
     */
    public function onAgentAfter(PhpClawEvent $event): void
    {
        $ctx = $event->context;

        $inputTokens = (int) ($ctx['input_tokens'] ?? 0);
        $outputTokens = (int) ($ctx['output_tokens'] ?? 0);

        $this->agentRuns[] = [
            'provider' => $ctx['provider'] ?? null,
            'model' => $ctx['model'] ?? null,
            'iterations' => $ctx['iterations'] ?? null,
            'duration_ms' => $ctx['duration_ms'] ?? null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'tools_called' => $ctx['tools_called'] ?? [],
        ];
    }

    /**
     * Records a tool call payload from the phpclaw.tool.after event.
     *
     * @param  PhpClawEvent  $event
     * @return void
     */
    public function onToolAfter(PhpClawEvent $event): void
    {
        $ctx = $event->context;

        $this->toolCalls[] = [
            'tool_name' => $ctx['tool_name'] ?? null,
            'iteration' => $ctx['iteration'] ?? null,
            'tool_input' => $this->redactToolInput($ctx['tool_input'] ?? null),
        ];
    }

    /**
     * Records a guard block payload from the phpclaw.guard.blocked event.
     *
     * @param  PhpClawEvent  $event
     * @return void
     */
    public function onGuardBlocked(PhpClawEvent $event): void
    {
        $this->guardBlocks[] = [
            'reason' => $event->context['reason'] ?? null,
        ];
    }

    /**
     * Redact secret-looking values from captured tool input before it is stored for the profiler.
     *
     * @param  mixed  $input  Raw tool input from the lifecycle event.
     * @return mixed The input with secret-pattern substrings masked.
     */
    private function redactToolInput(mixed $input): mixed
    {
        if (is_array($input)) {
            return array_map(fn (mixed $v): mixed => $this->redactToolInput($v), $input);
        }

        if (! is_string($input)) {
            return $input;
        }

        $patterns = [
            '/\bsk-[A-Za-z0-9_-]{8,}/',
            '/\bBearer\s+[A-Za-z0-9._-]{8,}/i',
            '/([A-Za-z0-9_]*(?:key|token|secret|password|passwd)[A-Za-z0-9_]*\s*[=:]\s*)\S+/i',
        ];
        $replacements = [
            '[redacted]',
            'Bearer [redacted]',
            '$1[redacted]',
        ];

        return (string) preg_replace($patterns, $replacements, $input);
    }
}
