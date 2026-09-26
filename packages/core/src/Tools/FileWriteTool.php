<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Concerns\AtomicFileWrite;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\Security\BlockedPaths;

/**
 * Writes content to a file within the configured workspace directory, sandbox-enforced, no path traversal.
 */
#[Tool(
    name: 'file_write',
    description: 'Write content to a file in the sandboxed workspace directory.',
    since: '1.0.0',
    default: true,
    needsConfig: ['workspaceRoot' => 'string', 'allowPhpWrite' => 'bool'],
)]
final class FileWriteTool implements AuthorizableToolInterface, MutatingToolInterface, ToolInterface, ToolRoutingInterface
{
    use AtomicFileWrite;
    use HasCoreToolBinding;

    public const DEFAULT_WORKSPACE_SUBPATH = 'storage/phpclaw';

    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    private const DIRECTORY_MODE = 0755;

    private const PHP_EXTENSIONS = ['php', 'phtml', 'phar'];

    private const BLOCKED_EXTENSIONS = [
        ...BlockedPaths::EXTENSIONS,
        'sh', 'bash', 'exe', 'bat', 'cmd', 'ps1',
    ];

    private const BLOCKED_DIR_SEGMENTS = [
        'vendor', 'node_modules',
        '.git', '.ssh', '.gnupg',
        '.aws', '.azure', '.gcloud', '.kube',
        '.docker', '.config',
        'wp-admin', 'wp-includes',
        'core', 'system', 'sysext',
    ];

    private readonly bool $allowPhpWrite;

    private readonly int $maxBytes;

    private readonly bool $followSymlinks;

    private readonly string $workspaceRoot;

    /**
     * Create a new FileWriteTool instance.
     *
     * @param  string|null  $workspaceRoot  Absolute path to workspace root. Defaults to <CWD>/storage/phpclaw/.
     * @param  bool  $allowPhpWrite  True to permit writing .php/.phtml/.phar files.
     * @param  int  $maxBytes  Maximum content size in bytes. Writes exceeding this throw ToolException.
     * @param  bool  $followSymlinks  False (default) rejects any path whose existing components include a symlink. True allows them, but the blocklists are still re-checked against the resolved target.
     * @return void
     */
    public function __construct(
        ?string $workspaceRoot = null,
        bool $allowPhpWrite = false,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        bool $followSymlinks = false,
    ) {
        $this->allowPhpWrite = $allowPhpWrite;
        $this->maxBytes = $maxBytes;
        $this->followSymlinks = $followSymlinks;
        $raw = rtrim(
            $workspaceRoot ?? (getcwd().DIRECTORY_SEPARATOR.self::DEFAULT_WORKSPACE_SUBPATH),
            DIRECTORY_SEPARATOR
        );
        $real = realpath($raw);
        $this->workspaceRoot = $real !== false ? $real : $raw;
    }

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return 'file_write';
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'WRITE content to a file in the workspace (storage/phpclaw/), creating parent dirs as needed. Use whenever the user asks to save or persist text. Invoke: never claim a write without calling.';
    }

    /**
     * JSON Schema describing the tool's `path` and `content` inputs.
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
                    'description' => 'Relative path to the file within the workspace (e.g. "output/report.txt").',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write to the file.',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    /**
     * Authorize the caller, validate the content size, and resolve the target path.
     *
     * @param  array<string, mixed>  $input  Must contain 'path' and 'content' keys.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException On path violation, blocked extension, or oversized content.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('write a file to the workspace');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $relativePath = trim((string) ($input['path'] ?? ''));
        $content = (string) ($input['content'] ?? '');

        if ($relativePath === '') {
            throw new ToolException('No file path provided.');
        }

        $this->validateContentSize($content);

        $relativePath = $this->normalizeWorkspacePath($relativePath);

        return [
            'input' => [
                'path' => $relativePath,
                'content' => $content,
                'absolute_path' => $this->validatePath($relativePath),
            ],
            'result' => null,
        ];
    }

    /**
     * Write the content atomically to the resolved path.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the write fails.
     */
    protected function perform(array $input): array
    {
        $absolutePath = (string) $input['absolute_path'];
        $content = (string) $input['content'];

        $this->ensureParentDirectory($absolutePath);

        $this->writeAtomically($absolutePath, $content, (string) $input['path'], 'file_write', 'write');

        return [
            'path' => (string) $input['path'],
            'bytes_written' => strlen($content),
        ];
    }

    /**
     * Accept the completed write unchanged.
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
     * Convert the completed write into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, [
            'mode' => 'write',
            'message' => 'Written '.$execution['bytes_written']." bytes to {$execution['path']}.",
        ]);
    }

    /**
     * Absolute workspace root path used for sandbox checks.
     *
     * @return string
     */
    public function workspaceRoot(): string
    {
        return $this->workspaceRoot;
    }

    /**
     * Maximum content size in bytes accepted by execute().
     *
     * @return int
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Reject content that exceeds the configured maxBytes.
     *
     * @param  string  $content  Content to size-check.
     * @return void
     *
     * @throws ToolException When the content is larger than maxBytes.
     */
    private function validateContentSize(string $content): void
    {
        $size = strlen($content);

        if ($size > $this->maxBytes) {
            throw new ToolException(
                "Content size ({$size} bytes) exceeds max allowed ({$this->maxBytes} bytes)."
            );
        }
    }

    /**
     * Run all path validations and return the canonical absolute path within the workspace. Content blocklists are checked twice: once against the unresolved relative path as a fast-fail, and again against the canonical absolute path once the file's real location is known, mirroring FileEditTool's proven pattern so a symlink inside the workspace can't launder a blocked name/extension/directory past the first pass.
     *
     * @param  string  $relativePath  Workspace-relative path supplied by the LLM.
     * @return string Canonical absolute path inside the workspace.
     *
     * @throws ToolException When any sandbox check fails.
     */
    private function validatePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new ToolException('Path traversal detected: write access denied.');
        }

        $this->checkExtension($relativePath);
        $this->checkBlockedDirSegments($relativePath);

        $segments = array_values(array_filter(
            preg_split('/[\/\\\\]/', $relativePath) ?: [],
            static fn (string $segment): bool => $segment !== '',
        ));

        $this->assertNoSymlinksInPath($segments);

        $absolutePath = $this->canonicalize($segments);

        $this->checkWithinWorkspace($absolutePath);

        $this->checkExtension($absolutePath);
        $this->checkBlockedDirSegmentsAbsolute($absolutePath);

        return $absolutePath;
    }

    /**
     * Reject any EXISTING path component that is a symlink, unless followSymlinks is enabled. Stops walking once it reaches a component that doesn't exist yet, nothing to check past that point, since a path that doesn't exist can't be a pre-planted symlink.
     *
     * @param  list<string>  $segments  Workspace-relative path segments.
     * @return void
     *
     * @throws ToolException When a symlink is found and followSymlinks is false.
     */
    private function assertNoSymlinksInPath(array $segments): void
    {
        if ($this->followSymlinks) {
            return;
        }

        $walked = rtrim($this->workspaceRoot, '/\\');

        foreach ($segments as $segment) {
            $walked .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($walked)) {
                throw new ToolException('Path traversal detected: path contains a symlink.');
            }

            if (! file_exists($walked)) {
                return;
            }
        }
    }

    /**
     * Resolve segments to a canonical absolute path, realpath()s the deepest EXISTING ancestor, then appends any not-yet-created trailing segments literally. Safe because those trailing segments were already confirmed non-existent (and therefore not symlinks) by assertNoSymlinksInPath(), and ensureParentDirectory() creates them for real before the write.
     *
     * @param  list<string>  $segments  Workspace-relative path segments.
     * @return string Canonical (or best-effort) absolute path.
     */
    private function canonicalize(array $segments): string
    {
        $resolved = rtrim($this->workspaceRoot, '/\\');
        $tailIndex = count($segments);

        foreach ($segments as $i => $segment) {
            $candidate = $resolved.DIRECTORY_SEPARATOR.$segment;

            if (! file_exists($candidate)) {
                $tailIndex = $i;

                break;
            }

            $resolved = $candidate;
        }

        $real = realpath($resolved);
        $base = $real !== false ? $real : $resolved;
        $tail = array_slice($segments, $tailIndex);

        return $tail === [] ? $base : $base.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $tail);
    }

    /**
     * Reject blocked extensions and .env* dotfiles.
     *
     * @param  string  $path  Relative path to check.
     * @return void
     *
     * @throws ToolException When the extension is blocked or basename starts with .env.
     */
    private function checkExtension(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $basename = strtolower(basename($path));

        if (str_starts_with($basename, '.env')) {
            throw new ToolException("Writing to '".$this->sanitizeForMessage($basename)."' files is blocked.");
        }

        if (in_array($extension, self::PHP_EXTENSIONS, true)) {
            if (! $this->allowPhpWrite) {
                throw new ToolException(
                    'Writing .'.$this->sanitizeForMessage($extension).' files is blocked by default. Enable allowPhpWrite to permit it.'
                );
            }

            return;
        }

        if ($extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new ToolException("Writing files with extension '.".$this->sanitizeForMessage($extension)."' is blocked.");
        }
    }

    /**
     * Reject paths that contain any blocked directory segment.
     *
     * @param  string  $relativePath  Workspace-relative path to inspect.
     * @return void
     *
     * @throws ToolException When any segment is in BLOCKED_DIR_SEGMENTS.
     */
    private function checkBlockedDirSegments(string $relativePath): void
    {
        $segments = preg_split('/[\/\\\\]/', $relativePath) ?: [];

        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::BLOCKED_DIR_SEGMENTS, true)) {
                throw new ToolException(
                    "Writing to the '".$this->sanitizeForMessage($segment)."' directory is blocked."
                );
            }
        }
    }

    /**
     * Verify the resolved absolute path lives under the workspace root, using a case-folded comparison so a case-differing symlink target can't bypass containment on a case-insensitive filesystem (Windows, macOS).
     *
     * @param  string  $absolutePath  Resolved absolute path candidate.
     * @return void
     *
     * @throws ToolException When the path escapes the workspace root.
     */
    private function checkWithinWorkspace(string $absolutePath): void
    {
        $normalised = $this->foldForComparison($absolutePath);
        $root = $this->foldForComparison($this->workspaceRoot);

        if (! str_starts_with($normalised.'/', $root.'/') && $normalised !== $root) {
            throw new ToolException('Path traversal detected: write access denied.');
        }
    }

    /**
     * Fold a path for case-insensitive comparison: normalises separators to "/" always, and lowercases only on Windows and Darwin, matching those filesystems' own default case-folding behavior.
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

    /**
     * Strip the workspace-root prefix from a resolved absolute path, then run the directory-segment check against only the remainder, avoids false-positiving on a blocked-looking name that happens to appear in the workspace root's OWN path.
     *
     * @param  string  $absolutePath  Canonical absolute path candidate.
     * @return void
     *
     * @throws ToolException When any remaining segment is blocked.
     */
    private function checkBlockedDirSegmentsAbsolute(string $absolutePath): void
    {
        $root = str_replace('\\', '/', $this->workspaceRoot);
        $normalised = str_replace('\\', '/', $absolutePath);

        $relative = $normalised === $root ? '' : substr($normalised, strlen($root) + 1);

        $this->checkBlockedDirSegments($relative);
    }

    /**
     * Strip workspace prefix from model-supplied paths so the workspace segment never gets duplicated during resolution.
     *
     * @param  string  $path  Raw path supplied by the LLM.
     * @return string Normalised workspace-relative path.
     */
    private function normalizeWorkspacePath(string $path): string
    {
        $path = ltrim($path, '/\\');

        if ($path === '') {
            return $path;
        }

        $workspaceReal = rtrim((string) (realpath($this->workspaceRoot) ?: $this->workspaceRoot), '/\\');

        if (str_starts_with($path, $workspaceReal.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($workspaceReal) + 1);
        }

        $segments = explode(DIRECTORY_SEPARATOR, trim($workspaceReal, '/\\'));
        for ($take = count($segments); $take > 0; $take--) {
            $tail = implode('/', array_slice($segments, -$take));
            if ($tail !== '' && str_starts_with($path, $tail.'/')) {
                return substr($path, strlen($tail) + 1);
            }
        }

        return $path;
    }

    /**
     * Create the parent directory of the target file if it does not yet exist.
     *
     * @param  string  $absolutePath  Validated absolute file path.
     * @return void
     *
     * @throws ToolException If the directory cannot be created.
     */
    private function ensureParentDirectory(string $absolutePath): void
    {
        $directory = dirname($absolutePath);

        if (is_dir($directory)) {
            return;
        }

        $created = mkdir($directory, self::DIRECTORY_MODE, recursive: true);

        if (! $created && ! is_dir($directory)) {
            throw new ToolException('Could not create directory: '.$this->sanitizeForMessage($directory));
        }
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
            tags: ['write', 'create', 'save', 'file'],
            intents: ['write file', 'create file', 'save contents'],
            examples: ['save this report to notes.md'],
        );
    }
}
