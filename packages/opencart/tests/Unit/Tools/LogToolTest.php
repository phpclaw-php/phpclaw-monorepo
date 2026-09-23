<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tools\LogTool;
use PHPUnit\Framework\TestCase;

final class LogToolTest extends TestCase
{
    private function payload(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['success'], 'Envelope reported failure: '.$json);

        return $decoded['data'];
    }

    public function test_name(): void
    {
        $tool = new LogTool('', true);
        self::assertSame('read_log', $tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'analyse the OpenCart error log',
            (new LogTool('', true))->description(),
        );
    }

    public function test_input_schema_has_no_required(): void
    {
        $tool = new LogTool('', true);
        $schema = $tool->inputSchema();
        self::assertSame([], $schema['required']);
    }

    public function test_schema_mode_returns_metadata(): void
    {
        $tool = new LogTool('/tmp', true);
        $data = $this->payload($tool->execute(['schema' => true]));
        self::assertArrayHasKey('valid_levels', $data);
        self::assertArrayHasKey('path', $data);
    }

    public function test_throws_when_log_not_found(): void
    {
        $tool = new LogTool('/nonexistent/path/to/nowhere', true);
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $tool->execute([]);
    }

    public function test_reads_real_log_file(): void
    {
        $dir = sys_get_temp_dir().'/oc_log_test_'.getmypid();
        mkdir($dir.'/system/storage/logs', 0777, true);
        $logPath = $dir.'/system/storage/logs/error.log';
        file_put_contents($logPath, "[2024-01-01 00:00:00] PHP Fatal error: test error\n");

        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute(['lines' => 5]);
            self::assertStringContainsString('Fatal error', $result);
        } finally {
            unlink($logPath);
            rmdir($dir.'/system/storage/logs');
            rmdir($dir.'/system/storage');
            rmdir($dir.'/system');
            rmdir($dir);
        }
    }

    public function test_aggregate_on_real_log(): void
    {
        $dir = sys_get_temp_dir().'/oc_log_agg_'.getmypid();
        mkdir($dir.'/system/storage/logs', 0777, true);
        $logPath = $dir.'/system/storage/logs/error.log';
        file_put_contents($logPath, "[2024-01-01] PHP Fatal error: crash\n[2024-01-01] PHP Warning: slow\n");

        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['aggregate' => true]));
            self::assertSame(1, $data['fatal_count']);
            self::assertSame(1, $data['warning_count']);
        } finally {
            unlink($logPath);
            rmdir($dir.'/system/storage/logs');
            rmdir($dir.'/system/storage');
            rmdir($dir.'/system');
            rmdir($dir);
        }
    }

    private function makeLogDir(string $suffix, string $content = ''): string
    {
        $dir = sys_get_temp_dir().'/oc_lt_'.$suffix.'_'.getmypid();
        mkdir($dir.'/system/storage/logs', 0777, true);
        $logPath = $dir.'/system/storage/logs/error.log';
        file_put_contents($logPath, $content);

        return $dir;
    }

    private function removeLogDir(string $dir): void
    {
        $logPath = $dir.'/system/storage/logs/error.log';
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @rmdir($dir.'/system/storage/logs');
        @rmdir($dir.'/system/storage');
        @rmdir($dir.'/system');
        @rmdir($dir);
    }

    public function test_level_filter_returns_only_matching_lines(): void
    {
        $dir = $this->makeLogDir('lvl', "[2024-01-01] PHP Error: oops\n[2024-01-01] PHP Warning: slow\n");
        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute(['level' => 'error']);
            self::assertStringContainsString('Error', $result);
            self::assertStringNotContainsString('Warning', $result);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_search_finds_a_match_older_than_the_maximum_tail(): void
    {
        $dir = $this->makeLogDir('oldsrch', "[2024-01-01] OLD_MARKER first line\n".str_repeat("[2024-01-02] routine entry\n", 300));
        try {
            $data = $this->payload((new LogTool($dir, true))->execute(['search' => 'old_marker', 'lines' => 200]));
            self::assertSame(['[2024-01-01] OLD_MARKER first line'], $data['lines']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_level_keeps_only_the_last_requested_matches_newest_last(): void
    {
        $log = '';
        for ($i = 1; $i <= 5; $i++) {
            $log .= "PHP Error: hit {$i}\n".str_repeat("PHP Notice: filler\n", 100);
        }
        $dir = $this->makeLogDir('lastn', $log);
        try {
            $data = $this->payload((new LogTool($dir, true))->execute(['level' => 'error', 'lines' => 2]));
            self::assertSame(['PHP Error: hit 4', 'PHP Error: hit 5'], $data['lines']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_level_and_search_together_require_both_terms(): void
    {
        $dir = $this->makeLogDir('both', "PHP Error: database down\nPHP Warning: database slow\nPHP Error: cache miss\n".str_repeat("PHP Notice: filler\n", 250));
        try {
            $data = $this->payload((new LogTool($dir, true))->execute(['level' => 'error', 'search' => 'database']));
            self::assertSame(['PHP Error: database down'], $data['lines']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_search_filter_returns_matching_lines(): void
    {
        $dir = $this->makeLogDir('srch', "[2024-01-01] DB connection failed\n[2024-01-01] Cache miss\n");
        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute(['search' => 'connection']);
            self::assertStringContainsString('connection', $result);
            self::assertStringNotContainsString('Cache', $result);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_no_match_returns_no_entries_found(): void
    {
        $dir = $this->makeLogDir('nomatch', "[2024-01-01] PHP Error: oops\n");
        try {
            $tool = new LogTool($dir, true);
            $decoded = json_decode($tool->execute(['level' => 'fatal']), true);
            self::assertSame([], $decoded['data']['lines']);
            self::assertSame('fatal', $decoded['meta']['level']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_empty_log_returns_empty_message(): void
    {
        $dir = $this->makeLogDir('empty', '');
        try {
            $tool = new LogTool($dir, true);
            $decoded = json_decode($tool->execute([]), true);
            self::assertSame([], $decoded['data']['lines']);
            self::assertSame(0, $decoded['meta']['shown']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_aggregate_counts_errors_and_notices(): void
    {
        $content = "[2024-01-01] PHP Error: one\n[2024-01-01] PHP Notice: two\n[2024-01-01] PHP Deprecated: old\n";
        $dir = $this->makeLogDir('cnt', $content);
        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['aggregate' => true]));
            self::assertSame(1, $data['error_count']);
            self::assertSame(1, $data['notice_count']);
            self::assertSame(1, $data['deprecated_count']);
            self::assertSame(0, $data['parse_count']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_aggregate_counts_parse_only_line(): void
    {
        $content = "[2024-01-01] PHP Parse: syntax issue\n";
        $dir = $this->makeLogDir('parsecnt', $content);
        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['aggregate' => true]));
            self::assertSame(1, $data['parse_count']);
            self::assertSame(0, $data['error_count']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_schema_mode_returns_exists_and_readable(): void
    {
        $dir = $this->makeLogDir('scm', "log line\n");
        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['schema' => true]));
            self::assertTrue($data['exists']);
            self::assertTrue($data['readable']);
            self::assertGreaterThan(0, $data['size_bytes']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_schema_mode_on_missing_file_shows_not_exists(): void
    {
        $tool = new LogTool('/tmp/nonexistent_phpclaw_test_xyz', true);
        $data = $this->payload($tool->execute(['schema' => true]));
        self::assertFalse($data['exists']);
        self::assertSame(0, $data['size_bytes']);
    }

    public function test_lines_parameter_limits_output(): void
    {
        $content = '';
        for ($i = 1; $i <= 20; $i++) {
            $content .= "[2024-01-01] line {$i}\n";
        }
        $dir = $this->makeLogDir('lines', $content);
        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute(['lines' => 3]);
            $lines = array_filter(explode("\n", trim($result)));
            self::assertLessThanOrEqual(3, count($lines));
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_storage_path_fallback_resolves(): void
    {
        $dir = sys_get_temp_dir().'/oc_lt_oc4_'.getmypid();
        mkdir($dir.'/storage/logs', 0777, true);
        $logPath = $dir.'/storage/logs/error.log';
        file_put_contents($logPath, "[2024-01-01] OC4 error\n");

        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute([]);
            self::assertStringContainsString('OC4 error', $result);
        } finally {
            @unlink($logPath);
            @rmdir($dir.'/storage/logs');
            @rmdir($dir.'/storage');
            @rmdir($dir);
        }
    }

    public function test_aggregate_has_size_human(): void
    {
        $dir = $this->makeLogDir('szh', "[2024-01-01] PHP Error: x\n");
        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['aggregate' => true]));
            self::assertArrayHasKey('size_human', $data);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_throws_when_log_is_not_readable(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Running as root, chmod 000 has no effect.');
        }

        $dir = $this->makeLogDir('unreadable', "[2024-01-01] PHP Error: x\n");
        $logPath = $dir.'/system/storage/logs/error.log';
        chmod($logPath, 0000);

        try {
            $tool = new LogTool($dir, true);
            $this->expectException(ToolException::class);
            $this->expectExceptionMessageMatches('/not readable/');
            $tool->execute([]);
        } finally {
            chmod($logPath, 0644);
            $this->removeLogDir($dir);
        }
    }

    public function test_output_is_truncated_at_8kb(): void
    {
        $line = str_repeat('[2024-01-01] PHP Error: '.str_repeat('x', 200)."\n", 1);
        $content = str_repeat($line, 45);
        $dir = $this->makeLogDir('trunc', $content);

        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute(['lines' => 200]);
            $decoded = json_decode($result, true);
            self::assertTrue($decoded['meta']['truncated']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_no_match_with_search_only_shows_search_in_message(): void
    {
        $dir = $this->makeLogDir('srchonly', "[2024-01-01] PHP Error: oops\n");
        try {
            $tool = new LogTool($dir, true);
            $result = $tool->execute(['search' => 'xyzzy_never_matches']);
            $decoded = json_decode($result, true);
            self::assertSame('xyzzy_never_matches', $decoded['meta']['search']);
            self::assertSame([], $decoded['data']['lines']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_resolve_log_path_uses_fallback_when_no_dir_constants(): void
    {
        $tool = new LogTool('', true);
        $data = $this->payload($tool->execute(['schema' => true]));
        self::assertStringContainsString('error.log', $data['path']);
    }

    public function test_extract_timestamp_returns_match_for_bracket_format(): void
    {
        $dir = $this->makeLogDir('ts', "[27-Apr-2026 14:30:00 UTC] PHP Fatal error: boom\n");
        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['aggregate' => true]));
            self::assertNotNull($data['last_error_timestamp']);
            self::assertStringContainsString('27-Apr-2026', $data['last_error_timestamp']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_human_file_size_returns_kb_for_large_file(): void
    {
        $content = str_repeat("[2024-01-01] PHP Error: pad\n", 50);
        $dir = $this->makeLogDir('szloop', $content);
        try {
            $tool = new LogTool($dir, true);
            $data = $this->payload($tool->execute(['schema' => true]));
            self::assertStringContainsString('KB', $data['size_human']);
        } finally {
            $this->removeLogDir($dir);
        }
    }

    public function test_aggregate_throws_when_file_unreadable_after_exists_check(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Running as root, chmod 000 has no effect.');
        }

        $dir = $this->makeLogDir('aggnoread', "[2024-01-01] PHP Error: x\n");
        $logPath = $dir.'/system/storage/logs/error.log';
        chmod($logPath, 0000);

        try {
            $tool = new LogTool($dir, true);
            $this->expectException(ToolException::class);
            $this->expectExceptionMessageMatches('/not readable/');
            $tool->execute(['aggregate' => true]);
        } finally {
            chmod($logPath, 0644);
            $this->removeLogDir($dir);
        }
    }
}
