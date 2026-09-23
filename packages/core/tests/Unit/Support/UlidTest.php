<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Support;

use PhpClaw\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function test_generate_returns_26_char_string(): void
    {
        $ulid = Ulid::generate();
        $this->assertSame(Ulid::LENGTH, strlen($ulid));
        $this->assertSame(26, Ulid::LENGTH);
    }

    public function test_generate_uses_only_crockford_base32_alphabet(): void
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        for ($i = 0; $i < 50; $i++) {
            $ulid = Ulid::generate();
            for ($c = 0; $c < strlen($ulid); $c++) {
                $this->assertStringContainsString(
                    $ulid[$c],
                    $alphabet,
                    "ULID char '{$ulid[$c]}' at position {$c} not in Crockford Base32 alphabet"
                );
            }
        }
    }

    public function test_generate_never_contains_ambiguous_letters(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $ulid = Ulid::generate();
            $this->assertStringNotContainsString('I', $ulid);
            $this->assertStringNotContainsString('L', $ulid);
            $this->assertStringNotContainsString('O', $ulid);
            $this->assertStringNotContainsString('U', $ulid);
        }
    }

    public function test_generate_returns_unique_values(): void
    {
        $set = [];
        for ($i = 0; $i < 1000; $i++) {
            $set[Ulid::generate()] = true;
        }
        $this->assertCount(1000, $set);
    }

    public function test_generate_is_time_sortable_in_quick_succession(): void
    {
        $first = Ulid::generate();
        usleep(3000);
        $second = Ulid::generate();
        $this->assertLessThan(0, strcmp(substr($first, 0, 10), substr($second, 0, 10)));
    }

    public function test_is_valid_accepts_freshly_generated_ulid(): void
    {
        $this->assertTrue(Ulid::isValid(Ulid::generate()));
    }

    public function test_is_valid_rejects_short_string(): void
    {
        $this->assertFalse(Ulid::isValid('0123456789ABCDEFGHJKMNPQR'));
    }

    public function test_is_valid_rejects_long_string(): void
    {
        $this->assertFalse(Ulid::isValid('0123456789ABCDEFGHJKMNPQRST'));
    }

    public function test_is_valid_rejects_empty_string(): void
    {
        $this->assertFalse(Ulid::isValid(''));
    }

    public function test_is_valid_rejects_excluded_letters(): void
    {
        $this->assertFalse(Ulid::isValid('IIIIIIIIIIIIIIIIIIIIIIIIII'));
        $this->assertFalse(Ulid::isValid('LLLLLLLLLLLLLLLLLLLLLLLLLL'));
        $this->assertFalse(Ulid::isValid('OOOOOOOOOOOOOOOOOOOOOOOOOO'));
        $this->assertFalse(Ulid::isValid('UUUUUUUUUUUUUUUUUUUUUUUUUU'));
    }

    public function test_is_valid_rejects_lowercase_letters(): void
    {
        $this->assertFalse(Ulid::isValid('abcdefghjkmnpqrstvwxyz0123'));
    }

    public function test_is_valid_rejects_special_characters(): void
    {
        $this->assertFalse(Ulid::isValid('01234-6789ABCDEFGHJKMNPQRS'));
    }

    public function test_length_constant_matches_format_spec(): void
    {
        $this->assertSame(26, Ulid::LENGTH);
    }

    public function test_cannot_be_instantiated_directly(): void
    {
        $ref = new \ReflectionClass(Ulid::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate());
    }
}
