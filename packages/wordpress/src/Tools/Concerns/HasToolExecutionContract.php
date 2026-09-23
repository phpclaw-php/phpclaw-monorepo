<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools\Concerns;

use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Report whether this request is running through WP-CLI.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return defined('WP_CLI') && WP_CLI;
    }

    /**
     * Report whether the current user holds a capability.
     *
     * @param  string  $capability  WordPress capability name.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        return current_user_can($capability);
    }
}
