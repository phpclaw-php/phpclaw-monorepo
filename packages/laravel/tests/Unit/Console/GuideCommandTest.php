<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;

final class GuideCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('phpclaw:guide')->assertSuccessful();
    }

    public function test_full_guide_outputs_banner(): void
    {
        $this->artisan('phpclaw:guide')
            ->assertSuccessful()
            ->expectsOutputToContain('AI agents for Laravel');
    }

    public function test_full_guide_outputs_all_sections(): void
    {
        $sections = [
            'QUICKSTART', 'TOOLS', 'PROVIDERS', 'MEMORY',
            'GUARDS', 'HOOKS', 'SKILLS', 'REST', 'CLI', 'PRIVACY', 'CONFIG',
        ];

        $result = $this->artisan('phpclaw:guide')->assertSuccessful();

        foreach ($sections as $section) {
            $result->expectsOutputToContain($section);
        }
    }

    public function test_section_quickstart_outputs_content(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'quickstart'])
            ->assertSuccessful()
            ->expectsOutputToContain('QUICKSTART');
    }

    public function test_section_tools_outputs_content(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'tools'])
            ->assertSuccessful()
            ->expectsOutputToContain('TOOLS')
            ->expectsOutputToContain('db_query');
    }

    public function test_section_providers_outputs_content(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'providers'])
            ->assertSuccessful()
            ->expectsOutputToContain('PROVIDERS')
            ->expectsOutputToContain('anthropic');
    }

    public function test_section_providers_lists_catalogue_dynamically(): void
    {
        ProviderCatalogue::register('acme_llm', 'Acme LLM', OpenAIProvider::class);

        try {
            $this->artisan('phpclaw:guide', ['--section' => 'providers'])
                ->assertSuccessful()
                ->expectsOutputToContain('acme_llm');
        } finally {
            ProviderCatalogue::reset();
        }
    }

    public function test_section_memory_outputs_content(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'memory'])
            ->assertSuccessful()
            ->expectsOutputToContain('MEMORY');
    }

    public function test_section_hooks_lists_registered_listeners_only(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'hooks'])
            ->assertSuccessful()
            ->expectsOutputToContain('No hooks registered');
    }

    public function test_section_skills_lists_registered_skills_dynamically(): void
    {
        SkillRegistry::register(new ArraySkill('acme_skill', 'Acme skill', ['acme'], 'Acme content'));

        $this->artisan('phpclaw:guide', ['--section' => 'skills'])
            ->assertSuccessful()
            ->expectsOutputToContain('SKILLS')
            ->expectsOutputToContain('acme_skill');
    }

    public function test_section_privacy_shows_store_messages_breakdown(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'privacy'])
            ->assertSuccessful()
            ->expectsOutputToContain('Conversation metadata')
            ->expectsOutputToContain('never saved')
            ->expectsOutputToContain('AI-generated');
    }

    public function test_section_config_outputs_env_reference(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'config'])
            ->assertSuccessful()
            ->expectsOutputToContain('PHPCLAW_PROVIDER')
            ->expectsOutputToContain('PHPCLAW_BASE_URL')
            ->expectsOutputToContain('PHPCLAW_TOOL_DENY');
    }

    public function test_section_cli_lists_all_commands(): void
    {
        Artisan::call('phpclaw:guide', ['--section' => 'cli']);
        $output = Artisan::output();

        $registered = array_filter(
            array_keys($this->app[Kernel::class]->all()),
            static fn (string $name): bool => str_starts_with($name, 'phpclaw:'),
        );

        self::assertNotSame([], $registered);

        foreach ($registered as $command) {
            self::assertStringContainsString(
                $command,
                $output,
                "the cli section must document every shipped command; {$command} is missing",
            );
        }
    }

    public function test_invalid_section_returns_failure(): void
    {
        $this->artisan('phpclaw:guide', ['--section' => 'nonexistent'])
            ->assertFailed();
    }

    public function test_live_flag_is_off_by_default(): void
    {
        Artisan::call('phpclaw:guide');
        $output = Artisan::output();

        self::assertStringNotContainsString('REMOTE SKILLS (live)', $output);
    }

    public function test_live_flag_with_no_configured_urls_reports_none(): void
    {
        Artisan::call('phpclaw:guide', ['--live' => true]);
        $output = Artisan::output();

        self::assertStringContainsString('REMOTE SKILLS (live)', $output);
        self::assertStringContainsString('No remote skill URLs configured', $output);
    }

    public function test_live_flag_combines_with_section(): void
    {
        Artisan::call('phpclaw:guide', ['--section' => 'skills', '--live' => true]);
        $output = Artisan::output();

        self::assertStringContainsString('SKILLS', $output);
        self::assertStringContainsString('REMOTE SKILLS (live)', $output);
    }
}
