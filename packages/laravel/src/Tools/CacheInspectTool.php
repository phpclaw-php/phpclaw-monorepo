<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use Illuminate\Support\Facades\Cache;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that reports the active cache store and whether a key exists (read-only).
 */
final class CacheInspectTool extends AbstractLaravelTool
{
    private const MAX_PREVIEW_BYTES = 256;

    private const ALLOWED_KEYS = ['key'];

    /**
     * Return the tool identifier used by the agent to invoke this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'cache_inspect';
    }

    /**
     * Return the human-readable description shown to the LLM for tool selection.
     *
     * @return string
     */
    public function description(): string
    {
        return 'INSPECT the Laravel cache: report the active store and whether a given key exists (with a small value preview). Read-only, never flushes or modifies cache entries.';
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
                    'description' => 'Cache key to inspect (optional, omit to report only the active store)',
                ],
            ],
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
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['performance', 'system'],
            tags: ['cache', 'caches', 'store', 'stores', 'key', 'keys', 'ttl', 'driver', 'redis', 'hit', 'miss', 'inspect'],
            intents: ['inspect the cache', 'show cache keys', 'what cache driver is in use'],
            examples: ['what cache driver is configured'],
        );
    }

    /**
     * Guard the caller, reject unknown arguments, and validate the key type before any cache access.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('inspect the cache');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        if (array_key_exists('key', $input) && ! is_string($input['key'])) {
            return ['input' => $input, 'result' => $this->error('INVALID_ARGUMENT', '"key" must be a string.')];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Resolve the active store and optionally check whether the requested key exists.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{store: string, key: string|null, exists: bool|null, preview: string|null, truncated: bool}
     *
     * @throws ToolException When the cache driver fails to answer the key lookup.
     */
    protected function perform(array $input): array
    {
        $store = (string) config('cache.default', 'unknown');
        $key = $this->stringInput($input, 'key');
        $hasKey = array_key_exists('key', $input) && $key !== '';
        $exists = null;
        $preview = null;
        $truncated = false;

        if ($hasKey) {
            try {
                $exists = Cache::has($key);

                if ($exists) {
                    $raw = Cache::get($key);
                    $encoded = is_scalar($raw)
                        ? (string) $raw
                        : (string) json_encode($raw, JSON_UNESCAPED_UNICODE);

                    if (strlen($encoded) > self::MAX_PREVIEW_BYTES) {
                        $encoded = substr($encoded, 0, self::MAX_PREVIEW_BYTES);
                        $truncated = true;
                    }

                    $preview = $this->redactPreview($key, $encoded);
                }
            } catch (\Throwable $e) {
                throw new ToolException("cache_inspect: failed to inspect key '{$key}'.", previous: $e);
            }
        }

        return [
            'store' => $store,
            'key' => $hasKey ? $key : null,
            'exists' => $exists,
            'preview' => $preview,
            'truncated' => $truncated,
        ];
    }

    /**
     * Confirm the execution result carries a valid store name before it is published.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the store name is missing or not a string.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_string($execution['store'])) {
            throw new ToolException('cache_inspect: execution result is missing a valid store name.');
        }

        return ['result' => null];
    }

    /**
     * Build the public response envelope from the verified execution result.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $hasKey = $execution['key'] !== null;
        $truncated = $execution['truncated'];
        $data = ['store' => $execution['store']];
        $warnings = [];

        if ($hasKey) {
            $data['key'] = $execution['key'];
            $data['exists'] = $execution['exists'];
            $data['preview'] = $execution['preview'];
        }

        $count = ($hasKey && $execution['exists'] === true) ? 1 : 0;

        if ($hasKey && $execution['exists'] === false) {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => 'Cache key "'.$execution['key'].'" was not found in the "'.$execution['store'].'" store.',
            ];
        }

        if ($truncated) {
            $warnings[] = [
                'code' => 'OUTPUT_TRUNCATED',
                'message' => 'The value preview was truncated at '.self::MAX_PREVIEW_BYTES.' bytes.',
            ];
        }

        return $this->success(
            $data,
            [
                'mode' => 'query',
                'count' => $count,
                'total' => $count,
                'truncated' => $truncated,
            ],
            $warnings,
        );
    }

    /**
     * Redact a cache value preview, fully masks secret-named keys and scrubs secret patterns from the value.
     *
     * @param  string  $key  The inspected cache key.
     * @param  string  $encoded  The encoded value preview.
     * @return string The redacted preview.
     */
    private function redactPreview(string $key, string $encoded): string
    {
        $keyLower = strtolower($key);

        foreach ([
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'auth', 'session',
            'credential', 'private', 'encryption', 'cipher', 'signing', 'hmac', 'salt', 'passphrase',
            'app_key', 'secret_key', 'private_key',
        ] as $pattern) {
            if (str_contains($keyLower, $pattern)) {
                return self::REDACTED;
            }
        }

        return $this->redactSecretsInText($encoded);
    }
}
