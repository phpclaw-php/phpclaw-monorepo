<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Resolves the acting admin identity and whether it may reach every user's conversations. The
 * backend session is injected as a Proxy so console runs never start it.
 */
// non-final: Magento interceptor required
class IdentityResolver
{
    public const MANAGE_ALL_RESOURCE = 'PhpClaw_Magento::phpclaw_settings';

    /**
     * Bind the application state and admin session this resolver reads identity from.
     *
     * @param  State  $appState  Application state, read to tell an HTTP request from a console run.
     * @param  Session  $authSession  Backend auth session, injected as a proxy so console runs never start it.
     * @param  AuthorizationInterface  $authorization  ACL checker for the manage-all resource.
     * @param  UserContextInterface  $userContext  Web API user context, used when there is no backend session.
     * @return void
     */
    public function __construct(
        private readonly State $appState,
        private readonly Session $authSession,
        private readonly AuthorizationInterface $authorization,
        private readonly UserContextInterface $userContext,
    ) {}

    /**
     * Whether this run is a console command rather than an HTTP request.
     *
     * @return bool
     */
    public function runningInConsole(): bool
    {
        return ! $this->isHttpArea();
    }

    /**
     * Admin user ID the current request acts as, or 0 when there is no admin identity.
     *
     * @return int
     */
    public function actingUserId(): int
    {
        if (! $this->isHttpArea()) {
            return 0;
        }

        try {
            $user = $this->authSession->getUser();
            if ($user !== null && (int) $user->getId() > 0) {
                return (int) $user->getId();
            }
        } catch (\Throwable) {
        }

        try {
            if ($this->userContext->getUserType() === UserContextInterface::USER_TYPE_ADMIN) {
                return (int) $this->userContext->getUserId();
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    /**
     * Whether the acting identity may read and write every user's conversations.
     *
     * @return bool
     */
    public function manageAll(): bool
    {
        if (! $this->isHttpArea()) {
            return false;
        }

        try {
            return $this->authorization->isAllowed(self::MANAGE_ALL_RESOURCE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the current run serves an admin or Web API request rather than console or cron.
     *
     * @return bool
     */
    private function isHttpArea(): bool
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException) {
            return false;
        }

        return $area === Area::AREA_ADMINHTML || $area === Area::AREA_WEBAPI_REST;
    }
}
