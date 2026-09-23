<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Service\OutputByteCap;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that reads the last N lines from the Magento log file.
 */
// non-final: Magento interceptor required
class LogTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    private const MAX_LINES = 200;

    private const DEFAULT_LINES = 50;

    private const VALID_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Bind the identity resolver, the ACL service and the log file path this tool reads.
     *
     * @param  IdentityResolver  $identityResolver  Resolver reporting the area and the acting admin identity.
     * @param  AuthorizationInterface  $acl  Magento authorization service.
     * @param  string  $logPath  Absolute path to the log file; empty string triggers auto-resolution via BP constant.
     * @return void
     */
    public function __construct(
        private readonly IdentityResolver $identityResolver,
        private readonly AuthorizationInterface $acl,
        private readonly string $logPath = '',
    ) {}

    /**
     * Return the ACL resource a caller must hold to read the log.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Whether this tool may be offered to the model. Magento evaluates ACL when the tool runs, so every tool stays eligible for routing.
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
            tags: ['log', 'logs', 'error', 'errors', 'warning', 'notice', 'debug', 'exception', 'trace', 'system', 'tail'],
            intents: ['read the log', 'show recent errors', 'tail the exception log'],
            examples: ['show me the most recent exception log entries'],
        );
    }

    /**
     * Return the identity resolver the contract trait reads the area from.
     *
     * @return IdentityResolver
     */
    protected function identity(): IdentityResolver
    {
        return $this->identityResolver;
    }

    /**
     * Return the ACL service the contract trait checks capabilities against.
     *
     * @return AuthorizationInterface
     */
    protected function authorization(): AuthorizationInterface
    {
        return $this->acl;
    }

    /**
     * Returns the tool identifier used in agent tool dispatch.
     *
     * @return string
     */
    public function name(): string
    {
        return 'read_log';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'READ last N lines of the Magento system log (var/log/system.log). Use for PHP errors, Magento errors, recent activity. Invoke it, never guess log content.';
    }

    /**
     * Returns the JSON Schema object describing accepted inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lines' => [
                    'type' => 'integer',
                    'description' => 'Number of lines to read from the end of the log (default 50, max 200)',
                    'default' => self::DEFAULT_LINES,
                    'minimum' => 1,
                    'maximum' => self::MAX_LINES,
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'Filter to only show lines containing this level keyword',
                    'enum' => self::VALID_LEVELS,
                ],
            ],
        ];
    }

    /**
     * Guard the caller, validate the input and confirm the log is readable.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read the Magento system log');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, ['lines', 'level']);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        if (isset($input['level'])) {
            if (! is_string($input['level']) || ! in_array(strtolower($input['level']), self::VALID_LEVELS, true)) {
                return [
                    'input' => $input,
                    'result' => $this->error(
                        'INVALID_ARGUMENT',
                        sprintf('"level" must be one of: %s.', implode(', ', self::VALID_LEVELS)),
                    ),
                ];
            }
        }

        $logPath = $this->resolveLogPath();

        if (! file_exists($logPath) || ! is_readable($logPath)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'LOG_UNAVAILABLE',
                    sprintf('The Magento log at "%s" is missing or not readable.', $logPath),
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Read the requested tail of the log file.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the log file cannot be opened.
     */
    protected function perform(array $input): array
    {
        $requested = isset($input['lines']) ? (int) $input['lines'] : self::DEFAULT_LINES;
        $lines = max(1, min($requested, self::MAX_LINES));
        $level = isset($input['level']) && is_string($input['level']) ? strtolower($input['level']) : null;

        $logPath = $this->resolveLogPath();
        $read = $this->readLastLines($logPath, $lines);

        if ($level !== null) {
            $read = array_values(
                array_filter($read, static fn (string $line): bool => stripos($line, $level) !== false),
            );
        }

        return [
            'path' => $logPath,
            'level' => $level,
            'requested_lines' => $lines,
            'lines' => $read,
        ];
    }

    /**
     * Confirm the execution produced a line list before it becomes the model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['lines'] ?? null)) {
            throw new ToolException('read_log: returned an incomplete log result.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified log read into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $lines = $execution['lines'];
        $text = OutputByteCap::truncate(implode("\n", $lines));

        return $this->success(
            [
                'text' => $text,
                'lines' => $lines,
            ],
            [
                'path' => $execution['path'],
                'level' => $execution['level'],
                'requested_lines' => $execution['requested_lines'],
                'returned_lines' => count($lines),
                'truncated' => $text !== implode("\n", $lines),
            ],
        );
    }

    /**
     * Return the injected log path, or auto-discover it via the Magento BP constant.
     *
     * @return string Absolute path to the log file to read.
     *
     * @throws ToolException When the Magento base path cannot be resolved.
     */
    private function resolveLogPath(): string
    {
        if ($this->logPath !== '') {
            return $this->logPath;
        }

        if (! defined('BP')) {
            throw new ToolException('read_log: cannot resolve the Magento base path (BP undefined); provide an explicit log path.');
        }

        $systemLog = BP.'/var/log/system.log';
        $exceptionLog = BP.'/var/log/exception.log';

        return file_exists($systemLog) ? $systemLog : $exceptionLog;
    }

    /**
     * Read the last $count lines from a file using a reverse-seek chunked approach.
     *
     * @param  string  $path  Absolute path to the log file.
     * @param  int  $count  Number of trailing lines to return.
     * @return string[]
     *
     * @throws ToolException When the file cannot be opened.
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
}
