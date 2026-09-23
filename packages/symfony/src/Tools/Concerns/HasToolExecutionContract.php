<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tools\Concerns;

use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Return the console context this tool reads, or null when none was injected.
     *
     * @return ConsoleContext|null
     */
    abstract protected function consoleContext(): ?ConsoleContext;

    /**
     * Return the identity resolver this tool reads, or null when none was injected.
     *
     * @return SymfonyIdentityResolver|null
     */
    abstract protected function identity(): ?SymfonyIdentityResolver;

    /**
     * Whether the host app requires ROLE_PHPCLAW_CHAT rather than any authenticated user.
     *
     * @return bool
     */
    abstract protected function requiresChatRole(): bool;

    /**
     * Report whether this run is an interactive console command. A queue worker is excluded by
     * the subscriber, so it never reaches this as true.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return $this->consoleContext()?->isConsole() ?? false;
    }

    /**
     * Return a FORBIDDEN envelope when the caller lacks capability, with a distinct code for
     * refusals caused only by the run being queued.
     *
     * @param  string  $subject  What the caller was trying to do, for the message.
     * @return string|null JSON-encoded error envelope, or null when the caller is allowed.
     */
    protected function guardCapability(string $subject): ?string
    {
        if ($this->runningInConsole()) {
            return null;
        }

        if ($this->callerHasCapability($this->requiredCapability())) {
            return null;
        }

        if ($this->refusedOnlyBecauseTheRunIsQueued()) {
            return $this->error(
                'FORBIDDEN_IN_QUEUED_RUN',
                sprintf(
                    'A queued run carries the dispatching user\'s identity but not their roles, so the '
                    .'"%s" role cannot be evaluated and this tool is unavailable asynchronously. Run the '
                    .'same request synchronously to %s.',
                    $this->requiredCapability(),
                    $subject,
                ),
                [
                    'acting_user' => $this->identity()?->actingUserId() ?? '',
                    'remedy' => 'Run this request synchronously rather than through the queue.',
                ],
            );
        }

        return $this->error(
            'FORBIDDEN',
            sprintf(
                'The current user lacks the "%s" capability required to %s.',
                $this->requiredCapability(),
                $subject,
            ),
        );
    }

    /**
     * Whether this refusal is caused by the run being queued rather than by the user lacking the
     * role. True only inside a queued run, and only for a tool gated on a role.
     *
     * @return bool
     */
    private function refusedOnlyBecauseTheRunIsQueued(): bool
    {
        $identity = $this->identity();

        if ($identity === null || ! $identity->isImpersonating()) {
            return false;
        }

        return $this->requiredCapability() === SymfonyIdentityResolver::MANAGE_ALL_ROLE
            || $this->requiresChatRole();
    }

    /**
     * Report whether the caller holds the capability this tool requires.
     *
     * @param  string  $capability  Role name, or the chat sentinel meaning "any authenticated user".
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        $identity = $this->identity();

        if ($identity === null) {
            return false;
        }

        if ($capability === SymfonyIdentityResolver::MANAGE_ALL_ROLE) {
            return $identity->manageAll();
        }

        if ($this->requiresChatRole()) {
            return $identity->hasRole($capability);
        }

        return $identity->actingUserId() !== '';
    }
}
