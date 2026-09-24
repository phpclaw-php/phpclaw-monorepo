<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ShellDeniedException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\PerInvocationMutabilityInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\Security\BlockedPaths;

/**
 * Executes shell commands from an explicit allowlist, hard-blocked list enforced, metacharacters stripped.
 */
#[Tool(
    name: 'shell',
    description: 'Execute shell commands from an explicit allowlist.',
    since: '1.0.0',
    default: true,
    needsConfig: ['allowlist' => 'array'],
)]
final class ShellTool implements AuthorizableToolInterface, MutatingToolInterface, PerInvocationMutabilityInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    public const DEFAULT_MAX_STDOUT_BYTES = 8192;

    public const DEFAULT_MAX_STDERR_BYTES = 10000;

    private const PROCESS_TIMEOUT_SECONDS = 5;

    private const HARD_READ_CEILING_BYTES = 10485760;

    private const DEFAULT_ALLOWLIST = [
        'ls', 'pwd', 'df',
        'cat', 'head', 'tail', 'grep', 'wc',
        'date', 'uptime', 'hostname', 'whoami',
    ];

    private const READONLY_COMMANDS = self::DEFAULT_ALLOWLIST;

    private const HARD_BLOCKED = [
        'rm', 'mv', 'dd', 'mkfs', 'fdisk', 'shred', 'mkfifo', 'mknod',
        'chmod', 'chown', 'chattr',
        'curl', 'wget', 'nc', 'ncat', 'netcat', 'socat', 'ssh', 'scp', 'sftp', 'ftp', 'rsync',
        'kill', 'killall', 'pkill', 'reboot', 'shutdown', 'halt', 'poweroff', 'init',
        'sudo', 'su', 'doas', 'pkexec', 'newgrp', 'chroot',
        'bash', 'sh', 'zsh', 'fish', 'csh', 'ksh', 'tcsh', 'dash',
        'python', 'python2', 'python3', 'ruby', 'perl', 'node',
        'npx', 'npm', 'pip', 'pip3',
        'at', 'batch', 'nohup', 'crontab',
        'gdb', 'strace', 'ltrace', 'ptrace',
        'ps', 'pstree', 'top', 'htop', 'du', 'find', 'env', 'printenv', 'set', 'export', 'artisan',
    ];

    private const METACHARACTERS = [
        ';', '&', '|', '>', '<', '`', '$',
        '(', ')', '{', '}', '[', ']', '\\', '"', "'", '!',
    ];

    private const FILE_READING_COMMANDS = [
        'cat', 'head', 'tail', 'less', 'more', 'tac', 'nl',
        'grep', 'egrep', 'fgrep', 'awk', 'sed',
        'wc', 'sort', 'uniq', 'cut', 'paste', 'tr',
        'strings', 'xxd', 'hexdump', 'od',
    ];

    private const BLOCKED_FILENAMES = BlockedPaths::FILENAMES;

    private const BLOCKED_EXTENSIONS = BlockedPaths::EXTENSIONS;

    private const BLOCKED_DIR_SEGMENTS = BlockedPaths::SECURITY_DIRS;

    private const BLOCKED_PATH_PATTERNS = [
        '#/etc/(passwd|shadow|group|gshadow|sudoers|hosts|fstab|crontab)#',
        '#/etc/ssh/#',
        '#/etc/ssl/#',
        '#/proc/#',
        '#/sys/#',
        '#^/dev/#',
        '#\.env(\.|$)#',
    ];

    /**
     * Create a new ShellTool instance.
     *
     * @param  string[]  $allowlist  Commands permitted for execution. Defaults to read-only system queries.
     * @param  int  $maxStdoutBytes  Inline stdout byte cap; output beyond this spills to a temp file.
     * @param  int  $maxStderrBytes  Inline stderr byte cap; output beyond this spills to a temp file.
     * @return void
     */
    public function __construct(
        private readonly array $allowlist = self::DEFAULT_ALLOWLIST,
        private readonly int $maxStdoutBytes = self::DEFAULT_MAX_STDOUT_BYTES,
        private readonly int $maxStderrBytes = self::DEFAULT_MAX_STDERR_BYTES,
    ) {}

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return 'shell_exec';
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'EXECUTE a shell command and return real stdout. Use for system state, versions, processes, dates. Invoke: do not describe.';
    }

    /**
     * JSON Schema describing the tool's `command` input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The shell command to execute (e.g. "ls -la /var/log").',
                ],
            ],
            'required' => ['command'],
        ];
    }

    /**
     * Authorize the caller and validate the command against the allowlist and blocklists.
     *
     * @param  array<string, mixed>  $input  Must contain 'command' key with the shell command to run.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException If no command is provided.
     * @throws ShellDeniedException If the command is hard-blocked or not in the allowlist.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('run a shell command');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $command = trim((string) ($input['command'] ?? ''));

        if ($command === '') {
            throw new ToolException('No command provided.');
        }

        $this->rejectOnMetacharacters($command);

        [$cmdName, $safe] = $this->parseCommand($command);

        $this->enforceCommandBlocklists($command, $cmdName);

        if (in_array($cmdName, self::FILE_READING_COMMANDS, true)) {
            $this->validateFileArguments($safe, $cmdName);
        }

        return [
            'input' => ['command' => $safe, 'command_name' => $cmdName],
            'result' => null,
        ];
    }

    /**
     * Run the validated command and announce it to the hook layer.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException If the process cannot be started.
     */
    protected function perform(array $input): array
    {
        $safe = (string) $input['command'];
        $cmdName = (string) $input['command_name'];

        $output = $this->runProcess($safe, $cmdName);

        HookDispatcher::shellExec($safe, $cmdName);

        return ['command' => $safe, 'output' => $output];
    }

    /**
     * Accept the process output unchanged.
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
     * Convert the process output into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, [
            'mode' => 'shell',
            'command_name' => (string) $input['command_name'],
        ]);
    }

    /**
     * Whether this specific command invocation would have a side effect, lets an approval gate fast-path a benign read (e.g. "ls") without gating every shell_exec call equally. Unparsable or empty input is treated as mutating (fail closed).
     *
     * @param  array<string, mixed>  $input  Same input execute() is about to receive.
     * @return bool True unless the command's first token is in READONLY_COMMANDS.
     */
    public function isMutating(array $input): bool
    {
        $command = trim((string) ($input['command'] ?? ''));

        if ($command === '') {
            return true;
        }

        [$cmdName] = $this->parseCommand($command);

        return ! in_array($cmdName, self::READONLY_COMMANDS, true);
    }

    /**
     * Currently configured command allowlist.
     *
     * @return string[]
     */
    public function allowlist(): array
    {
        return $this->allowlist;
    }

    /**
     * Maximum stdout bytes returned to the LLM.
     *
     * @return int
     */
    public function maxStdoutBytes(): int
    {
        return $this->maxStdoutBytes;
    }

    /**
     * Maximum stderr bytes returned when stdout is empty.
     *
     * @return int
     */
    public function maxStderrBytes(): int
    {
        return $this->maxStderrBytes;
    }

    /**
     * Reject the command outright if it contains any shell metacharacter.
     *
     * @param  string  $command  Raw command string from the LLM.
     * @return void
     *
     * @throws ShellDeniedException When a metacharacter is present.
     */
    private function rejectOnMetacharacters(string $command): void
    {
        foreach (self::METACHARACTERS as $char) {
            if (str_contains($command, $char)) {
                HookDispatcher::shellDenied($command, '', 'metacharacter', $char);

                throw new ShellDeniedException(
                    'Command contains a disallowed shell metacharacter.'
                );
            }
        }
    }

    /**
     * Extract the leading command name from an already-validated command string.
     *
     * @param  string  $command  Raw command string from the LLM.
     * @return array{0: string, 1: string} [cmdName, command]
     */
    private function parseCommand(string $command): array
    {
        preg_match('/^([a-zA-Z0-9_\-]+)/', $command, $matches);
        $cmdName = strtolower($matches[1] ?? '');

        return [$cmdName, $command];
    }

    /**
     * Apply HARD_BLOCKED and allowlist checks. Fires the shell.denied hook on rejection.
     *
     * @param  string  $command  Full command string (used in the hook payload).
     * @param  string  $cmdName  First-word command name.
     * @return void
     *
     * @throws ShellDeniedException When the command is hard-blocked or not in the allowlist.
     */
    private function enforceCommandBlocklists(string $command, string $cmdName): void
    {
        if (in_array($cmdName, self::HARD_BLOCKED, true)) {
            HookDispatcher::shellDenied($command, $cmdName, 'hard_blocked');

            throw new ShellDeniedException(
                "Command '{$cmdName}' is permanently blocked and cannot be executed."
            );
        }

        if (! in_array($cmdName, $this->allowlist, true)) {
            HookDispatcher::shellDenied($command, $cmdName, 'not_in_allowlist');

            throw new ShellDeniedException(
                "Command '{$cmdName}' is not in the shell allowlist."
            );
        }
    }

    /**
     * Validate file arguments in file-reading commands against sensitive file rules.
     *
     * @param  string  $command  Metacharacter-stripped command string.
     * @param  string  $cmdName  First-word command name.
     * @return void
     *
     * @throws ShellDeniedException If any argument references a sensitive file.
     */
    private function validateFileArguments(string $command, string $cmdName): void
    {
        $tokens = preg_split('/\s+/', $command) ?: [];

        array_shift($tokens);

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '-')) {
                $eq = strpos($token, '=');
                if ($eq !== false) {
                    $candidate = substr($token, $eq + 1);
                    if ($candidate !== '') {
                        $this->assertPathNotSensitive($command, $cmdName, $candidate);
                    }
                }

                continue;
            }

            $this->assertPathNotSensitive($command, $cmdName, $token);
        }
    }

    /**
     * Strip a trailing editor/backup suffix from a basename so a backup copy of a sensitive file matches the same rules as the original.
     *
     * @param  string  $basename  Lower-cased file basename.
     * @return string The basename with one trailing `~` or backup extension removed.
     */
    private static function stripBackupSuffix(string $basename): string
    {
        $stripped = rtrim($basename, '~');

        return preg_replace('/\.(bak|old|orig|save|swp|swo|tmp|copy|backup)$/', '', $stripped) ?? $stripped;
    }

    /**
     * Check one path-like token (a plain argument, or a flag's embedded '=' value) against every sensitive-file rule. Identical logic to what validateFileArguments() ran inline before the flag-embedded-path fix, extracted so both the plain-token and flag-value paths share it.
     *
     * @param  string  $command  Metacharacter-stripped command string (for hook/error context).
     * @param  string  $cmdName  First-word command name (for hook/error context).
     * @param  string  $token  Path-like token to validate.
     * @return void
     *
     * @throws ShellDeniedException If the token references a sensitive file.
     */
    private function assertPathNotSensitive(string $command, string $cmdName, string $token): void
    {
        $basename = strtolower(basename($token));
        $effective = self::stripBackupSuffix($basename);
        $ext = strtolower(pathinfo($token, PATHINFO_EXTENSION));
        $effectiveExt = strtolower(pathinfo($effective, PATHINFO_EXTENSION));
        $lower = strtolower($token);

        if (in_array($basename, self::BLOCKED_FILENAMES, true) || in_array($effective, self::BLOCKED_FILENAMES, true)) {
            HookDispatcher::shellDenied($command, $cmdName, 'sensitive_file', $token);

            throw new ShellDeniedException(
                "Access to '{$basename}' is blocked: contains sensitive configuration."
            );
        }

        if (($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true))
            || ($effectiveExt !== '' && in_array($effectiveExt, self::BLOCKED_EXTENSIONS, true))) {
            HookDispatcher::shellDenied($command, $cmdName, 'sensitive_extension', $token);

            throw new ShellDeniedException(
                "Access to '.{$ext}' files is blocked: may contain secrets."
            );
        }

        if (str_starts_with($basename, '.env')) {
            HookDispatcher::shellDenied($command, $cmdName, 'sensitive_file', $token);

            throw new ShellDeniedException(
                "Access to '{$basename}' is blocked: environment files contain secrets."
            );
        }

        $segments = preg_split('/[\/\\\\]/', $lower) ?: [];
        foreach ($segments as $segment) {
            if ($segment !== '' && in_array($segment, self::BLOCKED_DIR_SEGMENTS, true)) {
                HookDispatcher::shellDenied($command, $cmdName, 'sensitive_directory', $token);

                throw new ShellDeniedException(
                    "Access to files in '{$segment}/' is blocked."
                );
            }
        }

        foreach (self::BLOCKED_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                HookDispatcher::shellDenied($command, $cmdName, 'sensitive_path', $token);

                throw new ShellDeniedException(
                    "Access to '{$token}' is blocked: system file."
                );
            }
        }

        $resolved = realpath($token) ?: realpath(dirname($token));
        if ($resolved !== false) {
            $resolvedLower = strtolower($resolved);

            foreach (self::BLOCKED_DIR_SEGMENTS as $segment) {
                if (str_contains('/'.$resolvedLower.'/', '/'.$segment.'/')) {
                    HookDispatcher::shellDenied($command, $cmdName, 'sensitive_directory', $token);

                    throw new ShellDeniedException(
                        "Access to files in '{$segment}/' is blocked."
                    );
                }
            }

            foreach (self::BLOCKED_PATH_PATTERNS as $pattern) {
                if (preg_match($pattern, $resolvedLower) === 1) {
                    HookDispatcher::shellDenied($command, $cmdName, 'sensitive_path', $token);

                    throw new ShellDeniedException(
                        "Access to '{$token}' is blocked: system file."
                    );
                }
            }
        }
    }

    /**
     * Run the sanitised command via proc_open and return the output.
     *
     * @param  string  $command  Sanitised command string to execute.
     * @param  string  $cmdName  First-word command name (used in error messages).
     * @return string Trimmed stdout, or stderr fallback on non-zero exit.
     *
     * @throws ToolException If proc_open fails to start the process.
     */
    private function runProcess(string $command, string $cmdName): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $parts = array_values(array_filter(
            preg_split('/\s+/', trim($command)) ?: [],
            static fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            throw new ToolException("Failed to parse command '{$cmdName}'.");
        }

        $process = proc_open($parts, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new ToolException("Failed to start process for command '{$cmdName}'.");
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        [$stdout, $stderr] = $this->readUntilExitOrDeadline($process, $pipes, $cmdName);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $stdout = $this->sanitizeOutput($stdout);
        $stderr = $this->sanitizeOutput($stderr);

        if ($exitCode !== 0 && trim($stdout) === '') {
            $result = trim($stderr) !== ''
                ? trim($stderr)
                : "Command exited with code {$exitCode}.";

            return $this->applyOutputCap($result, $this->maxStderrBytes, 'stderr');
        }

        return $this->applyOutputCap(trim($stdout), $this->maxStdoutBytes, 'stdout');
    }

    /**
     * Reads stdout/stderr against a real wall-clock deadline, not a per-read stall timeout, a process that keeps producing output past PROCESS_TIMEOUT_SECONDS is killed regardless of how continuously it writes.
     *
     * @param  resource  $process  Open process handle from proc_open().
     * @param  resource[]  $pipes  Non-blocking stdout (index 1) and stderr (index 2) pipes.
     * @param  string  $cmdName  First-word command name (used in error messages).
     * @return string[] [stdout, stderr] accumulated so far.
     *
     * @throws ToolException If the wall-clock deadline or the hard output-size ceiling is exceeded.
     */
    private function readUntilExitOrDeadline($process, array $pipes, string $cmdName): array
    {
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::PROCESS_TIMEOUT_SECONDS;

        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $this->killProcess($process, $pipes);

                throw new ToolException("Command '{$cmdName}' was terminated after ".self::PROCESS_TIMEOUT_SECONDS.' seconds.');
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            $waitSeconds = (int) $remaining;
            $waitMicroseconds = (int) (($remaining - $waitSeconds) * 1_000_000);
            stream_select($read, $write, $except, $waitSeconds, $waitMicroseconds);

            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (strlen($stdout) + strlen($stderr) > self::HARD_READ_CEILING_BYTES) {
                $this->killProcess($process, $pipes);

                throw new ToolException("Command '{$cmdName}' exceeded the output size limit and was terminated.");
            }

            $status = proc_get_status($process);

            if (! $status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        return [$stdout, $stderr];
    }

    /**
     * Force-kill the process and release its pipe and process handles, the shared teardown for both the wall-clock-deadline and output-ceiling termination paths.
     *
     * @param  resource  $process  Open process handle from proc_open().
     * @param  resource[]  $pipes  Open stdout (index 1) and stderr (index 2) pipes.
     * @return void
     */
    private function killProcess($process, array $pipes): void
    {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    /**
     * Strip ANSI escape sequences and non-printable control characters from command output.
     *
     * @param  string  $output  Raw stdout or stderr from the process.
     * @return string Output with ANSI codes and control characters (except tab, newline, carriage return) removed.
     */
    private function sanitizeOutput(string $output): string
    {
        $output = (string) preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $output);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output);
    }

    /**
     * Returns output as-is under the inline cap; past it, spills the full output to a temp file and returns a preview + path.
     *
     * @param  string  $output  Sanitized stdout/stderr text.
     * @param  int  $inlineCap  Byte threshold under which output is returned inline.
     * @param  string  $streamName  "stdout" or "stderr": used in the spill filename and truncation note.
     * @return string Output, or a truncated preview plus a pointer to the spill file.
     */
    private function applyOutputCap(string $output, int $inlineCap, string $streamName): string
    {
        if (strlen($output) <= $inlineCap) {
            return $output;
        }

        $preview = substr($output, 0, $inlineCap);
        $path = @tempnam(sys_get_temp_dir(), "phpclaw-shell-{$streamName}-");

        if ($path !== false && @file_put_contents($path, $output, LOCK_EX) !== false) {
            return $preview."\n... [truncated; full {$streamName} (".strlen($output)." bytes) written to {$path}]";
        }

        return $preview."\n... [truncated; ".(strlen($output) - $inlineCap).' more bytes discarded]';
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['system'],
            tags: ['shell', 'command', 'run', 'execute', 'terminal', 'bash', 'disk', 'usage', 'space'],
            intents: ['run command', 'execute shell'],
            examples: ['run whoami', 'check disk usage'],
        );
    }
}
