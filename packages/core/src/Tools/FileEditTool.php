<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Concerns\AtomicFileWrite;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Concerns\ResolvesWorkspacePaths;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ResettableInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\Security\BlockedPaths;

/**
 * Replaces a unique old_str with new_str in a workspace file, failing loudly when old_str is missing or matches more than once.
 */
#[Tool(
    name: self::TOOL_NAME,
    description: 'Replace a unique string in a workspace file (surgical edit, not a full rewrite).',
    since: '1.0.0',
    default: true,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class FileEditTool implements AuthorizableToolInterface, MutatingToolInterface, ResettableInterface, ToolInterface, ToolRoutingInterface
{
    use AtomicFileWrite;
    use HasCoreToolBinding;
    use ResolvesWorkspacePaths;

    public const DEFAULT_WORKSPACE_SUBPATH = 'storage/phpclaw';

    public const DEFAULT_MAX_BYTES = 2097152;

    private const TOOL_NAME = 'file_edit';

    private const BINARY_SNIFF_BYTES = 8192;

    private const BLOCKED_EXTENSIONS = BlockedPaths::EXTENSIONS;

    private const BLOCKED_FILENAMES = BlockedPaths::FILENAMES;

    private const EDIT_BLOCKED_FILENAMES = [
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
        '.gitignore', 'phpunit.xml', 'phpunit.xml.dist', 'dockerfile',
        '.gitlab-ci.yml', 'artisan',
    ];

    private const SECURITY_BLOCKED_DIRS = BlockedPaths::SECURITY_DIRS;

    private const EDIT_BLOCKED_DIRS = ['.github', '.git', '.circleci'];

    private const NOISE_BLOCKED_DIRS = BlockedPaths::NOISE_DIRS;

    private readonly bool $followSymlinks;

    private readonly array $noiseBlockedDirs;

    private readonly int $maxBytes;

    private readonly string $workspaceRootConfigured;

    private ?string $workspaceRootResolved = null;

    /**
     * Create a new FileEditTool instance.
     *
     * @param  string|null  $workspaceRoot  Absolute path to workspace root. Defaults to <CWD>/storage/phpclaw/. Resolved lazily on first execute(): see resolveWorkspaceRoot().
     * @param  bool  $followSymlinks  False (default) rejects any path whose components include a symlink. True allows them, but the blocklists are still re-checked against the resolved target.
     * @param  array  $noiseBlockedDirs  Directory names blocked as noise, not security (default: vendor/, node_modules/). Override to allow editing inside one of these; SECURITY_BLOCKED_DIRS and EDIT_BLOCKED_DIRS can never be overridden.
     * @param  int  $maxBytes  Maximum file size read into memory to edit. A larger file is refused before any read is attempted.
     * @return void
     *
     * @throws \InvalidArgumentException When maxBytes is less than 1.
     */
    public function __construct(
        ?string $workspaceRoot = null,
        bool $followSymlinks = false,
        array $noiseBlockedDirs = self::NOISE_BLOCKED_DIRS,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        $this->followSymlinks = $followSymlinks;
        $this->noiseBlockedDirs = $noiseBlockedDirs;
        $this->maxBytes = $maxBytes;
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be greater than 0.');
        }
        $this->workspaceRootConfigured = rtrim(
            $workspaceRoot ?? (getcwd().DIRECTORY_SEPARATOR.self::DEFAULT_WORKSPACE_SUBPATH),
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Edit a file by replacing old_str with new_str. old_str must appear exactly once. '
             .'Use for targeted fixes; use file_write only for whole new files.';
    }

    /**
     * JSON Schema describing the tool's file, old_str, and new_str inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Workspace-relative path of the file to edit.'],
                'old_str' => ['type' => 'string', 'description' => 'Exact unique string to replace, with enough surrounding context to be unique.'],
                'new_str' => ['type' => 'string', 'description' => 'Replacement string.'],
            ],
            'required' => ['path', 'old_str', 'new_str'],
        ];
    }

    /**
     * Authorize the caller, resolve the file, and locate the old_str match, which must be exact
     * and unique; an empty new_str is a valid deletion.
     *
     * @param  array<string, mixed>  $input  Must contain 'path' (or the legacy 'file'), 'old_str', and 'new_str' keys.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException On path escape, missing file, binary content, or a non-unique old_str match.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('edit a file in the workspace');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $file = (string) ($input['path'] ?? $input['file'] ?? '');
        $oldStr = (string) ($input['old_str'] ?? '');

        if ($file === '' || $oldStr === '') {
            throw new ToolException('file_edit: path and old_str are required.');
        }

        $absolutePath = $this->validatePath($file);

        if (! is_file($absolutePath)) {
            throw new ToolException('file_edit: file not found: '.$this->sanitizeForMessage($file));
        }

        if (! FileReadLog::wasRead($this->resolveWorkspaceRoot(), $absolutePath)) {
            throw new ToolException('file_edit: file_read must be called on '.$this->sanitizeForMessage($file).' before editing it.');
        }

        $contents = $this->readAndCheckBinary($absolutePath, $file);

        $count = substr_count($contents, $oldStr);

        if ($count === 0) {
            throw new ToolException('file_edit: old_str not found in '.$this->sanitizeForMessage($file).'. Match must be exact including whitespace.');
        }

        if ($count > 1) {
            throw new ToolException('file_edit: old_str matches '.$count.' locations in '.$this->sanitizeForMessage($file).' - add more surrounding context to make it unique.');
        }

        return [
            'input' => [
                'file' => $file,
                'absolute_path' => $absolutePath,
                'contents' => $contents,
                'old_str' => $oldStr,
                'new_str' => (string) ($input['new_str'] ?? ''),
            ],
            'result' => null,
        ];
    }

    /**
     * Replace the located match and write the file atomically: content lands in a temp file in the
     * same directory first, and only rename() makes it live.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the write fails.
     */
    protected function perform(array $input): array
    {
        $contents = (string) $input['contents'];
        $oldStr = (string) $input['old_str'];
        $file = (string) $input['file'];

        $pos = (int) strpos($contents, $oldStr);
        $line = substr_count(substr($contents, 0, $pos), "\n") + 1;

        $this->writeAtomically(
            (string) $input['absolute_path'],
            str_replace($oldStr, (string) $input['new_str'], $contents),
            $file,
            'file_edit',
            'edit',
        );

        return [
            'status' => 'edited',
            'file' => $file,
            'line_changed' => $line,
        ];
    }

    /**
     * Accept the completed edit unchanged.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     */
    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    /**
     * Convert the completed edit into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, [
            'mode' => 'edit',
            'line_changed' => (int) $execution['line_changed'],
        ]);
    }

    /**
     * Clear this run's read log so a new agent run starts with no files marked as read.
     *
     * @return void
     */
    public function reset(): void
    {
        FileReadLog::reset();
    }

    /**
     * Maximum file size read into memory to edit. A larger file is refused before any read.
     *
     * @return int
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Run all path validations and return the canonical absolute path within the workspace. Content blocklists are checked twice: once against the unresolved relative path as a fast-fail, and again against the canonical absolute path once the file's real location is known, mirroring FileReadTool's proven pattern so a symlink inside the workspace can't launder a blocked name/extension/directory past the first pass.
     *
     * @param  string  $file  Workspace-relative file path.
     * @return string Canonical absolute path within the workspace.
     *
     * @throws ToolException When the path is absolute, contains a symlink (unless allowed), is blocked, or escapes the workspace root.
     */
    private function validatePath(string $file): string
    {
        if ($this->isAbsolutePath($file)) {
            throw new ToolException('file_edit: Path traversal detected: access denied.');
        }

        $this->checkExtension($file);
        $this->checkBlockedFilename($file);
        $this->checkBlockedDirSegments($file);

        $segments = $this->collapseSegments($file);
        $this->assertNoSymlinksInPath($segments);

        $absolutePath = $this->canonicalize($segments, $file);

        $this->checkWithinWorkspace($absolutePath);

        $this->checkExtension($absolutePath);
        $this->checkBlockedFilename($absolutePath);
        $this->checkBlockedDirSegmentsAbsolute($absolutePath);

        return $absolutePath;
    }

    /**
     * Lexically collapse a relative path into a list of real segments, "." is dropped, ".." pops the previous segment. This runs BEFORE the symlink walk and canonicalization so both operate on the same component sequence the kernel would actually resolve; skipping ".." during the walk instead of collapsing it first lets a path like "a/../link" check the wrong location (/ws/a/link, which doesn't exist) while the kernel resolves a completely different one (/ws/link, which might be a malicious symlink).
     *
     * @param  string  $relativePath  Workspace-relative path supplied by the LLM.
     * @return list<string> Real path segments, "." and ".." fully resolved.
     *
     * @throws ToolException When a ".." would pop above the workspace root.
     */
    private function collapseSegments(string $relativePath): array
    {
        $raw = preg_split('/[\/\\\\]/', $relativePath) ?: [];
        $collapsed = [];

        foreach ($raw as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($collapsed === []) {
                    throw new ToolException('file_edit: Path traversal detected: access denied.');
                }
                array_pop($collapsed);

                continue;
            }

            $collapsed[] = $segment;
        }

        return $collapsed;
    }

    /**
     * Reject any path whose components include a symlink, unless followSymlinks is enabled. Operates on the already-lexically-collapsed segment list (see collapseSegments()), so every component walked is a real, forward-only step from the workspace root, never a ".." that could walk this check outside the sandbox.
     *
     * @param  list<string>  $segments  Collapsed, workspace-relative path segments.
     * @return void
     *
     * @throws ToolException When a symlink is found and followSymlinks is false.
     */
    private function assertNoSymlinksInPath(array $segments): void
    {
        if ($this->followSymlinks) {
            return;
        }

        $walked = rtrim($this->resolveWorkspaceRoot(), '/\\');

        foreach ($segments as $segment) {
            $walked .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($walked)) {
                throw new ToolException('file_edit: Access denied: path contains a symlink.');
            }
        }
    }

    /**
     * Reject blocked filenames: FileReadTool's list plus EDIT_BLOCKED_FILENAMES (targets that are harmless to read but dangerous to write). Accepts either a relative or an absolute path, only the basename is ever inspected.
     *
     * @param  string  $path  Relative or absolute path to check.
     * @return void
     *
     * @throws ToolException When the basename matches either blocklist.
     */
    private function checkBlockedFilename(string $path): void
    {
        $basename = strtolower(basename($path));

        if (in_array($basename, self::BLOCKED_FILENAMES, true) || in_array($basename, self::EDIT_BLOCKED_FILENAMES, true)) {
            throw new ToolException("file_edit: Access to '".$this->sanitizeForMessage($basename)."' is blocked.");
        }
    }

    /**
     * Reject paths that contain any blocked directory segment, security-critical and edit-integrity segments unconditionally, noise segments unless overridden via $noiseBlockedDirs.
     *
     * @param  string  $relativePath  Workspace-relative path to inspect.
     * @return void
     *
     * @throws ToolException When any segment is blocked.
     */
    private function checkBlockedDirSegments(string $relativePath): void
    {
        $segments = preg_split('/[\/\\\\]/', $relativePath) ?: [];

        foreach ($segments as $segment) {
            $lower = strtolower($segment);

            if ($lower === '') {
                continue;
            }

            $blocked = in_array($lower, self::SECURITY_BLOCKED_DIRS, true)
                || in_array($lower, self::EDIT_BLOCKED_DIRS, true)
                || in_array($lower, $this->noiseBlockedDirs, true);

            if ($blocked) {
                throw new ToolException(
                    "file_edit: Editing the '".$this->sanitizeForMessage($segment)."' directory is blocked."
                );
            }
        }
    }

    /**
     * Strip the workspace-root prefix from a resolved absolute path, then run the directory- segment check against only the remainder. The prefix comparison is case-folded via foldForComparison() (the same folding checkWithinWorkspace() uses) so the two checks can never disagree about whether a path is inside the root; the remainder itself is sliced from the ORIGINAL-case string so segment names are compared and reported in their real case.
     *
     * @param  string  $absolutePath  Canonical absolute path candidate.
     * @return void
     *
     * @throws ToolException When the path is not under the workspace root, or any remaining segment is blocked.
     */
    private function checkBlockedDirSegmentsAbsolute(string $absolutePath): void
    {
        $foldedPath = $this->foldForComparison($absolutePath);
        $foldedRoot = $this->foldForComparison($this->resolveWorkspaceRoot());

        if ($foldedPath !== $foldedRoot && ! str_starts_with($foldedPath, $foldedRoot.'/')) {
            throw new ToolException('file_edit: Path traversal detected: access denied.');
        }

        $normalisedOriginal = str_replace('\\', '/', $absolutePath);
        $relative = $foldedPath === $foldedRoot ? '' : substr($normalisedOriginal, strlen($foldedRoot) + 1);

        $this->checkBlockedDirSegments($relative);
    }

    /**
     * Reject blocked extensions and .env dotfiles. Matches ".env" exactly or ".env.*" (".env.local", ".env.production", ...), a plain str_starts_with(".env") false-positives on unrelated dotfiles like ".envoy" or ".environment.md". Accepts either a relative or an absolute path, only the basename/extension is ever inspected.
     *
     * @param  string  $path  Relative or absolute path to check.
     * @return void
     *
     * @throws ToolException When the extension is blocked or the basename is a .env file.
     */
    private function checkExtension(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $basename = strtolower(basename($path));

        if ($basename === '.env' || str_starts_with($basename, '.env.')) {
            throw new ToolException("file_edit: Access to '".$this->sanitizeForMessage($basename)."' files is blocked.");
        }

        if ($extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new ToolException("file_edit: Files with extension '.".$this->sanitizeForMessage($extension)."' are blocked.");
        }
    }

    /**
     * Verify the canonical absolute path lives under the workspace root, using the shared case-folding comparison (see foldForComparison()).
     *
     * @param  string  $absolutePath  Canonical absolute path candidate.
     * @return void
     *
     * @throws ToolException When the path escapes the workspace root.
     */
    private function checkWithinWorkspace(string $absolutePath): void
    {
        $normalised = $this->foldForComparison($absolutePath);
        $root = $this->foldForComparison($this->resolveWorkspaceRoot());

        if (! str_starts_with($normalised.'/', $root.'/') && $normalised !== $root) {
            throw new ToolException('file_edit: Path traversal detected: access denied.');
        }
    }

    /**
     * Canonicalize a collapsed segment list into the real absolute path, realpath() the parent directory first (resolving any symlink an allowed intermediate component introduced), then realpath() the full parent+leaf candidate so a leaf that is itself a symlink (relevant when followSymlinks allows one) resolves to its real target, that's what lets the post-resolution blocklist re-check in validatePath() catch a symlink laundering a blocked name past the fast-fail pass. When the leaf doesn't exist yet, that second realpath() fails and this falls back to the parent-resolved-plus-literal-leaf form, which is still safe: segments were already lexically collapsed before this method ever runs, so no ".." can be hiding in that fallback string. If the PARENT can't be resolved, the file cannot exist either way, so this reports the same clean "file not found" a missing leaf would, never a traversal error for a path that was always inside the workspace, just not there yet.
     *
     * @param  list<string>  $segments  Collapsed, workspace-relative path segments.
     * @param  string  $relativePath  Original workspace-relative path (for the error message only).
     * @return string Canonical absolute path.
     *
     * @throws ToolException When the parent directory cannot be resolved.
     */
    private function canonicalize(array $segments, string $relativePath): string
    {
        $root = rtrim($this->resolveWorkspaceRoot(), '/\\');

        if ($segments === []) {
            return $root;
        }

        $leaf = array_pop($segments);
        $parentRaw = $segments === [] ? $root : $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
        $parentReal = realpath($parentRaw);

        if ($parentReal === false) {
            throw new ToolException('file_edit: file not found: '.$this->sanitizeForMessage($relativePath));
        }

        $candidate = rtrim($parentReal, '/\\').DIRECTORY_SEPARATOR.$leaf;
        $leafReal = realpath($candidate);

        return $leafReal !== false ? $leafReal : $candidate;
    }

    /**
     * Read the full file, first checking its size via fstat() on the already-open handle (no extra stat call, no TOCTOU window between checking and reading) and refusing anything over maxBytes before any content is read into memory, reading an unbounded file with stream_get_contents() risks exhausting memory_limit, which is a fatal error, not an exception, so it would never reach the ToolException handling this method otherwise relies on. Then sniffs the first 8KB for a null byte, text files never contain one, binary formats almost always do early on, and file_edit performs a plain string replacement on raw bytes that would otherwise silently corrupt binary content instead of failing loudly. The size check, the sniff, and the full read all share this one file handle (opened once, checked, sniffed, rewound, then read) instead of opening the file twice, which both halves an unnecessary TOCTOU window and fails closed (throws) if the file can't be opened at all instead of silently treating an unreadable file as "not binary".
     *
     * @param  string  $absolutePath  Canonical absolute path.
     * @param  string  $relativePath  Workspace-relative path (used in error messages).
     * @return string Full file contents.
     *
     * @throws ToolException When the file is too large, binary, or cannot be opened/read.
     */
    private function readAndCheckBinary(string $absolutePath, string $relativePath): string
    {
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new ToolException('file_edit: cannot read: '.$this->sanitizeForMessage($relativePath));
        }

        try {
            $stat = fstat($handle);
            $size = $stat !== false ? $stat['size'] : false;

            if ($size !== false && $size > $this->maxBytes) {
                throw new ToolException(
                    'file_edit: file too large to edit ('.$size.' bytes, limit '.$this->maxBytes.'): '
                    .$this->sanitizeForMessage($relativePath)
                );
            }

            $sample = (string) fread($handle, self::BINARY_SNIFF_BYTES);

            if (str_contains($sample, "\0")) {
                throw new ToolException(
                    'file_edit: Binary file; file_edit operates on text files only: '.$this->sanitizeForMessage($relativePath)
                );
            }

            if (rewind($handle) === false) {
                throw new ToolException('file_edit: cannot read: '.$this->sanitizeForMessage($relativePath));
            }

            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            throw new ToolException('file_edit: cannot read: '.$this->sanitizeForMessage($relativePath));
        }

        return $contents;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['filesystem', 'code'],
            tags: ['edit', 'replace', 'modify', 'patch', 'change'],
            intents: ['edit file', 'replace string'],
            examples: ['change the timeout in config.php'],
        );
    }
}
