<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when an LLM provider API call fails.
 */
final class ProviderException extends PhpClawException
{
    /**
     * Create a new ProviderException instance.
     *
     * @param  string  $message  Human-readable error description; never a raw vendor body or secret.
     * @param  int  $statusCode  HTTP status the provider returned, or 0 for a transport-level failure.
     * @param  \Throwable|null  $previous  The exception this one wraps, if any.
     * @return void
     */
    public function __construct(
        string $message = '',
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $this->statusCode, $previous);
    }
}
