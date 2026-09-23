<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Concerns\NormalisesText;
use PhpClaw\Guards\Contracts\RawInputGuardInterface;

/**
 * Blocks destructive SQL issued as an instruction, such as "drop table users", while allowing the
 * same phrase inside a question so the assistant can still discuss SQL.
 */
#[Guard(priority: 6, name: 'destructive_sql', label: 'Destructive SQL', enabledByDefault: true, since: '1.0.0')]
final class DestructiveSqlGuard implements RawInputGuardInterface
{
    use NormalisesText;

    private const PATTERNS = [
        'drop table',
        'drop database',
        'drop schema',
        'drop index',
        'truncate table',
        'delete from',
        'alter table',
    ];

    private const INTERROGATIVE_LEADS = [
        'how', 'what', 'why', 'when', 'where', 'which', 'who',
        'can', 'could', 'should', 'would', 'is', 'are', 'does', 'did',
        'explain', 'describe', 'tell', 'teach', 'show',
    ];

    private const ERROR_PATTERN = "Destructive SQL blocked: message instructs '%s'.";

    /**
     * Scan the message for destructive SQL phrases issued as an instruction.
     *
     * @param  string  $message  The raw user message to scan.
     * @return void
     *
     * @throws GuardException If a destructive pattern is present outside a question.
     */
    public function scan(string $message): void
    {
        $normalised = $this->normalise($message);

        if ($this->leadsWithQuestion($normalised)) {
            return;
        }

        self::assertNoMatch($normalised, self::PATTERNS, self::ERROR_PATTERN);
    }

    /**
     * Whether the message opens with an interrogative or explanatory word, which marks it as a
     * question about SQL rather than an instruction to run it.
     *
     * @param  string  $normalised  Normalised message text.
     * @return bool
     */
    private function leadsWithQuestion(string $normalised): bool
    {
        $first = strtok($normalised, " \t\n");

        if ($first === false) {
            return false;
        }

        return in_array(trim($first, '.,!?;:'), self::INTERROGATIVE_LEADS, true);
    }
}
