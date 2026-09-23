<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\CodeSearchTool;
use PHPUnit\Framework\TestCase;

final class CodeSearchToolTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('cst_', true);
        mkdir($this->workspace.'/sub', 0755, true);
        mkdir($this->workspace.'/vendor', 0755, true);

        file_put_contents($this->workspace.'/sub/a.php', "<?php\nfunction targetNeedle() {}\n");
        file_put_contents($this->workspace.'/b.txt', "no match here\ntargetNeedle in text\n");
        file_put_contents($this->workspace.'/vendor/skip.php', "targetNeedle in vendor\n");
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
            if ($entry !== '.' && $entry !== '..') {
                $this->rrm($path.DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($path);
    }

    private function search(array $input): array
    {
        $decoded = (array) json_decode((new CodeSearchTool($this->workspace))->execute($input), true);

        return (array) ($decoded['data'] ?? []);
    }

    public function test_name_and_required_input(): void
    {
        $tool = new CodeSearchTool($this->workspace);

        self::assertSame('code_search', $tool->name());
        self::assertSame(['pattern'], $tool->inputSchema()['required']);
    }

    public function test_finds_literal_match_with_file_and_line(): void
    {
        $res = $this->search(['pattern' => 'targetNeedle', 'extension' => 'php']);
        $hits = $res['results'];

        self::assertSame('sub/a.php', $hits[0]['file']);
        self::assertSame(2, $hits[0]['line']);
    }

    public function test_extension_filter_excludes_other_files(): void
    {
        $res = $this->search(['pattern' => 'targetNeedle', 'extension' => 'php']);
        $files = array_column($res['results'], 'file');

        self::assertNotContains('b.txt', $files);
    }

    public function test_vendor_directory_is_skipped(): void
    {
        $res = $this->search(['pattern' => 'targetNeedle']);
        $files = array_column($res['results'], 'file');

        self::assertNotContains('vendor/skip.php', $files);
    }

    public function test_regex_search_matches(): void
    {
        $res = $this->search(['pattern' => '/function\s+target\w+/', 'is_regex' => true, 'extension' => 'php']);

        self::assertGreaterThanOrEqual(1, $res['total']);
    }

    public function test_invalid_regex_throws(): void
    {
        $this->expectException(ToolException::class);

        (new CodeSearchTool($this->workspace))->execute(['pattern' => '/unclosed(', 'is_regex' => true]);
    }

    public function test_empty_pattern_throws(): void
    {
        $this->expectException(ToolException::class);

        (new CodeSearchTool($this->workspace))->execute(['pattern' => '']);
    }

    public function test_path_escape_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/escapes workspace/');

        (new CodeSearchTool($this->workspace))->execute(['pattern' => 'x', 'path' => '../..']);
    }

    public function test_catastrophic_regex_is_capped_and_fast(): void
    {
        file_put_contents($this->workspace.'/evil.txt', str_repeat('a', 35)."!\n");

        $previousAmbient = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000000');

        try {
            $start = microtime(true);
            $res = $this->search(['pattern' => '/^(a+)+$/', 'is_regex' => true, 'extension' => 'txt', 'path' => '.']);
            $elapsed = microtime(true) - $start;
        } finally {
            ini_set('pcre.backtrack_limit', $previousAmbient);
        }

        self::assertSame(0, $res['total'], 'catastrophic pattern must not match, it is capped, not evaluated to completion');
        self::assertLessThan(0.5, $elapsed, 'search must return well under a second even under an elevated ambient backtrack_limit');
    }

    public function test_ambient_backtrack_limit_is_restored_after_search(): void
    {
        $previousAmbient = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000000');

        try {
            $this->search(['pattern' => '/targetNeedle/', 'is_regex' => true, 'extension' => 'php']);
            self::assertSame('1000000000', (string) ini_get('pcre.backtrack_limit'));
        } finally {
            ini_set('pcre.backtrack_limit', $previousAmbient);
        }
    }

    public function test_backtrack_ceiling_hit_is_logged(): void
    {
        mkdir($this->workspace.'/evildir');
        file_put_contents($this->workspace.'/evildir/evil.txt', str_repeat('a', 35)."!\n");

        $logFile = $this->workspace.'/error.log';
        $previousErrorLog = ini_set('error_log', $logFile);

        try {
            $this->search(['pattern' => '/^(a+)+$/', 'is_regex' => true, 'path' => 'evildir']);
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        $logged = file_exists($logFile) ? file_get_contents($logFile) : '';
        $lines = array_values(array_filter(explode("\n", trim($logged))));

        self::assertCount(1, $lines, 'exactly one summary log line per search, not one per skipped line');
        self::assertStringContainsString('skipped 1/1 line(s)', $lines[0]);
    }

    public function test_complex_but_legitimate_regex_still_matches(): void
    {
        $res = $this->search(['pattern' => '/function\s+\w+\(\)\s*\{\}/', 'is_regex' => true, 'extension' => 'php']);

        self::assertSame(1, $res['total']);
        self::assertSame('sub/a.php', $res['results'][0]['file']);
    }

    private function seedManyMatches(int $count): void
    {
        mkdir($this->workspace.'/many', 0755, true);
        for ($i = 0; $i < $count; $i++) {
            file_put_contents($this->workspace."/many/f{$i}.php", "<?php\n// pad\n\$x = pagedNeedle; // {$i}\n// pad\n");
        }
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return $decoded['data'] ?? $decoded;
    }

    public function test_default_result_rows_carry_file_and_line_only(): void
    {
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute(['pattern' => 'targetNeedle', 'extension' => 'php']));

        $this->assertSame(1, $data['total']);
        $this->assertSame(['file', 'line'], array_keys($data['results'][0]));
        $this->assertSame('sub/a.php', $data['results'][0]['file']);
        $this->assertSame(2, $data['results'][0]['line']);
    }

    public function test_context_lines_zero_adds_the_matched_line(): void
    {
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute(['pattern' => 'targetNeedle', 'extension' => 'php', 'context_lines' => 0]));

        $this->assertSame(['file', 'line', 'match'], array_keys($data['results'][0]));
        $this->assertStringContainsString('targetNeedle', $data['results'][0]['match']);
    }

    public function test_context_lines_two_adds_before_and_after(): void
    {
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute(['pattern' => 'targetNeedle', 'extension' => 'php', 'context_lines' => 2]));

        $row = $data['results'][0];
        $this->assertSame(['file', 'line', 'match', 'before', 'after'], array_keys($row));
        $this->assertSame(['<?php'], $row['before']);
    }

    public function test_truncated_result_carries_next_offset_and_hint(): void
    {
        $this->seedManyMatches(400);
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute(['pattern' => 'pagedNeedle', 'extension' => 'php']));

        $this->assertTrue($data['truncated']);
        $this->assertGreaterThan(50, $data['total'], 'lean rows must pack more than the old 50 per page');
        $this->assertLessThan(400, $data['total']);
        $this->assertSame($data['total'], $data['next_offset']);
        $this->assertStringContainsString('offset='.$data['next_offset'], $data['hint']);
        $this->assertLessThanOrEqual(8192 + 512, strlen((string) json_encode($data['results'])), 'page stays near the byte budget');
    }

    public function test_offset_pages_cover_every_match_exactly_once(): void
    {
        $this->seedManyMatches(400);
        $tool = new CodeSearchTool($this->workspace);
        $key = static fn (array $r): string => $r['file'].':'.$r['line'];

        $seen = [];
        $offset = 0;
        $pages = 0;
        do {
            $page = $this->decode($tool->execute(['pattern' => 'pagedNeedle', 'extension' => 'php', 'offset' => $offset]));
            $pages++;
            foreach ($page['results'] as $r) {
                $this->assertArrayNotHasKey($key($r), $seen, 'pages must not overlap');
                $seen[$key($r)] = true;
            }
            $offset = $page['next_offset'] ?? null;
        } while ($offset !== null && $pages < 20);

        $this->assertCount(400, $seen);
        $this->assertGreaterThan(1, $pages);
        $this->assertFalse($page['truncated']);
    }

    public function test_a_small_result_set_is_not_reported_as_truncated(): void
    {
        $this->seedManyMatches(30);
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute(['pattern' => 'pagedNeedle', 'extension' => 'php']));

        $this->assertSame(30, $data['total']);
        $this->assertFalse($data['truncated']);
        $this->assertArrayNotHasKey('next_offset', $data);
    }

    public function test_context_rows_pack_fewer_per_page_than_lean_rows(): void
    {
        $this->seedManyMatches(400);
        $tool = new CodeSearchTool($this->workspace);

        $lean = $this->decode($tool->execute(['pattern' => 'pagedNeedle', 'extension' => 'php']));
        $fat = $this->decode($tool->execute(['pattern' => 'pagedNeedle', 'extension' => 'php', 'context_lines' => 2]));

        $this->assertGreaterThan($fat['total'], $lean['total']);
    }

    public function test_offset_beyond_the_last_match_returns_empty_and_not_truncated(): void
    {
        $this->seedManyMatches(5);
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute(['pattern' => 'pagedNeedle', 'extension' => 'php', 'offset' => 500]));

        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['results']);
        $this->assertFalse($data['truncated']);
    }

    public function test_description_and_path_schema_state_the_resolved_root(): void
    {
        $tool = new CodeSearchTool($this->workspace);
        $root = (string) realpath($this->workspace);

        $this->assertStringContainsString($root, $tool->description());
        $this->assertStringContainsString($root, $tool->inputSchema()['properties']['path']['description']);
        $this->assertArrayHasKey('offset', $tool->inputSchema()['properties']);
    }

    public function test_missing_directory_reports_not_found_not_an_escape(): void
    {
        $tool = new CodeSearchTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('directory not found: nosuchdir');

        $tool->execute(['pattern' => 'targetNeedle', 'path' => 'nosuchdir']);
    }

    public function test_absolute_path_inside_the_workspace_is_accepted(): void
    {
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute([
            'pattern' => 'targetNeedle',
            'extension' => 'php',
            'path' => (string) realpath($this->workspace).'/sub',
        ]));

        $this->assertSame(1, $data['total']);
        $this->assertSame('sub/a.php', $data['results'][0]['file'], 'paths stay relative to the workspace root, not the searched sub-path');
    }

    public function test_the_workspace_root_itself_is_accepted_as_an_absolute_path(): void
    {
        $tool = new CodeSearchTool($this->workspace);
        $data = $this->decode($tool->execute([
            'pattern' => 'targetNeedle',
            'extension' => 'php',
            'path' => (string) realpath($this->workspace),
        ]));

        $this->assertSame(1, $data['total']);
    }

    public function test_absolute_path_outside_the_workspace_is_still_blocked(): void
    {
        $tool = new CodeSearchTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('escapes workspace root');

        $tool->execute(['pattern' => 'targetNeedle', 'path' => '/etc']);
    }

    public function test_parent_traversal_is_still_blocked(): void
    {
        $tool = new CodeSearchTool($this->workspace);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('escapes workspace root');

        $tool->execute(['pattern' => 'targetNeedle', 'path' => '../..']);
    }
}
