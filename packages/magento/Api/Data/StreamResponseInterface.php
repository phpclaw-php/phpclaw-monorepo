<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Api\Data;

/**
 * Data contract for the POST /V1/phpclaw/chat/stream response.
 */
interface StreamResponseInterface
{
    /**
     * Return whether the stream was started.
     *
     * @return bool
     */
    public function getStarted(): bool;
}
