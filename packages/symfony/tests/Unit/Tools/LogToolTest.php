<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PhpClaw\Symfony\Tools\LogTool;
use PHPUnit\Framework\TestCase;

final class LogToolTest extends TestCase
{
    use BuildsIdentity;

    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir().'/phpclaw_log_test_'.uniqid();
        @mkdir($this->logDir, 0777, true);

        file_put_contents(
            $this->logDir.'/dev.log',
            implode("\n", [
                '[2026-04-15] DEBUG: test line 1',
                '[2026-04-15] INFO: test line 2',
                '[2026-04-15] ERROR: test line 3',
                '[2026-04-15] WARNING: test line 4',
                '[2026-04-15] ERROR: test line 5',
            ])."\n",
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir.'/*.log') ?: []);
        @rmdir($this->logDir);
    }

    public function test_name_and_description(): void
    {
        $tool = new LogTool($this->logDir);

        self::assertSame('read_log', $tool->name());
        self::assertStringContainsString('READ', $tool->description());
    }

    public function test_reads_last_n_lines(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 50]), true);

        self::assertTrue($result['success']);
        $joined = implode("\n", $result['data']['entries']);
        self::assertStringContainsString('test line 1', $joined);
        self::assertStringContainsString('test line 5', $joined);
    }

    public function test_reads_specific_file(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['filename' => 'dev.log', 'lines' => 50]), true);

        self::assertTrue($result['success']);
        $joined = implode("\n", $result['data']['entries']);
        self::assertStringContainsString('test line 1', $joined);
        self::assertStringContainsString('test line 5', $joined);
    }

    public function test_filters_by_level(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 50, 'level' => 'ERROR']), true);

        self::assertTrue($result['success']);
        $joined = implode("\n", $result['data']['entries']);
        self::assertStringContainsString('ERROR', $joined);
        self::assertStringNotContainsString('DEBUG', $joined);
        self::assertStringNotContainsString('INFO', $joined);
    }

    public function test_level_finds_an_error_older_than_the_maximum_tail(): void
    {
        file_put_contents(
            $this->logDir.'/dev.log',
            "[2026-04-15] app.ERROR: early failure\n".str_repeat("[2026-04-15] app.INFO: routine\n", 300),
        );

        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 200, 'level' => 'ERROR']), true);

        self::assertTrue($result['success']);
        self::assertSame(['[2026-04-15] app.ERROR: early failure'], $result['data']['entries']);
    }

    public function test_level_keeps_only_the_last_requested_matches_newest_last(): void
    {
        $log = '';
        for ($i = 1; $i <= 5; $i++) {
            $log .= "[2026-04-15] app.ERROR: hit {$i}\n".str_repeat("[2026-04-15] app.DEBUG: filler\n", 100);
        }
        file_put_contents($this->logDir.'/dev.log', $log);

        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 2, 'level' => 'ERROR']), true);

        self::assertSame(['[2026-04-15] app.ERROR: hit 4', '[2026-04-15] app.ERROR: hit 5'], $result['data']['entries']);
    }

    public function test_throws_for_missing_log_dir(): void
    {
        $this->expectException(ToolException::class);

        $tool = new LogTool('/nonexistent/path', identity: $this->buildIdentity('user1'));
        $tool->execute(['lines' => 5]);
    }

    public function test_throws_for_missing_file(): void
    {
        $this->expectException(ToolException::class);

        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $tool->execute(['filename' => 'nope.log']);
    }

    public function test_input_schema_shape(): void
    {
        $tool = new LogTool($this->logDir);
        $schema = $tool->inputSchema();

        self::assertArrayHasKey('lines', $schema['properties']);
        self::assertArrayHasKey('filename', $schema['properties']);
        self::assertArrayHasKey('level', $schema['properties']);
    }

    public function test_throws_for_invalid_filename_pattern(): void
    {
        $this->expectException(ToolException::class);

        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $tool->execute(['filename' => '../etc/passwd']);
    }

    public function test_level_filter_with_no_matching_lines(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 50, 'level' => 'EMERGENCY']), true);

        self::assertTrue($result['success']);
        self::assertSame([], $result['data']['entries']);
        $codes = array_column($result['warnings'], 'code');
        self::assertContains('NOT_FOUND', $codes);
    }

    public function test_resolves_log_dir_from_server_var(): void
    {
        $projectDir = sys_get_temp_dir().'/phpclaw_server_var_project_'.uniqid();
        $logDir = $projectDir.'/var/log';
        mkdir($logDir, 0777, true);
        file_put_contents($logDir.'/dev.log', "[2026-09-13] INFO: from server var\n");

        $_SERVER['KERNEL_PROJECT_DIR'] = $projectDir;

        try {
            $tool = new LogTool('', identity: $this->buildIdentity('user1'));
            $result = json_decode($tool->execute([]), true);

            self::assertTrue($result['success']);
            self::assertSame(['[2026-09-13] INFO: from server var'], $result['data']['entries']);
        } finally {
            unset($_SERVER['KERNEL_PROJECT_DIR']);
            unlink($logDir.'/dev.log');
            rmdir($logDir);
            rmdir($projectDir.'/var');
            rmdir($projectDir);
        }
    }

    public function test_resolves_log_dir_from_cwd_fallback(): void
    {
        unset($_SERVER['KERNEL_PROJECT_DIR']);

        $cwdProjectDir = sys_get_temp_dir().'/phpclaw_cwd_project_'.uniqid();
        $logDir = $cwdProjectDir.'/var/log';
        mkdir($logDir, 0777, true);
        file_put_contents($logDir.'/dev.log', "[2026-09-13] INFO: from cwd fallback\n");

        $originalCwd = (string) getcwd();
        chdir($cwdProjectDir);

        try {
            $tool = new LogTool('', identity: $this->buildIdentity('user1'));
            $result = json_decode($tool->execute([]), true);

            self::assertTrue($result['success']);
            self::assertSame(['[2026-09-13] INFO: from cwd fallback'], $result['data']['entries']);
        } finally {
            chdir($originalCwd);
            unlink($logDir.'/dev.log');
            rmdir($logDir);
            rmdir($cwdProjectDir.'/var');
            rmdir($cwdProjectDir);
        }
    }

    public function test_throws_when_no_log_files_in_dir(): void
    {
        $emptyDir = sys_get_temp_dir().'/phpclaw_log_empty_'.uniqid();
        mkdir($emptyDir, 0777, true);

        $this->expectException(ToolException::class);

        try {
            $tool = new LogTool($emptyDir, identity: $this->buildIdentity('user1'));
            $tool->execute([]);
        } finally {
            @rmdir($emptyDir);
        }
    }

    public function test_returns_empty_message_when_log_is_blank(): void
    {
        file_put_contents($this->logDir.'/blank.log', '');
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['filename' => 'blank.log', 'lines' => 5]), true);

        self::assertTrue($result['success']);
        self::assertSame([], $result['data']['entries']);
        $codes = array_column($result['warnings'], 'code');
        self::assertContains('NOT_FOUND', $codes);
    }

    public function test_lines_clamped_to_max(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 9999]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
    }

    public function test_default_lines_used_when_not_specified(): void
    {
        $lines = [];
        for ($i = 1; $i <= 120; $i++) {
            $lines[] = "[2026-04-15] ERROR: line {$i}";
        }
        file_put_contents($this->logDir.'/dev.log', implode("\n", $lines)."\n");

        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute([]), true);

        self::assertTrue($result['success']);
        self::assertCount(50, $result['data']['entries'], 'omitting lines must fall back to DEFAULT_LINES');
    }

    public function test_success_envelope_shape(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 5]), true);

        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('meta', $result);
        self::assertArrayHasKey('warnings', $result);
        self::assertTrue($result['success']);
    }

    public function test_no_identity_resolver_returns_forbidden(): void
    {
        $tool = new LogTool($this->logDir);
        $result = json_decode($tool->execute(['lines' => 5]), true);

        self::assertFalse($result['success']);
        self::assertSame('FORBIDDEN', $result['error']['code']);
    }

    public function test_unauthenticated_identity_returns_forbidden(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity(''));
        $result = json_decode($tool->execute(['lines' => 5]), true);

        self::assertFalse($result['success']);
        self::assertSame('FORBIDDEN', $result['error']['code']);
    }

    public function test_authenticated_user_succeeds(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 5]), true);

        self::assertTrue($result['success']);
        self::assertArrayHasKey('entries', $result['data']);
    }

    public function test_empty_log_yields_not_found_warning(): void
    {
        file_put_contents($this->logDir.'/empty.log', '');
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['filename' => 'empty.log', 'lines' => 10]), true);

        self::assertTrue($result['success']);
        self::assertSame(0, $result['meta']['count']);
        $codes = array_column($result['warnings'], 'code');
        self::assertContains('NOT_FOUND', $codes);
    }

    public function test_out_of_range_lines_returns_invalid_argument(): void
    {
        $tool = new LogTool($this->logDir, identity: $this->buildIdentity('user1'));
        $result = json_decode($tool->execute(['lines' => 500]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
    }
}
