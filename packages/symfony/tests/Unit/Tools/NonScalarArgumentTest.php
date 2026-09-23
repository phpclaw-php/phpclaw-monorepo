<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Tools;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\Tools\DatabaseTool;
use PhpClaw\Symfony\Tools\LogTool;
use PHPUnit\Framework\TestCase;

final class NonScalarArgumentTest extends TestCase
{
    private string $logDir = '';

    private array $raisedWarnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir().'/phpclaw_nonscalar_'.bin2hex(random_bytes(4));
        mkdir($this->logDir);
        file_put_contents(
            $this->logDir.'/dev.log',
            "[2026-09-13T10:00:00] app.ERROR: boom\n[2026-09-13T10:00:01] app.INFO: fine\n",
        );

        $this->raisedWarnings = [];
        set_error_handler(function (int $number, string $message): bool {
            $this->raisedWarnings[] = $message;

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        foreach (glob($this->logDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logDir);

        parent::tearDown();
    }

    public function test_read_log_rejects_an_array_level_instead_of_reporting_an_empty_log(): void
    {
        $result = $this->decode($this->logTool()->execute(['filename' => 'dev.log', 'level' => ['ERROR', 'WARNING']]));

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
        self::assertSame('"level" must be a string.', $result['error']['message']);
        self::assertSame([], $this->arrayToStringWarnings());
    }

    public function test_read_log_rejects_an_array_filename(): void
    {
        $result = $this->decode($this->logTool()->execute(['filename' => ['dev.log']]));

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
        self::assertSame('"filename" must be a string.', $result['error']['message']);
        self::assertSame([], $this->arrayToStringWarnings());
    }

    public function test_read_log_still_reads_the_file_for_a_string_level(): void
    {
        $result = $this->decode($this->logTool()->execute(['filename' => 'dev.log', 'level' => 'ERROR']));

        self::assertTrue($result['success']);
        self::assertSame(['[2026-09-13T10:00:00] app.ERROR: boom'], $result['data']['entries']);
        self::assertSame([], $this->arrayToStringWarnings());
    }

    public function test_an_empty_level_filter_is_not_reported_as_an_empty_log(): void
    {
        $result = $this->decode($this->logTool()->execute(['filename' => 'dev.log', 'level' => 'DEBUG']));

        self::assertTrue($result['success']);
        self::assertSame([], $result['data']['entries']);

        $message = $result['warnings'][0]['message'];

        self::assertStringContainsString('no DEBUG entries', $message);
        self::assertStringNotContainsString('The log file is empty.', $message);
    }

    public function test_a_genuinely_empty_log_still_says_so(): void
    {
        file_put_contents($this->logDir.'/blank.log', '');

        $result = $this->decode($this->logTool()->execute(['filename' => 'blank.log']));

        self::assertSame('The log file is empty.', $result['warnings'][0]['message']);
    }

    public function test_db_query_rejects_an_array_sql(): void
    {
        $result = $this->decode($this->databaseTool()->execute(['sql' => ['SELECT * FROM t']]));

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
        self::assertSame([], $this->arrayToStringWarnings());
    }

    public function test_db_query_rejects_an_array_limit_instead_of_silently_returning_one_row(): void
    {
        $result = $this->decode($this->databaseTool()->execute(['sql' => 'SELECT * FROM t', 'limit' => [50]]));

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
        self::assertSame('"limit" must be an integer between 1 and 100.', $result['error']['message']);
        self::assertSame([], $this->arrayToStringWarnings());
    }

    public function test_a_limit_word_in_a_literal_cannot_defeat_the_row_ceiling(): void
    {
        $connection = $this->connection();
        for ($i = 0; $i < 150; $i++) {
            $connection->insert('t', ['name' => 'row '.$i]);
        }

        $tool = new DatabaseTool($connection, $this->consoleContext(), null, false);
        $result = $this->decode($tool->execute(['sql' => "SELECT id FROM t WHERE name != 'no limit'"]));

        self::assertTrue($result['success']);
        self::assertSame(100, $result['meta']['total'], 'reading must stop at MAX_ROWS');
        self::assertTrue($result['meta']['truncated']);
        self::assertSame('ROW_CAP_REACHED', $result['warnings'][0]['code']);
    }

    public function test_an_explicit_small_limit_is_still_honoured(): void
    {
        $connection = $this->connection();
        for ($i = 0; $i < 150; $i++) {
            $connection->insert('t', ['name' => 'row '.$i]);
        }

        $tool = new DatabaseTool($connection, $this->consoleContext(), null, false);
        $result = $this->decode($tool->execute(['sql' => 'SELECT id FROM t LIMIT 5']));

        self::assertSame(5, $result['meta']['total']);
        self::assertFalse($result['meta']['truncated']);
    }

    public function test_db_query_still_runs_for_a_string_sql(): void
    {
        $result = $this->decode($this->databaseTool()->execute(['sql' => 'SELECT * FROM t']));

        self::assertTrue($result['success']);
        self::assertSame([['id' => 1, 'name' => 'a']], $result['data']['rows']);
        self::assertSame([], $this->arrayToStringWarnings());
    }

    private function logTool(): LogTool
    {
        return new LogTool($this->logDir, $this->consoleContext(), null, false);
    }

    private function databaseTool(): DatabaseTool
    {
        return new DatabaseTool($this->connection(), $this->consoleContext(), null, false);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement("INSERT INTO t (name) VALUES ('a')");

        return $connection;
    }

    private function consoleContext(): ConsoleContext
    {
        $context = new ConsoleContext;
        $context->markConsole();

        return $context;
    }

    private function arrayToStringWarnings(): array
    {
        return array_values(array_filter(
            $this->raisedWarnings,
            static fn (string $message): bool => str_contains($message, 'Array to string conversion'),
        ));
    }

    private function decode(string $payload): array
    {
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
