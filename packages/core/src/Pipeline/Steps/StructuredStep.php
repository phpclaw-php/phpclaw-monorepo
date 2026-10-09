<?php

declare(strict_types=1);

namespace PhpClaw\Pipeline\Steps;

use PhpClaw\Claw;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\PipelineException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\StructuredOutputException;
use PhpClaw\Exceptions\UnsupportedSchemaException;
use PhpClaw\Pipeline\Contracts\PipelineStepInterface;
use Stringable;

/**
 * Pipeline step that sends its text input through Claw::sendStructured() and returns the validated data.
 */
final class StructuredStep implements PipelineStepInterface
{
    /**
     * Hold the agent and the schema every reply must satisfy.
     *
     * @param  Claw  $claw  Agent whose sendStructured() validates and repairs the reply.
     * @param  array<string, mixed>  $schema  JSON Schema the reply must satisfy.
     */
    public function __construct(
        private readonly Claw $claw,
        private readonly array $schema,
    ) {}

    /**
     * Send the input as one message and return the reply data, already validated against the schema.
     *
     * @param  mixed  $input  The message, as a string or Stringable.
     * @return array<string, mixed>
     *
     * @throws PipelineException When the input is not a string or Stringable.
     * @throws GuardException If prompt injection is detected.
     * @throws ProviderException If the LLM API call fails.
     * @throws UnsupportedSchemaException If the schema uses a keyword this library does not enforce.
     * @throws StructuredOutputException If the reply still fails validation after every repair attempt.
     */
    public function run(mixed $input): array
    {
        if (! is_string($input) && ! $input instanceof Stringable) {
            throw PipelineException::invalidInput(self::class, 'a string or Stringable input', $input);
        }

        return $this->claw->sendStructured((string) $input, $this->schema)->data;
    }
}
