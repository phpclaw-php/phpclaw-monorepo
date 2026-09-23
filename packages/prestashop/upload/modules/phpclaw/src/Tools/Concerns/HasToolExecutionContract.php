<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tools\Concerns;

use PhpClaw\PrestaShop\PsIdentityResolver;
use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract, which lives in core. This trait
 * supplies only what core cannot know: console recognition and back-office tab access.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Report whether this request runs through the phpClaw CLI entry point. Narrower than
     * PHP_SAPI === 'cli': a cron or web-server CLI bootstrap never sets this marker.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return defined('PHPCLAW_PS_CONSOLE') && constant('PHPCLAW_PS_CONSOLE') === true;
    }

    /**
     * Report whether the acting employee holds view on the given phpClaw back-office tab.
     *
     * @param  string  $capability  Tab class name, for example AdminPhpClawDebug.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        return PsIdentityResolver::hasTabAccess($capability);
    }
}
