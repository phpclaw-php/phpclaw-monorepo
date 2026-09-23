<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Concerns;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;

/**
 * Binds the execution contract's two platform hooks for core's own tools: both answers come from
 * the adapter's authorizer, and a tool handed none allows every caller.
 */
trait HasCoreToolBinding
{
    use HasToolExecutionContract;

    private ?ToolAuthorizerInterface $authorizer = null;

    /**
     * Bind the authorizer this tool consults before it runs.
     *
     * @param  ToolAuthorizerInterface|null  $authorizer  Authorizer to consult, or null to allow.
     * @return void
     */
    public function withAuthorizer(?ToolAuthorizerInterface $authorizer): void
    {
        $this->authorizer = $authorizer;
    }

    /**
     * Return the capability every core tool requires, identical across all of them.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return ToolAuthorizerInterface::CAPABILITY;
    }

    /**
     * Whether this tool may be offered to the model for the current caller: always on the console, otherwise only when the caller holds the required capability.
     *
     * @return bool True when the tool is eligible for routing.
     */
    public function isEligibleForRouting(): bool
    {
        return $this->runningInConsole() || $this->callerHasCapability($this->requiredCapability());
    }

    /**
     * Report whether this request is running through the adapter's console.
     *
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        return $this->authorizer !== null && $this->authorizer->runningInConsole();
    }

    /**
     * Report whether an authenticated caller is present, allowing when no authorizer is bound.
     *
     * @param  string  $capability  Platform-specific capability or action name.
     * @return bool
     */
    protected function callerHasCapability(string $capability): bool
    {
        return $this->authorizer === null || $this->authorizer->allows();
    }

    /**
     * Return a FORBIDDEN envelope when no authenticated caller is present, naming the real reason
     * rather than a platform capability core has no namespace for.
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
            sprintf('No authenticated caller was identified, so this request may not %s.', $subject),
        );
    }

    /**
     * Encode the response envelope verbatim, because decoding entities in file bytes or process
     * output would hand back content the source never held.
     *
     * @param  array<string, mixed>  $result  Result payload.
     * @return string JSON-encoded result.
     *
     * @throws ToolException When JSON encoding fails.
     */
    protected function encodeResult(array $result): string
    {
        try {
            return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new ToolException('Unable to encode tool response.', previous: $e);
        }
    }

    /**
     * Return the number of retries a core tool allows, none, so an infrastructure failure
     * surfaces to the caller unchanged.
     *
     * @return int
     */
    protected function maxRecoveryAttempts(): int
    {
        return 0;
    }
}
