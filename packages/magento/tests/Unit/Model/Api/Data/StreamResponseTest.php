<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model\Api\Data;

use PhpClaw\Magento\Model\Api\Data\StreamResponse;
use PHPUnit\Framework\TestCase;

final class StreamResponseTest extends TestCase
{
    public function test_get_started_returns_false_by_default(): void
    {
        $response = new StreamResponse;

        self::assertFalse($response->getStarted());
    }

    public function test_get_started_returns_true_when_started(): void
    {
        $response = new StreamResponse(started: true);

        self::assertTrue($response->getStarted());
    }
}
