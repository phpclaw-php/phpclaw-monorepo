<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\SqlGuard;

use PHPUnit\Framework\TestCase;

final class GuardBoundaryTest extends TestCase
{
    private function guardDir(): string
    {
        return dirname(__DIR__, 3).'/src/SqlGuard';
    }

    public function test_guard_namespace_imports_nothing_outside_itself(): void
    {
        foreach (glob($this->guardDir().'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            self::assertMatchesRegularExpression(
                '/^namespace\s+PhpClaw\\\\SqlGuard;/m',
                $source,
                basename($file).' must live in the PhpClaw\\SqlGuard namespace.',
            );

            preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

            foreach ($matches[1] as $import) {
                $import = ltrim(trim($import), '\\');
                self::assertStringStartsWith(
                    'PhpClaw\\SqlGuard\\',
                    $import,
                    basename($file)." imports outside the guard namespace: {$import}",
                );
            }
        }
    }

    public function test_only_the_guard_namespace_constructs_safe_sql(): void
    {
        $coreSrc = dirname(__DIR__, 3).'/src';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($coreSrc, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/SqlGuard/')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (preg_match('/\bnew\s+SafeSql\s*\(/', $source) === 1) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame([], $offenders, 'SafeSql may only be constructed inside PhpClaw\\SqlGuard.');
    }
}
