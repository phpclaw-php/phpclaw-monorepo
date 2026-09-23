<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PhpClaw\Magento\Setup\Patch\Data\GrantPhpClawAclToAdmins;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GrantPhpClawAclToAdminsTest extends TestCase
{
    private const RESOURCES = [
        'PhpClaw_Magento::phpclaw',
        'PhpClaw_Magento::phpclaw_settings',
        'PhpClaw_Magento::phpclaw_chat',
        'PhpClaw_Magento::phpclaw_analytics',
        'PhpClaw_Magento::phpclaw_guide',
        'PhpClaw_Magento::phpclaw_about',
    ];

    private ModuleDataSetupInterface&MockObject $setup;

    private AdapterInterface&MockObject $connection;

    private GrantPhpClawAclToAdmins $patch;

    protected function setUp(): void
    {
        $this->setup = $this->createMock(ModuleDataSetupInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->setup->method('getConnection')->willReturn($this->connection);
        $this->setup->method('getTable')->willReturnArgument(0);

        $this->patch = new GrantPhpClawAclToAdmins($this->setup);
    }

    public function test_apply_returns_self_for_fluent_chaining(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame($this->patch, $this->patch->apply());
    }

    public function test_apply_inserts_six_rows_for_single_admin_role(): void
    {
        $this->connection->method('fetchAll')->willReturn([['role_id' => '1']]);
        $this->connection->method('fetchOne')->willReturn(0);

        $inserted = [];
        $this->connection->expects(self::exactly(6))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $bind) use (&$inserted): int {
                $inserted[] = $bind;

                return 1;
            });

        $this->patch->apply();

        $resources = array_column($inserted, 'resource_id');
        self::assertSame(self::RESOURCES, $resources);
        foreach ($inserted as $row) {
            self::assertSame(1, $row['role_id']);
            self::assertSame('allow', $row['permission']);
        }
    }

    public function test_apply_skips_resources_that_already_exist(): void
    {
        $this->connection->method('fetchAll')->willReturn([['role_id' => '1']]);

        $this->connection->method('fetchOne')->willReturnCallback(
            fn (string $sql, array $bind): int => $bind[1] === 'PhpClaw_Magento::phpclaw_chat' ? 1 : 0,
        );

        $this->connection->expects(self::exactly(5))->method('insert')->willReturn(1);

        $this->patch->apply();
    }

    public function test_apply_inserts_nothing_when_resources_all_present(): void
    {
        $this->connection->method('fetchAll')->willReturn([['role_id' => '1']]);
        $this->connection->method('fetchOne')->willReturn(1);

        $this->connection->expects(self::never())->method('insert');

        $this->patch->apply();
    }

    public function test_apply_inserts_nothing_when_no_admin_role_exists(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->connection->expects(self::never())->method('fetchOne');
        $this->connection->expects(self::never())->method('insert');

        $this->patch->apply();
    }

    public function test_apply_handles_multiple_admin_roles(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['role_id' => '1'],
            ['role_id' => '5'],
        ]);
        $this->connection->method('fetchOne')->willReturn(0);

        $this->connection->expects(self::exactly(12))->method('insert')->willReturn(1);

        $this->patch->apply();
    }

    public function test_apply_is_idempotent_across_two_runs(): void
    {
        $existing = [];

        $this->connection->method('fetchAll')->willReturn([['role_id' => '1']]);
        $this->connection->method('fetchOne')->willReturnCallback(
            fn (string $sql, array $bind): int => isset($existing["{$bind[0]}|{$bind[1]}"]) ? 1 : 0,
        );
        $this->connection->method('insert')->willReturnCallback(
            function (string $table, array $bind) use (&$existing): int {
                $existing["{$bind['role_id']}|{$bind['resource_id']}"] = true;

                return 1;
            },
        );

        $this->patch->apply();
        $countAfterFirst = count($existing);

        $this->patch->apply();
        $countAfterSecond = count($existing);

        self::assertSame(6, $countAfterFirst);
        self::assertSame(6, $countAfterSecond);
    }

    public function test_apply_binds_role_id_as_int(): void
    {
        $this->connection->method('fetchAll')->willReturn([['role_id' => '42']]);
        $this->connection->method('fetchOne')->willReturn(0);

        $captured = [];
        $this->connection->method('insert')->willReturnCallback(
            function (string $table, array $bind) use (&$captured): int {
                $captured[] = $bind;

                return 1;
            },
        );

        $this->patch->apply();

        self::assertCount(count(self::RESOURCES), $captured);

        foreach ($captured as $row) {
            self::assertSame(42, $row['role_id']);
        }
    }

    public function test_get_dependencies_returns_empty_array(): void
    {
        self::assertSame([], GrantPhpClawAclToAdmins::getDependencies());
    }

    public function test_get_aliases_returns_empty_array(): void
    {
        self::assertSame([], $this->patch->getAliases());
    }

    public function test_apply_uses_authorization_rule_table_name(): void
    {
        $this->connection->method('fetchAll')->willReturn([['role_id' => '1']]);
        $this->connection->method('fetchOne')->willReturn(0);

        $tables = [];
        $this->connection->method('insert')->willReturnCallback(
            function (string $table, array $bind) use (&$tables): int {
                $tables[] = $table;

                return 1;
            },
        );

        $this->patch->apply();

        self::assertSame(['authorization_rule'], array_unique($tables));
    }
}
