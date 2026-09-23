<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Orchestra\Testbench\TestCase;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\LogTool;

final class LogToolTest extends TestCase
{
    private LogTool $tool;

    private string $logPath;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = sys_get_temp_dir().'/phpclaw_test_laravel_'.uniqid().'.log';

        $this->tool = new LogTool(logPath: $this->logPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    public function test_name_returns_read_log(): void
    {
        $this->assertSame('read_log', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool->description();

        $this->assertStringContainsString('READ last N lines of the real Laravel log', $description);
        $this->assertStringContainsString('storage/logs/laravel.log', $description);
    }

    public function test_input_schema_has_optional_lines_and_level(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('lines', $schema['properties']);
        $this->assertArrayHasKey('level', $schema['properties']);

        $required = $schema['required'] ?? [];
        $this->assertNotContains('lines', $required);
        $this->assertNotContains('level', $required);
    }

    public function test_execute_success_envelope_has_required_keys(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:00] local.INFO: Hello\n");

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('success', $envelope);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertArrayHasKey('warnings', $envelope);
        $this->assertTrue($envelope['success']);
    }

    public function test_execute_returns_last_n_lines(): void
    {
        $lines = array_map(
            fn (int $i): string => "[2024-01-01 00:00:{$i}] local.INFO: Line {$i} context: [] []",
            range(1, 30),
        );
        file_put_contents($this->logPath, implode("\n", $lines)."\n");

        $raw = $this->tool->execute(['lines' => 10]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertCount(10, $envelope['data']['entries']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringContainsString('Line 30', $entriesText);
        $this->assertStringNotContainsString('Line 20', $entriesText);
    }

    public function test_execute_defaults_to_50_lines(): void
    {
        $lines = array_map(
            fn (int $i): string => "[2024-01-01 00:00:00] local.INFO: Line {$i} context: [] []",
            range(1, 100),
        );
        file_put_contents($this->logPath, implode("\n", $lines)."\n");

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertCount(50, $envelope['data']['entries']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringContainsString('Line 100', $entriesText);
        $this->assertStringNotContainsString('Line 50', $entriesText);
    }

    public function test_execute_returns_invalid_argument_for_lines_exceeding_maximum(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:00] local.INFO: Line\n");

        $raw = $this->tool->execute(['lines' => 500]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_level_finds_an_error_older_than_the_maximum_tail(): void
    {
        file_put_contents(
            $this->logPath,
            "[2024-01-01 00:00:01] local.ERROR: early failure\n".str_repeat("[2024-01-01 00:00:02] local.INFO: routine\n", 300),
        );

        $envelope = json_decode($this->tool->execute(['level' => 'error', 'lines' => 200]), associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame(['[2024-01-01 00:00:01] local.ERROR: early failure'], $envelope['data']['entries']);
    }

    public function test_level_keeps_only_the_last_requested_matches_newest_last(): void
    {
        $log = '';
        for ($i = 1; $i <= 5; $i++) {
            $log .= "[2024-01-01 00:00:0{$i}] local.ERROR: hit {$i}\n".str_repeat("[2024-01-01 00:00:09] local.INFO: filler\n", 100);
        }
        file_put_contents($this->logPath, $log);

        $envelope = json_decode($this->tool->execute(['level' => 'error', 'lines' => 2]), associative: true);

        $this->assertSame(
            ['[2024-01-01 00:00:04] local.ERROR: hit 4', '[2024-01-01 00:00:05] local.ERROR: hit 5'],
            $envelope['data']['entries'],
        );
    }

    public function test_execute_filters_by_level(): void
    {
        $logContent = implode("\n", [
            '[2024-01-01 00:00:01] local.INFO: Info message context: [] []',
            '[2024-01-01 00:00:02] local.ERROR: Error message context: [] []',
            '[2024-01-01 00:00:03] local.DEBUG: Debug message context: [] []',
            '[2024-01-01 00:00:04] local.ERROR: Another error context: [] []',
            '[2024-01-01 00:00:05] local.WARNING: Warning message context: [] []',
        ])."\n";
        file_put_contents($this->logPath, $logContent);

        $raw = $this->tool->execute(['level' => 'error']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringContainsString('Error message', $entriesText);
        $this->assertStringContainsString('Another error', $entriesText);
        $this->assertStringNotContainsString('Info message', $entriesText);
        $this->assertStringNotContainsString('Debug message', $entriesText);
        $this->assertStringNotContainsString('Warning message', $entriesText);
    }

    public function test_explicit_log_path_is_used_when_provided(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:00] local.INFO: Custom path works\n");

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertStringContainsString('Custom path works', implode("\n", $envelope['data']['entries']));
    }

    public function test_empty_log_path_falls_back_to_storage_path(): void
    {
        $defaultPath = storage_path('logs/laravel.log');
        $dir = dirname($defaultPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $marker = 'Default path fallback '.uniqid();
        $existing = file_exists($defaultPath) ? (string) file_get_contents($defaultPath) : null;
        file_put_contents($defaultPath, "[2024-01-01 00:00:00] local.INFO: {$marker}\n");

        try {
            $raw = (new LogTool)->execute([]);
            $envelope = json_decode($raw, associative: true);

            $this->assertTrue($envelope['success']);
            $this->assertStringContainsString(
                $marker,
                implode("\n", $envelope['data']['entries']),
                'with no path configured the tool must read storage_path(logs/laravel.log)',
            );
        } finally {
            if ($existing === null) {
                @unlink($defaultPath);
            } else {
                file_put_contents($defaultPath, $existing);
            }
        }
    }

    public function test_execute_throws_when_log_file_missing(): void
    {
        $tool = new LogTool(logPath: '/nonexistent/path/laravel.log');

        $this->expectException(ToolException::class);

        $tool->execute([]);
    }

    public function test_output_truncated_at_8kb(): void
    {
        $longLine = str_repeat('X', 200);
        $lines = [];

        for ($i = 1; $i <= 200; $i++) {
            $lines[] = "[2024-01-01 00:00:00] local.INFO: {$longLine} Line{$i}";
        }

        file_put_contents($this->logPath, implode("\n", $lines)."\n");

        $raw = $this->tool->execute(['lines' => 200]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertTrue($envelope['meta']['truncated']);

        $truncatedWarnings = array_filter($envelope['warnings'], fn (array $w): bool => $w['code'] === 'OUTPUT_TRUNCATED');
        $this->assertNotEmpty($truncatedWarnings);
    }

    public function test_execute_returns_not_found_warning_for_empty_log(): void
    {
        file_put_contents($this->logPath, '');

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['entries']);
        $this->assertSame(0, $envelope['meta']['count']);

        $notFound = array_filter($envelope['warnings'], fn (array $w): bool => $w['code'] === 'NOT_FOUND');
        $this->assertNotEmpty($notFound);
        $this->assertStringContainsString('empty', array_values($notFound)[0]['message']);
    }

    public function test_execute_handles_file_smaller_than_chunk_size(): void
    {
        $content = implode("\n", [
            '[2024-01-01 00:00:01] local.INFO: Line one',
            '[2024-01-01 00:00:02] local.INFO: Line two',
            '[2024-01-01 00:00:03] local.INFO: Line three',
        ])."\n";

        file_put_contents($this->logPath, $content);

        $raw = $this->tool->execute(['lines' => 50]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringContainsString('Line one', $entriesText);
        $this->assertStringContainsString('Line three', $entriesText);
    }

    public function test_execute_returns_not_found_warning_when_level_matches_nothing(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:01] local.INFO: Only info here\n");

        $raw = $this->tool->execute(['level' => 'error']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['entries']);

        $notFound = array_filter($envelope['warnings'], fn (array $w): bool => $w['code'] === 'NOT_FOUND');
        $this->assertNotEmpty($notFound);
        $this->assertStringContainsString("'error'", array_values($notFound)[0]['message']);
        $this->assertStringNotContainsString('Only info here', implode("\n", $envelope['data']['entries']));
    }

    public function test_execute_returns_invalid_argument_for_unknown_level(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:01] local.INFO: Some entry\n");

        $raw = $this->tool->execute(['level' => 'badlevel']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_execute_throws_when_log_file_not_readable(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable files as root.');
        }

        file_put_contents($this->logPath, "[2024-01-01] local.INFO: Secret\n");
        chmod($this->logPath, 0000);

        try {
            $this->expectException(ToolException::class);
            $this->tool->execute([]);
        } finally {
            chmod($this->logPath, 0644);
        }
    }

    public function test_execute_redacts_credentials_from_log_lines(): void
    {
        file_put_contents(
            $this->logPath,
            '[2024-01-01 00:00:00] local.ERROR: payload {"user":"bob","api_token":"live_9fabc123"}'."\n"
            .'[2024-01-01 00:00:01] local.INFO: retry with Bearer sk-abcdef1234567890'."\n",
        );

        $raw = $this->tool->execute(['lines' => 10]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringNotContainsString('live_9fabc123', $entriesText);
        $this->assertStringNotContainsString('sk-abcdef1234567890', $entriesText);
        $this->assertStringContainsString('[redacted]', $entriesText);
        $this->assertStringContainsString('bob', $entriesText);
    }

    public function test_execute_redacts_colon_delimited_credentials_without_splitting_them(): void
    {
        file_put_contents(
            $this->logPath,
            '[2024-01-01 00:00:00] local.ERROR: Authorization: Bearer xyz12345678'."\n"
            .'[2024-01-01 00:00:01] local.ERROR: X-Api-Key: k_9f8e7d6c5b4a'."\n"
            .'[2024-01-01 00:00:02] local.ERROR: token: abc123def456 user: bob'."\n",
        );

        $raw = $this->tool->execute(['lines' => 10]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringNotContainsString('xyz12345678', $entriesText);
        $this->assertStringNotContainsString('k_9f8e7d6c5b4a', $entriesText);
        $this->assertStringNotContainsString('abc123def456', $entriesText);
        $this->assertStringContainsString('user: bob', $entriesText);
    }

    public function test_execute_leaves_ordinary_log_lines_untouched(): void
    {
        file_put_contents(
            $this->logPath,
            '[2024-01-01 00:00:00] local.INFO: connect failed at 10:30:00'."\n",
        );

        $raw = $this->tool->execute(['lines' => 10]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringContainsString('connect failed at 10:30:00', $entriesText);
        $this->assertStringNotContainsString('[redacted]', $entriesText);
    }

    public function test_execute_returns_forbidden_when_no_authenticated_user_and_gate_undefined(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:00] local.INFO: Hello\n");

        $reflection = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $original = $reflection->getValue($this->app);
        $reflection->setValue($this->app, false);

        try {
            $raw = $this->tool->execute([]);
        } finally {
            $reflection->setValue($this->app, $original);
        }

        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_execute_returns_invalid_argument_for_out_of_range_lines(): void
    {
        file_put_contents($this->logPath, "[2024-01-01 00:00:00] local.INFO: Line\n");

        $raw = $this->tool->execute(['lines' => 201]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_execute_redacts_bearer_token_in_log_line(): void
    {
        file_put_contents(
            $this->logPath,
            '[2024-01-01 00:00:00] local.ERROR: Authorization: Bearer eyJhbGciOiJSUzI1NiJ9'."\n",
        );

        $raw = $this->tool->execute(['lines' => 5]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $entriesText = implode("\n", $envelope['data']['entries']);
        $this->assertStringNotContainsString('eyJhbGciOiJSUzI1NiJ9', $entriesText);
        $this->assertStringContainsString('[redacted]', $entriesText);
    }
}
