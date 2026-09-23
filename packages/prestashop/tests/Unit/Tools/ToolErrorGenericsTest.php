<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tools\DatabaseTool;
use PhpClaw\PrestaShop\Tools\PsCustomerTool;
use PhpClaw\PrestaShop\Tools\PsOrderTool;
use PhpClaw\PrestaShop\Tools\PsProductTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseTool::class)]
final class ToolErrorGenericsTest extends TestCase
{
    use ActsAsChatTierEmployee;

    private const SECRET = 'Table ps_product has crashed, internal MySQL error 1034';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();
        \PrestaShopLogger::reset();

        parent::tearDown();
    }

    private function failingDb(): PsDbInterface
    {
        $db = $this->createMock(PsDbInterface::class);
        $db->method('query')->willThrowException(new \RuntimeException(self::SECRET));
        $db->method('escape')->willReturnArgument(0);

        return $db;
    }

    public function test_database_tool_does_not_leak_raw_message_on_query_failure(): void
    {
        $tool = new DatabaseTool($this->failingDb(), 'ps_', isConsole: true);

        try {
            $tool->execute(['sql' => 'SELECT 1']);
            self::fail('Expected ToolException was not thrown.');
        } catch (ToolException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('database:', $e->getMessage());
        }
    }

    public function test_database_tool_logs_real_message_server_side_on_query_failure(): void
    {
        $tool = new DatabaseTool($this->failingDb(), 'ps_', isConsole: true);

        try {
            $tool->execute(['sql' => 'SELECT 1']);
        } catch (ToolException) {
        }

        $logged = array_column(\PrestaShopLogger::$logs, 'message');
        self::assertNotEmpty($logged, 'PrestaShopLogger::addLog() must have been called.');
        self::assertStringContainsString(self::SECRET, implode(' ', $logged));
    }

    public function test_database_tool_does_not_leak_raw_message_on_schema_failure(): void
    {
        $tool = new DatabaseTool($this->failingDb(), 'ps_', isConsole: true);

        try {
            $tool->execute(['schema' => true]);
            self::fail('Expected ToolException was not thrown.');
        } catch (ToolException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('database:', $e->getMessage());
        }
    }

    public function test_ps_product_tool_does_not_leak_raw_message_on_query_failure(): void
    {
        $tool = new PsProductTool($this->failingDb(), 'ps_');

        try {
            $tool->execute([]);
            self::fail('Expected ToolException was not thrown.');
        } catch (ToolException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('ps_product:', $e->getMessage());
        }
    }

    public function test_ps_product_tool_logs_real_message_server_side(): void
    {
        $tool = new PsProductTool($this->failingDb(), 'ps_');

        try {
            $tool->execute([]);
        } catch (ToolException) {
        }

        $logged = array_column(\PrestaShopLogger::$logs, 'message');
        self::assertNotEmpty($logged);
        self::assertStringContainsString(self::SECRET, implode(' ', $logged));
    }

    public function test_ps_order_tool_does_not_leak_raw_message_on_query_failure(): void
    {
        $tool = new PsOrderTool($this->failingDb(), 'ps_');

        try {
            $tool->execute([]);
            self::fail('Expected ToolException was not thrown.');
        } catch (ToolException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('ps_order:', $e->getMessage());
        }
    }

    public function test_ps_order_tool_logs_real_message_server_side(): void
    {
        $tool = new PsOrderTool($this->failingDb(), 'ps_');

        try {
            $tool->execute([]);
        } catch (ToolException) {
        }

        $logged = array_column(\PrestaShopLogger::$logs, 'message');
        self::assertNotEmpty($logged);
        self::assertStringContainsString(self::SECRET, implode(' ', $logged));
    }

    public function test_ps_customer_tool_does_not_leak_raw_message_on_query_failure(): void
    {
        $tool = new PsCustomerTool($this->failingDb(), 'ps_');

        try {
            $tool->execute([]);
            self::fail('Expected ToolException was not thrown.');
        } catch (ToolException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('ps_customer:', $e->getMessage());
        }
    }

    public function test_ps_customer_tool_logs_real_message_server_side(): void
    {
        $tool = new PsCustomerTool($this->failingDb(), 'ps_');

        try {
            $tool->execute([]);
        } catch (ToolException) {
        }

        $logged = array_column(\PrestaShopLogger::$logs, 'message');
        self::assertNotEmpty($logged);
        self::assertStringContainsString(self::SECRET, implode(' ', $logged));
    }

    public function test_logger_severity_is_3_for_tool_errors(): void
    {
        $tool = new DatabaseTool($this->failingDb(), 'ps_', isConsole: true);

        try {
            $tool->execute(['sql' => 'SELECT 1']);
        } catch (ToolException) {
        }

        $severities = array_column(\PrestaShopLogger::$logs, 'severity');
        self::assertContains(3, $severities, 'Tool DB errors must be logged at severity 3 (error).');
    }
}
