<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command\Concerns;

use Composer\InstalledVersions;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the phpClaw wordmark banner for console commands.
 */
trait RendersBanner
{
    /**
     * Print the phpClaw wordmark, coloured on decorated terminals and plain ASCII otherwise.
     *
     * @param  SymfonyStyle  $io
     * @return void
     */
    private function banner(SymfonyStyle $io): void
    {
        $logo = <<<'ART'
██████╗  ██╗  ██╗  ██████╗   ██████╗ ██╗       █████╗  ██╗    ██╗
██╔══██╗ ██║  ██║  ██╔══██╗ ██╔════╝ ██║      ██╔══██╗ ██║ █╗ ██║
██████╔╝ ███████║  ██████╔╝ ██║      ██║      ███████║ ██║███╗██║
██╔═══╝  ██╔══██║  ██╔═══╝  ██║      ██║      ██╔══██║ ╚███╔███╔╝
██║      ██║  ██║  ██║      ╚██████╗ ███████╗ ██║  ██║  ╚██╔██╔╝
╚═╝      ╚═╝  ╚═╝  ╚═╝       ╚═════╝ ╚══════╝ ╚═╝  ╚═╝   ╚═╝╚═╝
ART;

        $gradient = [201, 129, 27, 51, 46, 226];
        $tagline = 'AI agents for Symfony · v'.$this->bannerVersion();
        $decorated = $io->isDecorated();

        foreach (explode("\n", $logo) as $i => $line) {
            if ($decorated) {
                $color = $gradient[$i] ?? 226;
                $io->writeln("\e[38;5;{$color}m{$line}\e[0m");
            } else {
                $io->writeln($line);
            }
        }

        $io->writeln($decorated ? "\e[2m{$tagline}\e[0m" : $tagline);
        $io->newLine();
    }

    /**
     * Resolve the installed adapter version for the banner, 'dev' when Composer cannot report one.
     *
     * @return string
     */
    private function bannerVersion(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                $resolved = InstalledVersions::getPrettyVersion('phpclaw/phpclaw-symfony');

                return self::normalizeVersion($resolved);
            } catch (\Throwable) {
            }
        }

        return 'dev';
    }

    /**
     * Passes through a real release tag; normalizes anything else (dev alias, no-version-set, null) to 'dev'.
     *
     * @param  ?string  $version
     * @return string
     */
    private static function normalizeVersion(?string $version): string
    {
        $version = $version === null ? null : ltrim($version, 'v');

        if ($version === null || ! preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return 'dev';
        }

        return $version;
    }
}
