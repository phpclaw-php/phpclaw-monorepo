<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Helpers;

use PHPUnit\Framework\TestCase;

abstract class OcDbTestCase extends TestCase
{
    protected static \mysqli $sharedMysqli;

    protected MysqliOcDb $db;

    protected string $prefix;

    private array $managedTables = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $host = (string) (getenv('PHPCLAW_TEST_DB_HOST') ?: '127.0.0.1');
        $user = (string) (getenv('PHPCLAW_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('PHPCLAW_TEST_DB_PASS') ?: '');
        $dbName = (string) (getenv('PHPCLAW_TEST_DB_NAME') ?: 'phpclaw_oc_unit_test');

        mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = new \mysqli($host, $user, $pass);

        if ($mysqli->connect_errno !== 0) {
            static::markTestSkipped(
                'MySQL not available for native OC DB tests: '.$mysqli->connect_error,
            );

            return;
        }

        if (! $mysqli->query('CREATE DATABASE IF NOT EXISTS `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
            static::markTestSkipped('Could not create test database: '.$mysqli->error);

            return;
        }

        if (! $mysqli->select_db($dbName)) {
            static::markTestSkipped('Could not select test database: '.$mysqli->error);

            return;
        }

        $mysqli->set_charset('utf8mb4');

        $mysqli->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        static::$sharedMysqli = $mysqli;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! isset(static::$sharedMysqli)) {
            self::markTestSkipped('MySQL not available for native OC DB tests.');
        }

        $this->prefix = defined('DB_PREFIX') ? (string) constant('DB_PREFIX') : 'oc_';
        $this->db = new MysqliOcDb(static::$sharedMysqli);
        $this->managedTables = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (! isset(static::$sharedMysqli)) {
            return;
        }

        foreach (array_reverse($this->managedTables) as $table) {
            static::$sharedMysqli->query('DROP TABLE IF EXISTS `'.$table.'`');
        }

        $this->managedTables = [];
    }

    protected function resetTables(array $ddl): void
    {
        foreach ($ddl as $statement) {
            if (static::$sharedMysqli->query($statement) === false) {
                self::fail('Failed to create table: '.static::$sharedMysqli->error."\nSQL: ".$statement);
            }

            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $statement, $m)) {
                $this->managedTables[] = $m[1];
            }
        }
    }

    protected function seed(string $sql): void
    {
        if (static::$sharedMysqli->query($sql) === false) {
            self::fail('Seed query failed: '.static::$sharedMysqli->error."\nSQL: ".$sql);
        }
    }
}
