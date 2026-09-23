<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use Doctrine\DBAL\Connection;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints conversation, message, and 24-hour activity counts from memory tables.
 */
#[AsCommand(
    name: 'phpclaw:stats',
    description: 'Display phpClaw conversation and message statistics.',
)]
final class StatsCommand extends Command
{
    private const COUNTABLE_DRIVERS = ['doctrine', 'doctrine_conversation'];

    /**
     * Constructs the command with the memory driver name and optional DBAL connection.
     *
     * @param  string  $memoryDriver  The configured memory driver slug.
     * @param  Connection|null  $connection  Optional Doctrine DBAL connection for stats queries.
     */
    public function __construct(
        private readonly string $memoryDriver,
        private readonly ?Connection $connection = null,
    ) {
        parent::__construct();
    }

    /**
     * Prints conversation and message counts from the Doctrine memory tables.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! in_array($this->memoryDriver, self::COUNTABLE_DRIVERS, true)) {
            $io->warning('phpClaw stats require a Doctrine memory driver.');
            $io->writeln("Current driver: {$this->memoryDriver}");
            $io->writeln('Set PHPCLAW_MEMORY_DRIVER=doctrine in .env and run migrations.');

            return Command::SUCCESS;
        }

        if ($this->connection === null) {
            $io->warning('Doctrine DBAL is not installed. Install doctrine/dbal to use stats.');

            return Command::SUCCESS;
        }

        $conversations = $this->countTable(DoctrineConversationMemory::CONVERSATIONS);
        $messages = $this->countTable(DoctrineConversationMemory::MESSAGES);
        $active24h = $this->countActive24h();

        $io->writeln('phpClaw Stats for Symfony');
        $io->writeln(str_repeat('━', 50));
        $io->writeln('Conversations:  '.$this->render($conversations));
        $io->writeln('Messages:       '.$this->render($messages));
        $io->writeln('Active 24h:     '.$this->render($active24h));
        $io->writeln(str_repeat('━', 50));

        if ($conversations === null || $messages === null || $active24h === null) {
            $io->warning('At least one count could not be read. Check the database connection and that migrations have run.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Render a count for display, marking an unreadable one rather than showing it as zero.
     *
     * @param  int|null  $count  The counted rows, or null when the query failed.
     * @return string
     */
    private function render(?int $count): string
    {
        return $count === null ? 'unavailable' : (string) $count;
    }

    /**
     * Returns the row count for a table, or null when the query cannot be run.
     *
     * @param  string  $table
     * @return int|null
     */
    private function countTable(string $table): ?int
    {
        try {
            $result = $this->connection?->fetchOne('SELECT COUNT(*) FROM '.$table);

            return $result !== false ? (int) $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns conversations with activity in the last 24 hours, or null when the query cannot be run.
     *
     * @return int|null
     */
    private function countActive24h(): ?int
    {
        try {
            $since = gmdate('Y-m-d H:i:s', time() - 86400);
            $result = $this->connection?->fetchOne(
                'SELECT COUNT(*) FROM '.DoctrineConversationMemory::CONVERSATIONS.' WHERE updated_at >= ?',
                [$since],
            );

            return $result !== false ? (int) $result : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
