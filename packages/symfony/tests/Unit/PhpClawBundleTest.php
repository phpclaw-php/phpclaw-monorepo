<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Symfony\PhpClawBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PhpClawBundleTest extends TestCase
{
    private PhpClawBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new PhpClawBundle;
    }

    public function test_bundle_reports_the_package_path_as_its_root(): void
    {
        self::assertSame(dirname(__DIR__, 2), $this->bundle->getPath());
    }

    public function test_bundle_returns_correct_path(): void
    {
        $path = $this->bundle->getPath();

        $this->assertStringEndsWith('symfony', str_replace('\\', '/', $path));
        $this->assertDirectoryExists($path);
    }

    public function test_bundle_namespace_is_correct(): void
    {
        $reflection = new \ReflectionClass($this->bundle);

        $this->assertSame('PhpClaw\Symfony', $reflection->getNamespaceName());
    }

    public function test_bundle_extends_abstract_bundle(): void
    {
        $this->assertInstanceOf(
            AbstractBundle::class,
            $this->bundle,
        );
    }
}
