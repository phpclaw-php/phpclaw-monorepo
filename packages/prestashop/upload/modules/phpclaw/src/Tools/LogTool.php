<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that reads application logs from the filesystem.
 */
final class LogTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_OUTPUT_BYTES = ToolOutputEncoder::MAX_OUTPUT_BYTES;

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const DEFAULT_LINES = 100;

    private const MAX_LINES = 500;

    private const LOG_LEVELS = ['error', 'warning', 'fatal', 'notice', 'deprecated'];

    private const LEVEL_TOKENS = [
        'error' => ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],
        'fatal' => ['CRITICAL', 'ALERT', 'EMERGENCY'],
        'warning' => ['WARNING'],
        'notice' => ['NOTICE'],
    ];

    private const ALLOWED_KEYS = ['schema', 'aggregate', 'lines', 'search', 'level'];

    private ?string $resolvedPath = null;

    /**
     * Create a new LogTool instance.
     *
     * @param  string  $rootDir  PrestaShop root directory (_PS_ROOT_DIR_)
     */
    public function __construct(
        private readonly string $rootDir,
    ) {}

    /**
     * Return the canonical tool name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'ps_log';
    }

    /**
     * Return a rich description including capabilities, log levels, and examples.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
        READ the PrestaShop application log file (dev.log).

        CAPABILITIES:
        - Tail mode (default): return the last N lines (default 100, max 500)
        - Search mode: grep for a regex/string pattern in the log
        - Level filter: show only lines matching error/warning/fatal/notice/deprecated
        - Schema mode: return log file path, existence, and size
        - Aggregate mode: total lines + counts per level + last_error timestamp

        LOG LEVELS: error, warning, fatal, notice, deprecated

        EXAMPLES:
        {"lines": 100}
        {"search": "out of memory"}
        {"level": "error", "lines": 50}
        {"schema": true}
        {"aggregate": true}

        Invoke. Never guess log data, read the actual file.
        DESC;
    }

    /**
     * Return the JSON Schema for accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'When true, return log file path, existence, and size.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'When true, return line counts by level and last_error timestamp.',
                ],
                'lines' => [
                    'type' => 'integer',
                    'description' => 'Number of tail lines to return (default 100, max 500).',
                    'minimum' => 1,
                    'maximum' => self::MAX_LINES,
                    'default' => self::DEFAULT_LINES,
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Grep pattern: return only lines containing this string.',
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'Filter by log level: error, warning, fatal, notice, or deprecated.',
                    'enum' => self::LOG_LEVELS,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Return the back-office tab grant required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Whether this tool may be offered to the model. PrestaShop evaluates employee permissions when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['diagnostics', 'system'],
            tags: ['log', 'logs', 'error', 'errors', 'warning', 'notice', 'debug', 'severity', 'trace', 'exception', 'tail'],
            intents: ['read the log', 'show recent errors', 'tail the error log'],
            examples: ['show me the most recent error log entries'],
        );
    }

    /**
     * Plan the execution: authorise first, then validate, before any file is opened.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read the PrestaShop application log');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Read the planned slice of the log file.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        $logPath = $this->resolveLogPath();

        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => $this->schemaData($logPath)];
        }

        if (($input['aggregate'] ?? false) === true) {
            return ['type' => 'aggregate', 'payload' => $this->aggregateData($logPath)];
        }

        return ['type' => 'query', 'payload' => $this->queryData($logPath, $input)];
    }

    /**
     * Verify the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['lines'] ?? null)) {
            throw new ToolException('ps_log: the log read returned an incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Complete a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $payload = $execution['payload'];

        if ($execution['type'] === 'schema') {
            return $this->success($payload, ['mode' => 'schema', 'file_read' => false]);
        }

        if ($execution['type'] === 'aggregate') {
            return $this->success($payload, ['mode' => 'aggregate']);
        }

        return $this->success(
            ['lines' => $payload['lines']],
            [
                'mode' => 'query',
                'path' => $payload['path'],
                'total_matching' => $payload['total_matching'],
                'requested_lines' => $payload['requested_lines'],
                'shown' => count($payload['lines']),
                'truncated' => $payload['truncated'],
                'max_output_bytes' => self::MAX_OUTPUT_BYTES,
            ],
            $payload['warnings'],
        );
    }

    /**
     * Read the tail of the log, optionally filtered by search pattern and log level.
     *
     * @param  string  $logPath  Resolved log file path.
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the log file cannot be read.
     */
    private function queryData(string $logPath, array $input): array
    {
        $this->assertFileReadable($logPath);

        $requestedLines = min(
            max(1, (int) ($input['lines'] ?? self::DEFAULT_LINES)),
            self::MAX_LINES,
        );
        $search = isset($input['search']) ? (string) $input['search'] : null;
        $level = isset($input['level']) ? strtolower((string) $input['level']) : null;

        $allLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($allLines === false) {
            throw new ToolException('ps_log: failed to read log file.');
        }

        if ($level !== null) {
            $allLines = array_values(array_filter(
                $allLines,
                static fn (string $line): bool => self::lineHasLevel($line, $level),
            ));
        }

        if ($search !== null && $search !== '') {
            $allLines = array_values(array_filter(
                $allLines,
                static fn (string $line): bool => stripos($line, $search) !== false,
            ));
        }

        $totalMatching = count($allLines);
        $capped = ToolOutputEncoder::cap(array_slice($allLines, -$requestedLines), self::MAX_ROW_BYTES);
        $warnings = ToolOutputEncoder::warnings($capped);

        if ($totalMatching > $requestedLines) {
            $warnings[] = [
                'code' => 'PARTIAL_TAIL',
                'message' => sprintf(
                    '%d lines match; the last %d are shown. Raise "lines" or narrow the filters.',
                    $totalMatching,
                    $requestedLines,
                ),
            ];
        }

        return [
            'lines' => $capped['rows'],
            'path' => $logPath,
            'total_matching' => $totalMatching,
            'requested_lines' => $requestedLines,
            'truncated' => $capped['truncated'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Decide whether a log line belongs to the requested level.
     *
     * @param  string  $line  A single raw log line.
     * @param  string  $level  One of the values in LOG_LEVELS.
     * @return bool True when the line carries that level.
     */
    private static function lineHasLevel(string $line, string $level): bool
    {
        $upper = strtoupper($line);

        if (! isset(self::LEVEL_TOKENS[$level])) {
            return str_contains($upper, strtoupper($level));
        }

        foreach (self::LEVEL_TOKENS[$level] as $token) {
            if (preg_match('/(?<![A-Z_])'.$token.':/', $upper) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count log lines per level and find the most recent error timestamp.
     *
     * @param  string  $logPath  Resolved log file path.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the log file cannot be opened.
     */
    private function aggregateData(string $logPath): array
    {
        $this->assertFileReadable($logPath);

        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            throw new ToolException('ps_log: unable to open log file for reading.');
        }

        $totalLines = 0;
        $counts = array_fill_keys(self::LOG_LEVELS, 0);
        $lastError = null;
        $lastErrorLine = null;

        while (($line = fgets($handle)) !== false) {
            $totalLines++;

            foreach (self::LOG_LEVELS as $level) {
                if (! self::lineHasLevel($line, $level)) {
                    continue;
                }

                $counts[$level]++;

                if ($level !== 'error' && $level !== 'fatal') {
                    continue;
                }

                $ts = $this->extractTimestamp($line);

                if ($ts !== null) {
                    $lastError = $ts;
                    $lastErrorLine = trim($line);
                }
            }
        }

        fclose($handle);

        $data = [
            'total_lines' => $totalLines,
            'counts' => $counts,
            'last_error' => $lastError,
        ];

        if ($lastErrorLine !== null) {
            $data['last_error_line'] = mb_substr($lastErrorLine, 0, 300);
        }

        return $data;
    }

    /**
     * Report the log file path, existence, and size without reading its contents.
     *
     * @param  string  $logPath  Resolved log file path.
     * @return array<string, mixed> Schema discovery data.
     */
    private function schemaData(string $logPath): array
    {
        $exists = file_exists($logPath);

        return [
            'path' => $logPath,
            'exists' => $exists,
            'size_bytes' => $exists ? (int) filesize($logPath) : 0,
            'size_human' => $exists ? $this->humanSize((int) filesize($logPath)) : '0 B',
            'levels' => self::LOG_LEVELS,
            'modes' => ['schema', 'aggregate', 'query'],
            'limits' => [
                'default_lines' => self::DEFAULT_LINES,
                'maximum_lines' => self::MAX_LINES,
            ],
        ];
    }

    /**
     * Validate runtime input and return a structured error when it is unusable.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validate(array $input): ?string
    {
        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return $unknown;
        }

        foreach (['schema', 'aggregate'] as $flag) {
            if (array_key_exists($flag, $input) && ! is_bool($input[$flag])) {
                return $this->error('INVALID_ARGUMENT', sprintf('"%s" must be a boolean.', $flag));
            }
        }

        if (($input['schema'] ?? false) === true && ($input['aggregate'] ?? false) === true) {
            return $this->error('CONFLICTING_MODES', 'Set only one of "schema" or "aggregate".');
        }

        if (array_key_exists('lines', $input) && ! is_numeric($input['lines'])) {
            return $this->error('INVALID_ARGUMENT', '"lines" must be a number.');
        }

        if (
            array_key_exists('level', $input)
            && ! in_array(strtolower((string) $input['level']), self::LOG_LEVELS, true)
        ) {
            return $this->error(
                'INVALID_ARGUMENT',
                sprintf('"level" must be one of: %s.', implode(', ', self::LOG_LEVELS)),
                ['accepted_levels' => self::LOG_LEVELS],
            );
        }

        return null;
    }

    /**
     * Return the newest dated PrestaShop log, the real filename shape in var/logs.
     *
     * @return string|null Absolute path, or null when none exist.
     */
    private function newestDatedLog(): ?string
    {
        $found = [];

        foreach (['/var/logs/', '/log/'] as $dir) {
            foreach (['dev', 'prod'] as $env) {
                $found = array_merge($found, glob($this->rootDir.$dir.$env.'-*.log') ?: []);
            }
        }

        if ($found === []) {
            return null;
        }

        usort($found, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $found[0];
    }

    /**
     * Resolve and memoise the PrestaShop log file path.
     *
     * @return string First existing candidate, else the newest dated log, else the default var/logs/dev.log path.
     */
    private function resolveLogPath(): string
    {
        if ($this->resolvedPath !== null) {
            return $this->resolvedPath;
        }

        $candidates = [
            $this->rootDir.'/var/logs/dev.log',
            $this->rootDir.'/var/logs/prod.log',
            $this->rootDir.'/log/dev.log',
            $this->rootDir.'/log/prod.log',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $this->resolvedPath = $candidate;

                return $candidate;
            }
        }

        $dated = $this->newestDatedLog();

        $this->resolvedPath = $dated ?? $candidates[0];

        return $this->resolvedPath;
    }

    /**
     * Assert the log file exists and is readable.
     *
     * @param  string  $path  Resolved log file path.
     * @return void
     *
     * @throws ToolException When the file is missing or unreadable.
     */
    private function assertFileReadable(string $path): void
    {
        if (! file_exists($path)) {
            throw new ToolException(
                "ps_log: log file not found at {$path}. "
                .'Checked var/logs/ and log/ for dev.log, prod.log, and dated dev-YYYY-MM-DD.log files.',
            );
        }

        if (! is_readable($path)) {
            throw new ToolException("ps_log: log file is not readable at {$path}.");
        }
    }

    /**
     * Extract a timestamp from a log line.
     *
     * @param  string  $line  One raw log line.
     * @return string|null The timestamp, or null when the line carries none.
     */
    private function extractTimestamp(string $line): ?string
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})]/', $line, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Convert a byte count to a human-readable string.
     *
     * @param  int  $bytes  Size in bytes.
     * @return string
     */
    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
