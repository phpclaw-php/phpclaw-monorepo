<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\PromptOnlyGuardInterface;

/**
 * Detects and optionally blocks prompts containing PII using offline regex-only detection.
 */
#[Guard(
    priority: 30,
    name: 'pii_detection',
    label: 'PII Detection',
    enabledByDefault: true,
    since: '1.0.0',
)]
final class PiiDetectionGuard implements PromptOnlyGuardInterface
{
    public const DEFAULT_MESSAGE_TEMPLATE = 'PII detected: {type} found in prompt.';

    private const DEFAULT_PATTERNS = [
        'email' => '/[a-zA-Z0-9._%+\-]{1,64}@[a-zA-Z0-9.\-]{1,253}\.[a-zA-Z]{2,63}/',
        'credit_card' => '/\b(?:\d{4}[\s\-]){3}\d{4}\b/',
        'ssn' => '/\b\d{3}[\s\-]\d{2}[\s\-]\d{4}\b/',
        'phone' => '/\b(?:\+?\d{1,3}[\s\-])?\(?\d{3}\)?[\s\-]\d{3}[\s\-]\d{4}\b/',
    ];

    private readonly bool $blockOnDetection;

    private readonly array $patterns;

    private readonly string $messageTemplate;

    /**
     * Build a PII detection guard with optional custom patterns and message template.
     *
     * @param  bool  $blockOnDetection  When false, scan() detects but never throws (passive/warn mode).
     * @param  array<string, string>  $customPatterns  Additional regex patterns merged with defaults. Pattern names override defaults.
     * @param  string  $messageTemplate  Error message; supports `{type}` placeholder for the matched pattern name.
     * @return void
     */
    public function __construct(
        bool $blockOnDetection = true,
        array $customPatterns = [],
        string $messageTemplate = self::DEFAULT_MESSAGE_TEMPLATE,
    ) {
        $this->blockOnDetection = $blockOnDetection;
        $this->patterns = array_merge(self::DEFAULT_PATTERNS, $customPatterns);
        $this->messageTemplate = $messageTemplate;
    }

    /**
     * Scan a message for PII patterns. Throws on first match when blocking is enabled.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException When PII is detected and blockOnDetection is true.
     */
    public function scan(string $message): void
    {
        $detected = $this->detect($message);

        if ($detected === [] || ! $this->blockOnDetection) {
            return;
        }

        $type = $detected[0];

        throw new GuardException(
            str_replace('{type}', $type, $this->messageTemplate),
            guardClass: self::class,
        );
    }

    /**
     * Detect ALL PII types found in the message, never throws.
     *
     * @param  string  $message  The user message to scan for every configured pattern.
     * @return list<string> Pattern names matched (e.g. ['email', 'phone']). Empty array means clean.
     */
    public function detect(string $message): array
    {
        $matched = [];

        foreach ($this->patterns as $type => $pattern) {
            if (preg_match($pattern, $message) === 1) {
                $matched[] = $type;
            }
        }

        return $matched;
    }

    /**
     * Return the active pattern map (defaults merged with custom).
     *
     * @return array<string, string> Pattern name → regex map currently in use by this guard instance.
     */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
