<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools\Concerns;

use Magento\Framework\AuthorizationInterface;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;

/**
 * Adapter binding for the shared tool execution contract.
 */
trait HasToolExecutionContract
{
    use CoreToolExecutionContract;

    /**
     * Return the identity resolver the using class injected.
     *
     * @return IdentityResolver Resolver reporting the area and the acting admin identity.
     */
    abstract protected function identity(): IdentityResolver;

    /**
     * Return the ACL service the using class injected.
     *
     * @return AuthorizationInterface Magento authorization service.
     */
    abstract protected function authorization(): AuthorizationInterface;

    /**
     * Report whether this run is a console command rather than an HTTP request.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return $this->identity()->runningInConsole();
    }

    /**
     * Report whether the acting caller holds an ACL resource.
     *
     * @param  string  $capability  Magento ACL resource id, for example PhpClaw_Magento::phpclaw_chat.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        try {
            return $this->authorization()->isAllowed($capability);
        } catch (\Throwable) {
            return false;
        }
    }
}
