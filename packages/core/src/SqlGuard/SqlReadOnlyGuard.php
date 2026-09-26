<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * Validates that a query is a single read-only SELECT/WITH statement and returns the exact string to execute; fail-closed: comments are rejected, only one trailing semicolon is accepted, only SELECT/WITH may lead, and write/exfiltration tokens outside string literals are blocked.
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

        foreach (self::BLOCKED_SEQUENCES as [$first, $second]) {
            if ($tokens->hasSequence($first, $second)) {
                throw new SqlGuardException("Blocked clause: {$first} {$second}.");
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
            $char = $sql[$i];

            if (self::isWordChar($char)) {
                $word .= $char;
                $i++;

                continue;
            }

            if ($word !== '') {
                $words[] = strtoupper($word);
                $word = '';
            }

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $i++;

                continue;
            }

            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                throw new SqlGuardException('SQL comments are not permitted (--).');
            }

            if ($char === '#') {
                throw new SqlGuardException('SQL comments are not permitted (#).');
            }

            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                throw new SqlGuardException('SQL comments are not permitted (/* */).');
            }

            if ($char === ';') {
                if (trim(substr($sql, $i + 1)) === '') {
                    break;
                }

                throw new SqlGuardException('Only a single statement is permitted (";" found).');
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $i = $this->consumeString($sql, $i, $char, $length);

                continue;
            }

            if (ord($char) < 0x20 || ord($char) >= 0x80) {
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
     * True when a single byte is a valid SQL identifier/keyword character (A-Z, a-z, 0-9, underscore).
     *
     * @param  string  $char  Single-byte character to classify.
     * @return bool
     */
    private static function isWordChar(string $char): bool
    {
        return ($char >= 'A' && $char <= 'Z')
            || ($char >= 'a' && $char <= 'z')
            || ($char >= '0' && $char <= '9')
            || $char === '_';
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
            $char = $sql[$i];

            if ($backslashEscapes && $char === '\\') {
                $i += 2;

                continue;
            }

            if ($char === $delim) {
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
