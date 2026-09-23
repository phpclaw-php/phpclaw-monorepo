<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Tools\Concerns\HasToolExecutionContract as CoreToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolContractParityTest extends TestCase
{
    private const LIFECYCLE = ['plan', 'perform', 'verify', 'complete'];

    private const VISIBILITY_RANK = ['public' => 0, 'protected' => 1, 'private' => 2];

    public static function convertedTools(): array
    {
        $cases = [];

        foreach (glob(__DIR__.'/../../../upload/modules/phpclaw/src/Tools/*.php') ?: [] as $file) {
            $class = 'PhpClaw\\PrestaShop\\Tools\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, ToolInterface::class)) {
                continue;
            }

            $cases[basename($file, '.php')] = [$class];
        }

        self::assertGreaterThanOrEqual(
            14,
            count($cases),
            'The parity invariant found fewer tools than this adapter ships, so it is not covering them all.',
        );

        return $cases;
    }

    #[DataProvider('convertedTools')]
    public function test_the_tool_declares_no_execute_of_its_own(string $class): void
    {
        $execute = new \ReflectionMethod($class, 'execute');
        $contract = new \ReflectionClass(CoreToolExecutionContract::class);

        self::assertSame(
            $contract->getFileName(),
            $execute->getFileName(),
            $class.' declares its own execute(), so it never runs the lifecycle and never '
            .'reaches the capability guard while still looking healthy.',
        );
    }

    #[DataProvider('convertedTools')]
    public function test_required_capability_returns_the_class_constant(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $constant = $reflection->getConstant('REQUIRED_CAPABILITY');

        self::assertIsString($constant, $class.' declares no REQUIRED_CAPABILITY constant.');

        $tool = $reflection->newInstanceWithoutConstructor();

        self::assertSame($constant, $tool->requiredCapability());
    }

    #[DataProvider('convertedTools')]
    public function test_the_four_lifecycle_methods_are_declared_in_order(string $class): void
    {
        $lines = $this->ownMethodLines($class);

        foreach (self::LIFECYCLE as $method) {
            self::assertArrayHasKey($method, $lines, $class.' does not implement '.$method.'().');
        }

        $declared = [
            $lines['plan'],
            $lines['perform'],
            $lines['verify'],
            $lines['complete'],
        ];
        $sorted = $declared;
        sort($sorted);

        self::assertSame($sorted, $declared, $class.' declares the lifecycle out of order.');
    }

    #[DataProvider('convertedTools')]
    public function test_declaration_order_runs_public_then_protected_then_private(string $class): void
    {
        $previousRank = 0;
        $previousName = '(start of class)';

        foreach ($this->ownMethods($class) as $method) {
            $rank = self::VISIBILITY_RANK[$this->visibility($method)];

            self::assertGreaterThanOrEqual(
                $previousRank,
                $rank,
                $class.'::'.$method->getName().'() is declared above '.$previousName
                .'(), which breaks the public, protected, private order.',
            );

            $previousRank = $rank;
            $previousName = $method->getName();
        }
    }

    public function test_every_tool_authorises_the_same_single_grant(): void
    {
        $grants = [];

        foreach (self::convertedTools() as $name => [$class]) {
            $grants[$name] = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->requiredCapability();
        }

        self::assertSame(
            ['AdminPhpClawDebug'],
            array_values(array_unique($grants)),
            'Flattening means one grant reaches every tool, which is what the Guide, CHANGELOG and '
            .'UPGRADING wording tells the person granting it.',
        );
        self::assertCount(14, $grants);
    }

    private function ownMethods(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $own = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getFileName() !== $reflection->getFileName()) {
                continue;
            }

            $own[] = $method;
        }

        usort($own, static fn (\ReflectionMethod $a, \ReflectionMethod $b): int => $a->getStartLine() <=> $b->getStartLine());

        return $own;
    }

    private function ownMethodLines(string $class): array
    {
        $lines = [];

        foreach ($this->ownMethods($class) as $method) {
            $lines[$method->getName()] = $method->getStartLine();
        }

        return $lines;
    }

    private function visibility(\ReflectionMethod $method): string
    {
        if ($method->isPrivate()) {
            return 'private';
        }

        return $method->isProtected() ? 'protected' : 'public';
    }
}
