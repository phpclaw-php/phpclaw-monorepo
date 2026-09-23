<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Api;

use PhpClaw\Magento\Api\Data\SendResponseInterface;

/**
 * REST API contract for synchronous phpClaw agent execution.
 */
interface SendInterface
{
    /**
     * Send a message to the phpClaw agent synchronously and return its response.
     *
     * @param  string  $message  User message to send to the agent.
     * @return SendResponseInterface Agent response with text, provider, model, tokens, iterations.
     *
     * @throws \InvalidArgumentException If message is empty.
     */
    public function send(string $message): SendResponseInterface;
}
