<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Admin\SiteHealth;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SiteHealth::class)]
final class SiteHealthTest extends PsDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    public function test_report_returns_exactly_the_documented_health_fields(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $keys = array_keys(SiteHealth::report($plugin, $this->db, 'ps_'));
        sort($keys);

        self::assertSame([
            'api_token_count', 'cloud_enabled', 'db_tables', 'max_iterations',
            'memory_driver', 'model', 'phpClaw_version', 'php_version',
            'prestashop_version', 'provider', 'store_messages',
            'system_prompt_set', 'tool_count',
        ], $keys);
    }

    public function test_report_php_version_is_current(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $report = SiteHealth::report($plugin, $this->db, 'ps_');

        self::assertSame(PHP_VERSION, $report['php_version']);
    }

    public function test_report_prestashop_version_is_stub(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $report = SiteHealth::report($plugin, $this->db, 'ps_');

        self::assertSame('8.1.0', $report['prestashop_version']);
    }

    public function test_report_db_tables_reports_missing_when_tables_absent(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $report = SiteHealth::report($plugin, $this->db, 'ps_');

        foreach ($report['db_tables'] as $status) {
            self::assertFalse($status);
        }
    }

    public function test_report_with_null_pdo_marks_all_tables_missing(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $report = SiteHealth::report($plugin, null, 'ps_');

        foreach ($report['db_tables'] as $status) {
            self::assertFalse($status);
        }
    }

    public function test_summary_lists_every_report_field_in_order(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $report = SiteHealth::report($plugin, $this->db, 'ps_');
        $lines = explode("\n", SiteHealth::summary($plugin, $this->db, 'ps_'));

        self::assertSame(
            "phpClaw {$report['phpClaw_version']} on PS {$report['prestashop_version']} / PHP {$report['php_version']}",
            $lines[0],
        );
        self::assertSame("Provider: {$report['provider']} | Model: {$report['model']}", $lines[1]);
        self::assertSame(
            "Memory: {$report['memory_driver']} | Max iterations: {$report['max_iterations']}",
            $lines[2],
        );
        self::assertSame('DB tables OK: ', $lines[4]);
    }

    public function test_summary_includes_php_version(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $summary = SiteHealth::summary($plugin, $this->db, 'ps_');

        self::assertStringContainsString(PHP_VERSION, $summary);
    }

    public function test_summary_mentions_missing_tables(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');
        $summary = SiteHealth::summary($plugin, $this->db, 'ps_');

        self::assertStringContainsString('Missing', $summary);
    }

    public function test_report_api_token_count_is_zero_before_any_token_is_issued(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertSame(0, SiteHealth::report($plugin, $this->db, 'ps_')['api_token_count']);
    }

    public function test_report_system_prompt_set_is_false_without_a_configured_prompt(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertFalse(SiteHealth::report($plugin, $this->db, 'ps_')['system_prompt_set']);
    }

    public function test_report_phpclaw_version_matches_the_module_constant(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertSame(
            class_exists(\Phpclaw::class) ? \Phpclaw::PHPCLAW_VERSION : 'unknown',
            SiteHealth::report($plugin, $this->db, 'ps_')['phpClaw_version'],
        );
    }

    public function test_report_db_tables_covers_every_required_table(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertSame(
            ['ps_phpclaw_memory', 'ps_phpclaw_conversations', 'ps_phpclaw_messages', 'ps_phpclaw_api_token'],
            array_keys(SiteHealth::report($plugin, $this->db, 'ps_')['db_tables']),
        );
    }

    public function test_summary_with_null_pdo_warns_about_every_missing_table(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        self::assertStringContainsString(
            '⚠ Missing tables: ps_phpclaw_memory, ps_phpclaw_conversations, ps_phpclaw_messages',
            SiteHealth::summary($plugin, null, 'ps_'),
        );
    }
}
