<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Concerns;

/**
 * Shared workspace-root resolution and path-shape primitives for the sandboxed file tools. The using class must declare a `string $workspaceRootConfigured` and a `?string $workspaceRootResolved` property (the resolution cache).
 */
trait ResolvesWorkspacePaths
{
    /**
     * Resolve the configured workspace root to its real, symlink-resolved path, caching the result after the first successful resolution. Deferred out of the constructor so an ancestor symlink that doesn't exist yet at construction time (e.g. an atomic-deploy `current -> releases/<sha>` layout mid-deploy) still resolves correctly once it does, instead of freezing an unresolved string that later mismatches every real read.
     *
     * @return string Resolved absolute path, or the configured raw path if it still can't resolve.
     */
    private function resolveWorkspaceRoot(): string
    {
        if ($this->workspaceRootResolved !== null) {
            return $this->workspaceRootResolved;
        }

        $real = realpath($this->workspaceRootConfigured);

        if ($real !== false) {
            $this->workspaceRootResolved = $real;

            return $real;
        }

        return $this->workspaceRootConfigured;
    }

    /**
     * Detect an absolute path: Unix-rooted, Windows drive-qualified (C:\ or C:/), or a Windows UNC share (\\server\share).
     *
     * @param  string  $path  Raw path supplied by the LLM.
     * @return bool True when the path is absolute in any of these forms.
     */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    /**
     * Fold a path for case-insensitive comparison: normalises separators to "/" always, and lowercases only on Windows and Darwin, matching those filesystems' own default case-folding behavior. The single source every path-containment comparison uses, so none of them can ever disagree about whether a path is inside the workspace.
     *
     * @param  string  $path  Path to fold.
     * @return string Folded path, safe for comparison only, never for display or I/O.
     */
    private function foldForComparison(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);

        if (in_array(\PHP_OS_FAMILY, ['Windows', 'Darwin'], true)) {
            $normalised = strtolower($normalised);
        }

        return $normalised;
    }
}
