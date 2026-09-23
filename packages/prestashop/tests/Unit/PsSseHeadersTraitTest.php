<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\PsSseHeadersTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsSseHeadersTrait::class)]
final class PsSseHeadersTraitTest extends TestCase
{
    private function newSubject(): object
    {
        return new class
        {
            use PsSseHeadersTrait;

            public function callSendSseHeaders(): void
            {
                $this->sendSseHeaders();
            }
        };
    }

    private function restoreBufferBaseline(int $toLevel): void
    {
        while (ob_get_level() < $toLevel) {
            ob_start();
        }
    }

    public function test_send_sse_headers_sets_all_four_sse_headers(): void
    {
        if (! \function_exists('xdebug_get_headers')) {
            self::markTestSkipped('xdebug_get_headers() requires the Xdebug extension, the only reliable way to observe header() calls under CLI SAPI.');
        }

        $baselineLevel = ob_get_level();

        $this->newSubject()->callSendSseHeaders();

        $headers = xdebug_get_headers();

        $this->restoreBufferBaseline($baselineLevel);

        self::assertContains('Content-Type: text/event-stream; charset=utf-8', $headers);
        self::assertContains('Cache-Control: no-cache, no-store, must-revalidate', $headers);
        self::assertContains('Connection: keep-alive', $headers);
        self::assertContains('X-Accel-Buffering: no', $headers);
    }

    public function test_send_sse_headers_drains_every_active_output_buffer(): void
    {
        $baselineLevel = ob_get_level();

        ob_start();
        echo 'buffered content that must not leak into the SSE stream';
        ob_start();
        ob_start();

        self::assertSame($baselineLevel + 3, ob_get_level());

        $this->newSubject()->callSendSseHeaders();

        $levelAfterDrain = ob_get_level();

        $this->restoreBufferBaseline($baselineLevel);

        self::assertSame(0, $levelAfterDrain);
    }

    public function test_send_sse_headers_is_a_no_op_when_already_at_baseline(): void
    {
        $baselineLevel = ob_get_level();

        $this->newSubject()->callSendSseHeaders();

        $levelAfterDrain = ob_get_level();

        $this->restoreBufferBaseline($baselineLevel);

        self::assertSame(0, $levelAfterDrain);
    }
}
