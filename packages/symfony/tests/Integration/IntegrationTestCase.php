<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Skills\SkillRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    private array $exceptionHandlersBefore = [];

    protected static function getKernelClass(): string
    {
        return IntegrationKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->exceptionHandlersBefore = $this->captureExceptionHandlers();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
        static::ensureKernelShutdown();
        $this->restoreExceptionHandlers($this->exceptionHandlersBefore);
        $this->exceptionHandlersBefore = [];
    }

    private function captureExceptionHandlers(): array
    {
        $handlers = [];
        $limit = 30;

        while ($limit-- > 0) {
            $handler = set_exception_handler(static function (\Throwable $e): never {
                throw $e;
            });
            restore_exception_handler();

            if ($handler === null) {
                restore_exception_handler();
                break;
            }

            restore_exception_handler();
            $handlers[] = $handler;
        }

        foreach (array_reverse($handlers) as $h) {
            set_exception_handler($h);
        }

        return $handlers;
    }

    private function restoreExceptionHandlers(array $target): void
    {
        $current = $this->captureExceptionHandlers();

        if (count($current) === count($target)) {
            return;
        }

        foreach ($current as $_) {
            restore_exception_handler();
        }

        foreach (array_reverse($target) as $h) {
            set_exception_handler($h);
        }
    }
}
