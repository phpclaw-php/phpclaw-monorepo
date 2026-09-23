<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Api;

use PhpClaw\Magento\Api\Data\StreamResponseInterface;

/**
 * REST API contract for SSE streaming chat.
 */
interface StreamInterface
{
    /**
     * Start an SSE stream for the given message.
     *
     * @param  string  $message  User prompt to send to the agent.
     * @param  string  $conversationId  Existing conversation ULID, or '' for a new conversation.
     * @return StreamResponseInterface Placeholder; real SSE exits before returning.
     */
    public function stream(string $message, string $conversationId = ''): StreamResponseInterface;
}
