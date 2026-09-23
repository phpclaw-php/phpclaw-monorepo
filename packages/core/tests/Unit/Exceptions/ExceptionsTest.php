<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Exceptions;

use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Exceptions\PhpClawException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Exceptions\ShellDeniedException;
use PhpClaw\Exceptions\SkillException;
use PhpClaw\Exceptions\ToolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionsTest extends TestCase
{
    public static function exceptions(): iterable
    {
        yield 'AdapterException' => [AdapterException::class,       PhpClawException::class];
        yield 'GuardException' => [GuardException::class,         PhpClawException::class];
        yield 'MaxIterationsException' => [MaxIterationsException::class, PhpClawException::class];
        yield 'MemoryException' => [MemoryException::class,        PhpClawException::class];
        yield 'ProviderException' => [ProviderException::class,      PhpClawException::class];
        yield 'SkillException' => [SkillException::class,         PhpClawException::class];
        yield 'ToolException' => [ToolException::class,          PhpClawException::class];
        yield 'ShellDeniedException' => [ShellDeniedException::class,   ToolException::class];
    }

    #[DataProvider('exceptions')]
    public function test_constructor_round_trips_the_message(string $class, string $parent): void
    {
        /** @var PhpClawException $e */
        $e = new $class('something went wrong');

        $this->assertSame('something went wrong', $e->getMessage());
    }

    #[DataProvider('exceptions')]
    public function test_default_message_is_empty(string $class, string $parent): void
    {
        /** @var PhpClawException $e */
        $e = new $class;

        $this->assertSame('', $e->getMessage());
    }

    #[DataProvider('exceptions')]
    public function test_extends_declared_parent(string $class, string $parent): void
    {
        $reflection = new \ReflectionClass($class);
        $this->assertSame($parent, $reflection->getParentClass()->getName());
    }

    #[DataProvider('exceptions')]
    public function test_catchable_as_phpclaw_base_exception(string $class, string $parent): void
    {
        $this->assertInstanceOf(PhpClawException::class, new $class);
    }

    #[DataProvider('exceptions')]
    public function test_ultimately_extends_runtime_exception(string $class, string $parent): void
    {
        $this->assertInstanceOf(RuntimeException::class, new $class);
    }

    public function test_phpclaw_base_exception_constructs_with_code_and_previous(): void
    {
        $prev = new \LogicException('original cause');
        $e = new PhpClawException('outer message', 42, $prev);

        $this->assertSame('outer message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
        $this->assertSame($prev, $e->getPrevious());
    }

    public function test_shell_denied_exception_is_caught_by_tool_exception(): void
    {
        $this->assertInstanceOf(ToolException::class, new ShellDeniedException('denied'));
    }

    public function test_human_denied_exception_retains_tool_context(): void
    {
        $e = new HumanDeniedException('file_write', ['path' => 'a.txt']);

        $this->assertInstanceOf(PhpClawException::class, $e);
        $this->assertSame('file_write', $e->toolName);
        $this->assertSame(['path' => 'a.txt'], $e->toolInput);
    }
}
