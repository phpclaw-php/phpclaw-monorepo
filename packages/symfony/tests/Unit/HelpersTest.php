<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Claw;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Symfony\PhpClawBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/src/helpers.php';
        putenv('OPENAI_API_KEY=sk-test-helpers');
        PhpClawBundle::resetBootedContainer();
    }

    protected function tearDown(): void
    {
        putenv('OPENAI_API_KEY');
        global $kernel;
        $kernel = null;
        PhpClawBundle::resetBootedContainer();
    }

    public function test_phpclaw_helper_returns_instance_when_no_global_kernel(): void
    {
        global $kernel;
        $kernel = null;

        $instance = phpclaw(fresh: true);

        self::assertInstanceOf(PhpClaw::class, $instance);
    }

    public function test_phpclaw_helper_returns_same_instance_on_repeated_calls(): void
    {
        global $kernel;
        $kernel = null;

        $first = phpclaw(fresh: true);
        $second = phpclaw();

        self::assertSame($first, $second);
    }

    public function test_phpclaw_helper_function_exists(): void
    {
        self::assertTrue(function_exists('phpclaw'));
    }

    public function test_phpclaw_helper_with_kernel_that_has_phpclaw_service(): void
    {
        global $kernel;

        $engine = phpclaw(fresh: true);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === ClawInterface::class,
        );
        $container->method('get')->willReturn($engine);

        $kernelMock = $this->createMock(KernelInterface::class);
        $kernelMock->method('getContainer')->willReturn($container);

        $kernel = $kernelMock;
        $resolved = phpclaw(fresh: true);

        self::assertSame($engine, $resolved);

        $kernel = null;
    }

    public function test_phpclaw_helper_with_kernel_claw_service_fallback(): void
    {
        global $kernel;

        $engine = phpclaw(fresh: true);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === Claw::class,
        );
        $container->method('get')->willReturn($engine);

        $kernelMock = $this->createMock(KernelInterface::class);
        $kernelMock->method('getContainer')->willReturn($container);

        $kernel = $kernelMock;
        $resolved = phpclaw(fresh: true);

        self::assertSame($engine, $resolved);

        $kernel = null;
    }

    public function test_phpclaw_helper_resolves_from_the_booted_bundle_container(): void
    {
        $engine = phpclaw(fresh: true);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === ClawInterface::class,
        );
        $container->method('get')->willReturn($engine);

        $ref = new \ReflectionProperty(PhpClawBundle::class, 'bootedContainer');
        $ref->setAccessible(true);
        $ref->setValue(null, $container);

        $resolved = phpclaw(fresh: true);

        self::assertSame($engine, $resolved, 'A real bundle boot must be resolvable without any global $kernel.');
    }

    public function test_phpclaw_helper_prefers_the_booted_bundle_container_over_global_kernel(): void
    {
        global $kernel;

        $bundleEngine = PhpClaw::builder()->apiKey('sk-bundle-engine')->build();
        $globalKernelEngine = PhpClaw::builder()->apiKey('sk-global-kernel-engine')->build();

        $bundleContainer = $this->createMock(ContainerInterface::class);
        $bundleContainer->method('has')->willReturn(true);
        $bundleContainer->method('get')->willReturn($bundleEngine);

        $ref = new \ReflectionProperty(PhpClawBundle::class, 'bootedContainer');
        $ref->setAccessible(true);
        $ref->setValue(null, $bundleContainer);

        $globalKernelContainer = $this->createMock(ContainerInterface::class);
        $globalKernelContainer->method('has')->willReturn(true);
        $globalKernelContainer->method('get')->willReturn($globalKernelEngine);

        $kernelMock = $this->createMock(KernelInterface::class);
        $kernelMock->method('getContainer')->willReturn($globalKernelContainer);
        $kernel = $kernelMock;

        $resolved = phpclaw(fresh: true);

        self::assertSame($bundleEngine, $resolved, 'The booted bundle container must win over a global $kernel.');

        $kernel = null;
    }
}
