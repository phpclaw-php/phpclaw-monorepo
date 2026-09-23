<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

use PhpClaw\PrestaShop\PsIdentityResolver;

/**
 * Shared API authentication for phpClaw front controllers: a per-employee Bearer token or a PS session.
 * Both branches run the same capability check, so a token never outranks the employee it belongs to.
 */
final class PsApiAuthenticator
{
    private const SAME_ORIGIN_FETCH_SITES = ['same-origin', 'same-site', 'none'];

    /**
     * Create a new PsApiAuthenticator.
     *
     * @param  PsApiToken|null  $tokens  Token store; resolved from PrestaShop when omitted.
     * @return void
     */
    public function __construct(private readonly ?PsApiToken $tokens = null) {}

    /**
     * Return true if the current request carries valid credentials.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->isTokenAuthenticated() || $this->isEmployeeSession();
    }

    /**
     * Return true when the request carries a Bearer token resolving to an active employee with the chat grant.
     *
     * @return bool
     */
    public function isTokenAuthenticated(): bool
    {
        $presented = $this->presentedBearerToken();

        if ($presented === '') {
            return false;
        }

        $store = $this->tokens ?? PsApiToken::fromPrestaShop();

        if ($store === null) {
            return false;
        }

        $employeeId = $store->resolveEmployeeId($presented);

        if ($employeeId <= 0) {
            return false;
        }

        $employee = $this->loadActiveEmployee($employeeId);

        if ($employee === null) {
            return false;
        }

        PsIdentityResolver::bindEmployee($employee);

        if (! PsIdentityResolver::canUseChat()) {
            PsIdentityResolver::unbindEmployee();

            return false;
        }

        $store->touch($presented);

        return true;
    }

    /**
     * Extract the presented Bearer token, never a `?api_token=` query value or a session cookie.
     *
     * @return string Empty string when no Bearer header is present.
     */
    private function presentedBearerToken(): string
    {
        $bearerHeader = $this->authorizationHeader();

        if (! str_starts_with($bearerHeader, 'Bearer ')) {
            return '';
        }

        return trim(substr($bearerHeader, 7));
    }

    /**
     * Load an employee by id, requiring a loaded and active record.
     *
     * @param  int  $employeeId  Employee id resolved from the token.
     * @return \Employee|null Null when the employee is missing or deactivated.
     */
    private function loadActiveEmployee(int $employeeId): ?\Employee
    {
        if (! class_exists(\Employee::class)) {
            return null;
        }

        $employee = new \Employee($employeeId);

        if ((int) $employee->id <= 0) {
            return null;
        }

        if (class_exists(\Validate::class) && ! \Validate::isLoadedObject($employee)) {
            return null;
        }

        return ((bool) $employee->active) ? $employee : null;
    }

    /**
     * Read the Authorization header, including the CGI/FastCGI `REDIRECT_` variant.
     *
     * @return string
     */
    private function authorizationHeader(): string
    {
        return trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')) ?: trim((string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    /**
     * Return true when the request carries an active PS employee session that is also authorised to use chat.
     *
     * @return bool
     */
    private function isEmployeeSession(): bool
    {
        if (! $this->isSameOriginRequest()) {
            return false;
        }

        return PsIdentityResolver::canUseChat();
    }

    /**
     * Whether the request is provably same-origin, so a session cookie cannot be ridden by a CSRF page.
     *
     * @return bool False whenever same-origin cannot be proven, including when no origin headers are sent.
     */
    private function isSameOriginRequest(): bool
    {
        $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));

        if ($site !== '') {
            return in_array($site, self::SAME_ORIGIN_FETCH_SITES, true);
        }

        return $this->originHeaderMatchesHost();
    }

    /**
     * Compare the Origin, then Referer, host against the host serving this request.
     *
     * @return bool
     */
    private function originHeaderMatchesHost(): bool
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));

        if ($host === '') {
            return false;
        }

        $value = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

        if ($value === '') {
            $value = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        }

        $originHost = (string) parse_url($value, PHP_URL_HOST);

        if ($originHost === '') {
            return false;
        }

        $port = parse_url($value, PHP_URL_PORT);
        $originAuthority = strtolower($originHost.($port !== null ? ':'.$port : ''));

        return $originAuthority === $host || $originHost === explode(':', $host)[0];
    }
}
