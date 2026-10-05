<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when sendStructured() still fails schema validation after every repair attempt.
 */
final class StructuredOutputException extends PhpClawException
{
    public readonly string $lastRawText;

    public readonly array $errors;

    /**
     * Create a new StructuredOutputException instance.
     *
     * @param  string  $lastRawText  Raw provider reply from the final attempt; never included in the exception message.
     * @param  list<string>  $errors  Validation errors from the final attempt.
     * @return void
     */
    public function __construct(string $lastRawText, array $errors)
    {
        $this->lastRawText = $lastRawText;
        $this->errors = $errors;

        $count = count($errors);
        $first = $errors[0] ?? 'no error detail available';

        parent::__construct("Structured output validation failed with {$count} error(s); first: {$first}");
    }
}
