<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Install;

use PhpClaw\PrestaShop\Install\SqlStatements;
use PHPUnit\Framework\TestCase;

final class InstallSqlStatementSplitTest extends TestCase
{
    public function test_a_semicolon_inside_a_comment_does_not_truncate_the_run(): void
    {
        $sql = <<<'SQL'
-- REST API tokens are issued per employee; only the sha256 hash is stored.
CREATE TABLE a (id INT)
SQL;

        $statements = SqlStatements::split($sql);

        self::assertCount(
            2,
            $statements,
            'A semicolon inside a comment splits the file, so the CREATE lands in a fragment '
            .'that begins mid-sentence and the installer aborts on it.',
        );
        self::assertStringStartsWith('only the sha256', $statements[1]);
    }

    public function test_a_comment_without_a_semicolon_keeps_its_statement_intact(): void
    {
        $sql = <<<'SQL'
-- REST API tokens are issued per employee. Only the sha256 hash is stored.
CREATE TABLE a (id INT)
SQL;

        $statements = SqlStatements::split($sql);

        self::assertCount(1, $statements);
        self::assertStringContainsString('CREATE TABLE a', $statements[0]);
    }

    public function test_blank_fragments_are_dropped_so_the_installer_never_runs_an_empty_query(): void
    {
        self::assertSame([], SqlStatements::split("  ;\n\n ; \t ;"));
    }

    public function test_statements_are_returned_in_file_order(): void
    {
        $statements = SqlStatements::split('CREATE TABLE a (id INT); CREATE TABLE b (id INT); INSERT INTO a VALUES (1)');

        self::assertCount(3, $statements);
        self::assertStringContainsString('TABLE a', $statements[0]);
        self::assertStringContainsString('TABLE b', $statements[1]);
        self::assertStringContainsString('INSERT', $statements[2]);
    }

    public function test_the_shipped_install_file_yields_only_runnable_statements(): void
    {
        $path = __DIR__.'/../../../upload/modules/phpclaw/sql/install.sql';
        $sql = str_replace('PREFIX_', 'ps_', (string) file_get_contents($path));

        foreach (SqlStatements::split($sql) as $index => $statement) {
            $body = $this->withoutLeadingComments($statement);

            if ($body === '') {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/^(CREATE|INSERT|ALTER|DROP|UPDATE|DELETE)\b/i',
                $body,
                "Statement {$index} does not begin with a SQL keyword, so the installer would abort here: {$body}",
            );
        }
    }

    /**
     * Drop leading SQL comment lines from a statement.
     *
     * @param  string  $statement  One split statement.
     * @return string The statement without its leading comment lines.
     */
    private function withoutLeadingComments(string $statement): string
    {
        $lines = [];

        foreach (explode("\n", $statement) as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '--')) {
                continue;
            }

            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }
}
