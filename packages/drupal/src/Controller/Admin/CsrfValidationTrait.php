<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a shared CSRF token validation helper for phpClaw admin controllers.
 */
trait CsrfValidationTrait
{
    /**
     * Validate the CSRF token from the X-CSRF-Token request header.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return bool True when the token is valid, false otherwise.
     */
    private function validateCsrfToken(Request $request): bool
    {
        $token = (string) $request->headers->get('X-CSRF-Token', '');

        return $this->csrfToken?->validate($token, 'phpclaw-chat') ?? false;
    }
}
