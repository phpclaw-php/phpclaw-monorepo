<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console\Concerns;

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
        $tagline = 'AI agents for Laravel';
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
}
