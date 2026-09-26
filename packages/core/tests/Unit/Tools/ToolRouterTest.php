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

    public function test_plural_message_words_match_singular_tags(): void
    {
        $out = (new ToolRouter(1))->filter(
            $this->tiedSchemas('user_tool'),
            'List the editors with their logins.',
            'qwen2.5:7b',
            ['user_tool' => new ToolRoutingMetadata(tags: ['editor', 'login'])],
        );

        $this->assertSame(['user_tool'], $this->names($out));
    }

    public function test_ies_plural_matches_y_singular_tag(): void
    {
        $out = (new ToolRouter(1))->filter(
            $this->tiedSchemas('category_tool'),
            'List the categories.',
            'qwen2.5:7b',
            ['category_tool' => new ToolRoutingMetadata(tags: ['category'])],
        );

        $this->assertSame(['category_tool'], $this->names($out));
    }

    public function test_three_letter_word_ending_in_s_is_not_trimmed(): void
    {
        $out = (new ToolRouter(1))->filter(
            $this->tiedSchemas('gas_tool'),
            'List the gas readings.',
            'qwen2.5:7b',
            ['gas_tool' => new ToolRoutingMetadata(tags: ['gas'])],
        );

        $this->assertSame(['gas_tool'], $this->names($out));
    }

    private function tiedSchemas(string $target): array
    {
        return array_map(
            static fn (string $n): array => ['name' => $n, 'description' => 'list records', 'input_schema' => ['type' => 'object']],
            ['alpha_tool', 'beta_tool', 'delta_tool', $target],
        );
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listHeavyRegistry(): array
    {
        $schemas = [];
        foreach (['a_list', 'b_list', 'c_list', 'd_list', 'e_list', 'f_list', 'g_list', 'h_list'] as $name) {
            $schemas[] = ['name' => $name, 'description' => 'generic helper'];
        }
        $schemas[] = ['name' => 'x_posts', 'description' => 'generic helper'];
        $schemas[] = ['name' => 'y_posts', 'description' => 'generic helper'];

        return $schemas;
    }

    /**
     * @return array<string, ToolRoutingMetadata>
     */
    private function listHeavyMetadata(): array
    {
        $metadata = [];
        foreach (['a_list', 'b_list', 'c_list', 'd_list', 'e_list', 'f_list', 'g_list', 'h_list'] as $name) {
            $metadata[$name] = new ToolRoutingMetadata(intents: ['list items']);
        }
        $metadata['x_posts'] = new ToolRoutingMetadata(intents: ['show posts']);
        $metadata['y_posts'] = new ToolRoutingMetadata(intents: ['show posts']);

        return $metadata;
    }

    public function test_a_word_most_tools_carry_weighs_less_than_a_rare_one(): void
    {
        $out = (new ToolRouter(2))->filter($this->listHeavyRegistry(), 'list posts', 'qwen2.5:7b', $this->listHeavyMetadata());

        $this->assertSame(['x_posts', 'y_posts'], $this->names($out));
    }

    public function test_confidence_rates_a_word_most_tools_carry_below_a_rare_one(): void
    {
        $router = new ToolRouter(2);

        $common = $router->confidence($this->listHeavyRegistry(), 'list', $this->listHeavyMetadata());
        $rare = $router->confidence($this->listHeavyRegistry(), 'posts', $this->listHeavyMetadata());

        $this->assertGreaterThan(0.0, $common);
        $this->assertLessThan($rare, $common);
    }

    public function test_registries_under_three_tools_weigh_every_word_equally(): void
    {
        $schemas = [
            ['name' => 'alpha', 'description' => 'generic helper'],
            ['name' => 'beta', 'description' => 'generic helper'],
        ];
        $metadata = [
            'alpha' => new ToolRoutingMetadata(intents: ['list items']),
            'beta' => new ToolRoutingMetadata(intents: ['list items']),
        ];

        $this->assertGreaterThan(0.0, (new ToolRouter(5))->confidence($schemas, 'list', $metadata));
    }

    public function test_score_floor_drops_tools_far_below_the_best_match(): void
    {
        $schemas = [
            ['name' => 'invoice_lookup', 'description' => 'generic helper'],
            ['name' => 'beta_tool', 'description' => 'generic helper'],
            ['name' => 'gamma_tool', 'description' => 'generic helper'],
            ['name' => 'delta_tool', 'description' => 'generic helper'],
            ['name' => 'epsilon_tool', 'description' => 'generic helper'],
            ['name' => 'zeta_tool', 'description' => 'generic helper'],
        ];
        $metadata = ['invoice_lookup' => new ToolRoutingMetadata(intents: ['look up an invoice'])];

        $withFloor = (new ToolRouter(5, minScoreShare: 0.2))->filter($schemas, 'look up an invoice', 'qwen2.5:7b', $metadata);
        $withoutFloor = (new ToolRouter(5))->filter($schemas, 'look up an invoice', 'qwen2.5:7b', $metadata);

        $this->assertSame(['invoice_lookup'], $this->names($withFloor));
        $this->assertCount(5, $withoutFloor);
    }

    public function test_score_floor_still_fills_the_cap_when_nothing_matches(): void
    {
        $schemas = [
            ['name' => 'alpha_tool', 'description' => 'generic helper'],
            ['name' => 'beta_tool', 'description' => 'generic helper'],
            ['name' => 'gamma_tool', 'description' => 'generic helper'],
        ];

        $out = (new ToolRouter(2, minScoreShare: 0.2))->filter($schemas, 'completely unrelated words', 'qwen2.5:7b');

        $this->assertCount(2, $out);
    }

    public function test_score_floor_keeps_an_explicitly_named_tool(): void
    {
        $schemas = [
            ['name' => 'invoice_lookup', 'description' => 'generic helper'],
            ['name' => 'order_export', 'description' => 'generic helper'],
            ['name' => 'gamma_tool', 'description' => 'generic helper'],
        ];
        $metadata = ['invoice_lookup' => new ToolRoutingMetadata(intents: ['look up an invoice'])];

        $out = (new ToolRouter(2, minScoreShare: 0.2))->filter($schemas, 'look up an invoice with order_export', 'qwen2.5:7b', $metadata);

        $this->assertContains('order_export', $this->names($out));
        $this->assertContains('invoice_lookup', $this->names($out));
    }

    public function test_last_ranked_names_keep_rank_order_while_the_result_is_alphabetical(): void
    {
        $router = new ToolRouter(2);
        $out = $router->filter($this->listHeavyRegistry(), 'show posts please', 'qwen2.5:7b', $this->listHeavyMetadata());

        $this->assertSame(['x_posts', 'y_posts'], $this->names($out));
        $this->assertSame('x_posts', $router->lastRankedNames()[0]);
        $this->assertCount(2, $router->lastRankedNames());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pasteRegistry(): array
    {
        return [
            ['name' => 'wp_query', 'description' => 'generic helper'],
            ['name' => 'wc_get_customer', 'description' => 'generic helper'],
            ['name' => 'wc_reviews', 'description' => 'generic helper'],
            ['name' => 'wp_menus', 'description' => 'generic helper'],
        ];
    }

    /**
     * @return array<string, ToolRoutingMetadata>
     */
    private function pasteMetadata(): array
    {
        return [
            'wp_query' => new ToolRoutingMetadata(intents: ['list posts']),
            'wc_get_customer' => new ToolRoutingMetadata(intents: ['customer statistics']),
            'wc_reviews' => new ToolRoutingMetadata(intents: ['customer feedback']),
            'wp_menus' => new ToolRoutingMetadata(intents: ['navigation notes']),
        ];
    }

    private function paste(int $chars): string
    {
        return substr(str_repeat('Meeting notes: customer feedback and navigation plans for the quarter. ', 400), 0, $chars);
    }

    public function test_long_paste_after_the_request_does_not_outrank_it(): void
    {
        $out = (new ToolRouter(1))->filter($this->pasteRegistry(), 'List the latest posts. My notes: '.$this->paste(6000), 'qwen2.5:7b', $this->pasteMetadata());

        $this->assertSame(['wp_query'], $this->names($out));
    }

    public function test_long_paste_before_the_request_does_not_outrank_it(): void
    {
        $out = (new ToolRouter(1))->filter($this->pasteRegistry(), $this->paste(6000).' Ignore the notes above. List the latest posts.', 'qwen2.5:7b', $this->pasteMetadata());

        $this->assertSame(['wp_query'], $this->names($out));
    }

    public function test_messages_up_to_the_threshold_are_scored_whole(): void
    {
        $neutral = str_repeat('lorem ipsum dolor sit amet. ', 30);
        $out = (new ToolRouter(1))->filter($this->pasteRegistry(), $neutral.'List the latest posts. '.$neutral, 'qwen2.5:7b', $this->pasteMetadata());

        $this->assertLessThanOrEqual(2000, strlen($neutral.'List the latest posts. '.$neutral));
        $this->assertSame(['wp_query'], $this->names($out));
    }
}
