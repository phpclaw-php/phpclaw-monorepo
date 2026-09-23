<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Tools\Concerns;

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract, which lives in core. This trait
 * supplies only what core cannot know: console recognition and ACL action testing.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Return the asset the required action is checked against. Authorisation here is
     * an action plus an asset, and the core contract asks only for the action.
     *
     * @return string
     */
    abstract protected function requiredAsset(): string;

    /**
     * Report whether this request is running through the console. Narrower than
     * PHP_SAPI === 'cli': a cron bootstrap is CLI, is not a ConsoleApplication, and keeps the guard.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        try {
            return Factory::getApplication() instanceof ConsoleApplication;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Report whether the current identity holds an action on this tool's asset.
     *
     * @param  string  $capability  Joomla ACL action, for example phpclaw.chat.use.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        try {
            return (bool) Factory::getApplication()
                ->getIdentity()
                ?->authorise($capability, $this->requiredAsset());
        } catch (\Throwable) {
            return false;
        }
    }
}
