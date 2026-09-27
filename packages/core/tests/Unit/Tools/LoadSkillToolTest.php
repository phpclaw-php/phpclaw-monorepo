<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Tools\Support\FakeToolAuthorizer;
use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;
use PhpClaw\Tools\LoadSkillTool;
use PHPUnit\Framework\TestCase;

final class LoadSkillToolTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    public function test_it_returns_the_body_of_the_named_skill_in_a_success_envelope(): void
    {
        SkillRegistry::register(new ArraySkill('wp_plugin_creator', 'WordPress plugin generation rules', ['plugin'], 'PLUGIN RULES BODY'));

        $result = json_decode((new LoadSkillTool)->execute(['name' => 'wp_plugin_creator']), true);

        $this->assertTrue($result['success']);
        $this->assertSame(['name' => 'wp_plugin_creator', 'instructions' => 'PLUGIN RULES BODY'], $result['data']);
        $this->assertSame(['mode' => 'load_skill'], $result['meta']);
    }

    public function test_an_unknown_name_returns_an_error_with_the_available_names(): void
    {
        SkillRegistry::register(new ArraySkill('xlsx', 'Spreadsheets', ['excel'], 'BODY'));

        $result = json_decode((new LoadSkillTool)->execute(['name' => 'nope']), true);

        $this->assertFalse($result['success']);
        $this->assertSame('UNKNOWN_SKILL', $result['error']['code']);
        $this->assertSame(['xlsx'], $result['error']['available']);
    }

    public function test_a_missing_name_returns_the_unknown_skill_error(): void
    {
        SkillRegistry::register(new ArraySkill('xlsx', 'Spreadsheets', ['excel'], 'BODY'));

        $this->assertSame('UNKNOWN_SKILL', json_decode((new LoadSkillTool)->execute([]), true)['error']['code']);
    }

    public function test_an_unknown_argument_is_rejected_before_any_lookup(): void
    {
        SkillRegistry::register(new ArraySkill('xlsx', 'Spreadsheets', ['excel'], 'BODY'));

        $result = json_decode((new LoadSkillTool)->execute(['name' => 'xlsx', 'path' => '/etc/passwd']), true);

        $this->assertSame('UNKNOWN_ARGUMENT', $result['error']['code']);
    }

    public function test_a_caller_without_the_capability_is_refused(): void
    {
        SkillRegistry::register(new ArraySkill('xlsx', 'Spreadsheets', ['excel'], 'BODY'));
        $tool = new LoadSkillTool;
        $tool->withAuthorizer(new FakeToolAuthorizer(allows: false));

        $result = json_decode($tool->execute(['name' => 'xlsx']), true);

        $this->assertSame('FORBIDDEN', $result['error']['code']);
        $this->assertFalse($tool->isEligibleForRouting());
    }

    public function test_the_console_reaches_the_skill_without_the_capability(): void
    {
        SkillRegistry::register(new ArraySkill('xlsx', 'Spreadsheets', ['excel'], 'BODY'));
        $tool = new LoadSkillTool;
        $tool->withAuthorizer(new FakeToolAuthorizer(allows: false, console: true));

        $this->assertTrue(json_decode($tool->execute(['name' => 'xlsx']), true)['success']);
    }

    public function test_it_requires_the_core_tool_capability(): void
    {
        $this->assertSame(ToolAuthorizerInterface::CAPABILITY, (new LoadSkillTool)->requiredCapability());
    }

    public function test_the_routing_metadata_names_the_skills_domain(): void
    {
        $this->assertSame(['skills'], (new LoadSkillTool)->routingMetadata()->domains);
    }

    public function test_the_system_prompt_section_is_empty_without_skills(): void
    {
        $this->assertSame('', LoadSkillTool::systemPromptSection());
    }

    public function test_the_system_prompt_section_lists_every_skill_with_its_load_call(): void
    {
        SkillRegistry::register(new ArraySkill('wp_plugin_creator', 'WordPress plugin generation rules', ['plugin'], 'A'));
        SkillRegistry::register(new ArraySkill('xlsx', 'Spreadsheet work', ['excel'], 'B'));

        $section = LoadSkillTool::systemPromptSection();

        $this->assertStringContainsString('## Skills System', $section);
        $this->assertStringContainsString('- **wp_plugin_creator**: WordPress plugin generation rules', $section);
        $this->assertStringContainsString('Call `load_skill` with name `xlsx` for full instructions', $section);
    }

    public function test_the_tool_contract(): void
    {
        $tool = new LoadSkillTool;

        $this->assertSame('load_skill', $tool->name());
        $this->assertSame(['name'], $tool->inputSchema()['required']);
    }
}
