<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Agent\MessageAugmenter;
use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\Config\ProviderConfig;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ToolRouter;
use PHPUnit\Framework\TestCase;

final class SkillSelectionTest extends TestCase
{
    private const CACHE_SUBDIR = 'phpclaw-skill-cache';

    protected function setUp(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
        SkillRegistry::register(new ArraySkill('wp_plugin_creator', 'WordPress plugin generation rules', ['plugin', 'woocommerce'], 'PLUGIN RULES BODY'));
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
    }

    public function test_a_message_sharing_only_a_tag_word_gets_no_skill(): void
    {
        $out = (new MessageAugmenter(null))->augment('List 5 WooCommerce products with their IDs.');

        $this->assertSame('List 5 WooCommerce products with their IDs.', $out);
    }

    public function test_a_message_naming_the_skill_gets_it_injected(): void
    {
        $out = (new MessageAugmenter(null))->augment('Use the wp_plugin_creator skill to build a plugin.');

        $this->assertStringContainsString('PLUGIN RULES BODY', $out);
    }

    public function test_the_hyphen_form_of_the_name_also_injects_the_skill(): void
    {
        $out = (new MessageAugmenter(null))->augment('Use wp-plugin-creator to build a plugin.');

        $this->assertStringContainsString('PLUGIN RULES BODY', $out);
    }

    public function test_with_system_prompt_returns_a_copy_and_leaves_the_original(): void
    {
        $config = new ClawConfig(new ProviderConfig(apiKey: 'k', provider: 'anthropic', model: 'claude-x', systemPrompt: 'ORIGINAL'));

        $copy = $config->withSystemPrompt('CHANGED');

        $this->assertSame('CHANGED', $copy->systemPrompt);
        $this->assertSame('ORIGINAL', $config->systemPrompt);
        $this->assertSame('claude-x', $copy->model);
        $this->assertSame('anthropic', $copy->providerName);
    }

    public function test_a_preset_provider_gets_the_skills_section_in_its_system_prompt(): void
    {
        $claw = Claw::builder()->provider('ollama')->model('qwen2.5:7b')->systemPrompt('SITE PROMPT')->build();

        $prompt = $this->providerSystemPrompt($claw);

        $this->assertStringContainsString('SITE PROMPT', $prompt);
        $this->assertStringContainsString('- **wp_plugin_creator**: WordPress plugin generation rules', $prompt);
    }

    public function test_a_native_provider_gets_the_skills_section_in_its_system_prompt(): void
    {
        $claw = Claw::builder()->provider('anthropic')->apiKey('test-key')->model('claude-sonnet-4-5')->build();

        $this->assertStringContainsString('- **wp_plugin_creator**', $this->providerSystemPrompt($claw));
    }

    public function test_without_skills_there_is_no_section_and_no_load_skill_tool(): void
    {
        SkillRegistry::reset();
        $claw = Claw::builder()->provider('ollama')->model('qwen2.5:7b')->build();

        $this->assertStringNotContainsString('## Skills System', $this->providerSystemPrompt($claw));
        $this->assertFalse($this->agentTools($claw)->has('load_skill'));
    }

    public function test_with_skills_the_load_skill_tool_is_registered(): void
    {
        $claw = Claw::builder()->provider('ollama')->model('qwen2.5:7b')->build();

        $this->assertTrue($this->agentTools($claw)->has('load_skill'));
    }

    public function test_a_remote_skill_is_listed_in_the_system_prompt(): void
    {
        $url = 'https://8.8.8.8/skills/remote-demo/SKILL.md';
        $base = sys_get_temp_dir().'/phpclaw-selection-'.uniqid('', true);
        $previous = $_ENV['PHPCLAW_CACHE_DIR'] ?? null;
        $_ENV['PHPCLAW_CACHE_DIR'] = $base;
        mkdir($base.'/'.self::CACHE_SUBDIR, 0700, true);
        file_put_contents($base.'/'.self::CACHE_SUBDIR.'/'.md5($url).'.cache', "---\ndescription: Remote demo rules\n---\n# Remote Demo\n\nBody.");

        try {
            $claw = Claw::builder()->provider('ollama')->model('qwen2.5:7b')->withRemoteSkills($url)->build();
            $prompt = $this->providerSystemPrompt($claw);
        } finally {
            if ($previous === null) {
                unset($_ENV['PHPCLAW_CACHE_DIR']);
            } else {
                $_ENV['PHPCLAW_CACHE_DIR'] = $previous;
            }
            array_map('unlink', glob($base.'/'.self::CACHE_SUBDIR.'/*') ?: []);
            rmdir($base.'/'.self::CACHE_SUBDIR);
            rmdir($base);
        }

        $this->assertStringContainsString('- **remote_demo**', $prompt);
    }

    public function test_the_router_always_offers_load_skill_even_under_a_cap_of_one(): void
    {
        $schemas = [
            ['name' => 'wp_query', 'description' => 'list posts'],
            ['name' => 'wp_users', 'description' => 'list users'],
            ['name' => 'load_skill', 'description' => 'read a skill'],
        ];

        $out = (new ToolRouter(1, 0.2))->filter($schemas, 'list posts', 'qwen2.5:7b');

        $this->assertContains('load_skill', array_column($out, 'name'));
    }

    public function test_send_runs_load_skill_and_returns_the_answer(): void
    {
        $seen = [];
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('anthropic');
        $provider->method('model')->willReturn('claude-haiku-4-5-20251001');
        $provider->method('send')->willReturnCallback(function (array $messages) use (&$seen): array {
            $seen[] = $messages;

            return count($seen) === 1
                ? ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 't1', 'tool_name' => 'load_skill', 'tool_input' => ['name' => 'wp_plugin_creator']]]]
                : ['type' => 'text', 'text' => 'Plugin ready.'];
        });

        $response = Claw::builder()->providerOverride($provider)->build()->send('Create a WordPress plugin with a shortcode.');

        $this->assertSame('Plugin ready.', $response->text);
        $this->assertSame(['load_skill'], $response->toolsCalled);
        $this->assertStringContainsString('PLUGIN RULES BODY', json_encode(end($seen[1])->batchResults));
    }

    private function providerSystemPrompt(Claw $claw): string
    {
        $agent = (fn (): Agent => $this->agent)->call($claw);
        $provider = (fn () => $this->provider)->call($agent);

        return (fn (): string => $this->systemPrompt)->call($provider);
    }

    private function agentTools(Claw $claw): ToolRegistry
    {
        $agent = (fn (): Agent => $this->agent)->call($claw);

        return (fn (): ToolRegistry => $this->tools)->call($agent);
    }
}
