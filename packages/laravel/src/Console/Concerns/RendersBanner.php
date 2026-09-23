<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console\Concerns;

use Composer\InstalledVersions;
use Illuminate\Console\Command;

/**
 * Renders the phpClaw wordmark banner for console commands.
 *
 * @mixin Command
 */
trait RendersBanner
{
    /**
     * Print the phpClaw wordmark, coloured on decorated terminals, plain ASCII otherwise.
     *
     * @return void
     */
    private function banner(): void
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
        $tagline = 'AI agents for Laravel · v'.$this->bannerVersion();
        $decorated = $this->getOutput()->isDecorated();

        foreach (explode("\n", $logo) as $i => $line) {
            if ($decorated) {
                $color = $gradient[$i] ?? 226;
                $this->line("\e[38;5;{$color}m{$line}\e[0m");
            } else {
                $this->line($line);
            }
        }

        $this->line($decorated ? "\e[2m{$tagline}\e[0m" : $tagline);
        $this->newLine();
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
                $resolved = InstalledVersions::getPrettyVersion('phpclaw/phpclaw-laravel');

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
