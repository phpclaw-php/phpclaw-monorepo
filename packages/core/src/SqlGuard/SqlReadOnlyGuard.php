<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * Validates that a query is a single read-only SELECT/WITH statement and returns the exact string to execute; allowlist-of-shape and fail-closed, comments and statement separators are rejected (never stripped), only SELECT/WITH may lead, and write/exfiltration tokens are rejected outside string literals, so a plain token scan is reliable with no stripped copy that can diverge from the executed string.
 */
final class SqlReadOnlyGuard
{
    private const ALLOWED_FIRST = ['SELECT', 'WITH'];

    private const BLOCKED_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'UPSERT',
        'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'RENAME',
        'GRANT', 'REVOKE', 'LOCK', 'UNLOCK',
        'CALL', 'EXEC', 'EXECUTE', 'HANDLER', 'DO', 'SET',
        'LOAD', 'LOAD_FILE', 'IMPORT', 'INTO', 'OUTFILE', 'DUMPFILE',
        'ATTACH', 'DETACH', 'PREPARE', 'DEALLOCATE', 'RESET', 'FLUSH',
        'KILL', 'SHUTDOWN', 'INSTALL', 'UNINSTALL', 'DELIMITER',
        'START', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE',
        'INFORMATION_SCHEMA', 'PG_READ_FILE', 'PG_LS_DIR', 'SLEEP', 'BENCHMARK', 'PG_SLEEP',
    ];

    private const BLOCKED_SEQUENCES = [
        ['FOR', 'UPDATE'],
        ['FOR', 'SHARE'],
    ];

    /**
     * Create a new SqlReadOnlyGuard instance.
     *
     * @param  SqlDialect  $dialect  Dialect controlling string/escape lexing rules.
     * @param  SqlPolicyInterface[]  $policies  Optional adapter policies run after shape checks.
     * @return void
     */
    public function __construct(
        private readonly SqlDialect $dialect = SqlDialect::Generic,
        private readonly array $policies = [],
    ) {}

    /**
     * Validate the query and return the exact validated string as a SafeSql.
     *
     * @param  string  $sql  Raw query supplied by the caller.
     * @return SafeSql Wrapping the validated string to execute.
     *
     * @throws SqlGuardException When the query is not a single safe read-only statement.
     */
    public function validate(string $sql): SafeSql
    {
        $trimmed = trim($sql);

        if ($trimmed === '') {
            throw new SqlGuardException('Empty query.');
        }

        $tokens = $this->lex($trimmed);

        $first = $tokens->firstWord();
        if ($first === null || ! in_array($first, self::ALLOWED_FIRST, true)) {
            throw new SqlGuardException('Only single SELECT/WITH read queries are permitted.');
        }

        foreach ($tokens->words() as $word) {
            if (in_array($word, self::BLOCKED_WORDS, true)) {
                throw new SqlGuardException("Blocked keyword: {$word}.");
            }
        }

        foreach (self::BLOCKED_SEQUENCES as [$a, $b]) {
            if ($tokens->hasSequence($a, $b)) {
                throw new SqlGuardException("Blocked clause: {$a} {$b}.");
            }
        }

        foreach ($this->policies as $policy) {
            $policy->apply($tokens);
        }

        return new SafeSql($trimmed);
    }

    /**
     * Single left-to-right lexical pass that rejects comments, statement separators, illegal characters, and unterminated strings, collecting upper-cased word tokens found outside every string literal.
     *
     * @param  string  $sql  Trimmed query.
     * @return SqlTokenStream
     *
     * @throws SqlGuardException On any comment, ";", illegal character, or unterminated literal.
     */
    private function lex(string $sql): SqlTokenStream
    {
        $words = [];
        $word = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $c = $sql[$i];

            if (($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9') || $c === '_') {
                $word .= $c;
                $i++;

                continue;
            }

            if ($word !== '') {
                $words[] = strtoupper($word);
                $word = '';
            }

            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;

                continue;
            }

            if ($c === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                throw new SqlGuardException('SQL comments are not permitted (--).');
            }

            if ($c === '#') {
                throw new SqlGuardException('SQL comments are not permitted (#).');
            }

            if ($c === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                throw new SqlGuardException('SQL comments are not permitted (/* */).');
            }

            if ($c === ';') {
                if (trim(substr($sql, $i + 1)) === '') {
                    break; // one trailing ";" followed only by whitespace is a single statement
                }

                throw new SqlGuardException('Only a single statement is permitted (";" found).');
            }

            if ($c === '\'' || $c === '"' || $c === '`') {
                $i = $this->consumeString($sql, $i, $c, $length);

                continue;
            }

            if (ord($c) < 0x20 || ord($c) >= 0x80) {
                throw new SqlGuardException('Illegal character outside a string literal.');
            }

            $i++;
        }

        if ($word !== '') {
            $words[] = strtoupper($word);
        }

        return new SqlTokenStream($words);
    }

    /**
     * Consume a string/quoted-identifier literal, handling doubled-delimiter escaping and, on the MySql dialect, backslash escaping. Fails closed on an unterminated literal.
     *
     * @param  string  $sql  Trimmed query.
     * @param  int  $i  Index of the opening delimiter.
     * @param  string  $delim  Delimiter character (' " or `).
     * @param  int  $length  Total length of $sql.
     * @return int Index of the first character after the closing delimiter.
     *
     * @throws SqlGuardException When the literal is never closed.
     */
    private function consumeString(string $sql, int $i, string $delim, int $length): int
    {
        $backslashEscapes = ($this->dialect === SqlDialect::MySql);
        $i++;

        while ($i < $length) {
            $c = $sql[$i];

            if ($backslashEscapes && $c === '\\') {
                $i += 2;

                continue;
            }

            if ($c === $delim) {
                if ($i + 1 < $length && $sql[$i + 1] === $delim) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        throw new SqlGuardException('Unterminated string literal.');
    }
}
