<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model\Api\Data;

use PhpClaw\Magento\Api\Data\StreamResponseInterface;

/**
 * Immutable value object for the POST /V1/phpclaw/chat/stream response.
 */
// non-final: Magento interceptor required
class StreamResponse implements StreamResponseInterface
{
    /**
     * Bind whether the stream was started before exiting.
     *
     * @param  bool  $started  Whether the stream was started before exiting.
     * @return void
     */
    public function __construct(
        private readonly bool $started = false,
    ) {}

    /**
     * Return whether the stream was started.
     *
     * @return bool
     */
    public function getStarted(): bool
    {
        return $this->started;
    }
}
