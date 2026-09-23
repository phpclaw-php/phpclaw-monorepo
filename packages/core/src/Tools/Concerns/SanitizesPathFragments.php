<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Concerns;

/**
 * Sanitises a path/basename/extension fragment before it enters an exception message, used by every file-touching tool.
 */
trait SanitizesPathFragments
{
    /**
     * Strip everything except [A-Za-z0-9._/-] and cap the length, so a path fragment interpolated into an exception message can't carry injection-shaped content back into the LLM's context (tool errors re-enter context after the guard chain has already run).
     *
     * @param  string  $value  Raw path/basename/extension fragment.
     * @return string Sanitized fragment, at most 64 characters.
     */
    private function sanitizeForMessage(string $value): string
    {
        $maxFragment = 64;
        $sanitized = (string) preg_replace('/[^A-Za-z0-9._\/-]/', '?', $value);

        return strlen($sanitized) > $maxFragment
            ? substr($sanitized, 0, $maxFragment)
            : $sanitized;
    }
}
