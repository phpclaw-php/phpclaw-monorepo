<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Concerns;

/**
 * Tracks canonical paths read via FileReadTool, keyed by workspace root, so FileEditTool can require a prior read. Static because FileReadTool and FileEditTool are instantiated independently with no shared object between them, an accepted cross-instance read-gate cache (Point F exemption), cleared via reset() for test isolation.
 */
final class FileReadLog
{
    private static array $readPathsByWorkspace = [];

    /**
     * Record that a canonical path was read within the given workspace.
     *
     * @param  string  $workspaceRoot  Workspace root the read is scoped to.
     * @param  string  $canonicalPath  Canonical absolute path that was read.
     * @return void
     */
    public static function markRead(string $workspaceRoot, string $canonicalPath): void
    {
        self::$readPathsByWorkspace[$workspaceRoot][$canonicalPath] = true;
    }

    /**
     * Whether a canonical path was previously read within the given workspace.
     *
     * @param  string  $workspaceRoot  Workspace root the read is scoped to.
     * @param  string  $canonicalPath  Canonical absolute path to check.
     * @return bool True when the path was recorded as read.
     */
    public static function wasRead(string $workspaceRoot, string $canonicalPath): bool
    {
        return isset(self::$readPathsByWorkspace[$workspaceRoot][$canonicalPath]);
    }

    /**
     * Forget every recorded read across all workspaces.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$readPathsByWorkspace = [];
    }
}
