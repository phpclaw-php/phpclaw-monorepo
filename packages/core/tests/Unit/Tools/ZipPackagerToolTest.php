<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\ZipPackagerTool;
use PHPUnit\Framework\TestCase;

final class ZipPackagerToolTest extends TestCase
{
    private string $workspace;

    private ?string $zipPath = null;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('zpt_', true);
        mkdir($this->workspace.'/plugin/inc', 0755, true);
        file_put_contents($this->workspace.'/plugin/main.php', '<?php // plugin');
        file_put_contents($this->workspace.'/plugin/inc/helper.php', '<?php // helper');
    }

    protected function tearDown(): void
    {
        $this->rrm($this->workspace);
        if ($this->zipPath !== null) {
            @unlink($this->zipPath);
        }
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

    public function test_packages_folder_under_top_level_named_directory(): void
    {
        $out = (new ZipPackagerTool($this->workspace))->execute(['source_dir' => 'plugin', 'output_name' => 'myplugin']);
        $res = (array) ((array) json_decode($out, true))['data'];
        $this->zipPath = $res['zip_path'];

        self::assertSame('created', $res['status']);
        self::assertSame(2, $res['files']);
        self::assertFileExists($this->zipPath);

        $zip = new \ZipArchive;
        $zip->open($this->zipPath);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('myplugin/main.php', $entries);
        self::assertContains('myplugin/inc/helper.php', $entries);
    }

    public function test_output_name_is_sanitised(): void
    {
        $out = (new ZipPackagerTool($this->workspace))->execute(['source_dir' => 'plugin', 'output_name' => 'my/../evil name']);
        $res = (array) ((array) json_decode($out, true))['data'];
        $this->zipPath = $res['zip_path'];

        self::assertStringEndsWith('/myevilname.zip', $res['zip_path']);
    }

    public function test_missing_source_dir_throws(): void
    {
        $this->expectException(ToolException::class);

        (new ZipPackagerTool($this->workspace))->execute(['source_dir' => 'ghost', 'output_name' => 'x']);
    }

    public function test_source_escaping_workspace_is_blocked(): void
    {
        $this->expectException(ToolException::class);

        (new ZipPackagerTool($this->workspace))->execute(['source_dir' => '../../etc', 'output_name' => 'x']);
    }
}
