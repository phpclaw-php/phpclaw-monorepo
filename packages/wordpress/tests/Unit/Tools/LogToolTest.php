<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\LogTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogTool::class)]
final class LogToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    private string $tmpLog;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        $this->tmpLog = sys_get_temp_dir().'/phpclaw_test_debug_'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();

        if (file_exists($this->tmpLog)) {
            unlink($this->tmpLog);
        }

        parent::tearDown();
    }

    public function test_name_is_read_log(): void
    {
        self::assertSame('read_log', (new LogTool)->name());
    }

    public function test_it_reads_last_lines(): void
    {
        file_put_contents($this->tmpLog, "line1\nline2\nline3\n");

        $tool = new LogTool($this->tmpLog);
        $result = $tool->execute(['lines' => 2]);

        self::assertStringContainsString('line2', $result);
        self::assertStringContainsString('line3', $result);
        self::assertStringNotContainsString('line1', $result);
    }

    public function test_it_filters_by_level(): void
    {
        file_put_contents($this->tmpLog, "[error] Something failed\n[info] All good\n[error] Another error\n");

        $tool = new LogTool($this->tmpLog);
        $result = $tool->execute(['lines' => 10, 'level' => 'error']);

        self::assertStringContainsString('[error]', $result);
        self::assertStringNotContainsString('[info]', $result);
    }

    public function test_it_returns_no_lines_for_an_empty_log(): void
    {
        file_put_contents($this->tmpLog, '');

        $r = json_decode((new LogTool($this->tmpLog))->execute([]), true);

        self::assertTrue($r['success']);
        self::assertSame([], $r['data']['lines']);
        self::assertSame(0, $r['meta']['count']);
    }

    public function test_it_returns_no_lines_when_level_matches_nothing(): void
    {
        file_put_contents($this->tmpLog, "[info] Everything is fine\n");

        $r = json_decode((new LogTool($this->tmpLog))->execute(['level' => 'fatal']), true);

        self::assertTrue($r['success']);
        self::assertSame([], $r['data']['lines']);
        self::assertSame('fatal', $r['meta']['filters']['level']);
    }

    public function test_an_unknown_level_is_rejected(): void
    {
        file_put_contents($this->tmpLog, "[info] fine\n");

        $r = json_decode((new LogTool($this->tmpLog))->execute(['level' => 'critical']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LEVEL', $r['error']['code']);
    }

    public function test_a_missing_log_returns_log_unavailable(): void
    {
        $r = json_decode((new LogTool('/nonexistent/path/debug.log'))->execute([]), true);

        self::assertFalse($r['success']);
        self::assertSame('LOG_UNAVAILABLE', $r['error']['code']);
    }

    public function test_schema_mode_returns_metadata_when_file_exists(): void
    {
        file_put_contents($this->tmpLog, "abcdef\n");

        $result = (new LogTool($this->tmpLog))->execute(['schema' => true]);
        $data = json_decode($result, true);

        self::assertSame('schema', $data['meta']['mode']);
        self::assertSame($this->tmpLog, $data['data']['path']);
        self::assertTrue($data['data']['exists']);
        self::assertSame(7, $data['data']['size_bytes']);
        self::assertTrue($data['data']['readable']);
        self::assertContains('query', $data['data']['modes']);
        self::assertContains('aggregate', $data['data']['modes']);
        self::assertSame(['error', 'warning', 'fatal', 'notice', 'deprecated', 'parse'], $data['data']['valid_levels']);
        self::assertSame(200, $data['data']['limits']['maximum_lines']);
        self::assertSame(50, $data['data']['limits']['default_lines']);
        self::assertSame(8192, $data['data']['limits']['maximum_output_bytes']);
    }

    public function test_schema_mode_reports_missing_file(): void
    {
        $result = (new LogTool('/nonexistent/path/debug.log'))->execute(['schema' => true]);
        $data = json_decode($result, true);

        self::assertFalse($data['data']['exists']);
        self::assertSame(0, $data['data']['size_bytes']);
        self::assertFalse($data['data']['readable']);
        self::assertSame('0 B', $data['data']['size_human']);
    }

    public function test_aggregate_counts_each_severity_level(): void
    {
        file_put_contents(
            $this->tmpLog,
            "[27-Apr-2026 14:30:00 UTC] PHP Fatal: boom\n".
            "[27-Apr-2026 14:31:00 UTC] PHP Warning: nope\n".
            "[27-Apr-2026 14:32:00 UTC] PHP Notice: heads up\n".
            "[27-Apr-2026 14:33:00 UTC] PHP Deprecated: old\n".
            "[27-Apr-2026 14:34:00 UTC] PHP Parse: unexpected token\n".
            "[27-Apr-2026 14:35:00 UTC] PHP Error: thing broke\n",
        );

        $result = (new LogTool($this->tmpLog))->execute(['aggregate' => true]);
        $data = json_decode($result, true);

        self::assertSame('aggregate', $data['meta']['mode']);
        self::assertSame(6, $data['data']['total_lines']);
        self::assertSame(1, $data['data']['fatal_count']);
        self::assertSame(1, $data['data']['warning_count']);
        self::assertSame(1, $data['data']['notice_count']);
        self::assertSame(1, $data['data']['deprecated_count']);
        self::assertSame(1, $data['data']['parse_count']);
        self::assertSame(1, $data['data']['error_count']);
        self::assertStringContainsString('PHP Error: thing broke', $data['data']['last_error']);
        self::assertSame('27-Apr-2026 14:35:00', $data['data']['last_error_timestamp']);
    }

    public function test_aggregate_empty_file_returns_zero_counts(): void
    {
        file_put_contents($this->tmpLog, '');

        $result = (new LogTool($this->tmpLog))->execute(['aggregate' => true]);
        $data = json_decode($result, true);

        self::assertSame(0, $data['data']['total_lines']);
        self::assertSame(0, $data['data']['error_count']);
        self::assertSame(0, $data['data']['fatal_count']);
        self::assertNull($data['data']['last_error']);
        self::assertNull($data['data']['last_error_timestamp']);
    }

    public function test_search_filter_matches_substring_case_insensitive(): void
    {
        file_put_contents(
            $this->tmpLog,
            "[error] database timeout occurred\n".
            "[error] cache flush failed\n".
            "[error] DATABASE pool exhausted\n",
        );

        $result = (new LogTool($this->tmpLog))->execute(['search' => 'database']);

        self::assertStringContainsString('database timeout', $result);
        self::assertStringContainsString('DATABASE pool', $result);
        self::assertStringNotContainsString('cache flush', $result);
    }

    public function test_search_finds_a_match_older_than_the_maximum_tail(): void
    {
        file_put_contents(
            $this->tmpLog,
            "[error] OLD_FATAL_MARKER first line\n".str_repeat("[info] routine entry\n", 300),
        );

        $r = json_decode((new LogTool($this->tmpLog))->execute(['search' => 'old_fatal_marker', 'lines' => 200]), true);

        self::assertTrue($r['success']);
        self::assertSame(['[error] OLD_FATAL_MARKER first line'], $r['data']['lines']);
        self::assertSame(1, $r['meta']['matched']);
    }

    public function test_level_finds_a_fatal_older_than_the_maximum_tail(): void
    {
        file_put_contents(
            $this->tmpLog,
            "PHP Fatal error: early crash\n".str_repeat("PHP Notice: later noise\n", 300),
        );

        $r = json_decode((new LogTool($this->tmpLog))->execute(['level' => 'fatal']), true);

        self::assertSame(['PHP Fatal error: early crash'], $r['data']['lines']);
    }

    public function test_search_keeps_only_the_last_requested_matches_newest_last(): void
    {
        $log = '';
        for ($i = 1; $i <= 5; $i++) {
            $log .= "[error] hit {$i}\n".str_repeat("[info] filler\n", 100);
        }
        file_put_contents($this->tmpLog, $log);

        $r = json_decode((new LogTool($this->tmpLog))->execute(['search' => 'hit', 'lines' => 2]), true);

        self::assertSame(['[error] hit 4', '[error] hit 5'], $r['data']['lines']);
        self::assertSame(2, $r['meta']['matched']);
    }

    public function test_search_and_level_together_require_both_terms(): void
    {
        file_put_contents(
            $this->tmpLog,
            "[error] database down\n[warning] database slow\n[error] cache miss\n".str_repeat("[info] filler\n", 250),
        );

        $r = json_decode((new LogTool($this->tmpLog))->execute(['search' => 'database', 'level' => 'error']), true);

        self::assertSame(['[error] database down'], $r['data']['lines']);
    }

    public function test_search_with_no_matches_reports_the_filter(): void
    {
        file_put_contents($this->tmpLog, "[error] something else\n");

        $r = json_decode((new LogTool($this->tmpLog))->execute(['search' => 'unicorn']), true);

        self::assertSame([], $r['data']['lines']);
        self::assertSame('unicorn', $r['meta']['filters']['search']);
    }

    public function test_blank_search_is_rejected(): void
    {
        file_put_contents($this->tmpLog, "line1\nline2\n");

        $r = json_decode((new LogTool($this->tmpLog))->execute(['search' => '   ']), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_SEARCH', $r['error']['code']);
    }

    public function test_output_is_truncated_at_8kb(): void
    {
        $longLine = str_repeat('x', 200);
        $contents = '';

        for ($i = 0; $i < 100; $i++) {
            $contents .= $longLine."\n";
        }

        file_put_contents($this->tmpLog, $contents);

        $result = (new LogTool($this->tmpLog))->execute(['lines' => 100]);

        $r = json_decode($result, true);

        self::assertTrue($r['meta']['truncated']);
        self::assertSame('OUTPUT_TRUNCATED', $r['warnings'][0]['code']);
    }

    public function test_lines_above_max_is_rejected_not_clamped(): void
    {
        file_put_contents($this->tmpLog, "row1\n");

        $r = json_decode((new LogTool($this->tmpLog))->execute(['lines' => 9999]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LINES', $r['error']['code']);
    }

    public function test_lines_below_one_is_rejected_not_clamped(): void
    {
        file_put_contents($this->tmpLog, "alpha\nbeta\n");

        $r = json_decode((new LogTool($this->tmpLog))->execute(['lines' => 0]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LINES', $r['error']['code']);
    }

    public function test_reads_last_lines_from_large_file_using_chunked_seek(): void
    {
        $contents = '';

        for ($i = 1; $i <= 500; $i++) {
            $contents .= "row{$i}\n";
        }

        file_put_contents($this->tmpLog, $contents);

        $result = (new LogTool($this->tmpLog))->execute(['lines' => 3]);

        self::assertStringContainsString('row498', $result);
        self::assertStringContainsString('row499', $result);
        self::assertStringContainsString('row500', $result);
        self::assertStringNotContainsString('row1'."\n", $result);
    }

    public function test_handles_file_with_no_trailing_newline(): void
    {
        file_put_contents($this->tmpLog, "first\nsecond\nthird");

        $result = (new LogTool($this->tmpLog))->execute(['lines' => 5]);

        self::assertStringContainsString('first', $result);
        self::assertStringContainsString('second', $result);
        self::assertStringContainsString('third', $result);
    }

    public function test_default_constructor_resolves_log_path_from_fallback(): void
    {
        $result = (new LogTool)->execute(['schema' => true]);
        $data = json_decode($result, true);

        self::assertSame('schema', $data['meta']['mode']);
        self::assertStringEndsWith('/debug.log', $data['data']['path']);
    }

    public function test_human_file_size_formats(): void
    {
        $tool = new LogTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('humanFileSize');
        $m->setAccessible(true);

        self::assertSame('0 B', $m->invoke($tool, 0));
        self::assertSame('500 B', $m->invoke($tool, 500));
        self::assertSame('1.5 KB', $m->invoke($tool, 1536));
        self::assertSame('2 MB', $m->invoke($tool, 2 * 1024 * 1024));
        self::assertSame('3 GB', $m->invoke($tool, 3 * 1024 * 1024 * 1024));
        self::assertStringEndsWith('GB', $m->invoke($tool, 10 * 1024 * 1024 * 1024));
    }

    public function test_extract_timestamp_recognises_multiple_formats(): void
    {
        $tool = new LogTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('extractTimestamp');
        $m->setAccessible(true);

        self::assertSame('27-Apr-2026 14:30:00', $m->invoke($tool, '[27-Apr-2026 14:30:00 UTC] PHP Error'));
        self::assertSame('2026-04-27 14:30:00', $m->invoke($tool, '[2026-04-27 14:30:00] PHP Error'));
        self::assertSame('2026-04-27T14:30:00', $m->invoke($tool, '[2026-04-27T14:30:00+00:00] PHP Error'));
        self::assertNull($m->invoke($tool, 'no timestamp here'));
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new LogTool)->execute(['schema' => true]));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new LogTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {

        $decoded = json_decode((new LogTool)->execute(['schema' => true]), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new LogTool;

        self::assertSame('wordpress.logs.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], LogTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        $result = json_decode((new LogTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('wordpress.logs.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }

    public function test_aggregate_mode_counts_each_severity_and_reports_the_last_error(): void
    {
        file_put_contents($this->tmpLog, implode("\n", [
            '[01-Jan-2026 10:00:00 UTC] PHP Warning:  something odd',
            '[01-Jan-2026 10:01:00 UTC] PHP Notice:  heads up',
            '[01-Jan-2026 10:02:00 UTC] PHP Deprecated:  old call',
            '[01-Jan-2026 10:04:00 UTC] PHP Error:  first failure',
            '[01-Jan-2026 10:05:00 UTC] PHP Fatal error:  last failure',
        ])."\n");

        $result = json_decode((new LogTool($this->tmpLog))->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(5, $result['data']['total_lines']);
        self::assertSame(1, $result['data']['warning_count']);
        self::assertSame(1, $result['data']['notice_count']);
        self::assertSame(1, $result['data']['deprecated_count']);
        self::assertSame(1, $result['data']['error_count']);
        self::assertSame(1, $result['data']['fatal_count']);
        self::assertStringContainsString('last failure', (string) $result['data']['last_error']);
        self::assertSame(filesize($this->tmpLog), $result['data']['size_bytes']);
    }

    public function test_input_schema_advertises_both_modes_and_the_documented_limits(): void
    {
        $schema = (new LogTool)->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame([], $schema['required']);
        self::assertSame(
            ['schema', 'aggregate', 'lines', 'search', 'level'],
            array_keys($schema['properties']),
        );
        self::assertSame('boolean', $schema['properties']['schema']['type']);
        self::assertSame('boolean', $schema['properties']['aggregate']['type']);
        self::assertSame(1, $schema['properties']['lines']['minimum']);
        self::assertNotSame([], $schema['properties']['level']['enum']);
    }

    public function test_description_names_the_capability_the_tool_checks(): void
    {
        $tool = (new LogTool);

        self::assertStringContainsString('"'.$tool->requiredCapability().'"', $tool->description());
    }
}
