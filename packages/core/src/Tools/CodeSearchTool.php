<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Support\Log;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/**
 * Recursive read-only pattern search across workspace files, returns file, line, match, and context.
 */
#[Tool(
    name: self::TOOL_NAME,
    description: 'Search a pattern across workspace files; returns file, line, match, and context.',
    since: '1.0.0',
    default: true,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class CodeSearchTool implements AuthorizableToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    public const DEFAULT_WORKSPACE_SUBPATH = 'storage/phpclaw';

    private const TOOL_NAME = 'code_search';

    private const MAX_SCAN_ROWS = 1000;

    private const MAX_PAGE_BYTES = 8192;

    private const MAX_FILE_BYTES = 1048576;

    private const CONTEXT_LINES = 0;

    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.idea'];

    private const MAX_CONTEXT_LINES = 5;

    private const MAX_BACKTRACK_STEPS = 200000;

    private readonly string $workspaceRoot;

    /**
     * Create a new CodeSearchTool instance.
     *
     * @param  string|null  $workspaceRoot  Absolute path to workspace root. Defaults to <CWD>/storage/phpclaw/.
     * @return void
     */
    public function __construct(?string $workspaceRoot = null)
    {
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
        return self::TOOL_NAME;
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Find where a class, method, function, string, or error message appears in the codebase under '
             .$this->workspaceRoot.'. Returns file and line only by default; pass context_lines to include '
             .'the matched line and surrounding code. Results are paged: when truncated, call again with the '
             .'returned next_offset. Read-only.';
    }

    /**
     * JSON Schema describing the tool's search inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string',  'description' => 'Literal string to search for, or a PCRE regex when is_regex is true.'],
                'path' => ['type' => 'string',  'description' => 'Sub-path under '.$this->workspaceRoot.'. Omit to search the whole root.'],
                'extension' => ['type' => 'string',  'description' => 'Optional extension filter, e.g. "php".'],
                'is_regex' => ['type' => 'boolean', 'description' => 'Treat pattern as a PCRE regex. Default false.'],
                'context_lines' => ['type' => 'integer', 'description' => 'Include the matched line, plus this many lines before and after it. Omit for file:line only.'],
                'offset' => ['type' => 'integer', 'description' => 'Skip this many matches. Use the next_offset from a truncated result to get the next page.'],
            ],
            'required' => ['pattern'],
        ];
    }

    /**
     * Authorize the caller, validate the pattern, and resolve the search root.
     *
     * @param  array<string, mixed>  $input  Must contain 'pattern'; optional path/extension/is_regex/context_lines.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException On empty/invalid pattern, path escape, or missing directory.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('search this workspace');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $pattern = (string) ($input['pattern'] ?? '');
        $subPath = (string) ($input['path'] ?? '');
        $isRegex = (bool) ($input['is_regex'] ?? false);

        if ($pattern === '') {
            throw new ToolException('code_search: pattern is required.');
        }

        if ($isRegex && @preg_match($pattern, '') === false) {
            throw new ToolException('code_search: invalid regex pattern.');
        }

        $root = $this->resolveRoot($subPath);

        if (! is_dir($root)) {
            throw new ToolException("code_search: directory not found: {$subPath}");
        }

        return [
            'input' => [
                'pattern' => $pattern,
                'root' => $root,
                'extension' => ltrim((string) ($input['extension'] ?? ''), '.'),
                'is_regex' => $isRegex,
                'context_lines' => max(0, min(self::MAX_CONTEXT_LINES, (int) ($input['context_lines'] ?? self::CONTEXT_LINES))),
                'with_content' => array_key_exists('context_lines', $input),
                'offset' => max(0, (int) ($input['offset'] ?? 0)),
            ],
            'result' => null,
        ];
    }

    /**
     * Scan the resolved root for the pattern.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        return [
            'results' => $this->scan(
                (string) $input['root'],
                (string) $input['pattern'],
                (string) $input['extension'],
                isRegex: (bool) $input['is_regex'],
                contextLines: (int) $input['context_lines'],
                offset: (int) $input['offset'],
            ),
        ];
    }

    /**
     * Accept the collected matches unchanged.
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
     * Convert the matches into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $results = (array) $execution['results'];
        $scanOverflowed = count($results) > self::MAX_SCAN_ROWS;
        $offset = (int) $input['offset'];
        $rows = [];
        $bytes = 0;

        foreach (array_slice($results, 0, self::MAX_SCAN_ROWS) as $row) {
            $shaped = $this->shapeRow($row, withContent: (bool) $input['with_content'], contextLines: (int) $input['context_lines']);
            $bytes += strlen((string) json_encode($shaped)) + 1;

            if ($bytes > self::MAX_PAGE_BYTES && $rows !== []) {
                break;
            }

            $rows[] = $shaped;
        }

        $truncated = $scanOverflowed || count($rows) < min(count($results), self::MAX_SCAN_ROWS);

        $data = [
            'pattern' => (string) $input['pattern'],
            'offset' => $offset,
            'total' => count($rows),
            'truncated' => $truncated,
            'results' => $rows,
        ];

        if ($truncated) {
            $data['next_offset'] = $offset + count($rows);
            $data['hint'] = 'More matches exist. Call again with offset='.$data['next_offset'].' for the next page.';
        }

        return $this->success($data, ['mode' => 'search', 'truncated' => $truncated]);
    }

    /**
     * Reduce a collected match to the fields the caller asked for: file and line always, the matched line when context was requested, surrounding lines only when context_lines is above zero.
     *
     * @param  array<string, mixed>  $row  Full collected match.
     * @param  bool  $withContent  Whether the caller passed context_lines at all.
     * @param  int  $contextLines  Requested context lines.
     * @return array<string, mixed> The trimmed row.
     */
    private function shapeRow(array $row, bool $withContent, int $contextLines): array
    {
        $shaped = ['file' => $row['file'], 'line' => $row['line']];

        if (! $withContent) {
            return $shaped;
        }

        $shaped['match'] = $row['match'];

        if ($contextLines > 0) {
            $shaped['before'] = $row['before'];
            $shaped['after'] = $row['after'];
        }

        return $shaped;
    }

    /**
     * Resolve the search root, keeping any sub-path inside the workspace.
     *
     * @param  string  $subPath  Optional workspace-relative sub-path.
     * @return string Absolute search root.
     *
     * @throws ToolException When the sub-path escapes the workspace root.
     */
    private function resolveRoot(string $subPath): string
    {
        if ($subPath === '') {
            return $this->workspaceRoot;
        }

        $workspaceBase = realpath($this->workspaceRoot) ?: $this->workspaceRoot;

        $real = realpath($this->isAbsolutePath($subPath)
            ? $subPath
            : $this->workspaceRoot.DIRECTORY_SEPARATOR.ltrim($subPath, '/\\'));

        if ($real === false) {
            throw new ToolException("code_search: directory not found: {$subPath}");
        }

        if (! str_starts_with($real.DIRECTORY_SEPARATOR, rtrim($workspaceBase, '/\\').DIRECTORY_SEPARATOR)) {
            throw new ToolException('code_search: path escapes workspace root - blocked.');
        }

        return $real;
    }

    /**
     * Whether a path is absolute on this platform, so it is resolved as given rather than joined to the workspace root.
     *
     * @param  string  $path  Caller-supplied path.
     * @return bool True for a POSIX or Windows absolute path.
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
     * Walk the tree and collect matches up to MAX_SCAN_ROWS plus one sentinel row that signals more exist. When is_regex is true, pcre.backtrack_limit is capped to MAX_BACKTRACK_STEPS for the duration of the search (restored in finally regardless of outcome), so a catastrophic pattern fails fast on this tool's own ceiling instead of depending on the ambient php.ini value. Lines whose match attempt hits that ceiling are silently skipped by design (never hang) but are counted and reported via a single warning log line so the skip is not indistinguishable from a genuine "no match" in a long/complex line.
     *
     * @param  string  $root  Absolute directory to search.
     * @param  string  $pattern  Literal or regex pattern.
     * @param  string  $extension  Extension filter, or '' for any.
     * @param  bool  $isRegex  Whether the pattern is a regex.
     * @param  int  $contextLines  Context lines around each match.
     * @param  int  $offset  Matches to skip before collecting, for paging.
     * @return list<array<string, mixed>> Up to MAX_SCAN_ROWS + 1 rows, the extra one signalling that more exist.
     */
    private function scan(string $root, string $pattern, string $extension, bool $isRegex, int $contextLines, int $offset = 0): array
    {
        $results = [];
        $seen = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $fileInfo): bool => ! in_array($fileInfo->getFilename(), self::SKIP_DIRS, true),
            ),
        );

        $previousBacktrackLimit = (string) ini_get('pcre.backtrack_limit');
        $skipped = 0;
        $linesScanned = 0;

        if ($isRegex) {
            ini_set('pcre.backtrack_limit', (string) self::MAX_BACKTRACK_STEPS);
        }

        try {
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getSize() > self::MAX_FILE_BYTES) {
                    continue;
                }
                if ($extension !== '' && strtolower($file->getExtension()) !== strtolower($extension)) {
                    continue;
                }

                $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $i => $line) {
                    $linesScanned++;

                    $hit = $this->matchLine($line, $pattern, $isRegex);

                    if ($hit === null) {
                        $skipped++;

                        continue;
                    }

                    if (! $hit) {
                        continue;
                    }

                    if ($seen++ < $offset) {
                        continue;
                    }

                    $results[] = $this->buildMatchRow($file->getPathname(), $i, $line, $lines, $contextLines);

                    if (count($results) > self::MAX_SCAN_ROWS) {
                        return $results;
                    }
                }
            }
        } finally {
            if ($isRegex) {
                ini_set('pcre.backtrack_limit', $previousBacktrackLimit);
            }

            if ($skipped > 0) {
                Log::warning(
                    "[phpClaw] CodeSearchTool: skipped {$skipped}/{$linesScanned} line(s): "
                    .'regex exceeded the '.self::MAX_BACKTRACK_STEPS.'-step backtrack ceiling.'
                );
            }
        }

        return $results;
    }

    /**
     * Determine whether a line matches the pattern; returns null when a regex hits the backtrack ceiling.
     *
     * @param  string  $line  Source line to test.
     * @param  string  $pattern  Literal string or compiled regex pattern.
     * @param  bool  $isRegex  Whether pattern is a regex.
     * @return bool|null True on match, false on no match, null when a regex backtrack limit is hit.
     */
    private function matchLine(string $line, string $pattern, bool $isRegex): ?bool
    {
        if (! $isRegex) {
            return str_contains($line, $pattern);
        }

        $matched = @preg_match($pattern, $line);

        return $matched === false ? null : (bool) $matched;
    }

    /**
     * Build a single result row from a confirmed match.
     *
     * @param  string  $filePath  Absolute path of the matched file.
     * @param  int  $index  Zero-based line index within the file.
     * @param  string  $line  The matched source line.
     * @param  string[]  $lines  All lines of the file, for context slicing.
     * @param  int  $contextLines  Number of surrounding lines to include.
     * @return array<string, mixed> Result row with file, line, match, before, and after keys.
     */
    private function buildMatchRow(string $filePath, int $index, string $line, array $lines, int $contextLines): array
    {
        return [
            'file' => substr($filePath, strlen($this->workspaceRoot) + 1),
            'line' => $index + 1,
            'match' => $line,
            'before' => array_slice($lines, max(0, $index - $contextLines), min($contextLines, $index)),
            'after' => array_slice($lines, $index + 1, $contextLines),
        ];
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['code', 'search'],
            tags: ['grep', 'find', 'search', 'pattern', 'regex', 'occurrences'],
            intents: ['search code', 'find pattern', 'locate usage'],
            examples: ['find every call to buildPayload'],
        );
    }
}
