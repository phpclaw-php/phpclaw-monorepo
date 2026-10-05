<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Exceptions;

use PhpClaw\Exceptions\ProviderException;
use PHPUnit\Framework\TestCase;

final class ProviderExceptionTest extends TestCase
{
    public function test_status_code_defaults_to_zero(): void
    {
        $e = new ProviderException('transport failure');

        self::assertSame(0, $e->statusCode);
    }

    public function test_status_code_is_carried_when_given_positionally(): void
    {
        $e = new ProviderException('rate limited', 429);

        self::assertSame(429, $e->statusCode);
        self::assertSame('rate limited', $e->getMessage());
    }

    public function test_previous_can_be_passed_by_name_without_a_status_code(): void
    {
        $cause = new \JsonException('bad json');
        $e = new ProviderException('Malformed tool-call arguments.', previous: $cause);

        self::assertSame(0, $e->statusCode);
        self::assertSame($cause, $e->getPrevious());
    }

    public function test_status_code_and_previous_can_be_combined(): void
    {
        $cause = new \RuntimeException('upstream 500');
        $e = new ProviderException('Every provider failed.', 500, $cause);

        self::assertSame(500, $e->statusCode);
        self::assertSame($cause, $e->getPrevious());
    }

    public function test_status_code_is_carried_as_the_exception_code(): void
    {
        $e = new ProviderException('unauthorized', 401);

        self::assertSame(401, $e->getCode());
    }
}
