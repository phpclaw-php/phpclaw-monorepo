<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Concerns\NormalisesText;
use PhpClaw\Guards\Contracts\PromptOnlyGuardInterface;

/**
 * Blocks PHP code-execution patterns (eval, exec, shell_exec, etc.) in prompt text.
 */
#[Guard(priority: 5, name: 'code_injection', label: 'Code Injection', enabledByDefault: true, since: '1.0.0')]
final class CodeInjectionGuard implements PromptOnlyGuardInterface
{
    use NormalisesText;

    private const FUNCTION_PATTERNS = [
        'eval(',
        'create_function(',
        'system(',
        'exec(',
        'shell_exec(',
        'passthru(',
        'popen(',
        'proc_open(',
        'pcntl_exec(',
    ];

    private const CODE_PATTERNS = [
        '<?php',
        '<?=',
        '?>',
        '$$',
    ];

    private const ERROR_CODE_PATTERN = "Prompt blocked: message contains PHP code pattern '%s'.";

    private const ERROR_FUNCTION_PATTERN = "Prompt blocked: message contains PHP code-injection pattern '%s'.";

    /**
     * Scan the message for PHP code-injection patterns.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException When the message contains a PHP code or code-injection pattern.
     */
    public function scan(string $message): void
    {
        self::assertNoMatch(mb_strtolower($message), self::CODE_PATTERNS, self::ERROR_CODE_PATTERN);
        self::assertNoMatch($this->normalise($message), self::FUNCTION_PATTERNS, self::ERROR_FUNCTION_PATTERN);
    }
}
