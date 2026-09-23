<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DeclaredCompatibilityTest extends TestCase
{
    private const WORDPRESS_API_FLOOR = [
        '%i' => '6.2',
        'wp_admin_notice(' => '6.4',
        'wp_trigger_error(' => '6.4',
    ];

    private function declaredMinimum(): string
    {
        $header = (string) file_get_contents(__DIR__.'/../../phpclaw.php');

        self::assertSame(
            1,
            preg_match('/^\s*\*\s*Requires at least:\s*([0-9.]+)/m', $header, $m),
            'the plugin header must declare Requires at least',
        );

        return $m[1];
    }

    private function sourceFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public function test_declared_minimum_wordpress_version_covers_every_api_the_code_calls(): void
    {
        $declared = $this->declaredMinimum();
        $files = $this->sourceFiles();

        self::assertNotSame([], $files, 'the scan must find source files');

        foreach (self::WORDPRESS_API_FLOOR as $needle => $floor) {
            foreach ($files as $file) {
                if (! str_contains((string) file_get_contents($file), $needle)) {
                    continue;
                }

                self::assertTrue(
                    version_compare($declared, $floor, '>='),
                    sprintf(
                        '%s uses "%s", which WordPress added in %s, but the plugin header declares %s.',
                        basename($file),
                        $needle,
                        $floor,
                        $declared,
                    ),
                );
            }
        }
    }

    public function test_every_surface_declares_the_same_minimum_wordpress_version(): void
    {
        $declared = $this->declaredMinimum();

        $readme = (string) file_get_contents(__DIR__.'/../../readme.txt');
        self::assertSame(1, preg_match('/^Requires at least:\s*([0-9.]+)/m', $readme, $r));
        self::assertSame($declared, $r[1], 'readme.txt must match the plugin header');

        $badge = (string) file_get_contents(__DIR__.'/../../README.md');
        self::assertStringContainsString(
            'WordPress-'.$declared.'%2B',
            $badge,
            'the README badge must match the plugin header',
        );
    }
}
