<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Tools;

use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Service\OutputByteCap;
use PhpClaw\Magento\Tools\LogTool;
use PHPUnit\Framework\TestCase;

final class LogToolTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'phpclaw_log_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function tool(string $logPath = '', bool $allowed = true, bool $console = false): LogTool
    {
        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn($console);

        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturn($allowed);

        return new LogTool($identity, $acl, $logPath);
    }

    private function writeLinesTo(string $path, array $lines): void
    {
        file_put_contents($path, implode("\n", $lines));
    }

    private function envelope(string $result): array
    {
        $decoded = json_decode($result, true);

        self::assertIsArray($decoded, 'the tool must return a JSON envelope');

        return $decoded;
    }

    private function textOf(string $result): string
    {
        $envelope = $this->envelope($result);

        self::assertTrue($envelope['success'], 'expected a success envelope');

        return $envelope['data']['text'];
    }

    private function linesOf(string $result): array
    {
        $envelope = $this->envelope($result);

        self::assertTrue($envelope['success'], 'expected a success envelope');

        return $envelope['data']['lines'];
    }

    public function test_name_is_read_log(): void
    {
        self::assertSame('read_log', $this->tool($this->tmpFile)->name());
    }

    public function test_required_capability_is_the_chat_resource(): void
    {
        self::assertSame('PhpClaw_Magento::phpclaw_chat', $this->tool($this->tmpFile)->requiredCapability());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool($this->tmpFile)->description();

        self::assertStringContainsString('READ last N lines of the Magento system log', $description);
        self::assertStringContainsString('var/log/system.log', $description);
    }

    public function test_input_schema_describes_lines_and_level_fields(): void
    {
        $schema = $this->tool($this->tmpFile)->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('lines', $schema['properties']);
        self::assertArrayHasKey('level', $schema['properties']);
        self::assertSame('integer', $schema['properties']['lines']['type']);
        self::assertSame(50, $schema['properties']['lines']['default']);
        self::assertSame(1, $schema['properties']['lines']['minimum']);
        self::assertSame(200, $schema['properties']['lines']['maximum']);
        self::assertContains('warning', $schema['properties']['level']['enum']);
        self::assertContains('error', $schema['properties']['level']['enum']);
    }

    public function test_a_caller_without_the_chat_resource_is_forbidden(): void
    {
        $this->writeLinesTo($this->tmpFile, ['[2026-01-01] info one']);

        $envelope = $this->envelope($this->tool($this->tmpFile, allowed: false)->execute([]));

        self::assertFalse($envelope['success']);
        self::assertSame('FORBIDDEN', $envelope['error']['code']);
        self::assertStringContainsString('PhpClaw_Magento::phpclaw_chat', $envelope['error']['message']);
    }

    public function test_the_console_reads_the_log_without_the_chat_resource(): void
    {
        $this->writeLinesTo($this->tmpFile, ['[2026-01-01] info one']);

        $result = $this->tool($this->tmpFile, allowed: false, console: true)->execute([]);

        self::assertStringContainsString('info one', $this->textOf($result));
    }

    public function test_an_unknown_argument_is_rejected(): void
    {
        $this->writeLinesTo($this->tmpFile, ['[2026-01-01] info one']);

        $envelope = $this->envelope($this->tool($this->tmpFile)->execute(['nope' => 1]));

        self::assertFalse($envelope['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $envelope['error']['code']);
    }

    public function test_an_invalid_level_is_rejected(): void
    {
        $this->writeLinesTo($this->tmpFile, ['[2026-01-01] info one']);

        $envelope = $this->envelope($this->tool($this->tmpFile)->execute(['level' => 'loud']));

        self::assertFalse($envelope['success']);
        self::assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_level_filter_with_no_matches_returns_an_empty_line_list(): void
    {
        $this->writeLinesTo($this->tmpFile, [
            '[2026-01-01] info one',
            '[2026-01-01] info two',
        ]);

        $result = $this->tool($this->tmpFile)->execute(['level' => 'critical']);

        self::assertSame([], $this->linesOf($result));
        self::assertSame('', $this->textOf($result));
        self::assertSame(0, $this->envelope($result)['meta']['returned_lines']);
    }

    public function test_reads_last_n_lines(): void
    {
        $this->writeLinesTo($this->tmpFile, [
            '[2026-01-01] line 1',
            '[2026-01-01] line 2',
            '[2026-01-01] line 3',
            '[2026-01-01] line 4',
            '[2026-01-01] line 5',
        ]);

        $result = $this->tool($this->tmpFile)->execute(['lines' => 3]);
        $lines = $this->linesOf($result);

        self::assertCount(3, $lines);
        self::assertStringContainsString('line 3', $lines[0]);
        self::assertStringContainsString('line 5', $lines[2]);
        self::assertStringNotContainsString('line 1', $this->textOf($result));
        self::assertStringNotContainsString('line 2', $this->textOf($result));
    }

    public function test_default_lines_is_50(): void
    {
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = "[2026-01-01] line {$i}";
        }
        $this->writeLinesTo($this->tmpFile, $lines);

        self::assertCount(50, $this->linesOf($this->tool($this->tmpFile)->execute([])));
    }

    public function test_level_filter_returns_matching_lines_only(): void
    {
        $this->writeLinesTo($this->tmpFile, [
            '[2026-01-01] INFO Application started',
            '[2026-01-01] ERROR Database connection failed',
            '[2026-01-01] INFO Cache cleared',
            '[2026-01-01] ERROR Timeout on request',
        ]);

        $text = $this->textOf($this->tool($this->tmpFile)->execute(['lines' => 50, 'level' => 'error']));

        self::assertStringContainsString('Database connection failed', $text);
        self::assertStringContainsString('Timeout on request', $text);
        self::assertStringNotContainsString('Application started', $text);
        self::assertStringNotContainsString('Cache cleared', $text);
    }

    public function test_output_is_truncated_at_8192_bytes(): void
    {
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = str_repeat("error log line {$i} ", 5);
        }
        $this->writeLinesTo($this->tmpFile, $lines);

        $result = $this->tool($this->tmpFile)->execute(['lines' => 200]);
        $text = $this->textOf($result);

        self::assertSame(
            OutputByteCap::MAX_OUTPUT_BYTES + strlen(OutputByteCap::truncationNotice()),
            strlen($text),
        );
        self::assertStringEndsWith(OutputByteCap::truncationNotice(), $text);
        self::assertTrue($this->envelope($result)['meta']['truncated']);
    }

    public function test_lines_capped_at_200(): void
    {
        $lines = [];
        for ($i = 1; $i <= 250; $i++) {
            $lines[] = "line {$i}";
        }
        $this->writeLinesTo($this->tmpFile, $lines);

        $result = $this->tool($this->tmpFile)->execute(['lines' => 9999]);
        $resultLines = $this->linesOf($result);

        self::assertCount(200, $resultLines, 'a request for 9999 lines must be capped at MAX_LINES');
        self::assertSame('line 51', $resultLines[0]);
        self::assertSame('line 250', $resultLines[199]);
        self::assertSame(200, $this->envelope($result)['meta']['requested_lines']);
    }

    public function test_missing_file_returns_a_log_unavailable_envelope(): void
    {
        $envelope = $this->envelope($this->tool('/nonexistent/path/to/system.log')->execute([]));

        self::assertFalse($envelope['success']);
        self::assertSame('LOG_UNAVAILABLE', $envelope['error']['code']);
        self::assertStringContainsString('/nonexistent/path/to/system.log', $envelope['error']['message']);
    }

    public function test_empty_file_returns_an_empty_text_payload(): void
    {
        file_put_contents($this->tmpFile, '');

        $result = $this->tool($this->tmpFile)->execute([]);

        self::assertSame('', $this->textOf($result));
        self::assertSame([], $this->linesOf($result));
    }

    public function test_resolve_log_path_returns_injected_path_unchanged(): void
    {
        file_put_contents($this->tmpFile, "only line\n");

        $result = $this->tool($this->tmpFile)->execute(['lines' => 1]);

        self::assertStringContainsString('only line', $this->textOf($result));
        self::assertSame($this->tmpFile, $this->envelope($result)['meta']['path']);
    }

    public function test_resolve_log_path_fails_when_the_magento_base_path_is_undefined(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('read_log: cannot resolve the Magento base path (BP undefined)');

        $this->tool()->execute(['lines' => 1]);
    }

    public function test_read_last_lines_returns_exactly_n_lines(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "line {$i}";
        }
        $this->writeLinesTo($this->tmpFile, $lines);

        $result = $this->tool($this->tmpFile)->execute(['lines' => 4]);
        $resultLines = $this->linesOf($result);

        self::assertCount(4, $resultLines);
        self::assertSame('line 7', $resultLines[0]);
        self::assertSame('line 10', $resultLines[3]);
    }

    public function test_read_last_lines_returns_all_lines_when_n_exceeds_file_length(): void
    {
        $this->writeLinesTo($this->tmpFile, ['line A', 'line B', 'line C']);

        self::assertCount(3, $this->linesOf($this->tool($this->tmpFile)->execute(['lines' => 200])));
    }

    public function test_read_last_lines_single_line_file(): void
    {
        file_put_contents($this->tmpFile, 'only one line');

        self::assertSame('only one line', $this->textOf($this->tool($this->tmpFile)->execute(['lines' => 50])));
    }

    public function test_minimum_lines_value_is_clamped_to_1(): void
    {
        $this->writeLinesTo($this->tmpFile, ['alpha', 'beta', 'gamma']);

        self::assertCount(1, $this->linesOf($this->tool($this->tmpFile)->execute(['lines' => -5])));
    }

    public function test_level_filter_is_case_insensitive(): void
    {
        $this->writeLinesTo($this->tmpFile, [
            '[2026-01-01] ERROR something failed',
            '[2026-01-01] info normal activity',
        ]);

        $text = $this->textOf($this->tool($this->tmpFile)->execute(['level' => 'ERROR']));

        self::assertStringContainsString('something failed', $text);
        self::assertStringNotContainsString('normal activity', $text);
    }

    public function test_execute_filters_by_level_across_large_read(): void
    {
        $lines = [];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = $i % 10 === 0
                ? "[2026-01-01] warning iteration {$i}"
                : "[2026-01-01] info iteration {$i}";
        }
        $this->writeLinesTo($this->tmpFile, $lines);

        $text = $this->textOf($this->tool($this->tmpFile)->execute(['lines' => 100, 'level' => 'warning']));

        self::assertStringNotContainsString('info', $text);
        self::assertStringContainsString('warning', $text);
    }

    public function test_unreadable_file_returns_a_log_unavailable_envelope(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission tests are meaningless when running as root.');
        }

        $unreadable = sys_get_temp_dir().'/phpclaw_log_unreadable_'.uniqid().'.log';
        file_put_contents($unreadable, "line one\nline two\n");
        chmod($unreadable, 0000);

        try {
            $envelope = $this->envelope($this->tool($unreadable)->execute(['lines' => 5]));

            self::assertFalse($envelope['success']);
            self::assertSame('LOG_UNAVAILABLE', $envelope['error']['code']);
        } finally {
            chmod($unreadable, 0644);
            @unlink($unreadable);
        }
    }

    public function test_zero_byte_log_file_returns_no_lines(): void
    {
        file_put_contents($this->tmpFile, '');

        self::assertSame([], $this->linesOf($this->tool($this->tmpFile)->execute(['lines' => 50])));
    }

    /**
     * Auto-discovery resolves var/log/system.log from the BP constant.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_resolve_log_path_auto_discovers_via_bp_constant(): void
    {
        $base = sys_get_temp_dir().'/phpclaw_bp_'.uniqid();
        $logDir = $base.'/var/log';
        mkdir($logDir, 0777, true);
        file_put_contents($logDir.'/system.log', "[2026-01-01] discovery line one\n[2026-01-01] discovery line two\n");

        try {
            define('BP', $base);

            self::assertStringContainsString(
                'discovery line two',
                $this->textOf($this->tool()->execute(['lines' => 5])),
            );
        } finally {
            @unlink($logDir.'/system.log');
            @rmdir($logDir);
            @rmdir(dirname($logDir));
            @rmdir($base);
        }
    }

    /**
     * Auto-discovery falls back to exception.log when system.log is absent.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_resolve_log_path_falls_back_to_exception_log(): void
    {
        $base = sys_get_temp_dir().'/phpclaw_bp_'.uniqid();
        $logDir = $base.'/var/log';
        mkdir($logDir, 0777, true);
        file_put_contents($logDir.'/exception.log', "[2026-01-01] exception fallback line\n");

        try {
            define('BP', $base);

            self::assertStringContainsString(
                'exception fallback line',
                $this->textOf($this->tool()->execute(['lines' => 5])),
            );
        } finally {
            @unlink($logDir.'/exception.log');
            @rmdir($logDir);
            @rmdir(dirname($logDir));
            @rmdir($base);
        }
    }
}
