<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Shared server-sent-event framing for the admin and Web API streaming chat surfaces.
 */
trait SseTransport
{
    /**
     * Emit the event-stream headers, clearing any buffering that would hold frames back.
     *
     * @return void
     */
    private function sendSseHeaders(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /**
     * Write one event-stream frame and flush it to the client.
     *
     * @param  string  $event  Event name placed on the `event:` line.
     * @param  array<string, mixed>  $payload  Frame body, JSON-encoded onto the `data:` line.
     * @return void
     */
    private function emit(string $event, array $payload): void
    {
        echo 'event: ', $event, "\n";
        echo 'data: ', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
