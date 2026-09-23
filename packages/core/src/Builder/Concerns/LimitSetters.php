<?php

declare(strict_types=1);

namespace PhpClaw\Builder\Concerns;

use PhpClaw\Config\LoopConfig;

/**
 * ReAct loop, retry, and history-window setters for ClawBuilder.
 */
trait LimitSetters
{
    private int $maxIterations = LoopConfig::DEFAULT_MAX_ITERATIONS;

    private int $maxRetries = 0;

    private int $maxHistoryLength = 0;

    private int $maxToolsPerTurn = 0;

    private int $maxHistoryTokens = 0;

    private int $maxToolResultTokens = LoopConfig::DEFAULT_MAX_TOOL_RESULT_TOKENS;

    /**
     * ReAct loop cap. Default 20.
     *
     * @param  int  $count  Maximum iterations before MaxIterationsException is thrown.
     * @return static Builder instance for fluent chaining.
     */
    public function maxIterations(int $count): static
    {
        $this->maxIterations = $count;

        return $this;
    }

    /**
     * Retry transient provider failures up to N times. Default 0.
     *
     * @param  int  $count  Number of retry attempts after a retryable provider failure.
     * @return static Builder instance for fluent chaining.
     */
    public function maxRetries(int $count): static
    {
        $this->maxRetries = $count;

        return $this;
    }

    /**
     * Fire context.overflow when history exceeds this length. 0 = disabled.
     *
     * @param  int  $length  Maximum message count in history before the overflow hook fires; 0 disables the check.
     * @return static Builder instance for fluent chaining.
     */
    public function maxHistoryLength(int $length): static
    {
        $this->maxHistoryLength = $length;

        return $this;
    }

    /**
     * Maximum tool schemas sent to the LLM per iteration. 0 = auto by model.
     *
     * @param  int  $limit  Per-turn tool cap; 0 lets ToolRouter pick a limit from the model id.
     * @return static Builder instance for fluent chaining.
     */
    public function maxToolsPerTurn(int $limit): static
    {
        $this->maxToolsPerTurn = max(0, $limit);

        return $this;
    }

    /**
     * Token-based history compaction ceiling (estimated as chars/4). 0 = count-based only.
     *
     * @param  int  $tokens  Estimated-token ceiling that triggers compaction; clamped to >=0.
     * @return static Builder instance for fluent chaining.
     */
    public function maxHistoryTokens(int $tokens): static
    {
        $this->maxHistoryTokens = max(0, $tokens);

        return $this;
    }

    /**
     * Ceiling on a single tool result (estimated as chars/4) before it is cut and marked. 0 disables the cut.
     *
     * @param  int  $tokens  Estimated-token ceiling; clamped to >=0.
     * @return static Builder instance for fluent chaining.
     */
    public function maxToolResultTokens(int $tokens): static
    {
        $this->maxToolResultTokens = max(0, $tokens);

        return $this;
    }
}
