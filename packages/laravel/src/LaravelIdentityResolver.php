<?php

declare(strict_types=1);

namespace PhpClaw\Laravel;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Resolves the acting user and whether it may reach every user's conversations.
 */
final class LaravelIdentityResolver
{
    public const CHAT_ABILITY = 'phpclaw.chat';

    public const MANAGE_ALL_ABILITY = 'phpclaw.manage-all';

    /**
     * The authenticated user ID as a string, or the empty-string "no logged-in user" sentinel.
     *
     * @return string
     */
    public static function actingUserId(): string
    {
        try {
            $id = Auth::id();
        } catch (\Throwable) {
            return '';
        }

        if (! is_int($id) && ! is_string($id)) {
            return '';
        }

        return trim((string) $id);
    }

    /**
     * Whether the acting user may reach every user's conversations, either by being listed
     * in phpclaw.admin_ids or by passing the manage-all gate.
     *
     * @return bool
     */
    public static function manageAll(): bool
    {
        if (self::isConfiguredAdmin()) {
            return true;
        }

        try {
            return Gate::allows(self::MANAGE_ALL_ABILITY);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the acting user's ID, compared as a string, is listed in the phpclaw.admin_ids allowlist.
     *
     * @return bool
     */
    private static function isConfiguredAdmin(): bool
    {
        $actingUserId = self::actingUserId();

        if ($actingUserId === '') {
            return false;
        }

        $adminIds = array_filter(
            array_map(
                static fn (mixed $id): string => is_int($id) || is_string($id) ? trim((string) $id) : '',
                (array) config('phpclaw.admin_ids', []),
            ),
            static fn (string $id): bool => $id !== '',
        );

        return in_array($actingUserId, $adminIds, true);
    }
}
