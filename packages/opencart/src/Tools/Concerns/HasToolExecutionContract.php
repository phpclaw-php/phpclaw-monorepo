<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools\Concerns;

use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Report whether this request runs through the CLI entry point.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return defined('PHPCLAW_OC_CONSOLE') && constant('PHPCLAW_OC_CONSOLE') === true;
    }

    /**
     * Report whether the acting user holds the phpClaw module grant.
     *
     * @param  string  $capability  OpenCart action name, always 'access' after flattening.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        return $this->callerMayUseModule;
    }
}
