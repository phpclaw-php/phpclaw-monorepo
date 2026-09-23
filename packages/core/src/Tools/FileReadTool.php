<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Concerns\ResolvesWorkspacePaths;
use PhpClaw\Tools\Concerns\SanitizesPathFragments;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ResettableInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\Security\BlockedPaths;

/**
 * Reads a file from the configured workspace directory, sandbox-enforced, no path traversal.
 */
#[Tool(
    name: 'file_read',
    description: 'Read a file from the sandboxed workspace directory.',
    since: '1.0.0',
    default: true,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class FileReadTool implements AuthorizableToolInterface, ResettableInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;
    use ResolvesWorkspacePaths;
    use SanitizesPathFragments;

    public const DEFAULT_WORKSPACE_SUBPATH = 'storage/phpclaw';

    public const DEFAULT_MAX_BYTES = 16384;

    private const BINARY_SNIFF_BYTES = 8192;

    private const BLOCKED_EXTENSIONS = BlockedPaths::EXTENSIONS;

    private const BLOCKED_FILENAMES = BlockedPaths::FILENAMES;

    private const SECURITY_BLOCKED_DIRS = BlockedPaths::SECURITY_DIRS;

    private const NOISE_BLOCKED_DIRS = BlockedPaths::NOISE_DIRS;

    private readonly string $workspaceRootConfigured;

    private ?string $workspaceRootResolved = null;

    /**
     * Create a new FileReadTool instance.
     *
     * @param  string|null  $workspaceRoot  Absolute path to workspace root. Defaults to <CWD>/storage/phpclaw/. Resolved lazily on first execute(): see resolveWorkspaceRoot().
     * @param  int  $maxBytes  Maximum bytes read. File truncated beyond this with a marker.
     * @param  bool  $followSymlinks  False (default) rejects any path whose components include a symlink. True allows them, but the blocklists are still re-checked against the resolved target.
     * @param  array  $noiseBlockedDirs  Directory names blocked as noise, not security (default: vendor/, node_modules/). Override to allow reading from one of these; SECURITY_BLOCKED_DIRS can never be overridden.
     * @return void
     *
     * @throws \InvalidArgumentException When maxBytes is less than 1.
     */
    public function __construct(
        ?string $workspaceRoot = null,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly bool $followSymlinks = false,
        private readonly array $noiseBlockedDirs = self::NOISE_BLOCKED_DIRS,
    ) {
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
        return 'file_read';
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'READ real file contents from the workspace (storage/phpclaw/). Use whenever the user asks what is in a file or to summarise one. Invoke: never guess contents.';
    }

    /**
     * JSON Schema describing the tool's `path` input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative path to the file within the workspace (e.g. "logs/app.log").',
                ],
            ],
            'required' => ['path'],
        ];
    }

    /**
     * Authorize the caller and resolve the requested path inside the workspace.
     *
     * @param  array<string, mixed>  $input  Must contain 'path'.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the path is missing, blocked, or escapes the workspace.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read a file from the workspace');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $relativePath = trim((string) ($input['path'] ?? ''));

        if ($relativePath === '') {
            throw new ToolException('No file path provided.');
        }

        $relativePath = $this->normalizeWorkspacePath($relativePath);

        return [
            'input' => [
                'path' => $relativePath,
                'absolute_path' => $this->validatePath($relativePath),
            ],
            'result' => null,
        ];
    }

    /**
     * Read the resolved file and record the read.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the file is missing, not a regular file, binary, or unreadable.
     */
    protected function perform(array $input): array
    {
        $absolutePath = (string) $input['absolute_path'];

        $read = $this->readTruncated($absolutePath, (string) $input['path']);

        FileReadLog::markRead($this->resolveWorkspaceRoot(), $absolutePath);

        return $read;
    }

    /**
     * Accept the file content unchanged.
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
     * Convert the file content into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $content = (string) $execution['content'];

        return $this->success(
            [
                'path' => (string) $input['path'],
                'content' => $content,
            ],
            [
                'mode' => 'read',
                'bytes' => strlen($content),
                'truncated' => (bool) $execution['truncated'],
            ],
        );
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
     * Absolute workspace root path used for sandbox checks. Resolves lazily on first call.
     *
     * @return string
     */
    public function workspaceRoot(): string
    {
        return $this->resolveWorkspaceRoot();
    }

    /**
     * Maximum bytes read by execute(). Files larger than this are truncated.
     *
     * @return int
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Run all path validations and return the canonical absolute path within the workspace. Blocklists are checked twice: once against the unresolved relative path as a fast-fail, and again against the canonical absolute path once we know the file's real location. The second pass is the authoritative gate, it's what stops a symlink inside the workspace from laundering a blocked name/extension/directory past the first pass.
     *
     * @param  string  $relativePath  Workspace-relative path supplied by the LLM.
     * @return string Canonical absolute path inside the workspace.
     *
     * @throws ToolException When any sandbox check fails.
     */
    private function validatePath(string $relativePath): string
    {
        $this->checkExtension($relativePath);
        $this->checkBlockedFilename($relativePath);
        $this->checkBlockedDirSegments($relativePath);

        $segments = $this->collapseSegments($relativePath);
        $this->assertNoSymlinksInPath($segments);

        $absolutePath = $this->canonicalize($segments, $relativePath);

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
                    throw new ToolException('Path traversal detected: access denied.');
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
                throw new ToolException('Access denied: path contains a symlink.');
            }
        }
    }

    /**
     * Reject blocked filenames (wp-config.php, id_rsa, etc.). Accepts either a relative or an absolute path, only the basename is ever inspected.
     *
     * @param  string  $path  Relative or absolute path to check.
     * @return void
     *
     * @throws ToolException When the basename matches BLOCKED_FILENAMES.
     */
    private function checkBlockedFilename(string $path): void
    {
        $basename = strtolower(basename($path));

        if (in_array($basename, self::BLOCKED_FILENAMES, true)) {
            throw new ToolException("Access to '".$this->sanitizeForMessage($basename)."' is blocked.");
        }
    }

    /**
     * Reject paths that contain any blocked directory segment, security-critical segments unconditionally, noise segments unless overridden via $noiseBlockedDirs.
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

            if (in_array($lower, self::SECURITY_BLOCKED_DIRS, true) || in_array($lower, $this->noiseBlockedDirs, true)) {
                throw new ToolException(
                    "Reading from the '".$this->sanitizeForMessage($segment)."' directory is blocked."
                );
            }
        }
    }

    /**
     * Strip the workspace-root prefix from a resolved absolute path, then run the directory- segment check against only the remainder, the portion reachable through the LLM-supplied relative path, not the workspace root's own ancestry (which may legitimately contain a segment like "vendor" above the sandbox boundary). The prefix comparison is case-folded via foldForComparison() (same folding checkWithinWorkspace() uses) so the two checks can never disagree about whether a path is inside the root; the remainder itself is sliced from the ORIGINAL-case string so segment names are compared and reported in their real case.
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
            throw new ToolException('Path traversal detected: access denied.');
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
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $basename = strtolower(basename($path));

        if ($basename === '.env' || str_starts_with($basename, '.env.')) {
            throw new ToolException("Access to '".$this->sanitizeForMessage($basename)."' files is blocked.");
        }

        if ($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            throw new ToolException("Files with extension '.".$this->sanitizeForMessage($ext)."' are blocked.");
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
            throw new ToolException('Path traversal detected: access denied.');
        }
    }

    /**
     * Make a model-supplied path forgiving by stripping any workspace prefix duplication. A stripped candidate is only used when it resolves to a real file that the unstripped candidate does not, an ambiguous tail (both exist, or neither does) is left unchanged rather than guessed at. A genuinely absolute path that does NOT duplicate the workspace root (e.g. "/etc/passwd") is rejected outright rather than silently reinterpreted as workspace-relative, which used to produce a confusing "File not found" instead of a clear rejection.
     *
     * @param  string  $path  Raw path supplied by the LLM.
     * @return string Normalised workspace-relative path.
     *
     * @throws ToolException When the path is absolute and does not duplicate the workspace root.
     */
    private function normalizeWorkspacePath(string $path): string
    {
        $wasAbsolute = $this->isAbsolutePath($path);
        $path = ltrim($path, '/\\');

        if ($path === '') {
            return $path;
        }

        $workspaceReal = rtrim($this->resolveWorkspaceRoot(), '/\\');

        if (str_starts_with($path, $workspaceReal.DIRECTORY_SEPARATOR)) {
            $stripped = substr($path, strlen($workspaceReal) + 1);

            return $this->preferExistingCandidate($stripped, $path);
        }

        $segments = explode(DIRECTORY_SEPARATOR, trim($workspaceReal, '/\\'));
        for ($take = count($segments); $take >= 2; $take--) {
            $tail = implode('/', array_slice($segments, -$take));
            if ($tail !== '' && str_starts_with($path, $tail.'/')) {
                $stripped = substr($path, strlen($tail) + 1);

                return $this->preferExistingCandidate($stripped, $path);
            }
        }

        if ($wasAbsolute) {
            throw new ToolException('Absolute paths are not allowed: '.$this->sanitizeForMessage($path));
        }

        return $path;
    }

    /**
     * Choose between a workspace-prefix-stripped candidate and the original path, preferring the stripped one only when it, and not the original, exists on disk.
     *
     * @param  string  $stripped  Path with the duplicated workspace prefix removed.
     * @param  string  $unstripped  Original path, prefix intact.
     * @return string Whichever candidate unambiguously exists; $unstripped otherwise.
     */
    private function preferExistingCandidate(string $stripped, string $unstripped): string
    {
        $root = rtrim($this->resolveWorkspaceRoot(), '/\\');

        $strippedExists = file_exists($root.DIRECTORY_SEPARATOR.$stripped);
        $unstrippedExists = file_exists($root.DIRECTORY_SEPARATOR.$unstripped);

        if ($strippedExists && ! $unstrippedExists) {
            return $stripped;
        }

        return $unstripped;
    }

    /**
     * Canonicalize a collapsed segment list into the real absolute path, realpath() the parent directory first (resolving any symlink an allowed intermediate component introduced), then realpath() the full parent+leaf candidate so a leaf that is itself a symlink (relevant when followSymlinks allows one) resolves to its real target, that's what lets the post-resolution blocklist re-check in validatePath() catch a symlink laundering a blocked name past the fast-fail pass. When the leaf doesn't exist yet, that second realpath() fails and this falls back to the parent-resolved-plus-literal-leaf form, which is still safe: segments were already lexically collapsed before this method ever runs, so no ".." can be hiding in that fallback string. If the PARENT can't be resolved, the file cannot exist either way, so this reports the same clean "File not found" a missing leaf would, never a traversal error for a path that was always inside the workspace, just not there yet.
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
            throw new ToolException('File not found: '.$this->sanitizeForMessage($relativePath));
        }

        $candidate = rtrim($parentReal, '/\\').DIRECTORY_SEPARATOR.$leaf;
        $leafReal = realpath($candidate);

        return $leafReal !== false ? $leafReal : $candidate;
    }

    /**
     * Read up to maxBytes from the file, appending a truncation marker if the file is larger. The binary sniff and the real read share a single file handle, opened once, sniffed, rewound, then read, instead of opening the file twice, which both halves an unnecessary TOCTOU window and fails closed (throws) if the file can't be opened at all instead of silently treating an unreadable file as "not binary".
     *
     * @param  string  $absolutePath  Canonical absolute path.
     * @param  string  $relativePath  Workspace-relative path (used in error messages).
     * @return array{content: string, truncated: bool}
     *
     * @throws ToolException When the file is missing, not a regular file, binary, or unreadable.
     */
    private function readTruncated(string $absolutePath, string $relativePath): array
    {
        if (! file_exists($absolutePath)) {
            throw new ToolException('File not found: '.$this->sanitizeForMessage($relativePath));
        }

        if (! is_file($absolutePath)) {
            throw new ToolException('Path is not a file: '.$this->sanitizeForMessage($relativePath));
        }

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new ToolException('Cannot open file for reading: '.$this->sanitizeForMessage($relativePath));
        }

        try {
            $sample = (string) fread($handle, self::BINARY_SNIFF_BYTES);

            if (str_contains($sample, "\0")) {
                throw new ToolException('Binary file; not readable as text: '.$this->sanitizeForMessage($relativePath));
            }

            if (rewind($handle) === false) {
                throw new ToolException('Cannot open file for reading: '.$this->sanitizeForMessage($relativePath));
            }

            $content = (string) fread($handle, $this->maxBytes);

            $stat = fstat($handle);
            $fileSize = $stat !== false ? $stat['size'] : false;
        } finally {
            fclose($handle);
        }

        $truncated = $fileSize !== false && $fileSize > $this->maxBytes;

        if ($truncated) {
            $content = $this->trimIncompleteUtf8Tail($content);
            $content .= "\n[File truncated at ".$this->maxBytes.' bytes]';
        }

        return ['content' => $content, 'truncated' => $truncated];
    }

    /**
     * Drop a trailing incomplete UTF-8 sequence, so a hard byte-count truncation never leaves the tail mid-character (which would produce invalid UTF-8 and break json_encode()).
     *
     * @param  string  $content  Possibly truncated byte string.
     * @return string Content with any incomplete trailing UTF-8 sequence removed.
     */
    private function trimIncompleteUtf8Tail(string $content): string
    {
        $length = strlen($content);

        for ($back = 1; $back <= 4 && $back <= $length; $back++) {
            $byte = ord($content[$length - $back]);

            if (($byte & 0xC0) === 0x80) {
                continue;
            }

            if ($byte < 0x80) {
                return $content;
            }

            $expectedLen = match (true) {
                ($byte & 0xE0) === 0xC0 => 2,
                ($byte & 0xF0) === 0xE0 => 3,
                ($byte & 0xF8) === 0xF0 => 4,
                default => 0,
            };

            if ($expectedLen === 0 || $expectedLen > $back) {
                return substr($content, 0, $length - $back);
            }

            return $content;
        }

        return $content;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['filesystem'],
            tags: ['read', 'open', 'file', 'contents', 'view', 'show'],
            intents: ['read file', 'show contents'],
            examples: ['show me the contents of app.log'],
        );
    }
}
