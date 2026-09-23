<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\ProjectTool;
use PHPUnit\Framework\TestCase;

final class ProjectToolTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/phpclaw-test-'.uniqid('prj_', true);
        mkdir($this->project.'/app', 0755, true);
        mkdir($this->project.'/vendor/foo', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->project);
    }

    private function rrm(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->rrm($path.DIRECTORY_SEPARATOR.$e);
            }
        }
        @rmdir($path);
    }

    private function info(array $input = []): array
    {
        $decoded = (array) json_decode((new ProjectTool($this->project))->execute($input), true);

        return (array) ($decoded['data'] ?? []);
    }

    public function test_detects_laravel_by_artisan_marker(): void
    {
        file_put_contents($this->project.'/artisan', '#!/usr/bin/env php');

        self::assertSame('laravel', $this->info()['framework']);
    }

    public function test_unknown_when_no_marker_present(): void
    {
        self::assertSame('unknown', $this->info()['framework']);
    }

    public function test_lists_composer_packages_from_lock(): void
    {
        file_put_contents($this->project.'/composer.lock', json_encode([
            'packages' => [['name' => 'acme/widget', 'version' => '1.2.3']],
        ]));

        $packages = $this->info()['packages'];

        self::assertSame('acme/widget', $packages[0]['name']);
        self::assertSame('1.2.3', $packages[0]['version']);
    }

    public function test_file_tree_skips_vendor(): void
    {
        $tree = $this->info(['depth' => 2]);

        self::assertArrayHasKey('app/', $tree['file_tree']);
        self::assertArrayNotHasKey('vendor/', $tree['file_tree']);
    }

    public function test_missing_root_throws(): void
    {
        $this->expectException(ToolException::class);

        (new ProjectTool($this->project.'/does-not-exist'))->execute([]);
    }
}
