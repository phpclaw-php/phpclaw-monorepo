<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use PhpClaw\Laravel\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Base class for Laravel-specific tools with shared output-cap and encoding helpers.
 */
abstract class AbstractLaravelTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    protected const MAX_OUTPUT_BYTES = 8192;

    protected const REDACTED = '[redacted]';

    protected const SECRET_IDENTIFIERS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'private_key', 'access_key', 'auth_key', 'credential',
        'passphrase', 'salt', 'signature', 'authorization',
    ];

    /**
     * Whether this tool may be offered to the model. Laravel evaluates the caller's gate when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by; subclasses override with their own domains, tags and intents.
     *
     * @return ToolRoutingMetadata Empty by default.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return ToolRoutingMetadata::empty();
    }

    /**
     * Read a string input value: trimmed when present and a string, else the default.
     *
     * @param  array<string, mixed>  $input  The tool input array.
     * @param  string  $key  The input key to read.
     * @param  string  $default  Value returned when the key is absent or non-string.
     * @return string
     */
    protected function stringInput(array $input, string $key, string $default = ''): string
    {
        return isset($input[$key]) && is_string($input[$key])
            ? trim($input[$key])
            : $default;
    }

    /**
     * Cap a list of rows to the shared output byte limit, dropping trailing rows that overflow it.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, mixed>
     */
    protected function capRowsToOutputBytes(array $rows): array
    {
        $capped = [];
        $size = 0;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                continue;
            }

            if ($size + strlen($encoded) > static::MAX_OUTPUT_BYTES) {
                break;
            }

            $capped[] = $row;
            $size += strlen($encoded);
        }

        return $capped;
    }

    /**
     * Truncate a string to the output cap with a marker when it overflows.
     *
     * @param  string  $text  The text to cap.
     * @return string
     */
    protected function truncate(string $text): string
    {
        if (strlen($text) > static::MAX_OUTPUT_BYTES) {
            return substr($text, 0, static::MAX_OUTPUT_BYTES)
                ."\n[... output truncated at ".(int) (static::MAX_OUTPUT_BYTES / 1024).' KB ...]';
        }

        return $text;
    }

    /**
     * JSON-encode a value and truncate to the output cap; null when encoding fails.
     *
     * @param  mixed  $data  The value to encode.
     * @param  int  $flags  json_encode flags.
     * @return ?string
     */
    protected function encodeTruncated(mixed $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE): ?string
    {
        $json = json_encode($data, $flags);

        return $json === false ? null : $this->truncate($json);
    }

    /**
     * Whether an identifier name contains a known credential term.
     *
     * @param  string  $name  A column, key, or field name.
     * @return bool
     */
    protected function isSecretIdentifier(string $name): bool
    {
        $needle = str_replace('-', '_', mb_strtolower($name));

        foreach (static::SECRET_IDENTIFIERS as $identifier) {
            if (str_contains($needle, $identifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace credential values inside free text, covering bearer tokens, JSON pairs, and key/value pairs.
     *
     * @param  string  $text  The text to scrub.
     * @return string
     */
    protected function redactSecretsInText(string $text): string
    {
        $names = implode('|', array_map(
            static fn (string $identifier): string => str_replace(
                '_',
                '[-_]?',
                preg_quote($identifier, '/'),
            ),
            static::SECRET_IDENTIFIERS,
        ));

        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/i',
            '/\bsk[-_][A-Za-z0-9_\-]{8,}/i',
            '/"([A-Za-z0-9_.\-]*(?:'.$names.')[A-Za-z0-9_.\-]*)"\s*:\s*"[^"]*"/i',
            '/\b([A-Za-z0-9_.\-]*(?:'.$names.')[A-Za-z0-9_.\-]*)\s*([=:])\s*(?:Bearer|Basic|Token|Digest|JWT)?\s*\S+/i',
        ];

        $replacements = [
            'Bearer '.static::REDACTED,
            static::REDACTED,
            '"$1": "'.static::REDACTED.'"',
            '$1$2'.static::REDACTED,
        ];

        return (string) preg_replace($patterns, $replacements, $text);
    }
}
