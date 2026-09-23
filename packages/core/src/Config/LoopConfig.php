<?php

declare(strict_types=1);

namespace PhpClaw\Config;

/**
 * ReAct loop, retry, and history-window limits for ClawConfig.
 */
final class LoopConfig
{
    public const DEFAULT_MAX_ITERATIONS = 20;

    public const DEFAULT_MAX_TOOL_RESULT_TOKENS = 2500;

    public readonly int $maxIterations;

    public readonly int $maxRetries;

    public readonly int $maxHistoryLength;

    public readonly int $maxHistoryTokens;

    public readonly int $maxToolsPerTurn;

    public readonly int $maxToolResultTokens;

    /**
     * Group and validate the runtime limits.
     *
     * @param  int  $maxIterations  ReAct loop cap; values <=0 fall back to DEFAULT_MAX_ITERATIONS.
     * @param  int  $maxRetries  Retry attempts for transient provider failures; clamped to >=0.
     * @param  int  $maxHistoryLength  Fire context.overflow when history exceeds this length; 0 disables the check.
     * @param  int  $maxHistoryTokens  Estimated-token ceiling that triggers history compaction; 0 = count-based only.
     * @param  int  $maxToolsPerTurn  Per-turn tool-schema cap; 0 lets ToolRouter derive a limit from the model id.
     * @param  int  $maxToolResultTokens  Estimated-token ceiling on a single tool result before it is cut; 0 disables the cut.
     * @return void
     */
    public function __construct(
        int $maxIterations = self::DEFAULT_MAX_ITERATIONS,
        int $maxRetries = 0,
        int $maxHistoryLength = 0,
        int $maxHistoryTokens = 0,
        int $maxToolsPerTurn = 0,
        int $maxToolResultTokens = self::DEFAULT_MAX_TOOL_RESULT_TOKENS,
    ) {
        $this->maxIterations = $maxIterations > 0 ? $maxIterations : self::DEFAULT_MAX_ITERATIONS;
        $this->maxRetries = max(0, $maxRetries);
        $this->maxHistoryLength = max(0, $maxHistoryLength);
        $this->maxHistoryTokens = max(0, $maxHistoryTokens);
        $this->maxToolsPerTurn = max(0, $maxToolsPerTurn);
        $this->maxToolResultTokens = max(0, $maxToolResultTokens);
    }
}
