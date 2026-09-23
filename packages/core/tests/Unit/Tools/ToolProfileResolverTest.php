<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolProfileResolver;
use PHPUnit\Framework\TestCase;

final class ToolProfileResolverTest extends TestCase
{
    public function test_cloud_providers_resolve_to_full(): void
    {
        $this->assertSame('full', ToolProfileResolver::resolve('anthropic', 'claude-sonnet-4-20250514'));
        $this->assertSame('full', ToolProfileResolver::resolve('openai', 'gpt-4o'));
        $this->assertSame('full', ToolProfileResolver::resolve('gemini', 'gemini-1.5-pro'));
        $this->assertSame('full', ToolProfileResolver::resolve('mistral', 'mistral-large'));
        $this->assertSame('full', ToolProfileResolver::resolve('deepseek', 'deepseek-chat'));
    }

    public function test_unknown_providers_resolve_to_full(): void
    {
        $this->assertSame('full', ToolProfileResolver::resolve('cohere', 'command-r-plus'));
        $this->assertSame('full', ToolProfileResolver::resolve('fireworks', 'any-model'));
        $this->assertSame('full', ToolProfileResolver::resolve('together', 'mixtral'));
    }

    public function test_ollama_small_models_resolve_to_minimal(): void
    {
        $this->assertSame('minimal', ToolProfileResolver::resolve('ollama', 'qwen2.5:7b'));
        $this->assertSame('minimal', ToolProfileResolver::resolve('ollama', 'phi3:3b'));
        $this->assertSame('minimal', ToolProfileResolver::resolve('ollama', 'llama3.2:8b'));
        $this->assertSame('minimal', ToolProfileResolver::resolve('ollama', 'qwen2.5:14b'));
    }

    public function test_ollama_medium_models_resolve_to_standard(): void
    {
        $this->assertSame('standard', ToolProfileResolver::resolve('ollama', 'llama3.1:70b'));
        $this->assertSame('standard', ToolProfileResolver::resolve('ollama', 'deepseek-v2:72b'));
        $this->assertSame('standard', ToolProfileResolver::resolve('ollama', 'qwen:65b'));
    }

    public function test_groq_small_models_resolve_to_minimal(): void
    {
        $this->assertSame('minimal', ToolProfileResolver::resolve('groq', 'llama-3.1-8b-instant'));
    }

    public function test_groq_large_models_resolve_to_standard(): void
    {
        $this->assertSame('standard', ToolProfileResolver::resolve('groq', 'llama-3.1-70b-versatile'));
    }

    public function test_max_tools_minimal(): void
    {
        $this->assertSame(5, ToolProfileResolver::maxTools('minimal'));
    }

    public function test_max_tools_standard(): void
    {
        $this->assertSame(8, ToolProfileResolver::maxTools('standard'));
    }

    public function test_max_tools_full(): void
    {
        $this->assertSame(0, ToolProfileResolver::maxTools('full'));
    }

    public function test_max_tools_unknown_profile_returns_zero(): void
    {
        $this->assertSame(0, ToolProfileResolver::maxTools('nonexistent'));
    }

    public function test_minimal_profile_reports_a_budget_of_5_and_no_longer_slices(): void
    {
        $tools = $this->makeFakeTools(13);

        $result = ToolProfileResolver::filter($tools);

        $this->assertCount(13, $result);
        $this->assertSame(5, ToolProfileResolver::maxTools(ToolProfileResolver::resolve('ollama', 'qwen2.5:7b')));
    }

    public function test_standard_profile_reports_a_budget_of_8_and_no_longer_slices(): void
    {
        $tools = $this->makeFakeTools(13);

        $result = ToolProfileResolver::filter($tools);

        $this->assertCount(13, $result);
        $this->assertSame(8, ToolProfileResolver::maxTools(ToolProfileResolver::resolve('ollama', 'llama3.1:70b')));
    }

    public function test_full_profile_keeps_all_tools(): void
    {
        $tools = $this->makeFakeTools(13);

        $result = ToolProfileResolver::filter($tools);

        $this->assertCount(13, $result);
    }

    public function test_fewer_tools_than_limit_keeps_all(): void
    {
        $tools = $this->makeFakeTools(3);

        $result = ToolProfileResolver::filter($tools);

        $this->assertCount(3, $result);
    }

    public function test_empty_tools_returns_empty(): void
    {
        $result = ToolProfileResolver::filter([]);

        $this->assertCount(0, $result);
    }

    public function test_deny_removes_single_tool(): void
    {
        $tools = $this->makeNamedTools(['tool_a', 'tool_b', 'tool_c']);

        $result = ToolProfileResolver::filter($tools, ['tool_b']);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('tool_b', $names);
        $this->assertCount(2, $result);
    }

    public function test_deny_removes_multiple_tools(): void
    {
        $tools = $this->makeNamedTools(['tool_a', 'tool_b', 'tool_c', 'tool_d']);

        $result = ToolProfileResolver::filter($tools, ['tool_b', 'tool_d']);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('tool_b', $names);
        $this->assertNotContains('tool_d', $names);
        $this->assertCount(2, $result);
    }

    public function test_empty_deny_removes_nothing(): void
    {
        $tools = $this->makeFakeTools(5);

        $result = ToolProfileResolver::filter($tools, []);

        $this->assertCount(5, $result);
    }

    public function test_deny_nonexistent_tool_has_no_effect(): void
    {
        $tools = $this->makeNamedTools(['tool_a', 'tool_b']);

        $result = ToolProfileResolver::filter($tools, ['tool_z']);

        $this->assertCount(2, $result);
    }

    public function test_deny_group_resolves_to_tool_names(): void
    {
        $tools = $this->makeNamedTools(['posts', 'users', 'database', 'logs']);
        $groups = [
            'group:system' => ['database', 'logs'],
        ];

        $result = ToolProfileResolver::filter($tools, ['group:system'], $groups);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('database', $names);
        $this->assertNotContains('logs', $names);
        $this->assertContains('posts', $names);
        $this->assertContains('users', $names);
    }

    public function test_deny_mix_of_group_and_individual(): void
    {
        $tools = $this->makeNamedTools(['posts', 'users', 'database', 'logs', 'media']);
        $groups = [
            'group:system' => ['database', 'logs'],
        ];

        $result = ToolProfileResolver::filter($tools, ['group:system', 'media'], $groups);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('database', $names);
        $this->assertNotContains('logs', $names);
        $this->assertNotContains('media', $names);
        $this->assertCount(2, $result);
    }

    public function test_deny_unknown_group_treated_as_tool_name(): void
    {
        $tools = $this->makeNamedTools(['posts', 'users', 'group:unknown']);
        $groups = ['group:system' => ['database']];

        $result = ToolProfileResolver::filter($tools, ['group:unknown'], $groups);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('group:unknown', $names);
        $this->assertCount(2, $result);
    }

    public function test_deny_still_removes_named_tools_without_slicing_the_rest(): void
    {
        $tools = $this->makeNamedTools(['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8', 't9', 't10']);

        $result = ToolProfileResolver::filter($tools, ['t3']);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertCount(9, $result);
        $this->assertNotContains('t3', $names);
        $this->assertContains('t6', $names);
    }

    private function makeFakeTools(int $count): array
    {
        return array_map(
            fn (int $i) => $this->createFakeTool("tool_{$i}"),
            range(1, $count),
        );
    }

    private function makeNamedTools(array $names): array
    {
        return array_map(
            fn (string $name) => $this->createFakeTool($name),
            $names,
        );
    }

    private function createFakeTool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface
        {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return '';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };
    }
}
