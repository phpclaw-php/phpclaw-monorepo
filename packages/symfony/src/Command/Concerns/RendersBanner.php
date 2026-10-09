<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command\Concerns;

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
        $tagline = 'AI agents for Symfony';
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
}
