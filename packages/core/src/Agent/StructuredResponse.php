<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Immutable value object returned by Claw::sendStructured(): validated data plus the underlying run.
 */
final class StructuredResponse
{
    /**
     * Create a new StructuredResponse instance.
     *
     * @param  array<string, mixed>  $data  Decoded reply, validated against the caller's schema.
     * @param  AgentResponse  $raw  Underlying run: text, provider, model, token usage, and iterations (provider calls made, including repair retries).
     * @return void
     */
    public function __construct(
        public readonly array $data,
        public readonly AgentResponse $raw,
    ) {}
}
