<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a run's accumulated provider token spend would exceed the configured budget.
 */
final class TokenBudgetExceededException extends PhpClawException
{
    public readonly int $tokensSpent;

    public readonly int $budget;

    /**
     * Create a new TokenBudgetExceededException instance.
     *
     * @param  int  $tokensSpent  Tokens spent so far this run, before the blocked call.
     * @param  int  $budget  Configured token budget that was reached.
     * @return void
     */
    public function __construct(int $tokensSpent, int $budget)
    {
        $this->tokensSpent = $tokensSpent;
        $this->budget = $budget;
        parent::__construct("Token budget exceeded: {$tokensSpent} tokens spent against a budget of {$budget}.");
    }
}
