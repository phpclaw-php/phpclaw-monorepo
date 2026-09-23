<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use PhpClaw\Joomla\Component\Administrator\Database\PhpClawTables;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineBootstrapper;
use PHPUnit\Framework\TestCase;

final class EnsureTablesTest extends TestCase
{
    public function test_it_checks_all_three_tables(): void
    {
        $required = (new \ReflectionClass(EngineBootstrapper::class))->getConstant('REQUIRED_TABLES');

        self::assertSame(
            [
                PhpClawTables::CONVERSATIONS_TABLE,
                PhpClawTables::MESSAGES_TABLE,
                PhpClawTables::MEMORY_TABLE,
            ],
            $required,
        );
    }

    public function test_the_required_list_matches_the_install_schema(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3).'/component/sql/install.mysql.utf8.sql');

        preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $m);

        $required = (new \ReflectionClass(EngineBootstrapper::class))->getConstant('REQUIRED_TABLES');

        sort($m[1]);
        $sorted = $required;
        sort($sorted);

        self::assertSame($m[1], $sorted, 'Every table the installer creates must be one ensureTables() checks.');
    }
}
