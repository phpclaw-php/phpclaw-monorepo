<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Laravel\Memory\AbstractDatabaseMemory;
use PHPUnit\Framework\TestCase;

final class AbstractDatabaseMemoryTest extends TestCase
{
    private AbstractDatabaseMemory $memory;

    protected function setUp(): void
    {
        $this->memory = new class extends AbstractDatabaseMemory
        {
            public function guardPublic(string $op, \Closure $fn): mixed
            {
                return $this->guard($op, $fn);
            }

            public function get(string $key, string $namespace = 'default'): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void {}

            public function forget(string $key, string $namespace = 'default'): void {}

            public function flush(string $namespace = 'default'): void {}

            public function all(string $namespace = 'default'): array
            {
                return [];
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return false;
            }
        };
    }

    public function test_guard_returns_closure_result_on_success(): void
    {
        $result = $this->memory->guardPublic('TestOp::get', fn () => 'expected-value');

        $this->assertSame('expected-value', $result);
    }

    public function test_guard_returns_null_for_void_operations(): void
    {
        $result = $this->memory->guardPublic('TestOp::set', function (): void {});

        $this->assertNull($result);
    }

    public function test_guard_passes_through_existing_memory_exception_untouched(): void
    {
        $original = new MemoryException('original message');

        try {
            $this->memory->guardPublic('TestOp::get', function () use ($original): never {
                throw $original;
            });
            $this->fail('Expected MemoryException not thrown');
        } catch (MemoryException $caught) {
            $this->assertSame($original, $caught);
            $this->assertSame('original message', $caught->getMessage());
        }
    }

    public function test_guard_wraps_generic_throwable_into_memory_exception(): void
    {
        $inner = new \RuntimeException('DB connection refused');

        try {
            $this->memory->guardPublic('TestOp::set', function () use ($inner): never {
                throw $inner;
            });
            $this->fail('Expected MemoryException not thrown');
        } catch (MemoryException $caught) {
            $this->assertStringContainsString('TestOp::set', $caught->getMessage());
            $this->assertSame($inner, $caught->getPrevious());
        }
    }

    public function test_guard_wraps_error_subclass_into_memory_exception(): void
    {
        $inner = new \TypeError('Argument must be of type string');

        try {
            $this->memory->guardPublic('TestOp::get', function () use ($inner): never {
                throw $inner;
            });
            $this->fail('Expected MemoryException not thrown');
        } catch (MemoryException $caught) {
            $this->assertStringContainsString('TestOp::get', $caught->getMessage());
            $this->assertSame($inner, $caught->getPrevious());
        }
    }

    public function test_guard_wrapped_message_contains_operation_label(): void
    {
        try {
            $this->memory->guardPublic('DatabaseMemory::flush', fn () => throw new \Exception('disk full'));
            $this->fail('Expected MemoryException not thrown');
        } catch (MemoryException $caught) {
            $this->assertStringContainsString('DatabaseMemory::flush', $caught->getMessage());
        }
    }
}
