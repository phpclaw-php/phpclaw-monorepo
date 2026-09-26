<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry;
    }

    private function makeTool(string $name, string $description = 'Test tool', array $schema = []): ToolInterface
    {
        return new class($name, $description, $schema) implements ToolInterface
        {
            public function __construct(
                private readonly string $n,
                private readonly string $d,
                private readonly array $s,
            ) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return $this->d;
            }

            public function inputSchema(): array
            {
                return $this->s;
            }

            public function execute(array $input): string
            {
                return 'result';
            }
        };
    }

    public function test_register_single_tool(): void
    {
        $tool = $this->makeTool('shell_exec');
        $this->registry->register([$tool]);

        $this->assertTrue($this->registry->has('shell_exec'));
        $this->assertSame(1, $this->registry->count());
    }

    public function test_register_multiple_tools(): void
    {
        $this->registry->register([
            $this->makeTool('tool_a'),
            $this->makeTool('tool_b'),
            $this->makeTool('tool_c'),
        ]);

        $this->assertSame(3, $this->registry->count());
        $this->assertTrue($this->registry->has('tool_a'));
        $this->assertTrue($this->registry->has('tool_b'));
        $this->assertTrue($this->registry->has('tool_c'));
    }

    public function test_has_returns_false_for_unregistered_tool(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function test_get_returns_correct_tool(): void
    {
        $tool = $this->makeTool('my_tool');
        $this->registry->register([$tool]);

        $this->assertSame($tool, $this->registry->get('my_tool'));
    }

    public function test_all_returns_registered_tools_as_list(): void
    {
        $a = $this->makeTool('a');
        $b = $this->makeTool('b');
        $this->registry->register([$a, $b]);

        $all = $this->registry->all();
        $this->assertCount(2, $all);
        $this->assertContains($a, $all);
        $this->assertContains($b, $all);
    }

    public function test_count_returns_zero_for_empty_registry(): void
    {
        $this->assertSame(0, $this->registry->count());
    }

    public function test_register_overwrites_duplicate_tool_name(): void
    {
        $first = $this->makeTool('my_tool', 'First');
        $second = $this->makeTool('my_tool', 'Second');
        $this->registry->register([$first, $second]);

        $this->assertSame(1, $this->registry->count());
        $this->assertSame('Second', $this->registry->get('my_tool')->description());
    }

    public function test_schemas_returns_empty_array_when_no_tools_registered(): void
    {
        $this->assertSame([], $this->registry->schemas('anthropic'));
        $this->assertSame([], $this->registry->schemas('openai'));
    }

    public function test_schemas_anthropic_format(): void
    {
        $schema = ['type' => 'object', 'properties' => ['command' => ['type' => 'string']]];
        $this->registry->register([$this->makeTool('shell_exec', 'Run shell command', $schema)]);

        $schemas = $this->registry->schemas('anthropic');

        $this->assertCount(1, $schemas);
        $this->assertSame('shell_exec', $schemas[0]['name']);
        $this->assertSame('Run shell command', $schemas[0]['description']);
        $this->assertSame($schema, $schemas[0]['input_schema']);
        $this->assertArrayNotHasKey('type', $schemas[0]);
        $this->assertArrayNotHasKey('function', $schemas[0]);
    }

    public function test_schemas_openai_format(): void
    {
        $schema = ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]];
        $this->registry->register([$this->makeTool('http_get', 'Fetch URL', $schema)]);

        $schemas = $this->registry->schemas('openai');

        $this->assertCount(1, $schemas);
        $this->assertSame('function', $schemas[0]['type']);
        $this->assertArrayHasKey('function', $schemas[0]);
        $this->assertSame('http_get', $schemas[0]['function']['name']);
        $this->assertSame('Fetch URL', $schemas[0]['function']['description']);
        $this->assertSame($schema, $schemas[0]['function']['parameters']);
    }

    public function test_schemas_groq_format_matches_openai(): void
    {
        $this->registry->register([$this->makeTool('tool_x', 'Tool X')]);

        $openai = $this->registry->schemas('openai');
        $groq = $this->registry->schemas('groq');

        $this->assertSame($openai, $groq);
    }

    public function test_schemas_gemini_format_matches_openai(): void
    {
        $this->registry->register([$this->makeTool('tool_y', 'Tool Y')]);

        $openai = $this->registry->schemas('openai');
        $gemini = $this->registry->schemas('gemini');

        $this->assertSame($openai, $gemini);
    }

    public function test_schemas_mistral_format_matches_openai(): void
    {
        $this->registry->register([$this->makeTool('tool_m', 'Mistral tool')]);

        $openai = $this->registry->schemas('openai');
        $mistral = $this->registry->schemas('mistral');

        $this->assertSame($openai, $mistral);
        $this->assertSame('function', $mistral[0]['type']);
        $this->assertArrayHasKey('function', $mistral[0]);
        $this->assertArrayNotHasKey('input_schema', $mistral[0]);
    }

    public function test_schemas_ollama_format_matches_openai(): void
    {
        $this->registry->register([$this->makeTool('tool_o', 'Ollama tool')]);

        $openai = $this->registry->schemas('openai');
        $ollama = $this->registry->schemas('ollama');

        $this->assertSame($openai, $ollama);
        $this->assertSame('function', $ollama[0]['type']);
        $this->assertArrayHasKey('function', $ollama[0]);
        $this->assertArrayNotHasKey('input_schema', $ollama[0]);
    }

    public function test_schemas_unknown_provider_falls_back_to_anthropic_shape(): void
    {
        $schema = ['type' => 'object'];
        $this->registry->register([$this->makeTool('tool_z', 'Z', $schema)]);

        $schemas = $this->registry->schemas('unknown_provider');

        $this->assertArrayHasKey('input_schema', $schemas[0]);
        $this->assertSame($schema, $schemas[0]['input_schema']);
    }

    public function test_schemas_returns_all_tools(): void
    {
        $this->registry->register([
            $this->makeTool('tool_1', 'One'),
            $this->makeTool('tool_2', 'Two'),
            $this->makeTool('tool_3', 'Three'),
        ]);

        $schemas = $this->registry->schemas('anthropic');

        $this->assertCount(3, $schemas);
        $names = array_column($schemas, 'name');
        $this->assertContains('tool_1', $names);
        $this->assertContains('tool_2', $names);
        $this->assertContains('tool_3', $names);
    }

    public function test_schemas_memoized_returns_identical_result_on_repeat_call(): void
    {
        $this->registry->register([$this->makeTool('shell_exec', 'Run shell command')]);

        $first = $this->registry->schemas('anthropic');
        $second = $this->registry->schemas('anthropic');

        $this->assertSame($first, $second);
    }

    public function test_schemas_cache_is_per_provider(): void
    {
        $this->registry->register([$this->makeTool('tool_p', 'Provider tool')]);

        $anthropic = $this->registry->schemas('anthropic');
        $openai = $this->registry->schemas('openai');

        $this->assertArrayHasKey('input_schema', $anthropic[0]);
        $this->assertArrayHasKey('function', $openai[0]);
    }

    public function test_register_invalidates_schemas_cache(): void
    {
        $this->registry->register([$this->makeTool('tool_a', 'A')]);
        $before = $this->registry->schemas('anthropic');
        $this->assertCount(1, $before);

        $this->registry->register([$this->makeTool('tool_b', 'B')]);
        $after = $this->registry->schemas('anthropic');

        $this->assertCount(2, $after);
        $names = array_column($after, 'name');
        $this->assertContains('tool_a', $names);
        $this->assertContains('tool_b', $names);
    }

    public function test_register_applies_deny_list_by_name(): void
    {
        $this->registry->register(
            [$this->makeTool('shell_exec'), $this->makeTool('file_read')],
            deny: ['shell_exec'],
        );

        $this->assertFalse($this->registry->has('shell_exec'));
        $this->assertTrue($this->registry->has('file_read'));
    }

    public function test_register_expands_group_reference_when_denying(): void
    {
        $this->registry->register(
            [$this->makeTool('shell_exec'), $this->makeTool('http_request'), $this->makeTool('file_read')],
            deny: ['group:system'],
            groups: ['group:system' => ['shell_exec', 'http_request']],
        );

        $this->assertFalse($this->registry->has('shell_exec'));
        $this->assertFalse($this->registry->has('http_request'));
        $this->assertTrue($this->registry->has('file_read'));
    }

    public function test_register_with_empty_deny_registers_everything(): void
    {
        $this->registry->register(
            [$this->makeTool('tool_a'), $this->makeTool('tool_b')],
            deny: [],
            groups: ['group:system' => ['tool_a']],
        );

        $this->assertTrue($this->registry->has('tool_a'));
        $this->assertTrue($this->registry->has('tool_b'));
    }

    public function test_register_logs_when_a_group_references_an_unregistered_tool(): void
    {
        $output = $this->captureErrorLog(function (): void {
            $this->registry->register(
                [$this->makeTool('shell_exec')],
                deny: [],
                groups: ['group:system' => ['shell_exec', 'http_get']],
            );
        });

        $this->assertStringContainsString("group 'group:system'", $output);
        $this->assertStringContainsString("unregistered tool 'http_get'", $output);
        $this->assertStringNotContainsString('shell_exec', $this->extractDanglingMemberLines($output));
    }

    public function test_register_does_not_log_when_every_group_member_is_registered(): void
    {
        $output = $this->captureErrorLog(function (): void {
            $this->registry->register(
                [$this->makeTool('shell_exec'), $this->makeTool('http_request')],
                deny: [],
                groups: ['group:system' => ['shell_exec', 'http_request']],
            );
        });

        $this->assertSame('', $output);
    }

    private function captureErrorLog(callable $fn): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw-registry-test');
        $prev = ini_get('error_log');
        ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        $contents = (string) file_get_contents($file);
        @unlink($file);

        return $contents;
    }

    private function extractDanglingMemberLines(string $output): string
    {
        $lines = array_filter(
            explode("\n", $output),
            static fn (string $line): bool => str_contains($line, 'unregistered tool'),
        );

        return implode("\n", $lines);
    }

    public function test_lean_schemas_send_only_the_first_description_line(): void
    {
        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('lean_probe', "Read the thing.\nMODES\n  long explanation follows")]);

        $anthropicLean = $registry->schemas('anthropic', lean: true);
        $anthropicFull = $registry->schemas('anthropic');
        $openAiLean = $registry->schemas('openai', lean: true);

        $this->assertSame('Read the thing.', $anthropicLean[0]['description']);
        $this->assertStringContainsString('long explanation follows', $anthropicFull[0]['description']);
        $this->assertSame('Read the thing.', $openAiLean[0]['function']['description']);
        $this->assertSame($anthropicFull[0]['input_schema'], $anthropicLean[0]['input_schema']);
    }
}
