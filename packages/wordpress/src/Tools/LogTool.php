<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Log tool - read-only access to the debug log.
 */
final class LogTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const MAX_LINES = 200;

    private const DEFAULT_LINES = 50;

    private const MAX_OUTPUT_BYTES = OutputByteCap::MAX_OUTPUT_BYTES;

    private const MAX_SEARCH_LENGTH = 255;

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'wordpress.logs.read';

    private const RISK_LEVEL = 'read';

    private const VALID_LEVELS = ['error', 'warning', 'fatal', 'notice', 'deprecated', 'parse'];

    private const TIMESTAMP_PATTERNS = [
        '/^\[(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s/',
        '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/',
        '/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/',
    ];

    private const ALLOWED_KEYS = [
        'schema',
        'aggregate',
        'lines',
        'search',
        'level',
    ];

    public const EXAMPLES = [
        [
            'prompt' => 'what is in the error log right now?',
            'arguments' => [],
        ],
        [
            'prompt' => 'how many errors has this site logged in total?',
            'arguments' => ['aggregate' => true],
        ],
        [
            'prompt' => 'show me any fatal errors in the recent log',
            'arguments' => ['level' => 'fatal', 'lines' => 200],
        ],
    ];

    /**
     * Bind the log file path this tool reads, or empty to auto-detect it.
     *
     * @param  string  $logPath  Absolute path to the log file. Empty string triggers auto-detection.
     */
    public function __construct(
        private readonly string $logPath = '',
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
     * Return the phpClaw capability identifier this tool exercises.
     *
     * @return string
     */
    public function capability(): string
    {
        return self::PHPCLAW_CAPABILITY;
    }

    /**
     * Return the WordPress capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the risk classification for this tool.
     *
     * @return string
     */
    public function risk(): string
    {
        return self::RISK_LEVEL;
    }

    /**
     * Report whether repeated identical calls produce the same result.
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /**
     * Return worked example prompts for this tool.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Read the WordPress debug log. READ-ONLY.
Requires the "phpclaw_use_chat" capability.

MODES
  schema=true     Log path, existence, size and permissions. No log read.
  aggregate=true  Counts by severity plus the last error. No log lines returned.
  default         Tail the most recent lines, newest last.

NEVER USE FOR
  Writing, rotating, truncating or deleting the log; reading any other file.
  This tool cannot perform those operations.

NOTES
  Levels: error, warning, fatal, notice, deprecated, parse.
  Default 50 lines, maximum 200. Output is capped at 8 KB.
  A missing or unreadable log returns LOG_UNAVAILABLE, not an empty result.
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
            'additionalProperties' => false,
            'properties' => [
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'Return log file metadata without reading its contents.',
                    'default' => false,
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'Return severity counts and the last error. No log lines returned.',
                    'default' => false,
                ],
                'lines' => [
                    'type' => 'integer',
                    'description' => 'Number of lines to read from the end of the log.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LINES,
                    'default' => self::DEFAULT_LINES,
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Only lines containing this substring, case-insensitive.',
                    'minLength' => 1,
                    'maxLength' => self::MAX_SEARCH_LENGTH,
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'Only lines matching this severity level.',
                    'enum' => self::VALID_LEVELS,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Plan the execution by enforcing authorization, validating input, and determining the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read the debug log');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (($input['schema'] ?? false) === true) {
            return ['input' => $input, 'result' => null];
        }

        $logPath = $this->resolveLogPath();

        if (! file_exists($logPath) || ! is_readable($logPath)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'LOG_UNAVAILABLE',
                    'The WordPress debug log is missing or not readable. Enable WP_DEBUG_LOG to create it.',
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Execute the planned log read without handling model policy.
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

        return ['type' => 'query', 'payload' => $this->tailData($logPath, $input)];
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
            throw new ToolException('LogTool returned an incomplete log result.');
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
            return $this->success($payload, [
                'mode' => 'schema',
                'database_query_performed' => false,
            ]);
        }

        if ($execution['type'] === 'aggregate') {
            $warnings = [];

            foreach (['lines', 'search', 'level'] as $argument) {
                if (array_key_exists($argument, $input)) {
                    $warnings[] = [
                        'code' => 'IGNORED_ARGUMENT',
                        'message' => sprintf(
                            '"%s" was ignored because aggregate mode summarises the whole log.',
                            $argument,
                        ),
                    ];
                }
            }

            return $this->success($payload, ['mode' => 'aggregate'], $warnings);
        }

        $warnings = [];

        if ($payload['truncated']) {
            $warnings[] = [
                'code' => 'OUTPUT_TRUNCATED',
                'message' => sprintf(
                    'Output was capped at %d bytes. %d of %d matching lines are included.',
                    self::MAX_OUTPUT_BYTES,
                    count($payload['lines']),
                    $payload['matched'],
                ),
            ];
        }

        return $this->success(
            ['lines' => $payload['lines']],
            [
                'mode' => 'query',
                'count' => count($payload['lines']),
                'matched' => $payload['matched'],
                'requested_lines' => $payload['requested'],
                'truncated' => $payload['truncated'],
                'filters' => $payload['filters'],
            ],
            $warnings,
        );
    }

    /**
     * Read the log tail, or the last matching lines of the whole log when a level or search filter is set.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Tail result data.
     */
    private function tailData(string $logPath, array $input): array
    {
        $requested = isset($input['lines']) ? (int) $input['lines'] : self::DEFAULT_LINES;
        $level = isset($input['level']) ? (string) $input['level'] : null;
        $search = isset($input['search']) ? trim((string) $input['search']) : null;

        $terms = array_values(array_filter(
            [$level, $search],
            static fn (?string $term): bool => $term !== null && $term !== '',
        ));

        $all = $terms === []
            ? $this->readLastLines($logPath, $requested)
            : $this->readLastMatches($logPath, $requested, $terms);

        $matched = count($all);
        $kept = [];
        $bytes = 0;
        $truncated = false;

        foreach ($all as $line) {
            $length = strlen($line) + 1;

            if ($bytes + $length > self::MAX_OUTPUT_BYTES) {
                $truncated = true;

                break;
            }

            $kept[] = $line;
            $bytes += $length;
        }

        return [
            'lines' => $kept,
            'matched' => $matched,
            'requested' => $requested,
            'truncated' => $truncated,
            'filters' => [
                'level' => $level,
                'search' => $search,
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

        if (array_key_exists('lines', $input)) {
            if (! is_int($input['lines']) || $input['lines'] < 1 || $input['lines'] > self::MAX_LINES) {
                return $this->error(
                    'INVALID_LINES',
                    sprintf('"lines" must be an integer between 1 and %d.', self::MAX_LINES),
                );
            }
        }

        if (array_key_exists('level', $input)
            && (! is_string($input['level']) || ! in_array($input['level'], self::VALID_LEVELS, true))) {
            return $this->error(
                'INVALID_LEVEL',
                '"level" must be one of: '.implode(', ', self::VALID_LEVELS).'.',
                ['valid_levels' => self::VALID_LEVELS],
            );
        }

        if (array_key_exists('search', $input)) {
            if (! is_string($input['search']) || trim($input['search']) === '') {
                return $this->error('INVALID_SEARCH', '"search" must be a non-empty string.');
            }

            if (mb_strlen($input['search']) > self::MAX_SEARCH_LENGTH) {
                return $this->error(
                    'SEARCH_TOO_LONG',
                    sprintf('"search" may not exceed %d characters.', self::MAX_SEARCH_LENGTH),
                );
            }
        }

        return null;
    }

    /**
     * Build log file metadata without reading its contents.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @return array<string, mixed> Schema metadata.
     */
    private function schemaData(string $logPath): array
    {
        $exists = file_exists($logPath);
        $size = $exists ? (int) filesize($logPath) : 0;

        return [
            'path' => $logPath,
            'exists' => $exists,
            'readable' => $exists && is_readable($logPath),
            'size_bytes' => $size,
            'size_human' => $this->humanFileSize($size),
            'valid_levels' => self::VALID_LEVELS,
            'examples' => self::EXAMPLES,
            'modes' => ['schema', 'aggregate', 'query'],
            'filters' => ['lines', 'search', 'level'],
            'limits' => [
                'default_lines' => self::DEFAULT_LINES,
                'maximum_lines' => self::MAX_LINES,
                'maximum_output_bytes' => self::MAX_OUTPUT_BYTES,
                'maximum_search_length' => self::MAX_SEARCH_LENGTH,
            ],
            'wordpress_capability' => self::REQUIRED_CAPABILITY,
            'phpclaw_capability' => self::PHPCLAW_CAPABILITY,
            'risk' => self::RISK_LEVEL,
            'idempotent' => true,
        ];
    }

    /**
     * Scan the whole log and summarise it by severity.
     *
     * @param  string  $logPath  Absolute path to the log file.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the log cannot be opened.
     */
    private function aggregateData(string $logPath): array
    {
        $handle = fopen($logPath, 'r');

        if ($handle === false) {
            $failure = new \RuntimeException('could not open log file for aggregate scan');

            $this->logExecutionError($failure);

            throw new ToolException('read_log: could not open log file for aggregate scan.', previous: $failure);
        }

        $totalLines = 0;
        $counts = [
            'error' => 0,
            'warning' => 0,
            'fatal' => 0,
            'notice' => 0,
            'deprecated' => 0,
            'parse' => 0,
        ];
        $lastError = null;
        $lastErrorTs = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $totalLines++;
                $upper = strtoupper($line);

                if (str_contains($upper, 'FATAL')) {
                    $counts['fatal']++;
                    $lastError = trim($line);
                    $lastErrorTs = $this->extractTimestamp($line) ?? $lastErrorTs;
                } elseif (str_contains($upper, 'ERROR')) {
                    $counts['error']++;
                    $lastError = trim($line);
                    $lastErrorTs = $this->extractTimestamp($line) ?? $lastErrorTs;
                } elseif (str_contains($upper, 'WARNING')) {
                    $counts['warning']++;
                } elseif (str_contains($upper, 'DEPRECATED')) {
                    $counts['deprecated']++;
                } elseif (str_contains($upper, 'PARSE')) {
                    $counts['parse']++;
                } elseif (str_contains($upper, 'NOTICE')) {
                    $counts['notice']++;
                }
            }
        } finally {
            fclose($handle);
        }

        $size = (int) filesize($logPath);

        return [
            'total_lines' => $totalLines,
            'error_count' => $counts['error'],
            'warning_count' => $counts['warning'],
            'fatal_count' => $counts['fatal'],
            'notice_count' => $counts['notice'],
            'deprecated_count' => $counts['deprecated'],
            'parse_count' => $counts['parse'],
            'last_error' => $lastError,
            'last_error_timestamp' => $lastErrorTs,
            'size_bytes' => $size,
            'size_human' => $this->humanFileSize($size),
        ];
    }

    /**
     * Resolve the absolute path to the WordPress debug log file.
     *
     * @return string
     */
    private function resolveLogPath(): string
    {
        if ($this->logPath !== '') {
            return $this->logPath;
        }

        if (defined('WP_DEBUG_LOG')) {
            $debugLog = constant('WP_DEBUG_LOG');
            if (is_string($debugLog)) {
                return $debugLog;
            }
        }

        return (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(__DIR__, 5)).'/debug.log';
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
     * Extract a timestamp from a log line using known WordPress log formats.
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

        while ($size >= 1024.0 && $index < 3) {
            $size /= 1024.0;
            $index++;
        }

        return round($size, 2).' '.$units[$index];
    }

    /**
     * Whether this tool may be offered to the model. WordPress evaluates the caller's capability when the tool runs, so every tool stays eligible for routing.
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
            tags: ['log', 'logs', 'debug', 'error', 'errors', 'warning', 'notice', 'trace', 'stack', 'exception', 'tail'],
            intents: ['read the log', 'show recent errors', 'tail the debug log'],
            examples: ['show me the last lines of the debug log'],
        );
    }
}
