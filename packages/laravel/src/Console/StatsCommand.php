<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;

/**
 * Artisan command `php artisan phpclaw:stats`, prints conversation and message counts from the database memory tables.
 */
final class StatsCommand extends Command
{
    protected $signature = 'phpclaw:stats';

    protected $description = 'Display phpClaw conversation and message statistics';

    private const DATABASE_DRIVERS = [
        'database', 'database_kv', 'database_conversation',
        'eloquent', 'eloquent_kv', 'eloquent_conversation',
    ];

    /**
     * Print conversation, message, and 24-hour activity counts.
     *
     * @return int Artisan exit code.
     */
    public function handle(): int
    {
        $driver = (string) config('phpclaw.memory_driver', 'database');

        if (! in_array($driver, self::DATABASE_DRIVERS, strict: true)) {
            $this->warn('phpClaw stats require a database memory driver.');
            $this->line("Current driver: {$driver}");
            $this->line('Set PHPCLAW_MEMORY_DRIVER=database in .env and run migrations.');

            return self::SUCCESS;
        }

        $conversations = $this->countTable(DatabaseConversationMemory::CONVERSATIONS_TABLE);
        $messages = $this->countTable(DatabaseConversationMemory::MESSAGES_TABLE);
        $active24h = $this->countActive24h();

        $this->line('phpClaw Stats, Laravel');
        $this->line(str_repeat('━', 30));
        $this->line("Conversations:  {$conversations}");
        $this->line("Messages:       {$messages}");
        $this->line("Active 24h:     {$active24h}");
        $this->line(str_repeat('━', 30));

        return self::SUCCESS;
    }

    /**
     * Count rows in a table, returning 0 when the table does not exist.
     *
     * @param  string  $table  The table name to count.
     * @return int Row count, or 0 if the table is missing.
     */
    private function countTable(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Count conversations with activity in the last 24 hours.
     *
     * @return int Number of conversations updated within the last day.
     */
    private function countActive24h(): int
    {
        try {
            return (int) DB::table(DatabaseConversationMemory::CONVERSATIONS_TABLE)
                ->where('updated_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
