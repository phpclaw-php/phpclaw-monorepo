<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Hooks\HookDispatcher;

/**
 * Strips PHP tags and dangerous function patterns from LLM output before returning to the caller.
 */
final class OutputSanitiser
{
    private const DANGEROUS_OUTPUT_FUNCTIONS = [
        'eval(',
        'system(',
        'exec(',
        'shell_exec(',
        'passthru(',
        'proc_open(',
        'pcntl_exec(',
    ];

    private const PHP_TAGS = ['<?php', '<?=', '?>'];

    private const PHP_TAG_REPLACEMENT = '[PHP_REMOVED]';

    private const DANGEROUS_FUNCTION_REPLACEMENT = '[REDACTED](';

    /**
     * Build an OutputSanitiser.
     *
     * @param  bool  $enabled  Whether output sanitisation is active.
     */
    public function __construct(private readonly bool $enabled = true) {}

    /**
     * Sanitise LLM response text: strips PHP tags and replaces dangerous function calls with [REDACTED], firing the matching guard hook on every redaction.
     *
     * @param  string  $text  Raw LLM response text to sanitise.
     * @return string Sanitised text with PHP tags and dangerous function calls neutralised.
     */
    public function sanitise(string $text): string
    {
        if (! $this->enabled) {
            return $text;
        }

        $text = $this->stripPhpTags($text);
        $text = $this->redactDangerousFunctions($text);

        return $text;
    }

    /**
     * Replace every PHP code tag with the [PHP_REMOVED] marker; fires guard hook per tag.
     *
     * @param  string  $text  Text to scan for PHP tag variants.
     * @return string Text with every recognised tag replaced.
     */
    private function stripPhpTags(string $text): string
    {
        foreach (self::PHP_TAGS as $tag) {
            if (! str_contains($text, $tag)) {
                continue;
            }

            HookDispatcher::guardOutputPhpTagRemoved($tag);
            $text = str_replace($tag, self::PHP_TAG_REPLACEMENT, $text);
        }

        return $text;
    }

    /**
     * Case-insensitively replace every dangerous function name with [REDACTED](.
     *
     * @param  string  $text  Text to scan for dangerous function calls.
     * @return string Text with every recognised function name redacted.
     */
    private function redactDangerousFunctions(string $text): string
    {
        $lower = mb_strtolower($text);

        foreach (self::DANGEROUS_OUTPUT_FUNCTIONS as $functionName) {
            if (! str_contains($lower, $functionName)) {
                continue;
            }

            HookDispatcher::guardOutputFunctionRedacted($functionName);

            $text = (string) preg_replace(
                '/'.preg_quote($functionName, '/').'/i',
                self::DANGEROUS_FUNCTION_REPLACEMENT,
                $text,
            );
            $lower = mb_strtolower($text);
        }

        return $text;
    }
}
