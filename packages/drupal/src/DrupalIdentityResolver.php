<?php

declare(strict_types=1);

namespace PhpClaw\Drupal;

/**
 * Resolves the acting identity from Drupal's own session, never from a request parameter.
 */
final class DrupalIdentityResolver
{
    /**
     * The logged-in user ID, or the `0` "no logged-in user" sentinel.
     *
     * @return int
     */
    public static function actingUserId(): int
    {
        if (DrupalConsole::isActive()) {
            return 0;
        }

        try {
            return (int) \Drupal::currentUser()->id();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Whether the acting user holds the Drupal permission to reach every user's conversations.
     *
     * @return bool
     */
    public static function manageAll(): bool
    {
        try {
            return (bool) \Drupal::currentUser()->hasPermission('manage all phpclaw conversations');
        } catch (\Throwable) {
            return false;
        }
    }
}
