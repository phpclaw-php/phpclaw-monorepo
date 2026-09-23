<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\FileReadTool;
use PHPUnit\Framework\TestCase;

final class FileReadToolTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('frt_', true);
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->workspace);
    }

    private function rrm(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
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

    private static function content(string $json): string
    {
        $decoded = (array) json_decode($json, true);

        return (string) ((array) $decoded['data'])['content'];
    }

    private function write(string $relativePath, string $content): void
    {
        $abs = $this->workspace.'/'.$relativePath;
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, $content);
    }

    public function test_name_and_description_advertised_to_llm(): void
    {
        $tool = new FileReadTool($this->workspace);

        self::assertSame('file_read', $tool->name());
        self::assertStringContainsStringIgnoringCase('read', $tool->description());
    }

    public function test_input_schema_requires_path(): void
    {
        $tool = new FileReadTool($this->workspace);
        $schema = $tool->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('path', $schema['properties']);
        self::assertSame(['path'], $schema['required']);
    }

    public function test_workspace_root_and_max_bytes_accessors(): void
    {
        $tool = new FileReadTool($this->workspace, maxBytes: 1024);

        self::assertSame(realpath($this->workspace), $tool->workspaceRoot());
        self::assertSame(1024, $tool->maxBytes());
    }

    public function test_constructor_falls_back_to_default_workspace_subpath_when_null(): void
    {
        $tool = new FileReadTool(null);

        self::assertStringContainsString(FileReadTool::DEFAULT_WORKSPACE_SUBPATH, $tool->workspaceRoot());
    }

    public function test_constructor_uses_raw_path_when_realpath_resolution_fails(): void
    {
        $nonexistent = sys_get_temp_dir().'/phpclaw-nonexistent-'.uniqid('', true);

        $tool = new FileReadTool($nonexistent);

        self::assertSame($nonexistent, $tool->workspaceRoot());
    }

    public function test_execute_reads_file_contents(): void
    {
        $this->write('hello.txt', 'world');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('world', self::content($tool->execute(['path' => 'hello.txt'])));
    }

    public function test_execute_reads_nested_file(): void
    {
        $this->write('sub/dir/file.txt', 'nested');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('nested', self::content($tool->execute(['path' => 'sub/dir/file.txt'])));
    }

    public function test_execute_throws_when_path_missing(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No file path provided.');

        $tool->execute([]);
    }

    public function test_execute_throws_when_path_empty(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '']);
    }

    public function test_execute_throws_when_path_only_whitespace(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '   ']);
    }

    public function test_constructor_throws_when_max_bytes_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBytes must be greater than 0.');

        new FileReadTool($this->workspace, maxBytes: 0);
    }

    public function test_constructor_throws_when_max_bytes_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileReadTool($this->workspace, maxBytes: -1);
    }

    public function test_execute_throws_when_file_not_found(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute(['path' => 'does-not-exist.txt']);
    }

    public function test_execute_throws_when_path_is_a_directory(): void
    {
        mkdir($this->workspace.'/adir', 0755, true);
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Path is not a file');

        $tool->execute(['path' => 'adir']);
    }

    public function test_execute_throws_on_nonexistent_workspace_root(): void
    {
        $nonexistent = sys_get_temp_dir().'/phpclaw-nonexistent-'.uniqid('', true);
        $tool = new FileReadTool($nonexistent);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'anything.txt']);
    }

    public function test_execute_denies_traversal_above_workspace_root_even_when_nothing_exists_there(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $tool->execute(['path' => '../../../../nonexistent-target-xyz.txt']);
    }

    public function test_execute_denies_traversal_to_existing_file_outside_workspace(): void
    {
        $outside = dirname($this->workspace).'/outside-'.uniqid('', true).'.txt';
        file_put_contents($outside, 'secret');

        try {
            $tool = new FileReadTool($this->workspace);

            $this->expectException(ToolException::class);
            $this->expectExceptionMessage('Path traversal detected');

            $tool->execute(['path' => '../'.basename($outside)]);
        } finally {
            @unlink($outside);
        }
    }

    public function test_execute_denies_deeply_nested_traversal(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => str_repeat('../', 10).'etc/passwd']);
    }

    public function test_execute_rejects_absolute_unix_path_outright(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Absolute paths are not allowed');

        $tool->execute(['path' => '/etc/passwd']);
    }

    public function test_execute_resolves_dot_slash_prefix(): void
    {
        $this->write('dotslash.txt', 'ok');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('ok', self::content($tool->execute(['path' => './dotslash.txt'])));
    }

    public function test_execute_resolves_repeated_separators(): void
    {
        $this->write('sub/file.txt', 'ok');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('ok', self::content($tool->execute(['path' => 'sub//file.txt'])));
    }

    public function test_execute_handles_trailing_slash_gracefully(): void
    {
        $this->write('trailer.txt', 'ok');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('ok', self::content($tool->execute(['path' => 'trailer.txt/'])));
    }

    public function test_execute_strips_duplicated_workspace_prefix(): void
    {
        $this->write('dup.txt', 'ok');
        $tool = new FileReadTool($this->workspace);
        $workspaceReal = realpath($this->workspace);

        self::assertSame('ok', self::content($tool->execute(['path' => $workspaceReal.'/dup.txt'])));
    }

    public function test_execute_denies_backslash_style_traversal_via_blocked_segment_split(): void
    {
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'vendor\\evil.php']);
    }

    public function test_execute_denies_symlink_by_default_even_inside_workspace(): void
    {
        $this->write('real.txt', 'linked content');
        symlink($this->workspace.'/real.txt', $this->workspace.'/link.txt');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'link.txt']);
    }

    public function test_execute_follow_symlinks_true_allows_benign_in_workspace_symlink(): void
    {
        $this->write('real.txt', 'linked content');
        symlink($this->workspace.'/real.txt', $this->workspace.'/link.txt');
        $tool = new FileReadTool($this->workspace, followSymlinks: true);

        self::assertSame('linked content', self::content($tool->execute(['path' => 'link.txt'])));
    }

    public function test_execute_denies_symlink_pointing_outside_workspace(): void
    {
        $outside = sys_get_temp_dir().'/phpclaw-outside-'.uniqid('', true).'.txt';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->workspace.'/escape.txt');

        try {
            $tool = new FileReadTool($this->workspace);

            $this->expectException(ToolException::class);
            $this->expectExceptionMessage('Access denied: path contains a symlink.');

            $tool->execute(['path' => 'escape.txt']);
        } finally {
            @unlink($outside);
        }
    }

    public function test_execute_denies_symlinked_directory_pointing_outside_workspace(): void
    {
        $outsideDir = sys_get_temp_dir().'/phpclaw-outside-dir-'.uniqid('', true);
        mkdir($outsideDir, 0755, true);
        file_put_contents($outsideDir.'/secret.txt', 'secret');
        symlink($outsideDir, $this->workspace.'/linked-dir');

        try {
            $tool = new FileReadTool($this->workspace);

            $this->expectException(ToolException::class);
            $this->expectExceptionMessage('Access denied: path contains a symlink.');

            $tool->execute(['path' => 'linked-dir/secret.txt']);
        } finally {
            @unlink($outsideDir.'/secret.txt');
            @rmdir($outsideDir);
        }
    }

    public function test_execute_denies_broken_symlink(): void
    {
        $missingTarget = $this->workspace.'/never-created-'.uniqid('', true).'.txt';
        symlink($missingTarget, $this->workspace.'/broken-link.txt');

        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'broken-link.txt']);
    }

    public function test_execute_denies_symlink_to_env_inside_workspace(): void
    {
        $this->write('.env', 'API_KEY=secret');
        symlink($this->workspace.'/.env', $this->workspace.'/notes.txt');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'notes.txt']);
    }

    public function test_execute_denies_symlink_to_wp_config_inside_workspace(): void
    {
        $this->write('wp-config.php', '<?php');
        symlink($this->workspace.'/wp-config.php', $this->workspace.'/ok.txt');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'ok.txt']);
    }

    public function test_execute_denies_symlink_to_git_directory(): void
    {
        $this->write('.git/config', '[core]');
        symlink($this->workspace.'/.git', $this->workspace.'/data');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'data/config']);
    }

    public function test_execute_denies_symlink_chain_to_env(): void
    {
        $this->write('.env', 'API_KEY=secret');
        symlink($this->workspace.'/.env', $this->workspace.'/b');
        symlink($this->workspace.'/b', $this->workspace.'/a');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'a']);
    }

    public function test_execute_follow_symlinks_true_still_blocks_symlink_to_env(): void
    {
        $this->write('.env', 'API_KEY=secret');
        symlink($this->workspace.'/.env', $this->workspace.'/notes.txt');
        $tool = new FileReadTool($this->workspace, followSymlinks: true);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'notes.txt']);
    }

    public function test_execute_blocks_env_extension(): void
    {
        $this->write('secret.env', 'API_KEY=x');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'secret.env']);
    }

    public function test_execute_blocks_env_extension_case_insensitively(): void
    {
        $this->write('secret.ENV', 'API_KEY=x');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'secret.ENV']);
    }

    public function test_execute_blocks_env_dotfile_variants(): void
    {
        foreach (['.env', '.env.local', '.env.production'] as $name) {
            $this->write($name, 'x');
            $tool = new FileReadTool($this->workspace);

            try {
                $tool->execute(['path' => $name]);
                self::fail("{$name} should have been blocked.");
            } catch (ToolException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_execute_blocks_key_extension(): void
    {
        $this->write('id_rsa.key', '-----BEGIN-----');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'id_rsa.key']);
    }

    public function test_execute_blocks_sqlite_extension(): void
    {
        $this->write('app.sqlite', 'binary');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'app.sqlite']);
    }

    public function test_execute_blocks_wp_config_by_name(): void
    {
        $this->write('wp-config.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Access to 'wp-config.php' is blocked.");

        $tool->execute(['path' => 'wp-config.php']);
    }

    public function test_execute_blocks_wp_config_by_name_case_insensitively(): void
    {
        $this->write('WP-CONFIG.PHP', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'WP-CONFIG.PHP']);
    }

    public function test_execute_blocks_configuration_php_by_name(): void
    {
        $this->write('configuration.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Access to 'configuration.php' is blocked.");

        $tool->execute(['path' => 'configuration.php']);
    }

    public function test_execute_blocks_config_php_by_name(): void
    {
        $this->write('config.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'config.php']);
    }

    public function test_execute_blocks_id_rsa_by_name(): void
    {
        $this->write('id_rsa', '-----BEGIN-----');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'id_rsa']);
    }

    public function test_execute_blocks_credentials_json_by_name(): void
    {
        $this->write('credentials.json', '{}');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'credentials.json']);
    }

    public function test_execute_blocks_htaccess_by_name(): void
    {
        $this->write('.htaccess', 'deny all');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '.htaccess']);
    }

    public function test_execute_blocks_magento_env_php_by_name(): void
    {
        $this->write('env.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Access to 'env.php' is blocked.");

        $tool->execute(['path' => 'env.php']);
    }

    public function test_execute_blocks_prestashop_settings_inc_php_by_name(): void
    {
        $this->write('settings.inc.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("Access to 'settings.inc.php' is blocked.");

        $tool->execute(['path' => 'settings.inc.php']);
    }

    public function test_execute_blocks_config_local_php_by_name(): void
    {
        $this->write('config.local.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'config.local.php']);
    }

    public function test_execute_blocks_config_prod_php_by_name(): void
    {
        $this->write('config.prod.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'config.prod.php']);
    }

    public function test_execute_blocks_vendor_directory(): void
    {
        $this->write('vendor/autoload.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'vendor/autoload.php']);
    }

    public function test_execute_blocks_vendor_directory_case_insensitively(): void
    {
        $this->write('Vendor/autoload.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'Vendor/autoload.php']);
    }

    public function test_execute_blocks_dot_git_directory(): void
    {
        $this->write('.git/config', '[core]');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '.git/config']);
    }

    public function test_execute_blocks_node_modules_directory(): void
    {
        $this->write('node_modules/pkg/index.js', 'x');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'node_modules/pkg/index.js']);
    }

    public function test_execute_blocks_wp_admin_directory(): void
    {
        $this->write('wp-admin/install.php', '<?php');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'wp-admin/install.php']);
    }

    public function test_execute_truncates_content_beyond_max_bytes(): void
    {
        $this->write('large.txt', str_repeat('a', 100));
        $tool = new FileReadTool($this->workspace, maxBytes: 10);

        $result = self::content($tool->execute(['path' => 'large.txt']));

        self::assertStringStartsWith(str_repeat('a', 10), $result);
        self::assertStringContainsString('[File truncated at 10 bytes]', $result);
    }

    public function test_execute_does_not_truncate_at_exact_max_bytes(): void
    {
        $this->write('exact.txt', str_repeat('a', 10));
        $tool = new FileReadTool($this->workspace, maxBytes: 10);

        $result = self::content($tool->execute(['path' => 'exact.txt']));

        self::assertSame(str_repeat('a', 10), $result);
        self::assertStringNotContainsString('truncated', $result);
    }

    public function test_execute_does_not_truncate_content_under_max_bytes(): void
    {
        $this->write('small.txt', 'tiny');
        $tool = new FileReadTool($this->workspace, maxBytes: 1000);

        $result = self::content($tool->execute(['path' => 'small.txt']));

        self::assertSame('tiny', $result);
    }

    public function test_execute_reads_filename_with_spaces(): void
    {
        $this->write('my file.txt', 'spaced');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('spaced', self::content($tool->execute(['path' => 'my file.txt'])));
    }

    public function test_execute_reads_unicode_filename(): void
    {
        $this->write('文件.txt', 'unicode content');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('unicode content', self::content($tool->execute(['path' => '文件.txt'])));
    }

    public function test_execute_handles_very_long_nonexistent_path_gracefully(): void
    {
        $tool = new FileReadTool($this->workspace);
        $longPath = str_repeat('a/', 200).'file.txt';

        $this->expectException(ToolException::class);

        $tool->execute(['path' => $longPath]);
    }

    public function test_constants_define_safe_defaults(): void
    {
        self::assertSame('storage/phpclaw', FileReadTool::DEFAULT_WORKSPACE_SUBPATH);
        self::assertSame(16384, FileReadTool::DEFAULT_MAX_BYTES);
    }

    public function test_execute_single_segment_tail_does_not_strip_as_workspace_duplicate(): void
    {
        $base = sys_get_temp_dir().'/phpclaw-test-'.uniqid('frt6_', true);
        $workspace = $base.'/phpclaw';
        mkdir($workspace, 0755, true);

        try {
            file_put_contents($workspace.'/notes.md', 'top-level');
            mkdir($workspace.'/phpclaw', 0755, true);
            file_put_contents($workspace.'/phpclaw/notes.md', 'nested');

            $tool = new FileReadTool($workspace);

            self::assertSame('nested', self::content($tool->execute(['path' => 'phpclaw/notes.md'])));
        } finally {
            $this->rrm($base);
        }
    }

    public function test_execute_ambiguous_tail_when_both_candidates_exist_prefers_unstripped(): void
    {
        $base = sys_get_temp_dir().'/phpclaw-test-'.uniqid('frt7_', true);
        $workspace = $base.'/proj/foo/bar';
        mkdir($workspace, 0755, true);

        try {
            file_put_contents($workspace.'/stripped.txt', 'stripped-target');
            mkdir($workspace.'/foo/bar', 0755, true);
            file_put_contents($workspace.'/foo/bar/stripped.txt', 'unstripped-target');

            $tool = new FileReadTool($workspace);

            self::assertSame('unstripped-target', self::content($tool->execute(['path' => 'foo/bar/stripped.txt'])));
        } finally {
            $this->rrm($base);
        }
    }

    public function test_execute_resolves_through_deploy_symlink_ancestor(): void
    {
        $base = sys_get_temp_dir().'/phpclaw-test-'.uniqid('frt8_', true);
        $release = $base.'/releases/20240101120000';
        mkdir($release.'/storage', 0755, true);
        symlink($release, $base.'/current');

        try {
            file_put_contents($release.'/storage/deployed.txt', 'deployed content');

            $tool = new FileReadTool($base.'/current/storage');

            self::assertSame('deployed content', self::content($tool->execute(['path' => 'deployed.txt'])));
        } finally {
            $this->rrm($base);
        }
    }

    public function test_construction_with_nonexistent_workspace_does_not_throw(): void
    {
        $nonexistent = sys_get_temp_dir().'/phpclaw-nonexistent-'.uniqid('', true);

        $tool = new FileReadTool($nonexistent);

        self::assertInstanceOf(FileReadTool::class, $tool);
    }

    public function test_execute_truncation_does_not_split_utf8_multibyte_char(): void
    {
        $content = str_repeat('a', 10)."\xE2\x82\xAC";
        $this->write('utf8.txt', $content);
        $tool = new FileReadTool($this->workspace, maxBytes: 11);

        $result = self::content($tool->execute(['path' => 'utf8.txt']));
        $textPortion = strstr($result, "\n[File truncated", true);

        self::assertNotFalse($textPortion, 'Expected a truncation marker.');
        self::assertSame(str_repeat('a', 10), $textPortion, 'Incomplete trailing UTF-8 byte must be trimmed, not left dangling.');
    }

    public function test_execute_refuses_binary_file(): void
    {
        $binary = "\x89PNG\r\n\x1a\n"."\x00\x00\x00\x0D".'IHDR'."\x00\x00\x00\x00";
        $this->write('image.png', $binary);
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Binary file; not readable as text');

        $tool->execute(['path' => 'image.png']);
    }

    public function test_execute_sanitizes_injection_like_path_in_exception_message(): void
    {
        $tool = new FileReadTool($this->workspace);

        try {
            $tool->execute(['path' => 'ignore-previous-<script>alert(1)</script>;rm -rf /.txt']);
            self::fail('Expected a ToolException.');
        } catch (ToolException $e) {
            self::assertStringNotContainsString('<script>', $e->getMessage());
            self::assertStringNotContainsString(';', $e->getMessage());
        }
    }

    public function test_execute_exception_message_truncates_long_path_fragment(): void
    {
        $tool = new FileReadTool($this->workspace);
        $longSegment = str_repeat('a', 100);

        try {
            $tool->execute(['path' => $longSegment.'.txt']);
            self::fail('Expected a ToolException.');
        } catch (ToolException $e) {
            self::assertLessThanOrEqual(strlen('File not found: ') + 64, strlen($e->getMessage()));
        }
    }

    public function test_execute_vendor_allowed_when_noise_blocked_dirs_overridden(): void
    {
        $this->write('vendor/autoload.php', '<?php return [];');
        $tool = new FileReadTool($this->workspace, noiseBlockedDirs: []);

        self::assertSame('<?php return [];', self::content($tool->execute(['path' => 'vendor/autoload.php'])));
    }

    public function test_execute_git_directory_still_blocked_when_noise_blocked_dirs_overridden_to_empty(): void
    {
        $this->write('.git/config', '[core]');
        $tool = new FileReadTool($this->workspace, noiseBlockedDirs: []);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '.git/config']);
    }

    public function test_check_blocked_dir_segments_absolute_throws_when_prefix_does_not_match(): void
    {
        $tool = new FileReadTool($this->workspace);
        $tool->workspaceRoot();

        $method = new \ReflectionMethod($tool, 'checkBlockedDirSegmentsAbsolute');
        $method->setAccessible(true);

        $this->expectException(ToolException::class);

        $method->invoke($tool, '/completely/unrelated/path/.git/config');
    }

    public function test_check_blocked_dir_segments_absolute_case_insensitive_prefix_still_blocks_on_darwin_and_windows(): void
    {
        if (! in_array(\PHP_OS_FAMILY, ['Darwin', 'Windows'], true)) {
            self::markTestSkipped('Case-insensitive path folding only applies on Darwin/Windows.');
        }

        $this->write('.git/config', '[core]');
        $tool = new FileReadTool($this->workspace);
        $root = $tool->workspaceRoot();

        $method = new \ReflectionMethod($tool, 'checkBlockedDirSegmentsAbsolute');
        $method->setAccessible(true);

        $mixedCasePath = strtoupper($root).'/.git/config';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/directory is blocked/');

        $method->invoke($tool, $mixedCasePath);
    }

    public function test_execute_dotdot_before_symlink_is_collapsed_before_the_walk(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $this->write('.env', 'SECRET=1');
        symlink($this->workspace.'/.env', $this->workspace.'/link');

        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'a/../link']);
    }

    public function test_execute_multiple_dotdot_before_symlink_is_collapsed_before_the_walk(): void
    {
        mkdir($this->workspace.'/a/b', 0755, true);
        $this->write('.env', 'SECRET=1');
        symlink($this->workspace.'/.env', $this->workspace.'/link');

        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => 'a/b/../../link']);
    }

    public function test_execute_dot_and_dotdot_before_symlink_is_collapsed_before_the_walk(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $this->write('.env', 'SECRET=1');
        symlink($this->workspace.'/.env', $this->workspace.'/link');

        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Access denied: path contains a symlink.');

        $tool->execute(['path' => './a/../link']);
    }

    public function test_execute_dotdot_escaping_above_root_reports_traversal_not_symlink(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $tool = new FileReadTool($this->workspace);

        try {
            $tool->execute(['path' => 'a/../../outside.txt']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('Path traversal detected', $e->getMessage());
            self::assertStringNotContainsString('symlink', $e->getMessage());
        }
    }

    public function test_execute_dotdot_to_legitimate_sibling_file_succeeds(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $this->write('b.php', 'plain text, not php-blocked since .php is not in BLOCKED_EXTENSIONS');

        $tool = new FileReadTool($this->workspace);

        $result = $tool->execute(['path' => 'a/../b.php']);

        self::assertStringContainsString('plain text', $result);
    }

    public function test_execute_nonexistent_parent_directory_reports_file_not_found_not_traversal(): void
    {
        $tool = new FileReadTool($this->workspace);

        try {
            $tool->execute(['path' => 'nonexistent-dir/file.txt']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('File not found', $e->getMessage());
            self::assertStringNotContainsString('traversal', $e->getMessage());
        }
    }

    public function test_execute_throws_when_file_unreadable_due_to_permissions(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root; permission checks are bypassed.');
        }

        $this->write('locked.txt', 'secret');
        chmod($this->workspace.'/locked.txt', 0000);

        try {
            $tool = new FileReadTool($this->workspace);
            $threw = false;
            try {
                @$tool->execute(['path' => 'locked.txt']);
            } catch (ToolException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Expected a ToolException for an unreadable file.');
        } finally {
            @chmod($this->workspace.'/locked.txt', 0644);
        }
    }

    public function test_execute_blocks_envrc_by_name(): void
    {
        $this->write('.envrc', 'export SECRET=1');
        $tool = new FileReadTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => '.envrc']);
    }

    public function test_execute_does_not_false_positive_on_envoy_file(): void
    {
        $this->write('.envoy', 'server: example.com');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('server: example.com', self::content($tool->execute(['path' => '.envoy'])));
    }

    public function test_execute_does_not_false_positive_on_environment_md(): void
    {
        $this->write('.environment.md', '# environment notes');
        $tool = new FileReadTool($this->workspace);

        self::assertSame('# environment notes', self::content($tool->execute(['path' => '.environment.md'])));
    }
}
