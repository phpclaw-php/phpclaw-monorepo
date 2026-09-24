<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ProjectTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ToolRouter;
use PhpClaw\Tools\ToolRoutingMetadata;
use PHPUnit\Framework\TestCase;

final class ToolRoutingTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $schemas
     * @return string[]
     */
    private function names(array $schemas): array
    {
        return array_map(static fn (array $s): string => (string) $s['name'], $schemas);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manySchemas(int $count, string $relevantName, string $relevantDescription): array
    {
        $out = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $out[] = ['name' => sprintf('filler_%02d', $i), 'description' => 'unrelated filler tool'];
        }

        $out[] = ['name' => $relevantName, 'description' => $relevantDescription];

        return $out;
    }

    public function test_relevant_tool_declared_last_is_selected(): void
    {
        $schemas = $this->manySchemas(30, 'invoice_lookup', 'Look up a customer invoice by number.');

        $out = (new ToolRouter(5))->filter($schemas, 'look up an invoice for a customer', 'gpt-3.5-turbo');

        self::assertCount(5, $out);
        self::assertContains('invoice_lookup', $this->names($out));
    }

    public function test_intent_match_beats_description_only_match(): void
    {
        $schemas = [
            ['name' => 'alpha_tool', 'description' => 'publish an article to the site'],
            ['name' => 'beta_tool', 'description' => 'unrelated helper'],
        ];

        $metadata = [
            'beta_tool' => new ToolRoutingMetadata(intents: ['publish article']),
        ];

        $out = (new ToolRouter(1))->filter($schemas, 'publish article now', 'gpt-3.5-turbo', $metadata);

        self::assertSame(['beta_tool'], $this->names($out));
    }

    public function test_domain_outranks_tag(): void
    {
        $schemas = [
            ['name' => 'aaa_tool', 'description' => ''],
            ['name' => 'bbb_tool', 'description' => ''],
        ];

        $metadata = [
            'aaa_tool' => new ToolRoutingMetadata(tags: ['catalog']),
            'bbb_tool' => new ToolRoutingMetadata(domains: ['catalog']),
        ];

        $out = (new ToolRouter(1))->filter($schemas, 'catalog question', 'gpt-3.5-turbo', $metadata);

        self::assertSame(['bbb_tool'], $this->names($out));
    }

    public function test_explicit_mention_outranks_every_other_signal(): void
    {
        $schemas = [
            ['name' => 'winner_tool', 'description' => 'nothing relevant at all'],
            ['name' => 'loser_tool', 'description' => 'publish an article to the site'],
        ];

        $metadata = [
            'loser_tool' => new ToolRoutingMetadata(intents: ['publish article'], domains: ['content']),
        ];

        $out = (new ToolRouter(1))->filter($schemas, 'publish article using winner_tool', 'gpt-3.5-turbo', $metadata);

        self::assertSame(['winner_tool'], $this->names($out));
    }

    public function test_unlimited_budget_returns_every_schema(): void
    {
        $schemas = $this->manySchemas(40, 'tail_tool', 'last one');

        $out = (new ToolRouter(ToolRouter::UNLIMITED))->filter($schemas, 'anything', 'gpt-3.5-turbo');

        self::assertCount(40, $out);
    }

    public function test_equal_scores_break_on_name_not_declaration_order(): void
    {
        $schemas = [
            ['name' => 'zzz_tool', 'description' => 'same words here'],
            ['name' => 'aaa_tool', 'description' => 'same words here'],
            ['name' => 'mmm_tool', 'description' => 'same words here'],
        ];

        $out = (new ToolRouter(2))->filter($schemas, 'completely unrelated', 'gpt-3.5-turbo');

        self::assertSame(['aaa_tool', 'mmm_tool'], $this->names($out));
    }

    public function test_weak_match_reports_low_confidence(): void
    {
        $schemas = [['name' => 'alpha_tool', 'description' => 'unrelated helper']];

        $confidence = (new ToolRouter(5))->confidence($schemas, 'something entirely different');

        self::assertSame(0.0, $confidence);
    }

    public function test_strong_match_reports_higher_confidence_than_a_weak_one(): void
    {
        $schemas = [['name' => 'invoice_lookup', 'description' => 'look up an invoice']];
        $metadata = ['invoice_lookup' => new ToolRoutingMetadata(intents: ['invoice lookup'], domains: ['invoice'])];

        $router = new ToolRouter(5);

        $strong = $router->confidence($schemas, 'invoice lookup please', $metadata);
        $weak = $router->confidence($schemas, 'weather forecast', $metadata);

        self::assertGreaterThan($weak, $strong);
        self::assertLessThanOrEqual(1.0, $strong);
    }

    public function test_tool_without_routing_metadata_is_still_rankable(): void
    {
        $schemas = [
            ['name' => 'alpha_tool', 'description' => 'reads invoices from the database'],
            ['name' => 'beta_tool', 'description' => 'unrelated helper'],
        ];

        $out = (new ToolRouter(1))->filter($schemas, 'read an invoice', 'gpt-3.5-turbo');

        self::assertSame(['alpha_tool'], $this->names($out));
    }

    public function test_a_tool_not_implementing_the_routing_contract_is_eligible(): void
    {
        $plain = new class
        {
            public function name(): string
            {
                return 'plain_tool';
            }
        };

        self::assertNotInstanceOf(ToolRoutingInterface::class, $plain);
    }

    private function authorizer(bool $allows, bool $console): ToolAuthorizerInterface
    {
        return new class($allows, $console) implements ToolAuthorizerInterface
        {
            public function __construct(private readonly bool $allows, private readonly bool $console) {}

            public function allows(): bool
            {
                return $this->allows;
            }

            public function runningInConsole(): bool
            {
                return $this->console;
            }
        };
    }

    public function test_an_ineligible_tool_is_absent_from_registry_schemas(): void
    {
        $tool = new ProjectTool(sys_get_temp_dir());
        $tool->withAuthorizer($this->authorizer(allows: false, console: false));

        $registry = new ToolRegistry;
        $registry->register([$tool]);

        self::assertSame([], $registry->schemas('anthropic'));
    }

    public function test_a_console_caller_makes_the_tool_eligible_even_when_denied(): void
    {
        $tool = new ProjectTool(sys_get_temp_dir());
        $tool->withAuthorizer($this->authorizer(allows: false, console: true));

        $registry = new ToolRegistry;
        $registry->register([$tool]);

        self::assertCount(1, $registry->schemas('anthropic'));
    }

    public function test_a_tool_with_no_authorizer_is_eligible(): void
    {
        $registry = new ToolRegistry;
        $registry->register([new ProjectTool(sys_get_temp_dir())]);

        self::assertCount(1, $registry->schemas('anthropic'));
    }

    public function test_registry_exposes_routing_metadata_for_core_tools(): void
    {
        $registry = new ToolRegistry;
        $registry->register([new ProjectTool(sys_get_temp_dir())]);

        $metadata = $registry->routingMetadata();

        self::assertArrayHasKey('project_info', $metadata);
        self::assertContains('framework', $metadata['project_info']->tags);
    }

    public function test_empty_metadata_reports_itself_empty(): void
    {
        self::assertTrue(ToolRoutingMetadata::empty()->isEmpty());
        self::assertFalse((new ToolRoutingMetadata(tags: ['x']))->isEmpty());
    }

    public function test_disk_usage_prompt_keeps_shell_exec_inside_a_five_tool_budget(): void
    {
        $shell = new ShellTool;
        $schemas = [];

        foreach (['cache_inspect', 'config_get', 'db_query', 'file_read', 'file_write', 'queue_status', 'read_log', 'route_list'] as $name) {
            $schemas[] = ['name' => $name, 'description' => 'unrelated filler tool'];
        }

        $schemas[] = ['name' => $shell->name(), 'description' => $shell->description()];

        $out = (new ToolRouter(5))->filter($schemas, 'Check disk usage', 'qwen2.5:7b', [$shell->name() => $shell->routingMetadata()]);

        self::assertCount(5, $out);
        self::assertContains('shell_exec', $this->names($out));
    }
}
