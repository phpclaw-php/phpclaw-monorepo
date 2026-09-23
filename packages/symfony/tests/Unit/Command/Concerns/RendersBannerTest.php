<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command\Concerns;

use PhpClaw\Symfony\Command\Concerns\RendersBanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RendersBannerTest extends TestCase
{
    public function test_banner_prints_wordmark_and_tagline_plain_when_not_decorated(): void
    {
        $content = $this->render(false);

        $this->assertStringContainsString('██████╗', $content);
        $this->assertStringContainsString('AI agents for Symfony', $content);
        $this->assertStringNotContainsString("\e[38;5;", $content);
    }

    public function test_banner_emits_ansi_colour_when_decorated(): void
    {
        $content = $this->render(true);

        $this->assertStringContainsString("\e[38;5;", $content);
        $this->assertStringContainsString('AI agents for Symfony', $content);
    }

    public function test_banner_version_is_a_real_release_tag_or_the_dev_fallback(): void
    {
        $subject = $this->subject();
        $version = $subject->version();

        $this->assertNotSame('', $version);
        $this->assertTrue(
            $version === 'dev' || (bool) preg_match('/^\d+\.\d+\.\d+/', $version),
            "bannerVersion() must return 'dev' or a real semver-shaped version, got: {$version}",
        );
    }

    private function render(bool $decorated): string
    {
        $output = new BufferedOutput;
        $output->setDecorated($decorated);
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->subject()->render($io);

        return $output->fetch();
    }

    private function subject(): object
    {
        return new class
        {
            use RendersBanner;

            public function render(SymfonyStyle $io): void
            {
                $this->banner($io);
            }

            public function version(): string
            {
                return $this->bannerVersion();
            }
        };
    }
}
