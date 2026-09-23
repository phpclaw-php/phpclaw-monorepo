<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcCategoryTool;

final class AbstractOpenCartToolTest extends OcDbTestCase
{
    public function test_fetch_one_returns_empty_array_when_db_is_null(): void
    {
        $tool = new OcCategoryTool(null, $this->prefix, true);
        $method = new \ReflectionMethod($tool, 'fetchOne');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($tool, 'SELECT 1', []));
    }

    public function test_fetch_rows_returns_empty_array_when_db_is_null(): void
    {
        $tool = new OcCategoryTool(null, $this->prefix, true);
        $method = new \ReflectionMethod($tool, 'fetchRows');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($tool, 'SELECT 1', []));
    }

    public function test_fetch_one_wraps_db_failure_in_tool_exception_with_error_label(): void
    {
        $tool = new OcCategoryTool($this->db, $this->prefix, true);
        $method = new \ReflectionMethod($tool, 'fetchOne');
        $method->setAccessible(true);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('category: operation failed.');

        $method->invoke($tool, 'SELECT * FROM `no_such_table_at_all`', []);
    }

    public function test_fetch_rows_wraps_db_failure_in_tool_exception_with_error_label(): void
    {
        $tool = new OcCategoryTool($this->db, $this->prefix, true);
        $method = new \ReflectionMethod($tool, 'fetchRows');
        $method->setAccessible(true);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('category: operation failed.');

        $method->invoke($tool, 'SELECT * FROM `no_such_table_at_all`', []);
    }

    public function test_fetch_one_returns_empty_array_when_row_not_found(): void
    {
        $categoryTable = $this->prefix.'category';
        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$categoryTable}` (
                category_id INT NOT NULL AUTO_INCREMENT,
                parent_id   INT NOT NULL DEFAULT 0,
                status      TINYINT NOT NULL DEFAULT 1,
                sort_order  INT NOT NULL DEFAULT 0,
                PRIMARY KEY (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $tool = new OcCategoryTool($this->db, $this->prefix, true);
        $method = new \ReflectionMethod($tool, 'fetchOne');
        $method->setAccessible(true);

        $result = $method->invoke($tool, "SELECT * FROM `{$categoryTable}` WHERE category_id = ?", [999]);

        self::assertSame([], $result);
    }
}
