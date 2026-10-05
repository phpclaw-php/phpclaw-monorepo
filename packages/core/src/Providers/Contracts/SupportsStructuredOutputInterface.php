<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Contracts;

/**
 * Contract for a provider (or decorator) that can enforce a JSON Schema natively on the vendor's own API.
 */
interface SupportsStructuredOutputInterface
{
    /**
     * Whether this instance, as currently configured, can enforce a response schema natively.
     *
     * @return bool
     */
    public function supportsResponseSchema(): bool;

    /**
     * Return a clone configured to send the given schema natively on every send() it makes.
     *
     * @param  array<string, mixed>  $schema  JSON Schema the vendor should enforce on its reply.
     * @return static
     */
    public function withResponseSchema(array $schema): static;
}
