<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Claw;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRouter;
use PhpClaw\Tools\ToolRoutingMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolRouterTest extends TestCase
{
    private function schemas(): array
    {
        $descriptions = [
            'product_tool' => 'list and search products in the store catalog',
            'order_tool' => 'create and update customer orders',
            'coupon_tool' => 'manage discount coupons',
        ];
        $names = ['file_read', 'file_write', 'shell_exec', 'http_request', 'code_search', 'project_info', 'zip_package',
            'product_tool', 'order_tool', 'coupon_tool',
            'fixture_tool_1', 'fixture_tool_2', 'fixture_tool_3', 'fixture_tool_4', 'fixture_tool_5'];

        return array_map(
            static fn (string $n): array => [
                'name' => $n,
                'description' => $descriptions[$n] ?? "handles {$n} operations",
                'input_schema' => ['type' => 'object'],
            ],
            $names,
        );
    }

    private function names(array $schemas): array
    {
        return array_map(static fn (array $s): string => (string) ($s['name'] ?? $s['function']['name']), $schemas);
    }

    public function test_haiku_limits_fifteen_tools_to_six(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), 'do something generic', 'claude-haiku-4-5');

        $this->assertCount(6, $out);
    }

    public function test_an_unrelated_message_still_fills_the_budget(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), 'unrelated request', 'claude-haiku-4-5');

        $this->assertCount(6, $out);
    }

    public function test_explicit_camelcase_mention_pins_the_tool(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), 'please use ShellTool to list processes', 'claude-haiku-4-5');

        $this->assertContains('shell_exec', $this->names($out));
    }

    public function test_keyword_overlap_surfaces_the_relevant_tool(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), 'give me the list of products in the store', 'claude-haiku-4-5');

        $this->assertContains('product_tool', $this->names($out));
    }

    public function test_list_at_or_below_limit_is_returned_unchanged(): void
    {
        $few = array_slice($this->schemas(), 0, 4);
        $out = (new ToolRouter(0))->filter($few, 'anything', 'claude-haiku-4-5');

        $this->assertCount(4, $out);
    }

    public function test_explicit_limit_overrides_model_limit(): void
    {
        $out = (new ToolRouter(3))->filter($this->schemas(), 'generic', 'claude-opus-4');

        $this->assertCount(3, $out);
    }

    public function test_openai_function_shape_is_supported(): void
    {
        $oai = array_map(
            static fn (array $s): array => ['type' => 'function', 'function' => [
                'name' => $s['name'], 'description' => $s['description'], 'parameters' => [],
            ]],
            $this->schemas(),
        );

        $out = (new ToolRouter(0))->filter($oai, 'use ShellTool now', 'gpt-4o-mini');
        $names = $this->names($out);

        $this->assertContains('shell_exec', $names);
        $this->assertContains('shell_exec', $names);
    }

    public function test_larger_model_keeps_more_tools(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), 'generic', 'claude-opus-4');

        $this->assertCount(15, $out);
    }

    public function test_snake_case_mention_pins_only_the_named_tool(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), 'run product_tool for the store', 'claude-haiku-4-5');
        $names = $this->names($out);

        self::assertContains('product_tool', $names);
        self::assertNotContains('order_tool', $names, 'a mention of product_tool must not pin order_tool');
    }

    public function test_empty_message_still_returns_limit_worth_of_tools(): void
    {
        $out = (new ToolRouter(0))->filter($this->schemas(), '', 'claude-haiku-4-5');

        self::assertCount(6, $out);
    }

    public function test_claw_injects_a_tool_router_into_the_agent(): void
    {
        $claw = Claw::builder()->provider('anthropic')->apiKey('x')->maxToolsPerTurn(4)->build();
        $agent = (function () {
            return $this->agent;
        })->call($claw);
        $router = (function () {
            return $this->toolRouter;
        })->call($agent);

        $this->assertInstanceOf(ToolRouter::class, $router);
    }

    #[DataProvider('modelLimitTable')]
    public function test_every_model_id_resolves_to_its_documented_tool_limit(string $model, int $expected): void
    {
        $schemas = [];
        for ($n = 0; $n < 40; $n++) {
            $schemas[] = ['name' => 'probe_tool_'.$n, 'description' => 'probe', 'input_schema' => ['type' => 'object']];
        }

        $offered = (new ToolRouter)->filter($schemas, 'unrelated question about nothing', $model);

        self::assertCount(
            $expected,
            $offered,
            sprintf('model "%s" must be offered exactly %d tools', $model, $expected),
        );
    }

    public static function modelLimitTable(): array
    {
        return [
            'haiku fragment' => ['haiku', 6],
            'flash fragment' => ['flash', 6],
            'gpt-3.5 fragment' => ['gpt-3.5', 5],
            'mini fragment' => ['mini', 8],
            'gemini fragment' => ['gemini', 15],
            'sonnet fragment' => ['sonnet', 12],
            'gpt-4o fragment' => ['gpt-4o', 15],
            'opus fragment' => ['opus', 20],

            'claude opus 5' => ['claude-opus-5', 20],
            'claude sonnet 5' => ['claude-sonnet-5', 12],
            'claude haiku 4.5' => ['claude-haiku-4-5-20251001', 6],
            'gpt-4o' => ['gpt-4o', 15],
            'gpt-4o-mini is a mini' => ['gpt-4o-mini', 8],
            'gpt-4 falls to the default' => ['gpt-4', 10],
            'gpt-4-turbo falls to the default' => ['gpt-4-turbo', 10],
            'gpt-3.5-turbo' => ['gpt-3.5-turbo', 5],
            'o1-mini' => ['o1-mini', 8],

            'gemini 1.5 pro is not a mini' => ['gemini-1.5-pro', 15],
            'gemini pro is not a mini' => ['gemini-pro', 15],
            'gemini 2.5 pro is not a mini' => ['gemini-2.5-pro', 15],
            'gemini path-prefixed id is not a mini' => ['models/gemini-1.5-pro-latest', 15],
            'gemini flash stays a flash' => ['gemini-2.0-flash', 6],
            'gemini flash lite stays a flash' => ['gemini-2.0-flash-lite', 6],

            'qwen falls to the default' => ['qwen2.5:7b', 10],
            'llama falls to the default' => ['llama3', 10],
            'mistral falls to the default' => ['mistral-large', 10],
            'deepseek falls to the default' => ['deepseek-chat', 10],
            'empty model falls to the default' => ['', 10],
        ];
    }

    public function test_snake_case_name_parts_match_message_words(): void
    {
        $schemas = [
            ['name' => 'code_search', 'description' => 'Locate occurrences in the tree.'],
            ['name' => 'file_read', 'description' => 'Return the file contents.'],
            ['name' => 'filler_one', 'description' => 'x'], ['name' => 'filler_two', 'description' => 'x'],
            ['name' => 'filler_three', 'description' => 'x'], ['name' => 'filler_four', 'description' => 'x'],
            ['name' => 'filler_five', 'description' => 'x'],
        ];

        $out = (new ToolRouter(maxToolsPerTurn: 1))->filter($schemas, 'search for JPATH_ROOT');

        $this->assertSame(['code_search'], $this->names($out));
    }

    public function test_same_selected_set_returns_identical_order_for_different_messages(): void
    {
        $schemas = [];
        foreach (['shell_exec', 'file_read', 'http_request', 'code_search', 'db_query', 'zip_package', 'project_info'] as $n) {
            $schemas[] = ['name' => $n, 'description' => "The {$n} tool."];
        }
        $router = new ToolRouter(maxToolsPerTurn: 5);

        $a = $this->names($router->filter($schemas, 'shell file http code db'));
        $b = $this->names($router->filter($schemas, 'db code http file shell'));

        $this->assertSame($a, $b, 'same five tools chosen from two messages must come back in identical order');
        $sorted = $a;
        sort($sorted);
        $this->assertSame($sorted, $a, 'trimmed output is sorted by name');
    }

    /**
     * @param  string[]  $intents
     */
    private function routedTool(string $name, string $description, array $intents): ToolInterface
    {
        return new class($name, $description, $intents) implements ToolInterface, ToolRoutingInterface
        {
            public function __construct(
                private readonly string $n,
                private readonly string $d,
                private readonly array $i,
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
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return 'ok';
            }

            public function isEligibleForRouting(): bool
            {
                return true;
            }

            public function routingMetadata(): ToolRoutingMetadata
            {
                return new ToolRoutingMetadata(intents: $this->i);
            }
        };
    }

    public function test_tool_routing_scores_the_user_message_not_the_injected_skill_context(): void
    {
        SkillRegistry::reset();
        $offered = [];
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('anthropic');
        $provider->method('model')->willReturn('claude-haiku-4-5-20251001');
        $provider->method('send')->willReturnCallback(function (array $messages, array $tools) use (&$offered): array {
            $offered = $this->names($tools);

            return ['type' => 'text', 'text' => 'ok', 'input_tokens' => 1, 'output_tokens' => 1];
        });

        $skill = new ArraySkill('publishing', 'how to publish articles', ['publishing'], 'Always call alpha_publish first. alpha_publish handles every publishing step.');

        Claw::builder()
            ->providerOverride($provider)
            ->useDefaultGuards(false)
            ->maxToolsPerTurn(1)
            ->tools([
                $this->routedTool('alpha_publish', 'publish an article', []),
                $this->routedTool('beta_fetch', 'fetch a url', ['fetch url']),
            ])
            ->skills([$skill])
            ->build()
            ->send('fetch url about publishing');

        SkillRegistry::reset();

        $this->assertSame(['beta_fetch'], $offered);
    }
}
