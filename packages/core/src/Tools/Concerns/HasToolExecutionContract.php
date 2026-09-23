<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Concerns;

use PhpClaw\Exceptions\ToolException;

/**
 * Shared execution contract for phpClaw adapter tools, carrying the orchestration half so
 * every tool behaves identically: plan, perform, verify, recover, then complete.
 */
trait HasToolExecutionContract
{
    /**
     * Authorize, validate, and decide the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    abstract protected function plan(array $input): array;

    /**
     * Run the domain operation only, with no model policy handling.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    abstract protected function perform(array $input): array;

    /**
     * Check the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    abstract protected function verify(array $execution, array $input): array;

    /**
     * Convert a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    abstract protected function complete(array $execution, array $input): string;

    /**
     * Return the platform capability required to run this tool.
     *
     * @return string
     */
    abstract public function requiredCapability(): string;

    /**
     * Run the complete tool lifecycle.
     *
     * @param  array<string, mixed>  $input  Runtime input matching the schema.
     * @return string JSON-encoded result envelope.
     *
     * @throws ToolException When infrastructure failure remains after recovery.
     */
    public function execute(array $input): string
    {
        $plan = $this->plan($input);

        if ($plan['result'] !== null) {
            return $plan['result'];
        }

        $attempt = 0;

        while (true) {
            try {
                $execution = $this->perform($plan['input']);

                $verification = $this->verify($execution, $plan['input']);

                if ($verification['result'] !== null) {
                    return $verification['result'];
                }

                return $this->complete($execution, $plan['input']);
            } catch (ToolException $exception) {
                if ($attempt >= $this->maxRecoveryAttempts()) {
                    throw $exception;
                }

                $attempt++;

                $recovery = $this->recover($exception, $attempt);

                if ($recovery['result'] !== null) {
                    return $recovery['result'];
                }
            }
        }
    }

    /**
     * Return the number of times a retryable failure may be retried.
     *
     * @return int
     */
    protected function maxRecoveryAttempts(): int
    {
        return 1;
    }

    /**
     * Return the short tool label used in log lines.
     *
     * @return string
     */
    protected function toolLabel(): string
    {
        $parts = explode('\\', static::class);

        return (string) end($parts);
    }

    /**
     * Report whether this request is running through the adapter's console. Abstract on
     * purpose: every default fails silently, and implementations must never use PHP_SAPI.
     *
     * @return bool
     */
    abstract protected function runningInConsole(): bool;

    /**
     * Report whether the current caller holds a platform capability.
     *
     * @param  string  $capability  Platform-specific capability or action name.
     * @return bool
     */
    abstract protected function callerHasCapability(string $capability): bool;

    /**
     * Return a FORBIDDEN envelope when the caller may not perform this action.
     *
     * @param  string  $subject  What the caller was trying to do, for the message.
     * @return string|null JSON-encoded error envelope, or null when the caller is allowed.
     */
    protected function guardCapability(string $subject): ?string
    {
        if ($this->runningInConsole()) {
            return null;
        }

        if ($this->callerHasCapability($this->requiredCapability())) {
            return null;
        }

        return $this->error(
            'FORBIDDEN',
            sprintf(
                'The current user lacks the "%s" capability required to %s.',
                $this->requiredCapability(),
                $subject,
            ),
        );
    }

    /**
     * Return an UNKNOWN_ARGUMENT envelope for any key outside the allowed set.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @param  array<int, string>  $allowed  Accepted argument names.
     * @return string|null JSON-encoded error envelope, or null when every key is allowed.
     */
    protected function rejectUnknownArguments(array $input, array $allowed): ?string
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowed, true)) {
                return $this->error(
                    'UNKNOWN_ARGUMENT',
                    sprintf('Unknown argument "%s".', (string) $key),
                    ['accepted_arguments' => $allowed],
                );
            }
        }

        return null;
    }

    /**
     * Return an INVALID_LIMIT or INVALID_OFFSET envelope for out-of-range paging.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @param  int  $maxLimit  Highest accepted limit.
     * @param  int  $maxOffset  Highest accepted offset.
     * @return string|null JSON-encoded error envelope, or null when paging is valid.
     */
    protected function validatePaging(array $input, int $maxLimit, int $maxOffset): ?string
    {
        if (array_key_exists('limit', $input)) {
            if (! is_int($input['limit']) || $input['limit'] < 1 || $input['limit'] > $maxLimit) {
                return $this->error(
                    'INVALID_LIMIT',
                    sprintf('"limit" must be an integer between 1 and %d.', $maxLimit),
                );
            }
        }

        if (array_key_exists('offset', $input)) {
            if (! is_int($input['offset']) || $input['offset'] < 0 || $input['offset'] > $maxOffset) {
                return $this->error(
                    'INVALID_OFFSET',
                    sprintf('"offset" must be an integer between 0 and %d.', $maxOffset),
                );
            }
        }

        return null;
    }

    /**
     * Retry a failed execution when the failure is retryable infrastructure.
     *
     * @param  ToolException  $exception  The failure raised by the execution.
     * @param  int  $attempt  The recovery attempt number.
     * @return array{result: string|null}
     *
     * @throws ToolException When the failure is not retryable.
     */
    protected function recover(ToolException $exception, int $attempt): array
    {
        if (! $this->isRetryableInfrastructureFailure($exception)) {
            throw $exception;
        }

        if ($attempt > $this->maxRecoveryAttempts()) {
            throw $exception;
        }

        return ['result' => null];
    }

    /**
     * Report whether the failure is a retryable infrastructure failure.
     *
     * @param  ToolException  $exception  The failure to classify.
     * @return bool
     */
    protected function isRetryableInfrastructureFailure(ToolException $exception): bool
    {
        return $exception->getPrevious() !== null;
    }

    /**
     * Build a success envelope.
     *
     * @param  array<string, mixed>  $data  Domain payload.
     * @param  array<string, mixed>  $meta  Response metadata.
     * @param  array<int, array<string, mixed>>  $warnings  Non-fatal notices.
     * @return string JSON-encoded response envelope.
     *
     * @throws ToolException When JSON encoding fails.
     */
    protected function success(array $data, array $meta, array $warnings = []): string
    {
        return $this->encodeResult([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Build a structured error envelope the model can correct from.
     *
     * @param  string  $code  Stable error identifier.
     * @param  string  $message  Human-readable explanation.
     * @param  array<string, mixed>  $context  Additional correction hints.
     * @return string JSON-encoded error envelope.
     *
     * @throws ToolException When JSON encoding fails.
     */
    protected function error(string $code, string $message, array $context = []): string
    {
        return $this->encodeResult([
            'success' => false,
            'error' => array_merge(
                [
                    'code' => $code,
                    'message' => $message,
                ],
                $context,
            ),
            'data' => null,
            'meta' => [
                'mode' => 'error',
            ],
            'warnings' => [],
        ]);
    }

    /**
     * Encode the deterministic public response envelope.
     *
     * @param  array<string, mixed>  $result  Result payload.
     * @return string JSON-encoded result.
     *
     * @throws ToolException When JSON encoding fails.
     */
    protected function encodeResult(array $result): string
    {
        try {
            return json_encode(
                self::decodeEntitiesRecursively($result),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException $e) {
            throw new ToolException('Unable to encode tool response.', previous: $e);
        }
    }

    /**
     * Recursively HTML-decodes every string value in the given data.
     *
     * @param  mixed  $value  Value to decode, at any nesting depth.
     * @return mixed The same shape, with every string entity-decoded.
     */
    private static function decodeEntitiesRecursively(mixed $value): mixed
    {
        if (is_string($value)) {
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (is_array($value)) {
            return array_map(self::decodeEntitiesRecursively(...), $value);
        }

        return $value;
    }

    /**
     * Return worked example prompts for this tool, as structured data rather than prose so
     * the docs table is generated from the same source and cannot drift from it.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return [];
    }

    /**
     * Record an execution failure without exposing internals to the model.
     *
     * @param  \Throwable  $exception  The failure to record.
     * @return void
     */
    protected function logExecutionError(\Throwable $exception): void
    {
        error_log(sprintf(
            'phpClaw %s execution failed: %s',
            $this->toolLabel(),
            $exception->getMessage(),
        ));
    }
}
