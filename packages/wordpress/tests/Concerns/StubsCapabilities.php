<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Concerns;

use Brain\Monkey\Functions;

trait StubsCapabilities
{
    protected function grantCapability(string ...$capabilities): void
    {
        $granted = $capabilities;

        Functions\when('current_user_can')->alias(
            static fn (string $capability): bool => in_array($capability, $granted, true),
        );
    }

    protected function grantAllCapabilities(): void
    {
        Functions\when('current_user_can')->justReturn(true);
    }

    protected function denyAllCapabilities(): void
    {
        Functions\when('current_user_can')->justReturn(false);
    }

    protected function assertForbiddenEnvelope(string $result): void
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertNull($decoded['data']);
        self::assertSame('error', $decoded['meta']['mode']);
    }
}
