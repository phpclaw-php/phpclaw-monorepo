<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tools\LogTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogTool::class)]
final class LogToolTest extends TestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/phpclaw_log_test_'.uniqid();
        mkdir($this->tmpDir.'/var/logs', 0777, true);
        $this->actAsChatTierEmployee();
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();
        parent::tearDown();
        $logFile = $this->tmpDir.'/var/logs/dev.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        @rmdir($this->tmpDir.'/var/logs');
        @rmdir($this->tmpDir.'/var');
        @rmdir($this->tmpDir);
    }

    private function writeLog(string $content): void
    {
        file_put_contents($this->tmpDir.'/var/logs/dev.log', $content);
    }

    public function test_name_returns_ps_log(): void
    {
        $tool = new LogTool($this->tmpDir);

        self::assertSame('ps_log', $tool->name());
    }

    public function test_description_states_it_reads_the_dev_log(): void
    {
        $tool = new LogTool($this->tmpDir);

        self::assertStringContainsString('READ the PrestaShop application log file', $tool->description());
    }

    public function test_input_schema_has_properties(): void
    {
        $tool = new LogTool($this->tmpDir);

        self::assertArrayHasKey('properties', $tool->inputSchema());
    }

    public function test_execute_schema_mode_returns_json_with_path(): void
    {
        $tool = new LogTool($this->tmpDir);
        $data = $this->data($tool->execute(['schema' => true]));

        self::assertArrayHasKey('path', $data);
        self::assertArrayHasKey('exists', $data);
    }

    public function test_execute_schema_mode_reports_not_exists_when_no_log(): void
    {
        $tool = new LogTool($this->tmpDir);
        $data = $this->data($tool->execute(['schema' => true]));

        self::assertFalse($data['exists']);
    }

    public function test_execute_schema_mode_reports_exists_when_log_present(): void
    {
        $this->writeLog("[2026-01-01 10:00:00] ERROR: something failed\n");
        $tool = new LogTool($this->tmpDir);
        $data = $this->data($tool->execute(['schema' => true]));

        self::assertTrue($data['exists']);
        self::assertGreaterThan(0, $data['size_bytes']);
    }

    public function test_execute_tail_returns_log_lines(): void
    {
        $this->writeLog("[2026-01-01 10:00:00] ERROR: db failed\n[2026-01-01 10:01:00] WARNING: slow query\n");
        $tool = new LogTool($this->tmpDir);
        $lines = implode("\n", $this->rows($tool->execute(['lines' => 10]), 'lines'));

        self::assertStringContainsString('db failed', $lines);
        self::assertStringContainsString('slow query', $lines);
    }

    public function test_execute_throws_when_file_missing_in_tail_mode(): void
    {
        $tool = new LogTool($this->tmpDir);

        $this->expectException(ToolException::class);
        $tool->execute(['lines' => 5]);
    }

    public function test_execute_search_returns_matching_lines_only(): void
    {
        $this->writeLog(
            "[2026-01-01 10:00:00] ERROR: db failed\n".
            "[2026-01-01 10:01:00] WARNING: slow query\n".
            "[2026-01-01 10:02:00] ERROR: out of memory\n"
        );
        $tool = new LogTool($this->tmpDir);
        $lines = implode("\n", $this->rows($tool->execute(['search' => 'out of memory']), 'lines'));

        self::assertStringContainsString('out of memory', $lines);
        self::assertStringNotContainsString('slow query', $lines);
    }

    public function test_execute_level_filter_returns_matching_lines(): void
    {
        $this->writeLog(
            "[2026-01-01 10:00:00] error: db failed\n".
            "[2026-01-01 10:01:00] warning: slow query\n".
            "[2026-01-01 10:02:00] error: timeout\n"
        );
        $tool = new LogTool($this->tmpDir);
        $lines = implode("\n", $this->rows($tool->execute(['level' => 'warning']), 'lines'));

        self::assertStringContainsString('slow query', $lines);
        self::assertStringNotContainsString('timeout', $lines);
    }

    public function test_execute_aggregate_returns_json_with_counts(): void
    {
        $this->writeLog(
            "[2026-01-01 10:00:00] ERROR: db failed\n".
            "[2026-01-01 10:01:00] WARNING: slow query\n".
            "[2026-01-01 10:02:00] FATAL: crash\n"
        );
        $tool = new LogTool($this->tmpDir);
        $data = $this->data($tool->execute(['aggregate' => true]));

        self::assertArrayHasKey('total_lines', $data);
        self::assertArrayHasKey('counts', $data);
        self::assertGreaterThanOrEqual(1, $data['counts']['error']);
    }

    public function test_execute_no_match_returns_no_matching_message(): void
    {
        $this->writeLog("[2026-01-01 10:00:00] INFO: all good\n");
        $tool = new LogTool($this->tmpDir);
        $result = $tool->execute(['search' => 'XXXXNOTFOUND']);

        self::assertSame([], $this->rows($result, 'lines'));
        self::assertSame(0, $this->meta($result)['total_matching']);
    }

    public function test_an_employee_below_the_chat_tier_is_refused(): void
    {
        $this->writeLog("[2026-01-01 10:00:00] ERROR: db failed\n");
        $this->actAsEmployeeBelowChatTier();

        $tool = new LogTool($this->tmpDir);

        self::assertSame('FORBIDDEN', $this->errorCode($tool->execute(['lines' => 10])));
    }

    public function test_the_required_capability_is_the_chat_tab(): void
    {
        self::assertSame('AdminPhpClawDebug', (new LogTool($this->tmpDir))->requiredCapability());
    }
}
