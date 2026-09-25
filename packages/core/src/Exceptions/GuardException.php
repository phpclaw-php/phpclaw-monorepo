<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown by any guard that blocks a message.
 */
final class GuardException extends PhpClawException
{
    private readonly ?string $guardClass;

    /**
     * Create a new GuardException instance.
     *
     * @param  string  $message  Human-readable reason the guard blocked the message.
     * @param  int  $code  Exception code, per the native Exception contract.
     * @param  \Throwable|null  $previous  Wrapped cause, per the native Exception contract.
     * @param  string|null  $guardClass  Fully-qualified class name of the guard that threw, when known.
     * @return void
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $guardClass = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->guardClass = $guardClass;
    }

    /**
     * Fully-qualified class name of the guard that threw this exception, or null when not attributed.
     *
     * @return string|null
     */
    public function guardClass(): ?string
    {
        return $this->guardClass;
    }
}
