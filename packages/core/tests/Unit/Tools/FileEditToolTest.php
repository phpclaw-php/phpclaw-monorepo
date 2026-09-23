<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tests\Unit\Tools\Support\ShortWriteStreamWrapper;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\FileEditTool;
use PHPUnit\Framework\TestCase;

final class FileEditToolTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('fet_', true);
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->workspace);
        FileReadLog::reset();
    }

    private function seed(string $name, string $contents): void
    {
        $path = $this->workspace.'/'.$name;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
    }

    private function markRead(string $name): void
    {
        $absolute = realpath($this->workspace.'/'.$name);
        if ($absolute !== false) {
            FileReadLog::markRead((string) realpath($this->workspace), $absolute);
        }
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

    private static function payload(string $json): array
    {
        $decoded = (array) json_decode($json, true);

        return (array) $decoded['data'];
    }

    public function test_name_and_schema(): void
    {
        $tool = new FileEditTool($this->workspace);

        self::assertSame('file_edit', $tool->name());
        self::assertSame(['path', 'old_str', 'new_str'], $tool->inputSchema()['required']);
    }

    public function test_the_legacy_file_key_is_still_accepted(): void
    {
        $this->seed('legacy.txt', "alpha\nTARGET\nomega\n");
        $this->markRead('legacy.txt');
        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'legacy.txt', 'old_str' => 'TARGET', 'new_str' => 'REPLACED']);

        self::assertStringContainsString('"status":"edited"', $out);
        self::assertStringContainsString('REPLACED', (string) file_get_contents($this->workspace.'/legacy.txt'));
    }

    public function test_the_new_path_key_is_accepted(): void
    {
        $this->seed('modern.txt', "alpha\nTARGET\nomega\n");
        $this->markRead('modern.txt');
        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['path' => 'modern.txt', 'old_str' => 'TARGET', 'new_str' => 'REPLACED']);

        self::assertStringContainsString('"status":"edited"', $out);
        self::assertStringContainsString('REPLACED', (string) file_get_contents($this->workspace.'/modern.txt'));
    }

    public function test_unique_match_is_replaced_and_reports_line(): void
    {
        $this->seed('a.txt', "line one\nTARGET\nline three\n");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'a.txt', 'old_str' => 'TARGET', 'new_str' => 'FIXED']);
        $result = self::payload($out);

        self::assertSame('edited', $result['status']);
        self::assertSame(2, $result['line_changed']);
        self::assertStringContainsString('FIXED', (string) file_get_contents($this->workspace.'/a.txt'));
    }

    public function test_zero_matches_throws(): void
    {
        $this->seed('a.txt', "nothing to see here\n");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $tool->execute(['file' => 'a.txt', 'old_str' => 'MISSING', 'new_str' => 'X']);
    }

    public function test_multiple_matches_throws(): void
    {
        $this->seed('a.txt', "dup\ndup\n");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/matches 2 locations/');

        $tool->execute(['file' => 'a.txt', 'old_str' => 'dup', 'new_str' => 'X']);
    }

    public function test_missing_file_throws(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/file not found/');

        $tool->execute(['file' => 'ghost.txt', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_path_traversal_is_blocked(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/traversal/');

        $tool->execute(['file' => '../escape.txt', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_missing_required_input_throws(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'a.txt', 'old_str' => '', 'new_str' => 'b']);
    }

    public function test_leaf_symlink_to_outside_file_is_blocked(): void
    {
        $outside = sys_get_temp_dir().'/phpclaw_outside_'.getmypid().'.txt';
        file_put_contents($outside, "SECRET_VALUE\n");
        $link = $this->workspace.'/leak.txt';
        @unlink($link);

        if (! @symlink($outside, $link)) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        try {
            $this->expectException(ToolException::class);
            $this->expectExceptionMessageMatches('/symlink/');
            (new FileEditTool($this->workspace))->execute(['file' => 'leak.txt', 'old_str' => 'SECRET_VALUE', 'new_str' => 'OVERWRITE']);
        } finally {
            self::assertStringContainsString('SECRET_VALUE', (string) file_get_contents($outside), 'outside file must be unchanged');
            @unlink($link);
            @unlink($outside);
        }
    }

    public function test_nonexistent_target_still_reports_not_found_not_escapes_workspace(): void
    {
        $tool = new FileEditTool($this->workspace);

        try {
            $tool->execute(['file' => 'does-not-exist-yet.txt', 'old_str' => 'a', 'new_str' => 'b']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('file not found', $e->getMessage());
        }
    }

    public function test_symlink_to_env_inside_workspace_is_rejected(): void
    {
        $this->seed('.env', "API_KEY=secret\n");
        symlink($this->workspace.'/.env', $this->workspace.'/notes.txt');

        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/symlink/');

        $tool->execute(['file' => 'notes.txt', 'old_str' => 'API_KEY', 'new_str' => 'X']);
    }

    public function test_symlink_to_wp_config_inside_workspace_is_rejected(): void
    {
        $this->seed('wp-config.php', "<?php define('DB_PASSWORD', 'secret');");
        symlink($this->workspace.'/wp-config.php', $this->workspace.'/ok.txt');

        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/symlink/');

        $tool->execute(['file' => 'ok.txt', 'old_str' => 'secret', 'new_str' => 'X']);
    }

    public function test_symlinked_parent_directory_pointing_outside_workspace_is_rejected(): void
    {
        $outsideDir = sys_get_temp_dir().'/phpclaw-fet-outside-'.uniqid('', true);
        mkdir($outsideDir, 0755, true);
        file_put_contents($outsideDir.'/target.php', 'CONTENT');
        symlink($outsideDir, $this->workspace.'/linked-dir');

        try {
            $tool = new FileEditTool($this->workspace);

            $this->expectException(ToolException::class);

            $tool->execute(['file' => 'linked-dir/target.php', 'old_str' => 'CONTENT', 'new_str' => 'X']);
        } finally {
            @unlink($outsideDir.'/target.php');
            @rmdir($outsideDir);
        }
    }

    public function test_symlinked_intermediate_path_component_is_rejected(): void
    {
        $outsideDir = sys_get_temp_dir().'/phpclaw-fet-outside-'.uniqid('', true);
        mkdir($outsideDir.'/sub', 0755, true);
        file_put_contents($outsideDir.'/sub/target.php', 'CONTENT');
        mkdir($this->workspace.'/a', 0755, true);
        symlink($outsideDir, $this->workspace.'/a/b');

        try {
            $tool = new FileEditTool($this->workspace);

            $this->expectException(ToolException::class);

            $tool->execute(['file' => 'a/b/sub/target.php', 'old_str' => 'CONTENT', 'new_str' => 'X']);
        } finally {
            $this->rrm($outsideDir);
        }
    }

    public function test_symlink_chain_to_outside_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/phpclaw-fet-outside-'.uniqid('', true).'.txt';
        file_put_contents($outside, 'CONTENT');
        symlink($outside, $this->workspace.'/b');
        symlink($this->workspace.'/b', $this->workspace.'/a');

        try {
            $tool = new FileEditTool($this->workspace);

            $this->expectException(ToolException::class);
            $this->expectExceptionMessageMatches('/symlink/');

            $tool->execute(['file' => 'a', 'old_str' => 'CONTENT', 'new_str' => 'X']);
        } finally {
            @unlink($outside);
        }
    }

    public function test_follow_symlinks_true_allows_benign_in_workspace_symlink(): void
    {
        $this->seed('real.txt', 'ORIGINAL');
        symlink($this->workspace.'/real.txt', $this->workspace.'/link.txt');
        $this->markRead('link.txt');

        $tool = new FileEditTool($this->workspace, followSymlinks: true);

        $out = $tool->execute(['file' => 'link.txt', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
        self::assertSame('CHANGED', file_get_contents($this->workspace.'/real.txt'));
    }

    public function test_dotdot_traversal_rejected(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/traversal/');

        $tool->execute(['file' => '../outside.php', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_absolute_unix_path_rejected(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '/etc/passwd', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_absolute_windows_drive_path_rejected(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'C:\\Windows\\System32\\config.sys', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_absolute_windows_unc_path_rejected(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '\\\\server\\share\\file.txt', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_redundant_separators_do_not_bypass_containment(): void
    {
        $this->seed('a/b/c.php', 'ORIGINAL');
        $this->markRead('a/b/c.php');

        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'a//b///c.php', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
        self::assertSame('CHANGED', file_get_contents($this->workspace.'/a/b/c.php'));
    }

    public function test_dot_segments_are_handled(): void
    {
        $this->seed('a/file.php', 'ORIGINAL');
        $this->markRead('a/file.php');

        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => './a/./file.php', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
        self::assertSame('CHANGED', file_get_contents($this->workspace.'/a/file.php'));
    }

    public function test_legitimate_filename_with_double_dots_is_accepted(): void
    {
        $this->seed('foo..bar.php', 'ORIGINAL');
        $this->markRead('foo..bar.php');

        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'foo..bar.php', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
        self::assertSame('CHANGED', file_get_contents($this->workspace.'/foo..bar.php'));
    }

    public function test_workspace_root_itself_is_handled_without_crashing_or_escaping(): void
    {
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '.', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_resolves_through_deploy_symlink_ancestor(): void
    {
        $base = sys_get_temp_dir().'/phpclaw-fet-deploy-'.uniqid('', true);
        $release = $base.'/releases/20240101120000';
        mkdir($release.'/storage', 0755, true);
        symlink($release, $base.'/current');

        try {
            file_put_contents($release.'/storage/deployed.php', 'ORIGINAL');

            $root = (string) realpath($base.'/current/storage');
            FileReadLog::markRead($root, (string) realpath($root.'/deployed.php'));

            $tool = new FileEditTool($base.'/current/storage');

            $out = $tool->execute(['file' => 'deployed.php', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

            self::assertSame('edited', self::payload($out)['status']);
            self::assertSame('CHANGED', file_get_contents($release.'/storage/deployed.php'));
        } finally {
            $this->rrm($base);
        }
    }

    public function test_construction_with_nonexistent_workspace_does_not_throw_but_execute_does(): void
    {
        $nonexistent = sys_get_temp_dir().'/phpclaw-fet-nonexistent-'.uniqid('', true);

        $tool = new FileEditTool($nonexistent);
        self::assertInstanceOf(FileEditTool::class, $tool);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'anything.txt', 'old_str' => 'a', 'new_str' => 'b']);
    }

    public function test_binary_file_is_refused_and_left_byte_identical(): void
    {
        $binary = "\x89PNG\r\n\x1a\n"."\x00\x00\x00\x0D".'IHDR'."\x00\x00\x00\x00";
        $this->seed('image.png', $binary);
        $this->markRead('image.png');

        $tool = new FileEditTool($this->workspace);

        try {
            $tool->execute(['file' => 'image.png', 'old_str' => 'IHDR', 'new_str' => 'XXXX']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('Binary file', $e->getMessage());
        }

        self::assertSame($binary, file_get_contents($this->workspace.'/image.png'), 'binary file must be byte-identical after a rejected edit');
    }

    public function test_injection_like_path_is_sanitized_in_exception_message(): void
    {
        $tool = new FileEditTool($this->workspace);

        try {
            $tool->execute(['file' => 'ignore-previous-<script>alert(1)</script>;rm.txt', 'old_str' => 'a', 'new_str' => 'b']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringNotContainsString('<script>', $e->getMessage());
            self::assertStringNotContainsString(';', $e->getMessage());
        }
    }

    public function test_exception_messages_never_contain_old_str_or_new_str_content(): void
    {
        $this->seed('a.txt', "line one\nSAFE\nline three\n");
        $tool = new FileEditTool($this->workspace);

        $secretOld = 'UNIQUE_SECRET_OLD_MARKER_XYZ';
        $secretNew = 'UNIQUE_SECRET_NEW_MARKER_XYZ';

        try {
            $tool->execute(['file' => 'a.txt', 'old_str' => $secretOld, 'new_str' => $secretNew]);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringNotContainsString($secretOld, $e->getMessage());
            self::assertStringNotContainsString($secretNew, $e->getMessage());
        }

        $this->seed('dup.txt', "MARKER_CONTENT\nMARKER_CONTENT\n");
        try {
            $tool->execute(['file' => 'dup.txt', 'old_str' => 'MARKER_CONTENT', 'new_str' => 'REPLACEMENT_TEXT_HERE']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringNotContainsString('MARKER_CONTENT', $e->getMessage());
            self::assertStringNotContainsString('REPLACEMENT_TEXT_HERE', $e->getMessage());
        }
    }

    public function test_empty_new_str_deletes_matched_text(): void
    {
        $this->seed('a.txt', "keep this\nDELETE_ME\nkeep that\n");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $tool->execute(['file' => 'a.txt', 'old_str' => "DELETE_ME\n", 'new_str' => '']);

        self::assertSame("keep this\nkeep that\n", file_get_contents($this->workspace.'/a.txt'));
    }

    public function test_old_str_with_dollar_backslash_and_newlines_replaces_literally(): void
    {
        $tricky = "price: \$100\\total\nline two";
        $this->seed('a.txt', "before\n{$tricky}\nafter\n");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $tool->execute(['file' => 'a.txt', 'old_str' => $tricky, 'new_str' => 'REPLACED']);

        self::assertSame("before\nREPLACED\nafter\n", file_get_contents($this->workspace.'/a.txt'));
    }

    public function test_match_at_start_of_file(): void
    {
        $this->seed('a.txt', "START\nmiddle\nend\n");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'a.txt', 'old_str' => 'START', 'new_str' => 'BEGIN']);

        self::assertSame(1, self::payload($out)['line_changed']);
        self::assertSame("BEGIN\nmiddle\nend\n", file_get_contents($this->workspace.'/a.txt'));
    }

    public function test_match_at_end_of_file(): void
    {
        $this->seed('a.txt', "start\nmiddle\nEND");
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'a.txt', 'old_str' => 'END', 'new_str' => 'FINISH']);

        self::assertSame(3, self::payload($out)['line_changed']);
        self::assertSame("start\nmiddle\nFINISH", file_get_contents($this->workspace.'/a.txt'));
    }

    public function test_check_blocked_dir_segments_absolute_throws_when_prefix_does_not_match(): void
    {
        $tool = new FileEditTool($this->workspace);

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

        $tool = new FileEditTool($this->workspace);
        $rootRef = new \ReflectionMethod($tool, 'resolveWorkspaceRoot');
        $rootRef->setAccessible(true);
        $root = $rootRef->invoke($tool);

        $method = new \ReflectionMethod($tool, 'checkBlockedDirSegmentsAbsolute');
        $method->setAccessible(true);

        $mixedCasePath = strtoupper($root).'/.git/config';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/directory is blocked/');

        $method->invoke($tool, $mixedCasePath);
    }

    public function test_dotdot_before_symlink_is_collapsed_before_the_walk(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $this->seed('.env', 'SECRET=1');
        symlink($this->workspace.'/.env', $this->workspace.'/link');

        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/symlink/');

        $tool->execute(['file' => 'a/../link', 'old_str' => 'SECRET', 'new_str' => 'X']);
    }

    public function test_multiple_dotdot_before_symlink_is_collapsed_before_the_walk(): void
    {
        mkdir($this->workspace.'/a/b', 0755, true);
        $this->seed('.env', 'SECRET=1');
        symlink($this->workspace.'/.env', $this->workspace.'/link');

        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/symlink/');

        $tool->execute(['file' => 'a/b/../../link', 'old_str' => 'SECRET', 'new_str' => 'X']);
    }

    public function test_dot_and_dotdot_before_symlink_is_collapsed_before_the_walk(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $this->seed('.env', 'SECRET=1');
        symlink($this->workspace.'/.env', $this->workspace.'/link');

        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/symlink/');

        $tool->execute(['file' => './a/../link', 'old_str' => 'SECRET', 'new_str' => 'X']);
    }

    public function test_dotdot_escaping_above_root_reports_traversal_not_symlink(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $tool = new FileEditTool($this->workspace);

        try {
            $tool->execute(['file' => 'a/../../outside.txt', 'old_str' => 'a', 'new_str' => 'b']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('traversal', $e->getMessage());
            self::assertStringNotContainsString('symlink', $e->getMessage());
        }
    }

    public function test_dotdot_to_legitimate_sibling_file_succeeds(): void
    {
        mkdir($this->workspace.'/a', 0755, true);
        $this->seed('b.php', 'ORIGINAL content here');
        $this->markRead('b.php');

        $tool = new FileEditTool($this->workspace);

        $out = $tool->execute(['file' => 'a/../b.php', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
        self::assertSame('CHANGED content here', file_get_contents($this->workspace.'/b.php'));
    }

    public function test_nonexistent_parent_directory_reports_file_not_found_not_traversal(): void
    {
        $tool = new FileEditTool($this->workspace);

        try {
            $tool->execute(['file' => 'nonexistent-dir/file.txt', 'old_str' => 'a', 'new_str' => 'b']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('file not found', $e->getMessage());
            self::assertStringNotContainsString('traversal', $e->getMessage());
        }
    }

    public function test_throws_when_file_unreadable_due_to_permissions(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root; permission checks are bypassed.');
        }

        $this->seed('locked.txt', 'secret');
        chmod($this->workspace.'/locked.txt', 0000);

        try {
            $tool = new FileEditTool($this->workspace);
            $threw = false;
            try {
                @$tool->execute(['file' => 'locked.txt', 'old_str' => 'secret', 'new_str' => 'x']);
            } catch (ToolException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Expected a ToolException for an unreadable file.');
        } finally {
            @chmod($this->workspace.'/locked.txt', 0644);
        }
    }

    public function test_composer_json_is_blocked(): void
    {
        $this->seed('composer.json', '{"scripts": {"post-install-cmd": "malicious"}}');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'composer.json', 'old_str' => 'malicious', 'new_str' => 'x']);
    }

    public function test_composer_lock_is_blocked(): void
    {
        $this->seed('composer.lock', '{}');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'composer.lock', 'old_str' => '{}', 'new_str' => 'x']);
    }

    public function test_package_json_is_blocked(): void
    {
        $this->seed('package.json', '{"scripts": {}}');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'package.json', 'old_str' => 'scripts', 'new_str' => 'x']);
    }

    public function test_gitignore_is_blocked(): void
    {
        $this->seed('.gitignore', 'node_modules/');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '.gitignore', 'old_str' => 'node_modules', 'new_str' => 'x']);
    }

    public function test_phpunit_xml_is_blocked(): void
    {
        $this->seed('phpunit.xml', '<phpunit></phpunit>');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'phpunit.xml', 'old_str' => 'phpunit', 'new_str' => 'x']);
    }

    public function test_artisan_is_blocked(): void
    {
        $this->seed('artisan', '#!/usr/bin/env php');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'artisan', 'old_str' => 'php', 'new_str' => 'x']);
    }

    public function test_wp_config_php_is_blocked(): void
    {
        $this->seed('wp-config.php', "<?php define('DB_PASSWORD', 'secret');");
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'wp-config.php', 'old_str' => 'secret', 'new_str' => 'x']);
    }

    public function test_dotenv_is_blocked(): void
    {
        $this->seed('.env', 'API_KEY=secret');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '.env', 'old_str' => 'secret', 'new_str' => 'x']);
    }

    public function test_github_directory_is_blocked(): void
    {
        $this->seed('.github/workflows/ci.yml', 'name: CI');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '.github/workflows/ci.yml', 'old_str' => 'CI', 'new_str' => 'x']);
    }

    public function test_circleci_directory_is_blocked(): void
    {
        $this->seed('.circleci/config.yml', 'version: 2');
        $tool = new FileEditTool($this->workspace);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '.circleci/config.yml', 'old_str' => 'version', 'new_str' => 'x']);
    }

    public function test_vendor_directory_allowed_when_noise_blocked_dirs_overridden(): void
    {
        $this->seed('vendor/autoload.php', '<?php return ORIGINAL;');
        $this->markRead('vendor/autoload.php');
        $tool = new FileEditTool($this->workspace, noiseBlockedDirs: []);

        $out = $tool->execute(['file' => 'vendor/autoload.php', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
    }

    public function test_git_directory_still_blocked_when_noise_blocked_dirs_overridden_to_empty(): void
    {
        $this->seed('.git/config', '[core]');
        $tool = new FileEditTool($this->workspace, noiseBlockedDirs: []);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => '.git/config', 'old_str' => 'core', 'new_str' => 'x']);
    }

    public function test_symlink_laundering_composer_json_is_caught_by_post_resolution_recheck(): void
    {
        $this->seed('composer.json', '{"scripts": {"post-install-cmd": "malicious"}}');
        symlink($this->workspace.'/composer.json', $this->workspace.'/notes.txt');

        $tool = new FileEditTool($this->workspace, followSymlinks: true);

        $this->expectException(ToolException::class);

        $tool->execute(['file' => 'notes.txt', 'old_str' => 'malicious', 'new_str' => 'x']);
    }

    public function test_successful_edit_leaves_no_stray_temp_files(): void
    {
        $this->seed('a.txt', 'ORIGINAL');
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        $tool->execute(['file' => 'a.txt', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        $strays = glob($this->workspace.'/.phpclaw-edit-*');
        self::assertSame([], $strays, 'No .phpclaw-edit-* temp files should survive a successful write.');
    }

    public function test_failed_edit_leaves_no_stray_temp_files(): void
    {
        $this->seed('a.txt', 'ORIGINAL');
        $tool = new FileEditTool($this->workspace);

        try {
            $tool->execute(['file' => 'a.txt', 'old_str' => 'MISSING', 'new_str' => 'CHANGED']);
            self::fail('expected ToolException');
        } catch (ToolException) {
        }

        $strays = glob($this->workspace.'/.phpclaw-edit-*');
        self::assertSame([], $strays, 'No .phpclaw-edit-* temp files should exist after a failed edit.');
    }

    public function test_edit_preserves_original_file_permissions(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root; permission bits are not meaningfully testable.');
        }

        $this->seed('a.txt', 'ORIGINAL');
        chmod($this->workspace.'/a.txt', 0640);
        $this->markRead('a.txt');

        $tool = new FileEditTool($this->workspace);
        $tool->execute(['file' => 'a.txt', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);

        $perms = fileperms($this->workspace.'/a.txt') & 0777;
        self::assertSame(0640, $perms, 'File permissions must be preserved across an atomic-rename edit.');
    }

    public function test_write_to_readonly_directory_fails_cleanly_and_leaves_original_untouched(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root; directory permission checks are bypassed.');
        }

        mkdir($this->workspace.'/locked-dir', 0755, true);
        $this->seed('locked-dir/a.txt', 'ORIGINAL');
        chmod($this->workspace.'/locked-dir', 0555);

        try {
            $tool = new FileEditTool($this->workspace);
            $threw = false;
            try {
                @$tool->execute(['file' => 'locked-dir/a.txt', 'old_str' => 'ORIGINAL', 'new_str' => 'CHANGED']);
            } catch (ToolException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Expected a ToolException when the target directory is not writable.');
            self::assertSame('ORIGINAL', file_get_contents($this->workspace.'/locked-dir/a.txt'));
        } finally {
            @chmod($this->workspace.'/locked-dir', 0755);
        }
    }

    public function test_short_write_throws_and_leaves_original_byte_identical(): void
    {
        $this->seed('a.txt', 'ORIGINAL CONTENT UNCHANGED');
        $this->markRead('a.txt');
        $tool = new FileEditTool($this->workspace);

        ShortWriteStreamWrapper::install();
        try {
            $threw = false;
            try {
                @$tool->execute([
                    'file' => 'a.txt',
                    'old_str' => 'ORIGINAL',
                    'new_str' => 'REPLACEMENT-TEXT-LONGER-THAN-FIVE-BYTES',
                ]);
            } catch (ToolException $e) {
                $threw = true;
                self::assertStringContainsString('failed to write', $e->getMessage());
            }
            self::assertTrue($threw, 'Expected a ToolException when the write is short.');
        } finally {
            ShortWriteStreamWrapper::uninstall();
        }

        self::assertSame(
            'ORIGINAL CONTENT UNCHANGED',
            file_get_contents($this->workspace.'/a.txt'),
            'A short/failed write must never promote a truncated temp file over the original.'
        );

        $strays = glob($this->workspace.'/.phpclaw-edit-*');
        self::assertSame([], $strays, 'The temp file must be cleaned up even when the write fails.');
    }

    public function test_file_larger_than_max_bytes_throws_and_leaves_original_untouched(): void
    {
        $content = str_repeat('A', 200);
        $this->seed('big.txt', $content);
        $this->markRead('big.txt');

        $tool = new FileEditTool($this->workspace, maxBytes: 100);

        try {
            $tool->execute(['file' => 'big.txt', 'old_str' => 'A', 'new_str' => 'B']);
            self::fail('expected ToolException');
        } catch (ToolException $e) {
            self::assertStringContainsString('file too large to edit', $e->getMessage());
            self::assertStringContainsString('200 bytes', $e->getMessage());
            self::assertStringContainsString('limit 100', $e->getMessage());
        }

        self::assertSame($content, file_get_contents($this->workspace.'/big.txt'));
    }

    public function test_file_exactly_at_max_bytes_succeeds(): void
    {
        $content = str_repeat('A', 100).'MARKER';
        $this->seed('exact.txt', $content);
        $this->markRead('exact.txt');

        $tool = new FileEditTool($this->workspace, maxBytes: strlen($content));

        $out = $tool->execute(['file' => 'exact.txt', 'old_str' => 'MARKER', 'new_str' => 'CHANGED']);

        self::assertSame('edited', self::payload($out)['status']);
        self::assertSame(str_repeat('A', 100).'CHANGED', file_get_contents($this->workspace.'/exact.txt'));
    }

    public function test_max_bytes_zero_throws_invalid_argument_exception_from_constructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBytes must be greater than 0.');

        new FileEditTool($this->workspace, maxBytes: 0);
    }

    public function test_max_bytes_negative_throws_invalid_argument_exception_from_constructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileEditTool($this->workspace, maxBytes: -1);
    }

    public function test_max_bytes_accessor_returns_configured_value(): void
    {
        $tool = new FileEditTool($this->workspace, maxBytes: 12345);

        self::assertSame(12345, $tool->maxBytes());
    }

    public function test_max_bytes_accessor_returns_default_when_not_configured(): void
    {
        $tool = new FileEditTool($this->workspace);

        self::assertSame(FileEditTool::DEFAULT_MAX_BYTES, $tool->maxBytes());
    }
}
