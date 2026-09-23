<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop;

/**
 * Shared SSE response-header logic for phpClaw admin and front controllers.
 */
trait PsSseHeadersTrait
{
    /**
     * Flush output buffers and emit headers required for a Server-Sent Events stream.
     *
     * @return void
     */
    protected function sendSseHeaders(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }
}
