<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\Command\GuideCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class GuideCommandTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    private function tester(string $remoteSkillUrls = ''): CommandTester
    {
        return new CommandTester(new GuideCommand($remoteSkillUrls));
    }

    public function test_full_guide_prints_all_sections(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        foreach (['QUICKSTART', 'TOOLS', 'PROVIDERS', 'MEMORY', 'GUARDS', 'HOOKS', 'SKILLS', 'REST', 'CLI', 'PRIVACY', 'CONFIG'] as $section) {
            self::assertStringContainsString($section, $display);
        }
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_section_rest_shows_endpoints(): void
    {
        $tester = $this->tester();
        $tester->execute(['--section' => 'rest']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('/phpclaw/send', $display);
        self::assertStringContainsString('/phpclaw/chat/stream', $display);
        self::assertStringContainsString('401', $display);
        self::assertStringContainsString('TokenAuthListener', $display);
    }

    public function test_section_cli_shows_every_shipped_command(): void
    {
        $tester = $this->tester();
        $tester->execute(['--section' => 'cli']);

        $display = $tester->getDisplay();

        $shipped = [];
        foreach (glob(dirname(__DIR__, 3).'/src/Command/*.php') ?: [] as $file) {
            $class = 'PhpClaw\\Symfony\\Command\\'.basename($file, '.php');
            $attributes = (new \ReflectionClass($class))->getAttributes(AsCommand::class);

            foreach ($attributes as $attribute) {
                $name = (string) $attribute->newInstance()->name;
                if (str_contains($name, ':')) {
                    $shipped[] = $name;
                }
            }
        }
        sort($shipped);

        self::assertSame([
            'phpclaw:about',
            'phpclaw:guide',
            'phpclaw:jobs:list',
            'phpclaw:jobs:status',
            'phpclaw:mcp-server',
            'phpclaw:stats',
        ], $shipped);

        foreach ($shipped as $command) {
            self::assertStringContainsString(
                $command,
                $display,
                "the cli section must document every shipped command; {$command} is missing",
            );
        }
    }

    public function test_section_providers_lists_dynamically(): void
    {
        ProviderCatalogue::register('fake_guide_prov', 'FakeGuideProv', AnthropicProvider::class);

        $tester = $this->tester();
        $tester->execute(['--section' => 'providers']);

        self::assertStringContainsString('fake_guide_prov', $tester->getDisplay());
    }

    public function test_section_config_lists_providers_dynamically(): void
    {
        ProviderCatalogue::register('fake_config_prov', 'FakeConfigProv', AnthropicProvider::class);

        $tester = $this->tester();
        $tester->execute(['--section' => 'config']);

        self::assertStringContainsString('FAKE_CONFIG_PROV_API_KEY', $tester->getDisplay());
    }

    public function test_unknown_section_returns_failure(): void
    {
        $tester = $this->tester();
        $tester->execute(['--section' => 'not-a-section']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_providers_section_uses_catalogue_not_hardcoded(): void
    {
        $slug = 'dynamic_prov_'.uniqid();
        ProviderCatalogue::register($slug, 'DynProv', AnthropicProvider::class);

        $tester = $this->tester();
        $tester->execute(['--section' => 'providers']);

        self::assertStringContainsString($slug, $tester->getDisplay());
    }

    public function test_print_section_default_branch_is_noop(): void
    {
        $command = new GuideCommand;
        $ref = new \ReflectionClass($command);
        $method = $ref->getMethod('printSection');

        $io = $this->createMock(SymfonyStyle::class);

        $result = $method->invoke($command, $io, 'unknown-section-slug');

        self::assertNull($result);
    }

    public function test_live_flag_is_off_by_default(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringNotContainsString('REMOTE SKILLS (live)', $tester->getDisplay());
    }

    public function test_live_flag_with_no_configured_urls_reports_none(): void
    {
        $tester = $this->tester('');
        $tester->execute(['--live' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('REMOTE SKILLS (live)', $display);
        self::assertStringContainsString('No remote skill URLs configured', $display);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_live_flag_combines_with_section(): void
    {
        $tester = $this->tester('');
        $tester->execute(['--section' => 'skills', '--live' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('SKILLS', $display);
        self::assertStringContainsString('REMOTE SKILLS (live)', $display);
    }
}
