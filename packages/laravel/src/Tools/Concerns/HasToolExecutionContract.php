<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools\Concerns;

use Illuminate\Support\Facades\Gate;
use PhpClaw\Laravel\LaravelConsole;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Report whether this request runs through an interactive artisan entrypoint. A queue
     * worker is excluded: it shares the CLI SAPI but runs work a web user queued.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        try {
            return LaravelConsole::isInteractive(app());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Report whether the caller holds the capability this tool requires. When the host app has
     * defined the ability, its answer is final; otherwise any authenticated user is allowed.
     *
     * @param  string  $capability  Gate ability name.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        if ($capability === LaravelIdentityResolver::MANAGE_ALL_ABILITY) {
            return LaravelIdentityResolver::manageAll();
        }

        try {
            if (Gate::has($capability)) {
                return (bool) Gate::allows($capability);
            }
        } catch (\Throwable) {
            return false;
        }

        return LaravelIdentityResolver::actingUserId() !== '';
    }
}
