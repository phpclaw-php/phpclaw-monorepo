<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that reads a single config value by dot-notation key, redacting secrets (read-only).
 */
final class ConfigGetTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const ALLOWED_KEYS = ['key'];

    private const SECRET_PATTERNS = [
        'password', 'passwd', 'secret', 'token', 'key', 'api_key',
        'dsn', 'webhook', 'cipher', 'salt', 'passphrase', 'private',
        'credential', 'cert', 'signature', 'hash', 'nonce', 'auth',
        'pwd', 'bearer', 'access', 'cred', 'license', 'pin', 'otp',
        'seed', 'jwt',
    ];

    private const REDACTION = '***withheld***';

    private const SECRET_PREFIXES = [
        'database.',
        'logging.channels.slack.',
    ];

    /**
     * Return the tool identifier used by the agent to invoke this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'config_get';
    }

    /**
     * Return the human-readable description shown to the LLM for tool selection.
     *
     * @return string
     */
    public function description(): string
    {
        return 'READ a single Laravel config value by dot-notation key (e.g. app.name, cache.default, mail.mailer). Safe keys only, secrets (passwords, tokens, API keys, database credentials) are redacted. Never dumps the full config tree.';
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
                'key' => [
                    'type' => 'string',
                    'description' => 'Dot-notation config key to read (e.g. app.name, cache.default)',
                ],
            ],
            'required' => ['key'],
        ];
    }

    /**
     * Return the platform capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::CHAT_ABILITY;
    }

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
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['settings', 'configuration'],
            tags: ['config', 'configuration', 'setting', 'settings', 'option', 'options', 'value', 'key', 'env', 'environment'],
            intents: ['read a config value', 'show a setting', 'what is this option set to'],
            examples: ['what is the app timezone set to'],
        );
    }

    /**
     * Guard the caller, reject unknown arguments, and validate the key before reading config.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read a configuration value');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $key = trim((string) ($input['key'] ?? ''));

        if ($key === '') {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', '"key" is required and must be a non-empty string.'),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Read the config value for the validated key, redacting secrets.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{key: string, value: string|null, found: bool, redacted: bool, kind: string}
     */
    protected function perform(array $input): array
    {
        $key = trim((string) ($input['key'] ?? ''));

        if ($this->isSecretKey($key)) {
            return ['key' => $key, 'value' => self::REDACTION, 'found' => true, 'redacted' => true, 'kind' => 'scalar'];
        }

        $value = config($key);

        if ($value === null) {
            return ['key' => $key, 'value' => null, 'found' => false, 'redacted' => false, 'kind' => 'scalar'];
        }

        if (is_array($value)) {
            return ['key' => $key, 'value' => null, 'found' => true, 'redacted' => false, 'kind' => 'array'];
        }

        if (! is_scalar($value)) {
            return ['key' => $key, 'value' => null, 'found' => true, 'redacted' => false, 'kind' => 'non_scalar'];
        }

        return ['key' => $key, 'value' => $this->stripUrlCredentials((string) $value), 'found' => true, 'redacted' => false, 'kind' => 'scalar'];
    }

    /**
     * Assert that the execution produced a valid key string.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result contains a non-string key.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_string($execution['key'])) {
            throw new ToolException('config_get: execution produced a non-string key.');
        }

        return ['result' => null];
    }

    /**
     * Build the success or error envelope from the verified execution result.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $key = $execution['key'];
        $value = $execution['value'];
        $found = $execution['found'];
        $redacted = $execution['redacted'];
        $kind = $execution['kind'];

        if ($kind === 'array') {
            return $this->error(
                'INVALID_ARGUMENT',
                "Config key '{$key}' resolves to an array. Specify a more precise key to read a scalar value.",
            );
        }

        if ($kind === 'non_scalar') {
            return $this->error(
                'INVALID_ARGUMENT',
                "Config key '{$key}' resolves to a non-scalar value and cannot be read.",
            );
        }

        $warnings = [];

        if (! $found) {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => "Config key '{$key}' is not set.",
            ];
        }

        if ($redacted) {
            $warnings[] = [
                'code' => 'REDACTED',
                'message' => "Config key '{$key}' matches a credential pattern, so its value was withheld.",
            ];
        }

        $count = $found ? 1 : 0;

        return $this->success(
            ['key' => $key, 'value' => $value],
            ['mode' => 'query', 'count' => $count, 'total' => $count, 'truncated' => false],
            $warnings,
        );
    }

    /**
     * Return true when the key matches any secret pattern and must be redacted.
     *
     * @param  string  $key  The dot-notation config key.
     * @return bool
     */
    private function isSecretKey(string $key): bool
    {
        $keyLower = strtolower($key);

        foreach (self::SECRET_PREFIXES as $prefix) {
            if (str_starts_with($keyLower, $prefix)) {
                return true;
            }
        }

        foreach (explode('.', $keyLower) as $segment) {
            foreach (self::SECRET_PATTERNS as $pattern) {
                if (str_contains($segment, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove an embedded username and password from a URL value.
     *
     * @param  string  $value  The resolved scalar config value.
     * @return string The value with any URL credentials removed.
     */
    private function stripUrlCredentials(string $value): string
    {
        return (string) preg_replace_callback(
            '#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s:@]+@#i',
            static fn (array $m): string => $m[1].self::REDACTION.'@',
            $value,
        );
    }
}
