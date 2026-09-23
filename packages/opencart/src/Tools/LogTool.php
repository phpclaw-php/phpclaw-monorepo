<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Log tool: read, search, filter, and aggregate error.log.
 */
final class LogTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_ACTION = 'access';

    private const MAX_LINES = 200;

    private const DEFAULT_LINES = 50;

    private const MAX_OUTPUT_BYTES = ToolOutputEncoder::MAX_OUTPUT_BYTES;

    private const VALID_LEVELS = ['error', 'warning', 'fatal', 'notice', 'deprecated', 'parse'];

    private const ALLOWED_KEYS = ['schema', 'aggregate', 'lines', 'search', 'level'];

    private const TIMESTAMP_PATTERNS = [
        '/^\[(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s/',
        '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/',
        '/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/',
        '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/',
    ];

    /**
     * Bind the log root directory and the caller's module grant.
     *
     * @param  string  $rootDir  Root directory used to resolve the log file path.
     *                           Pass empty string to auto-detect from OpenCart constants.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly bool $callerMayUseModule,
    ) {}

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'read_log';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Read, search, filter, and analyse the OpenCart error log (system/storage/logs/error.log).

CAPABILITIES:
  - Tail the last N lines (default 50, max 200)
  - Filter by severity level (error, warning, fatal, notice, deprecated, parse)
  - Search by substring pattern (case-insensitive)
  - Aggregate mode: total lines, error/warning/fatal/notice counts, last error

LOG LEVELS DETECTED:
  error, warning, fatal, notice, deprecated, parse

EXAMPLES:
  "Show recent errors"              -> level: "error"
  "Last 100 log lines"              -> lines: 100
  "Search for database errors"      -> search: "database"
  "Error summary"                   -> aggregate: true
  "Fatal errors only, last 20"      -> level: "fatal", lines: 20
  "Deprecated notices"              -> level: "deprecated"
DESC;
    }

    /**
     * Return the JSON Schema describing the tool's accepted input parameters.
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
                    'description' => 'true = return log file metadata (path, exists, size_bytes, size_human, readable). No log content read.',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = return statistics only: total_lines, error_count, warning_count, fatal_count, notice_count, deprecated_count, last_error.',
                ],
                'lines' => [
                    'type' => 'integer',
                    'description' => 'Number of lines to read from the end of the log (default 50, max 200).',
                    'default' => self::DEFAULT_LINES,
                    'minimum' => 1,
                    'maximum' => self::MAX_LINES,
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Grep pattern: only lines containing this substring (case-insensitive) are returned. Applied after level filter.',
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'Filter to lines matching this severity level.',
                    'enum' => self::VALID_LEVELS,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Return the action a caller must hold to reach this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_ACTION;
    }

    /**
     * Authorise the caller and validate input before the log is opened.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read the OpenCart application log');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        if (isset($input['level']) && ! in_array((string) $input['level'], self::VALID_LEVELS, true)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'INVALID_LEVEL',
                    sprintf('"level" must be one of: %s.', implode(', ', self::VALID_LEVELS)),
                    ['accepted_levels' => self::VALID_LEVELS],
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Read the log in the mode the input selected.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the log file cannot be read.
     */
    protected function perform(array $input): array
    {
        $logPath = $this->resolveLogPath();

        if (! empty($input['schema'])) {
            return ['type' => 'schema', 'payload' => $this->schemaData($logPath)];
        }

        $this->assertFileReadable($logPath);

        if (! empty($input['aggregate'])) {
            return ['type' => 'aggregate', 'payload' => $this->aggregateData($logPath)];
        }

        return ['type' => 'query', 'payload' => $this->queryData($logPath, $input)];
    }

    /**
     * Check the raw execution result before it becomes the final model result.
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
            throw new ToolException('read_log: the log read returned an incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Convert a verified execution into the public response envelope.
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
                'requested_lines' => $payload['requested_lines'],
                'shown' => count($payload['lines']),
                'level' => $payload['level'],
                'search' => $payload['search'],
                'truncated' => $payload['truncated'],
                'max_output_bytes' => self::MAX_OUTPUT_BYTES,
            ],
        );
    }

    /**
     * Return log file metadata without reading content.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @return array<string, mixed>
     */
    private function schemaData(string $logPath): array
    {
        $exists = file_exists($logPath);
        $size = $exists ? (int) filesize($logPath) : 0;

        return [
            'path' => $logPath,
            'exists' => $exists,
            'size_bytes' => $size,
            'size_human' => $this->humanFileSize($size),
            'readable' => $exists && is_readable($logPath),
            'valid_levels' => self::VALID_LEVELS,
            'max_lines' => self::MAX_LINES,
            'default_lines' => self::DEFAULT_LINES,
            'max_output_bytes' => self::MAX_OUTPUT_BYTES,
            'capabilities' => ['tail', 'level_filter', 'search', 'schema', 'aggregate'],
        ];
    }

    /**
     * Refuse a log file that is missing or unreadable.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @return void
     *
     * @throws ToolException When the file is missing or unreadable.
     */
    private function assertFileReadable(string $logPath): void
    {
        if (! file_exists($logPath)) {
            throw new ToolException("read_log: error.log not found at '{$logPath}'. Check DIR_LOGS or DIR_STORAGE in config.php.");
        }

        if (! is_readable($logPath)) {
            throw new ToolException("read_log: error.log is not readable at '{$logPath}'.");
        }
    }

    /**
     * Return aggregate statistics by scanning the entire log file.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @return array<string, mixed>
     *
     * @throws ToolException If the file cannot be opened.
     */
    private function aggregateData(string $logPath): array
    {
        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            throw new ToolException('read_log: could not open log file for aggregate scan.');
        }

        $totalLines = 0;
        $errorCount = 0;
        $warningCount = 0;
        $fatalCount = 0;
        $noticeCount = 0;
        $deprecatedCount = 0;
        $parseCount = 0;
        $lastError = null;
        $lastErrorTs = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $totalLines++;
                $upper = strtoupper($line);

                if (strpos($upper, 'FATAL') !== false) {
                    $fatalCount++;
                    $lastError = trim($line);
                    $lastErrorTs = $this->extractTimestamp($line) ?? $lastErrorTs;
                } elseif (strpos($upper, 'ERROR') !== false) {
                    $errorCount++;
                    $lastError = trim($line);
                    $lastErrorTs = $this->extractTimestamp($line) ?? $lastErrorTs;
                } elseif (strpos($upper, 'WARNING') !== false) {
                    $warningCount++;
                } elseif (strpos($upper, 'DEPRECATED') !== false) {
                    $deprecatedCount++;
                } elseif (strpos($upper, 'PARSE') !== false) {
                    $parseCount++;
                } elseif (strpos($upper, 'NOTICE') !== false) {
                    $noticeCount++;
                }
            }
        } finally {
            fclose($handle);
        }

        $size = (int) filesize($logPath);

        return [
            'total_lines' => $totalLines,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'fatal_count' => $fatalCount,
            'notice_count' => $noticeCount,
            'deprecated_count' => $deprecatedCount,
            'parse_count' => $parseCount,
            'last_error' => $lastError,
            'last_error_timestamp' => $lastErrorTs,
            'size_bytes' => $size,
            'size_human' => $this->humanFileSize($size),
        ];
    }

    /**
     * Tail the log file, or return the last matching lines of the whole log when a level or search filter is set.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return array<string, mixed>
     *
     * @throws ToolException If the file cannot be opened.
     */
    private function queryData(string $logPath, array $input): array
    {
        $lineCount = isset($input['lines']) ? (int) $input['lines'] : self::DEFAULT_LINES;
        $lineCount = max(1, min($lineCount, self::MAX_LINES));
        $level = isset($input['level']) && is_string($input['level']) ? strtolower($input['level']) : null;
        $search = isset($input['search']) && is_string($input['search']) && trim($input['search']) !== ''
            ? trim($input['search'])
            : null;

        $terms = array_values(array_filter([$level, $search], static fn (?string $term): bool => $term !== null));

        $lines = $terms === []
            ? $this->readLastLines($logPath, $lineCount)
            : $this->readLastMatches($logPath, $lineCount, $terms);

        $truncated = false;
        $bytes = 0;
        $kept = [];

        foreach ($lines as $line) {
            $bytes += strlen($line) + 1;

            if ($bytes > self::MAX_OUTPUT_BYTES) {
                $truncated = true;
                break;
            }

            $kept[] = $line;
        }

        return [
            'lines' => $kept,
            'path' => $logPath,
            'requested_lines' => $lineCount,
            'level' => $level,
            'search' => $search,
            'truncated' => $truncated,
        ];
    }

    /**
     * Resolve the absolute path to the OpenCart error log file.
     *
     * @return string Absolute path to the log file.
     */
    private function resolveLogPath(): string
    {
        if ($this->rootDir !== '') {
            $candidate = rtrim($this->rootDir, '/').'/system/storage/logs/error.log';

            if (file_exists($candidate)) {
                return $candidate;
            }

            $storageCandidate = rtrim($this->rootDir, '/').'/storage/logs/error.log';

            if (file_exists($storageCandidate)) {
                return $storageCandidate;
            }

            return $candidate;
        }

        if (defined('DIR_LOGS')) {
            return rtrim(DIR_LOGS, '/').'/error.log';
        }

        if (defined('DIR_STORAGE')) {
            return rtrim(DIR_STORAGE, '/').'/logs/error.log';
        }

        return 'system/storage/logs/error.log';
    }

    /**
     * Scan the whole log and keep the last $count lines that contain every term, case-insensitive.
     *
     * @param  string  $path  Absolute path to the log file.
     * @param  int  $count  Maximum number of matching lines to keep.
     * @param  string[]  $terms  Substrings a line must contain, all of them.
     * @return string[] Last matching lines, newest last.
     *
     * @throws ToolException If the file cannot be opened.
     */
    private function readLastMatches(string $path, int $count, array $terms): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new ToolException('read_log: could not open log file.');
        }

        $kept = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\n");

                foreach ($terms as $term) {
                    if (stripos($line, $term) === false) {
                        continue 2;
                    }
                }

                $kept[] = $line;

                if (count($kept) > $count) {
                    array_shift($kept);
                }
            }
        } finally {
            fclose($handle);
        }

        return $kept;
    }

    /**
     * Read the last $count lines from a file using a reverse seek.
     *
     * @param  string  $path  Absolute path to the log file.
     * @param  int  $count  Number of lines to read from the end.
     * @return string[]
     *
     * @throws ToolException If the file cannot be opened.
     */
    private function readLastLines(string $path, int $count): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new ToolException('read_log: could not open log file.');
        }

        try {
            fseek($handle, 0, SEEK_END);
            $fileSize = ftell($handle);

            if ($fileSize === 0) {
                return [];
            }

            $buffer = '';
            $collected = 0;
            $position = $fileSize;
            $chunkSize = 4096;

            while ($position > 0 && $collected < $count) {
                $readSize = min($chunkSize, $position);
                $position -= $readSize;
                fseek($handle, $position);
                $chunk = fread($handle, $readSize);
                if ($chunk === false) {
                    break;
                }
                $buffer = $chunk.$buffer;

                $collected = substr_count($buffer, "\n");

                if ($collected > $count + 1) {
                    $lines = explode("\n", $buffer);
                    $buffer = implode("\n", array_slice($lines, -($count + 1)));
                    break;
                }
            }

            $allLines = explode("\n", $buffer);

            if (end($allLines) === '') {
                array_pop($allLines);
            }

            return array_slice($allLines, -$count);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extract a timestamp from a log line using known OpenCart/PHP log formats.
     *
     * @param  string  $line  A single log line.
     * @return string|null The extracted timestamp string, or null if none found.
     */
    private function extractTimestamp(string $line): ?string
    {
        foreach (self::TIMESTAMP_PATTERNS as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Format a byte count into a human-readable size string.
     *
     * @param  int  $bytes  File size in bytes.
     * @return string Formatted size (e.g. "1.5 MB").
     */
    private function humanFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024.0 && $index < count($units) - 1) {
            $size /= 1024.0;
            $index++;
        }

        return round($size, 2).' '.$units[$index];
    }

    /**
     * Whether this tool may be offered to the model. OpenCart evaluates module access when the tool runs, so every tool stays eligible for routing.
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
            tags: ['log', 'logs', 'error', 'errors', 'warning', 'notice', 'debug', 'trace', 'exception', 'tail'],
            intents: ['read the log', 'show recent errors', 'tail the error log'],
            examples: ['show me the last lines of the error log'],
        );
    }
}
