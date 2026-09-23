<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools\Concerns;

use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Report whether this request runs through the console.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return DrupalConsole::isActive();
    }

    /**
     * Report whether the current account holds a permission.
     *
     * @param  string  $capability  Drupal permission string, for example "use phpclaw chat".
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        try {
            return (bool) \Drupal::currentUser()->hasPermission($capability);
        } catch (\Throwable) {
            return false;
        }
    }
}
