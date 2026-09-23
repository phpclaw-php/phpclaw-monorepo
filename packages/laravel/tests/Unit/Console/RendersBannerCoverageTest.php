<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Console\Concerns\RendersBanner;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class RendersBannerCoverageTest extends TestCase
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

    public function test_banner_decorated_path_executes_when_output_is_decorated(): void
    {
        $command = new class extends Command
        {
            use RendersBanner;

            protected $signature = 'phpclaw:test-banner-decorated';

            public function handle(): int
            {
                $this->banner();

                return 0;
            }
        };

        $buffered = new BufferedOutput(
            OutputInterface::VERBOSITY_NORMAL,
            true,
        );

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle($input, $buffered));

        $result = $command->handle();

        $content = $buffered->fetch();
        $this->assertSame(0, $result);
        $this->assertStringContainsString('AI agents for Laravel', $content);
        $this->assertStringContainsString("\e[", $content, 'Decorated output must contain ANSI escape codes.');
    }

    public function test_banner_version_is_a_real_release_tag_or_the_dev_fallback(): void
    {
        $command = new class extends Command
        {
            use RendersBanner;

            protected $signature = 'phpclaw:test-version';

            public function handle(): int
            {
                return 0;
            }

            public function getVersion(): string
            {
                return $this->bannerVersion();
            }
        };

        $command->setLaravel($this->app);

        $version = $command->getVersion();
        $this->assertIsString($version);
        $this->assertTrue(
            $version === 'dev' || (bool) preg_match('/^\d+\.\d+\.\d+/', $version),
            "bannerVersion() must return 'dev' or a real semver-shaped version, got: {$version}",
        );
    }
}
