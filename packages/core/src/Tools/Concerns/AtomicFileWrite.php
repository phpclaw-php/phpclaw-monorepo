<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Concerns;

use PhpClaw\Exceptions\ToolException;

/**
 * Shared atomic tempnam+rename write, used by FileEditTool and FileWriteTool.
 */
trait AtomicFileWrite
{
    use SanitizesPathFragments;

    /**
     * Writes via tempnam+rename in the same directory so the target is never left truncated or half-written.
     *
     * @param  string  $absolutePath  Canonical absolute path of the file being written.
     * @param  string  $content  Full file content.
     * @param  string  $relativePath  Workspace-relative path (used in error messages).
     * @param  string  $toolLabel  Tool prefix for error messages, e.g. "file_edit" or "file_write".
     * @param  string  $tempPrefix  Tempnam basename prefix, e.g. "edit" or "write".
     * @return void
     *
     * @throws ToolException When the temp file cannot be created, fully written, or promoted.
     */
    private function writeAtomically(string $absolutePath, string $content, string $relativePath, string $toolLabel, string $tempPrefix): void
    {
        $dir = dirname($absolutePath);
        $tempPath = tempnam($dir, ".phpclaw-{$tempPrefix}-");

        if ($tempPath === false) {
            throw new ToolException("{$toolLabel}: failed to create temp file for write.");
        }

        if (dirname($tempPath) !== $dir) {
            @unlink($tempPath);
            throw new ToolException("{$toolLabel}: cannot write atomically: target directory is not writable.");
        }

        try {
            $written = file_put_contents($tempPath, $content, LOCK_EX);

            if ($written === false || $written !== strlen($content)) {
                throw new ToolException("{$toolLabel}: failed to write: ".$this->sanitizeForMessage($relativePath));
            }

            $perms = @fileperms($absolutePath);
            if ($perms !== false) {
                @chmod($tempPath, $perms & 0777);
            }

            $owner = @fileowner($absolutePath);
            if ($owner !== false) {
                @chown($tempPath, $owner);
            }

            $group = @filegroup($absolutePath);
            if ($group !== false) {
                @chgrp($tempPath, $group);
            }

            if (! rename($tempPath, $absolutePath)) {
                throw new ToolException("{$toolLabel}: failed to finalize write: ".$this->sanitizeForMessage($relativePath));
            }
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
