<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Memory\NamespaceValidator;
use PHPUnit\Framework\TestCase;

final class NamespaceValidatorTest extends TestCase
{
    public function test_simple_alphanumeric_passes(): void
    {
        NamespaceValidator::validate('conversations');
        $this->assertTrue(true);
    }

    public function test_namespace_with_underscore_passes(): void
    {
        NamespaceValidator::validate('my_namespace');
        $this->assertTrue(true);
    }

    public function test_namespace_with_hyphen_passes(): void
    {
        NamespaceValidator::validate('my-namespace');
        $this->assertTrue(true);
    }

    public function test_namespace_with_dot_passes(): void
    {
        NamespaceValidator::validate('phpclaw.memory');
        $this->assertTrue(true);
    }

    public function test_namespace_with_colon_passes(): void
    {
        NamespaceValidator::validate('app:sessions');
        $this->assertTrue(true);
    }

    public function test_namespace_with_numbers_passes(): void
    {
        NamespaceValidator::validate('cache123');
        $this->assertTrue(true);
    }

    public function test_namespace_at_max_length_passes(): void
    {
        NamespaceValidator::validate(str_repeat('a', 100));
        $this->assertTrue(true);
    }

    public function test_empty_namespace_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('must not be empty');
        NamespaceValidator::validate('');
    }

    public function test_namespace_exceeding_max_length_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('must not exceed');
        NamespaceValidator::validate(str_repeat('a', 101));
    }

    public function test_null_byte_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('null byte');
        NamespaceValidator::validate("valid\0name");
    }

    public function test_double_dot_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage("'..'");
        NamespaceValidator::validate('../secret');
    }

    public function test_double_dot_in_middle_throws(): void
    {
        $this->expectException(MemoryException::class);
        NamespaceValidator::validate('some..thing');
    }

    public function test_forward_slash_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('path separators');
        NamespaceValidator::validate('path/to/namespace');
    }

    public function test_backslash_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('path separators');
        NamespaceValidator::validate('path\\namespace');
    }

    public function test_space_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('illegal characters');
        NamespaceValidator::validate('my namespace');
    }

    public function test_at_sign_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('illegal characters');
        NamespaceValidator::validate('user@domain');
    }

    public function test_hash_throws(): void
    {
        $this->expectException(MemoryException::class);
        $this->expectExceptionMessage('illegal characters');
        NamespaceValidator::validate('tag#1');
    }

    public function test_exclamation_throws(): void
    {
        $this->expectException(MemoryException::class);
        NamespaceValidator::validate('invalid!');
    }
}
