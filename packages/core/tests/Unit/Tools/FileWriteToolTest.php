<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ToolCatalogue;
use PHPUnit\Framework\TestCase;

final class FileWriteToolTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('fwt_', true);
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->workspace);
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
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rrm($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }

    public function test_name_and_description_advertised_to_llm(): void
    {
        $tool = new FileWriteTool($this->workspace);

        self::assertSame('file_write', $tool->name());
        self::assertStringContainsStringIgnoringCase('write', $tool->description());
    }

    public function test_input_schema_requires_path_and_content(): void
    {
        $tool = new FileWriteTool($this->workspace);
        $schema = $tool->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('path', $schema['properties']);
        self::assertArrayHasKey('content', $schema['properties']);
        self::assertSame(['path', 'content'], $schema['required']);
    }

    public function test_workspace_root_and_max_bytes_accessors(): void
    {
        $tool = new FileWriteTool($this->workspace, maxBytes: 1024);

        self::assertSame(realpath($this->workspace), $tool->workspaceRoot());
        self::assertSame(1024, $tool->maxBytes());
    }

    public function test_constructor_falls_back_to_default_workspace_subpath_when_null(): void
    {
        $tool = new FileWriteTool(null);

        self::assertStringContainsString(FileWriteTool::DEFAULT_WORKSPACE_SUBPATH, $tool->workspaceRoot());
    }

    public function test_constructor_uses_raw_path_when_realpath_resolution_fails(): void
    {
        $nonexistent = sys_get_temp_dir().'/phpclaw-nonexistent-'.uniqid('', true);

        $tool = new FileWriteTool($nonexistent);

        self::assertSame($nonexistent, $tool->workspaceRoot());
    }

    public function test_execute_writes_file_and_returns_confirmation(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $result = $tool->execute(['path' => 'hello.txt', 'content' => 'world']);

        $decoded = (array) json_decode($result, true);

        self::assertSame(5, ((array) $decoded['data'])['bytes_written']);
        self::assertSame('hello.txt', ((array) $decoded['data'])['path']);
        self::assertSame('world', file_get_contents($this->workspace.'/hello.txt'));
    }

    public function test_execute_creates_parent_directories(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $tool->execute(['path' => 'sub/dir/file.txt', 'content' => 'nested']);

        self::assertFileExists($this->workspace.'/sub/dir/file.txt');
        self::assertSame('nested', file_get_contents($this->workspace.'/sub/dir/file.txt'));
    }

    public function test_execute_throws_when_path_empty(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No file path provided.');

        $tool->execute(['path' => '', 'content' => 'x']);
    }

    public function test_execute_throws_when_path_only_whitespace(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '   ', 'content' => 'x']);
    }

    public function test_execute_throws_when_content_exceeds_max_bytes(): void
    {
        $tool = new FileWriteTool($this->workspace, maxBytes: 5);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('exceeds max allowed');

        $tool->execute(['path' => 'big.txt', 'content' => 'too long content']);
    }

    public function test_execute_blocks_dotfile_env_extension(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'secret.env', 'content' => 'API_KEY=x']);
    }

    public function test_execute_blocks_php_extension(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'evil.php', 'content' => '<?php phpinfo();']);
    }

    public function test_execute_allows_php_when_allow_php_write_enabled(): void
    {
        $tool = new FileWriteTool($this->workspace, allowPhpWrite: true);
        $result = $tool->execute(['path' => 'plugin.php', 'content' => "<?php echo 'ok';"]);

        self::assertStringContainsString('bytes', $result);
        self::assertFileExists($this->workspace.'/plugin.php');
    }

    public function test_allow_php_write_covers_phtml_and_phar(): void
    {
        $tool = new FileWriteTool($this->workspace, allowPhpWrite: true);

        $tool->execute(['path' => 'view.phtml', 'content' => 'x']);
        $tool->execute(['path' => 'bundle.phar', 'content' => 'x']);

        self::assertFileExists($this->workspace.'/view.phtml');
        self::assertFileExists($this->workspace.'/bundle.phar');
    }

    public function test_allow_php_write_does_not_unblock_shell_scripts(): void
    {
        $tool = new FileWriteTool($this->workspace, allowPhpWrite: true);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'backdoor.sh', 'content' => 'rm -rf /']);
    }

    public function test_allow_php_write_flows_through_autodiscovery_needsconfig(): void
    {
        $tool = $this->fileWriteFromCatalogue(['workspaceRoot' => $this->workspace, 'allowPhpWrite' => true]);
        $result = $tool->execute(['path' => 'auto.php', 'content' => '<?php echo 1;']);

        self::assertStringContainsString('bytes', $result);
        self::assertFileExists($this->workspace.'/auto.php');
    }

    public function test_autodiscovery_defaults_php_write_off(): void
    {
        $tool = $this->fileWriteFromCatalogue(['workspaceRoot' => $this->workspace]);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'auto.php', 'content' => '<?php echo 1;']);
    }

    private function fileWriteFromCatalogue(array $config): FileWriteTool
    {
        foreach (ToolCatalogue::instantiateDefaults($config) as $tool) {
            if ($tool instanceof FileWriteTool) {
                return $tool;
            }
        }
        self::fail('file_write not present among default tools');
    }

    public function test_execute_blocks_sh_extension(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'backdoor.sh', 'content' => 'rm -rf /']);
    }

    public function test_execute_blocks_key_extension(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'id_rsa.key', 'content' => '-----BEGIN-----']);
    }

    public function test_execute_blocks_path_traversal_with_dotdot(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '../escape.txt', 'content' => 'x']);
    }

    public function test_execute_blocks_path_into_vendor_directory(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'vendor/autoload.txt', 'content' => 'x']);
    }

    public function test_execute_blocks_path_into_dot_git_directory(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '.git/config', 'content' => 'x']);
    }

    public function test_execute_blocks_path_into_node_modules_directory(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'node_modules/payload.txt', 'content' => 'x']);
    }

    public function test_execute_normalizes_leading_slash_relative_path(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $tool->execute(['path' => '/normalize-me.txt', 'content' => 'ok']);

        self::assertFileExists($this->workspace.'/normalize-me.txt');
    }

    public function test_execute_treats_missing_content_key_as_empty_string(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $result = $tool->execute(['path' => 'empty.txt']);

        $decoded = (array) json_decode($result, true);

        self::assertSame(0, ((array) $decoded['data'])['bytes_written']);
        self::assertSame('empty.txt', ((array) $decoded['data'])['path']);
        self::assertSame('', file_get_contents($this->workspace.'/empty.txt'));
    }

    public function test_execute_overwrites_existing_file(): void
    {
        $tool = new FileWriteTool($this->workspace);

        $tool->execute(['path' => 'overwrite.txt', 'content' => 'first']);
        $tool->execute(['path' => 'overwrite.txt', 'content' => 'second']);

        self::assertSame('second', file_get_contents($this->workspace.'/overwrite.txt'));
    }

    public function test_constants_define_safe_defaults(): void
    {
        self::assertSame('storage/phpclaw', FileWriteTool::DEFAULT_WORKSPACE_SUBPATH);
        self::assertSame(10 * 1024 * 1024, FileWriteTool::DEFAULT_MAX_BYTES);
    }

    public function test_symlinked_intermediate_directory_pointing_outside_workspace_is_rejected(): void
    {
        $outsideDir = sys_get_temp_dir().'/phpclaw-fwt-outside-'.uniqid('', true);
        mkdir($outsideDir, 0755, true);

        if (! @symlink($outsideDir, $this->workspace.'/linked-dir')) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        try {
            $tool = new FileWriteTool($this->workspace);

            try {
                $tool->execute(['path' => 'linked-dir/evil.txt', 'content' => 'X']);
                self::fail('Expected a ToolException for a write through a symlinked directory.');
            } catch (ToolException $e) {
                self::assertMatchesRegularExpression('/symlink/', $e->getMessage());
            }

            self::assertFileDoesNotExist($outsideDir.'/evil.txt');
        } finally {
            $this->rrm($outsideDir);
        }
    }

    public function test_symlinked_leaf_pointing_outside_workspace_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/phpclaw-fwt-outside-'.uniqid('', true).'.txt';
        file_put_contents($outside, 'ORIGINAL');

        if (! @symlink($outside, $this->workspace.'/link.txt')) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        try {
            $tool = new FileWriteTool($this->workspace);

            try {
                $tool->execute(['path' => 'link.txt', 'content' => 'HIJACKED']);
                self::fail('Expected a ToolException for a write through a symlinked leaf.');
            } catch (ToolException $e) {
                self::assertMatchesRegularExpression('/symlink/', $e->getMessage());
            }

            self::assertSame('ORIGINAL', file_get_contents($outside));
        } finally {
            @unlink($outside);
        }
    }

    public function test_symlink_chain_to_outside_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/phpclaw-fwt-outside-'.uniqid('', true).'.txt';
        file_put_contents($outside, 'ORIGINAL');

        if (! @symlink($outside, $this->workspace.'/b')) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }
        symlink($this->workspace.'/b', $this->workspace.'/a');

        try {
            $tool = new FileWriteTool($this->workspace);

            $this->expectException(ToolException::class);
            $this->expectExceptionMessageMatches('/symlink/');

            $tool->execute(['path' => 'a', 'content' => 'HIJACKED']);
        } finally {
            @unlink($outside);
        }
    }

    public function test_follow_symlinks_true_allows_writing_through_in_workspace_symlink(): void
    {
        mkdir($this->workspace.'/real', 0755, true);

        if (! @symlink($this->workspace.'/real', $this->workspace.'/linked-dir')) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        $tool = new FileWriteTool($this->workspace, followSymlinks: true);

        $tool->execute(['path' => 'linked-dir/note.txt', 'content' => 'ok']);

        self::assertSame('ok', file_get_contents($this->workspace.'/real/note.txt'));
    }

    public function test_follow_symlinks_true_still_blocks_target_outside_workspace(): void
    {
        $outsideDir = sys_get_temp_dir().'/phpclaw-fwt-outside-'.uniqid('', true);
        mkdir($outsideDir, 0755, true);

        if (! @symlink($outsideDir, $this->workspace.'/linked-dir')) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        try {
            $tool = new FileWriteTool($this->workspace, followSymlinks: true);

            try {
                $tool->execute(['path' => 'linked-dir/evil.txt', 'content' => 'X']);
                self::fail('Expected a ToolException: followSymlinks must not bypass workspace containment.');
            } catch (ToolException) {
                // Expected: the symlink target resolves outside the workspace.
            }

            self::assertFileDoesNotExist($outsideDir.'/evil.txt');
        } finally {
            $this->rrm($outsideDir);
        }
    }
}
