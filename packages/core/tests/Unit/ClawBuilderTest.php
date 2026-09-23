<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit;

use PhpClaw\Claw;
use PhpClaw\ClawBuilder;
use PhpClaw\ClawConfig;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class ClawBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-test';
        HookRegistry::reset();
        GuardRegistry::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ANTHROPIC_API_KEY']);
        HookRegistry::reset();
        GuardRegistry::reset();
    }

    public function test_build_with_no_setters_returns_phpclaw_instance(): void
    {
        $claw = Claw::builder()->build();
        $this->assertInstanceOf(Claw::class, $claw);
    }

    public function test_builder_method_returns_builder(): void
    {
        $this->assertInstanceOf(ClawBuilder::class, Claw::builder());
    }

    public function test_api_key_setter_threads_through_to_config(): void
    {
        $claw = Claw::builder()->apiKey('sk-explicit')->provider('openai')->build();
        $this->assertSame('sk-explicit', $claw->config()->apiKey);
    }

    public function test_provider_setter_threads_through(): void
    {
        $claw = Claw::builder()->apiKey('sk-x')->provider('groq')->build();
        $this->assertSame('groq', $claw->config()->providerName);
    }

    public function test_model_setter_threads_through(): void
    {
        $claw = Claw::builder()->model('claude-sonnet-test')->build();
        $this->assertSame('claude-sonnet-test', $claw->config()->model);
    }

    public function test_store_messages_default_true(): void
    {
        $claw = Claw::builder()->build();
        $this->assertTrue($claw->storeMessages());
    }

    public function test_store_messages_can_be_disabled(): void
    {
        $claw = Claw::builder()->storeMessages(false)->build();
        $this->assertFalse($claw->storeMessages());
    }

    public function test_store_messages_default_arg_is_true(): void
    {
        $claw = Claw::builder()->storeMessages()->build();
        $this->assertTrue($claw->storeMessages());
    }

    public function test_max_iterations_default_is_constant(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame(ClawConfig::DEFAULT_MAX_ITERATIONS, $claw->config()->maxIterations);
    }

    public function test_max_iterations_setter_threads_through(): void
    {
        $claw = Claw::builder()->maxIterations(42)->build();
        $this->assertSame(42, $claw->config()->maxIterations);
    }

    public function test_max_retries_default_zero(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame(0, $claw->config()->maxRetries);
    }

    public function test_max_retries_setter_threads_through(): void
    {
        $claw = Claw::builder()->maxRetries(3)->build();
        $this->assertSame(3, $claw->config()->maxRetries);
    }

    public function test_max_history_length_default_zero(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame(0, $claw->config()->maxHistoryLength);
    }

    public function test_max_history_length_setter_threads_through(): void
    {
        $claw = Claw::builder()->maxHistoryLength(50)->build();
        $this->assertSame(50, $claw->config()->maxHistoryLength);
    }

    public function test_max_tokens_default_zero(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame(0, $claw->config()->maxTokens);
    }

    public function test_max_tokens_setter_threads_through(): void
    {
        $claw = Claw::builder()->maxTokens(8000)->build();
        $this->assertSame(8000, $claw->config()->maxTokens);
    }

    public function test_prompt_cache_defaults_to_true(): void
    {
        $claw = Claw::builder()->build();
        $this->assertTrue($claw->config()->promptCache);
    }

    public function test_prompt_cache_setter_threads_through(): void
    {
        $claw = Claw::builder()->promptCache(true)->build();
        $this->assertTrue($claw->config()->promptCache);
    }

    public function test_prompt_cache_default_arg_is_true(): void
    {
        $claw = Claw::builder()->promptCache()->build();
        $this->assertTrue($claw->config()->promptCache);
    }

    public function test_thinking_budget_default_zero(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame(0, $claw->config()->thinkingBudget);
    }

    public function test_thinking_budget_setter_threads_through(): void
    {
        $claw = Claw::builder()->thinkingBudget(8192)->build();
        $this->assertSame(8192, $claw->config()->thinkingBudget);
    }

    public function test_use_default_guards_default_true(): void
    {
        $claw = Claw::builder()->build();
        $this->assertTrue($claw->config()->useDefaultGuards);
    }

    public function test_use_default_guards_can_be_disabled(): void
    {
        $claw = Claw::builder()->useDefaultGuards(false)->build();
        $this->assertFalse($claw->config()->useDefaultGuards);
    }

    public function test_use_default_guards_default_arg_is_true(): void
    {
        $claw = Claw::builder()->useDefaultGuards()->build();
        $this->assertTrue($claw->config()->useDefaultGuards);
    }

    public function test_shell_allowlist_default_uses_constant_fallback(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame(ClawConfig::DEFAULT_SHELL_ALLOWLIST, $claw->config()->shellAllowlist);
    }

    public function test_shell_allowlist_setter_threads_through(): void
    {
        $list = ['ls', 'pwd', 'whoami'];
        $claw = Claw::builder()->shellAllowlist($list)->build();
        $this->assertSame($list, $claw->config()->shellAllowlist);
    }

    public function test_tools_setter_threads_through(): void
    {
        $tool = $this->makeTool('weather');
        $claw = Claw::builder()->tools([$tool])->build();
        $this->assertCount(1, $claw->config()->tools);
        $this->assertSame($tool, $claw->config()->tools[0]);
    }

    public function test_tools_setter_replaces_previous_list(): void
    {
        $t1 = $this->makeTool('a');
        $t2 = $this->makeTool('b');
        $claw = Claw::builder()
            ->tools([$t1])
            ->tools([$t2])
            ->build();
        $this->assertCount(1, $claw->config()->tools);
        $this->assertSame($t2, $claw->config()->tools[0]);
    }

    public function test_add_tool_appends_to_existing_list(): void
    {
        $t1 = $this->makeTool('a');
        $t2 = $this->makeTool('b');
        $claw = Claw::builder()->addTool($t1)->addTool($t2)->build();
        $this->assertCount(2, $claw->config()->tools);
    }

    public function test_add_tool_after_tools_setter_appends(): void
    {
        $t1 = $this->makeTool('a');
        $t2 = $this->makeTool('b');
        $claw = Claw::builder()->tools([$t1])->addTool($t2)->build();
        $this->assertCount(2, $claw->config()->tools);
    }

    public function test_skills_setter_threads_through(): void
    {
        $skill = new ArraySkill('test-skill', 'desc', ['test'], 'content body');
        $claw = Claw::builder()->skills([$skill])->build();
        $this->assertCount(1, $claw->config()->skills);
    }

    public function test_add_skill_appends_to_existing_list(): void
    {
        $s1 = new ArraySkill('s1', 'desc 1', ['a'], 'body 1');
        $s2 = new ArraySkill('s2', 'desc 2', ['b'], 'body 2');
        $claw = Claw::builder()->addSkill($s1)->addSkill($s2)->build();
        $this->assertCount(2, $claw->config()->skills);
    }

    public function test_provider_override_threads_through(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $claw = Claw::builder()->providerOverride($provider)->build();
        $this->assertSame($provider, $claw->config()->providerOverride);
    }

    public function test_memory_setter_threads_through(): void
    {
        $memory = new ArrayMemory;
        $claw = Claw::builder()->memory($memory)->build();
        $this->assertSame($memory, $claw->memory());
    }

    public function test_cloud_key_setter_threads_through(): void
    {
        $claw = Claw::builder()->cloudKey('test-key-builder-cloud')->build();
        $this->assertSame('test-key-builder-cloud', $claw->config()->cloudKey);
    }

    public function test_cloud_disable_setter_threads_through(): void
    {
        $disabled = ['observability', 'analytics'];
        $claw = Claw::builder()->cloudDisable($disabled)->build();
        $this->assertSame($disabled, $claw->config()->cloudDisable);
    }

    public function test_cloud_signing_secret_setter_threads_through(): void
    {
        $claw = Claw::builder()->cloudSigningSecret('sign-secret-builder')->build();
        $this->assertSame('sign-secret-builder', $claw->config()->cloudSigningSecret);
    }

    public function test_cloud_signing_secret_defaults_to_empty(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame('', $claw->config()->cloudSigningSecret);
    }

    public function test_system_prompt_with_no_tools_is_passed_verbatim(): void
    {
        $claw = Claw::builder()->systemPrompt('You are a tester.')->build();
        $this->assertSame('You are a tester.', $claw->config()->systemPrompt);
    }

    public function test_empty_system_prompt_with_no_tools_stays_empty(): void
    {
        $claw = Claw::builder()->build();
        $this->assertSame('', $claw->config()->systemPrompt);
    }

    public function test_doctrine_prepends_when_tools_registered(): void
    {
        $claw = Claw::builder()
            ->systemPrompt('Custom prompt.')
            ->addTool($this->makeTool('x'))
            ->build();
        $prompt = $claw->config()->systemPrompt;
        $this->assertStringContainsString(Claw::AGENTIC_DOCTRINE, $prompt);
        $this->assertStringContainsString('Custom prompt.', $prompt);
        $this->assertStringStartsWith(Claw::AGENTIC_DOCTRINE, $prompt);
    }

    public function test_doctrine_only_when_tools_registered_and_no_system_prompt(): void
    {
        $claw = Claw::builder()
            ->addTool($this->makeTool('x'))
            ->build();
        $this->assertSame(Claw::AGENTIC_DOCTRINE, $claw->config()->systemPrompt);
    }

    public function test_agentic_doctrine_used_when_agentic_mode_on(): void
    {
        $claw = Claw::builder()
            ->systemPrompt('Custom prompt.')
            ->addTool($this->makeTool('x'))
            ->build();
        $prompt = $claw->config()->systemPrompt;
        $this->assertStringContainsString(Claw::AGENTIC_DOCTRINE, $prompt);
        $this->assertStringContainsString('Custom prompt.', $prompt);
        $this->assertStringStartsWith(Claw::AGENTIC_DOCTRINE, $prompt);
    }

    public function test_agentic_doctrine_only_when_no_system_prompt(): void
    {
        $claw = Claw::builder()
            ->addTool($this->makeTool('x'))
            ->build();
        $this->assertSame(Claw::AGENTIC_DOCTRINE, $claw->config()->systemPrompt);
    }

    public function test_default_doctrine_is_agentic_not_framework(): void
    {
        $claw = Claw::builder()
            ->addTool($this->makeTool('x'))
            ->build();
        $this->assertSame(Claw::AGENTIC_DOCTRINE, $claw->config()->systemPrompt);
    }

    public function test_agentic_mode_suppressed_when_no_tools(): void
    {
        $claw = Claw::builder()
            ->systemPrompt('Custom prompt.')
            ->build();
        $this->assertSame('Custom prompt.', $claw->config()->systemPrompt);
    }

    public function test_doctrine_suppressed_when_no_tools(): void
    {
        $claw = Claw::builder()
            ->systemPrompt('Custom prompt.')
            ->build();
        $this->assertSame('Custom prompt.', $claw->config()->systemPrompt);
        $this->assertStringNotContainsString(Claw::AGENTIC_DOCTRINE, $claw->config()->systemPrompt);
    }

    public function test_full_chain_returns_consistent_phpclaw(): void
    {
        $tool = $this->makeTool('all-in');
        $memory = new ArrayMemory;
        $claw = Claw::builder()
            ->apiKey('sk-full')
            ->provider('anthropic')
            ->model(AnthropicProvider::MODEL_HAIKU)
            ->systemPrompt('Senior engineer.')
            ->maxIterations(15)
            ->maxRetries(2)
            ->maxHistoryLength(100)
            ->maxTokens(2048)
            ->promptCache(true)
            ->thinkingBudget(1024)
            ->storeMessages(false)
            ->useDefaultGuards(true)
            ->shellAllowlist(['ls'])
            ->addTool($tool)
            ->memory($memory)
            ->cloudKey('test-key-full-chain')
            ->cloudDisable(['x'])
            ->build();

        $cfg = $claw->config();
        $this->assertSame('sk-full', $cfg->apiKey);
        $this->assertSame('anthropic', $cfg->providerName);
        $this->assertSame(AnthropicProvider::MODEL_HAIKU, $cfg->model);
        $this->assertStringContainsString('Senior engineer.', $cfg->systemPrompt);
        $this->assertStringContainsString(Claw::AGENTIC_DOCTRINE, $cfg->systemPrompt);
        $this->assertSame(15, $cfg->maxIterations);
        $this->assertSame(2, $cfg->maxRetries);
        $this->assertSame(100, $cfg->maxHistoryLength);
        $this->assertSame(2048, $cfg->maxTokens);
        $this->assertTrue($cfg->promptCache);
        $this->assertSame(1024, $cfg->thinkingBudget);
        $this->assertFalse($cfg->storeMessages);
        $this->assertTrue($cfg->useDefaultGuards);
        $this->assertSame(['ls'], $cfg->shellAllowlist);
        $this->assertCount(1, $cfg->tools);
        $this->assertSame($memory, $cfg->memory);
        $this->assertSame('test-key-full-chain', $cfg->cloudKey);
        $this->assertSame(['x'], $cfg->cloudDisable);
    }

    private function makeTool(string $name): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        return $tool;
    }
}
