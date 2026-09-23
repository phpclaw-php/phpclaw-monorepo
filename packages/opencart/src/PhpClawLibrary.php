<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\OpenCart\Factory\PhpClawFactory;
use PhpClaw\OpenCart\Factory\PhpClawFactoryInterface;

/**
 * PhpClaw library for OpenCart 3/4.
 */
final class PhpClawLibrary
{
    private readonly ClawInterface $engine;

    /**
     * Build the engine through the given factory, or the default OpenCart factory.
     *
     * @param  PhpClawFactoryInterface|null  $factory  Optional factory override for testing.
     */
    public function __construct(?PhpClawFactoryInterface $factory = null)
    {
        $this->engine = ($factory ?? new PhpClawFactory)->create();
    }

    /**
     * Send a message to the AI agent and return the response text.
     *
     * @param  string  $message  The user prompt.
     * @return string The AI response text.
     */
    public function send(string $message): string
    {
        return $this->engine->send($message)->text;
    }

    /**
     * Access the raw Claw engine for streaming or conversation use.
     *
     * @return ClawInterface
     */
    public function getEngine(): ClawInterface
    {
        return $this->engine;
    }
}
