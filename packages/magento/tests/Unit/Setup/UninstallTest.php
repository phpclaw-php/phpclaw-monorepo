<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PhpClaw\Magento\Setup\Uninstall;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    private SchemaSetupInterface&MockObject $setup;

    private ModuleContextInterface&MockObject $context;

    private AdapterInterface&MockObject $connection;

    private Uninstall $uninstall;

    protected function setUp(): void
    {
        $this->setup = $this->createMock(SchemaSetupInterface::class);
        $this->context = $this->createMock(ModuleContextInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->setup->method('getConnection')->willReturn($this->connection);
        $this->setup->method('getTable')->willReturnArgument(0);
        $this->setup->method('startSetup')->willReturn($this->setup);
        $this->setup->method('endSetup')->willReturn($this->setup);

        $this->uninstall = new Uninstall;
    }

    public function test_uninstall_calls_start_and_end_setup(): void
    {
        $this->setup->expects(self::once())->method('startSetup');
        $this->setup->expects(self::once())->method('endSetup');

        $this->connection->method('isTableExists')->willReturn(false);

        $this->uninstall->uninstall($this->setup, $this->context);
    }

    public function test_uninstall_drops_tables_when_they_exist(): void
    {
        $this->connection->method('isTableExists')->willReturn(true);

        $this->connection->expects(self::exactly(3))
            ->method('dropTable');

        $this->connection->method('delete')->willReturn(1);

        $this->uninstall->uninstall($this->setup, $this->context);
    }

    public function test_uninstall_does_not_drop_tables_when_missing(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $this->connection->expects(self::never())->method('dropTable');

        $this->connection->method('delete')->willReturn(0);

        $this->uninstall->uninstall($this->setup, $this->context);
    }

    public function test_uninstall_drops_phpclaw_messages_table(): void
    {
        $dropped = [];
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('dropTable')
            ->willReturnCallback(function (string $t) use (&$dropped): bool {
                $dropped[] = $t;

                return true;
            });
        $this->connection->method('delete')->willReturn(1);

        $this->uninstall->uninstall($this->setup, $this->context);

        self::assertContains('phpclaw_messages', $dropped);
    }

    public function test_uninstall_drops_phpclaw_conversations_table(): void
    {
        $dropped = [];
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('dropTable')
            ->willReturnCallback(function (string $t) use (&$dropped): bool {
                $dropped[] = $t;

                return true;
            });
        $this->connection->method('delete')->willReturn(1);

        $this->uninstall->uninstall($this->setup, $this->context);

        self::assertContains('phpclaw_conversations', $dropped);
    }

    public function test_uninstall_drops_phpclaw_memory_table(): void
    {
        $dropped = [];
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('dropTable')
            ->willReturnCallback(function (string $t) use (&$dropped): bool {
                $dropped[] = $t;

                return true;
            });
        $this->connection->method('delete')->willReturn(1);

        $this->uninstall->uninstall($this->setup, $this->context);

        self::assertContains('phpclaw_memory', $dropped);
    }

    public function test_uninstall_deletes_config_with_phpclaw_path(): void
    {
        $this->connection->method('isTableExists')->willReturn(false);

        $this->connection->expects(self::once())
            ->method('delete')
            ->with('core_config_data', self::stringContains("path LIKE 'phpclaw/%'"));

        $this->uninstall->uninstall($this->setup, $this->context);
    }

    public function test_uninstall_calls_get_table_for_each_phpclaw_table(): void
    {
        $tables = [];
        $this->setup->method('getTable')
            ->willReturnCallback(function (string $t) use (&$tables): string {
                $tables[] = $t;

                return $t;
            });

        $this->connection->method('isTableExists')->willReturn(false);
        $this->connection->method('delete')->willReturn(0);

        $this->uninstall->uninstall($this->setup, $this->context);

        self::assertContains('phpclaw_messages', $tables);
        self::assertContains('phpclaw_conversations', $tables);
        self::assertContains('phpclaw_memory', $tables);
        self::assertContains('core_config_data', $tables);
    }
}
