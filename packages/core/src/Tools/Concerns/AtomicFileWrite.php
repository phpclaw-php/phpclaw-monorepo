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
        $directory = dirname($absolutePath);
        $tempPath = tempnam($directory, ".phpclaw-{$tempPrefix}-");

        if ($tempPath === false) {
            throw new ToolException("{$toolLabel}: failed to create temp file for write.");
        }

        if (dirname($tempPath) !== $directory) {
            @unlink($tempPath);
            throw new ToolException("{$toolLabel}: cannot write atomically: target directory is not writable.");
        }

        try {
            $written = file_put_contents($tempPath, $content, LOCK_EX);

            if ($written === false || $written !== strlen($content)) {
                throw new ToolException("{$toolLabel}: failed to write: ".$this->sanitizeForMessage($relativePath));
            }

            $this->copyFileMetadata($absolutePath, $tempPath);

            if (! rename($tempPath, $absolutePath)) {
                throw new ToolException("{$toolLabel}: failed to finalize write: ".$this->sanitizeForMessage($relativePath));
            }
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Copy filesystem metadata (mode, owner, group) from one path to another, best-effort.
     *
     * @param  string  $source  Path to read metadata from.
     * @param  string  $target  Path to apply metadata to.
     * @return void
     */
    private function copyFileMetadata(string $source, string $target): void
    {
        $permissions = @fileperms($source);
        if ($permissions !== false) {
            @chmod($target, $permissions & 0777);
        }

        $owner = @fileowner($source);
        if ($owner !== false) {
            @chown($target, $owner);
        }

        $group = @filegroup($source);
        if ($group !== false) {
            @chgrp($target, $group);
        }
    }
}
