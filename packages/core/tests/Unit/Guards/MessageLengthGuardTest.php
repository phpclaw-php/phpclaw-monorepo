<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\MessageLengthGuard;
use PHPUnit\Framework\TestCase;

final class MessageLengthGuardTest extends TestCase
{
    public function test_implements_guard_interface(): void
    {
        $this->assertInstanceOf(GuardInterface::class, new MessageLengthGuard);
    }

    public function test_empty_string_passes(): void
    {
        (new MessageLengthGuard)->scan('');
        $this->assertTrue(true);
    }

    public function test_short_message_passes(): void
    {
        (new MessageLengthGuard)->scan('Hello');
        $this->assertTrue(true);
    }

    public function test_message_at_exact_default_limit_passes(): void
    {
        (new MessageLengthGuard)->scan(str_repeat('a', 40000));
        $this->assertTrue(true);
    }

    public function test_default_max_length_is_40000(): void
    {
        $guard = new MessageLengthGuard;

        $guard->scan(str_repeat('x', 40000));

        $this->expectException(GuardException::class);
        $guard->scan(str_repeat('x', 40001));
    }

    public function test_message_over_default_limit_throws(): void
    {
        $this->expectException(GuardException::class);
        (new MessageLengthGuard)->scan(str_repeat('a', 40001));
    }

    public function test_custom_max_length_accepted(): void
    {
        (new MessageLengthGuard(50))->scan(str_repeat('a', 50));
        $this->assertTrue(true);
    }

    public function test_custom_max_length_blocks_one_over(): void
    {
        $this->expectException(GuardException::class);
        (new MessageLengthGuard(50))->scan(str_repeat('a', 51));
    }

    public function test_exception_message_contains_limit(): void
    {
        try {
            (new MessageLengthGuard(100))->scan(str_repeat('a', 101));
            $this->fail('Expected GuardException');
        } catch (GuardException $e) {
            $this->assertStringContainsString('100', $e->getMessage());
        }
    }

    public function test_multibyte_chars_counted_by_mb_strlen(): void
    {
        $fiveEmoji = str_repeat('😀', 5);

        (new MessageLengthGuard(5))->scan($fiveEmoji);
        $this->assertTrue(true);
    }

    public function test_multibyte_one_over_limit_throws(): void
    {
        $sixEmoji = str_repeat('😀', 6);

        $this->expectException(GuardException::class);
        (new MessageLengthGuard(5))->scan($sixEmoji);
    }
}
