<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Admin\AnalyticsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalyticsPage::class)]
final class AnalyticsPageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_attr' => static fn (string $s): string => $s,
            'wp_cache_get' => static fn (string $k, string $g = ''): bool => false,
            'wp_cache_set' => static fn (string $k, mixed $v, string $g = '', int $ttl = 0): bool => true,
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function allowChat(bool $manageAll, int $userId = 7): void
    {
        Functions\when('current_user_can')->alias(
            static fn (string $cap): bool => $cap === 'phpclaw_manage_all_conversations' ? $manageAll : true,
        );
        Functions\when('get_current_user_id')->justReturn($userId);
    }

    private function recordingWpdb(): object
    {
        return new class
        {
            public string $prefix = 'wp_';

            public array $queries = [];

            public function get_var(string|array $sql): int
            {
                [$rawSql, $args] = is_array($sql) ? $sql : [$sql, []];
                $this->queries[] = ['sql' => $rawSql, 'args' => $args];

                if (stripos($rawSql, '1 DAY') !== false) {
                    return 5;
                }

                if (stripos($rawSql, 'INNER JOIN') !== false) {
                    return 100;
                }

                $tableArg = (string) ($args[0] ?? '');

                if (stripos($tableArg, 'phpclaw_messages') !== false) {
                    return 100;
                }

                return 25;
            }

            public function prepare(string $sql, mixed ...$args): array
            {
                return [$sql, $args];
            }
        };
    }

    public function test_register_method_exists_and_is_callable(): void
    {
        $ref = new \ReflectionClass(AnalyticsPage::class);
        $m = $ref->getMethod('register');

        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
        self::assertSame(0, $m->getNumberOfParameters());
    }

    public function test_render_dies_for_user_without_chat_capability(): void
    {
        Functions\expect('current_user_can')->once()->with('phpclaw_use_chat')->andReturnFalse();
        Functions\expect('wp_die')->once()->andThrow(new \RuntimeException('died'));

        $this->expectException(\RuntimeException::class);
        AnalyticsPage::render();
    }

    public function test_render_shows_three_stat_cards_for_an_administrator(): void
    {
        $this->allowChat(true);
        $GLOBALS['wpdb'] = $this->recordingWpdb();

        ob_start();
        AnalyticsPage::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Total Conversations', $html);
        self::assertStringContainsString('Total Messages', $html);
        self::assertStringContainsString('Active', $html);
        self::assertStringContainsString('100', $html);
        self::assertStringContainsString('25', $html);
    }

    public function test_administrator_counts_are_not_scoped_to_a_user(): void
    {
        $this->allowChat(true);
        $wpdb = $this->recordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        ob_start();
        AnalyticsPage::render();
        ob_end_clean();

        self::assertCount(3, $wpdb->queries);

        foreach ($wpdb->queries as $query) {
            self::assertStringNotContainsString('user_id = %d', $query['sql']);
        }
    }

    public function test_non_admin_counts_are_scoped_to_the_acting_user(): void
    {
        $this->allowChat(false, 42);
        $wpdb = $this->recordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        ob_start();
        AnalyticsPage::render();
        ob_end_clean();

        self::assertCount(3, $wpdb->queries);

        foreach ($wpdb->queries as $query) {
            self::assertStringContainsString('user_id = %d', $query['sql']);
            self::assertContains(42, $query['args']);
        }
    }

    public function test_non_admin_message_count_joins_through_conversations(): void
    {
        $this->allowChat(false, 42);
        $wpdb = $this->recordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        ob_start();
        AnalyticsPage::render();
        ob_end_clean();

        $joined = array_filter(
            $wpdb->queries,
            static fn (array $q): bool => stripos($q['sql'], 'INNER JOIN') !== false,
        );

        self::assertCount(1, $joined);

        $query = array_values($joined)[0];

        self::assertStringContainsString('c.user_id = %d', $query['sql']);
    }

    public function test_administrator_message_count_joins_through_conversations_without_a_user_predicate(): void
    {
        $this->allowChat(true);
        $wpdb = $this->recordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        ob_start();
        AnalyticsPage::render();
        ob_end_clean();

        $joined = array_values(array_filter(
            $wpdb->queries,
            static fn (array $q): bool => stripos($q['sql'], 'INNER JOIN') !== false,
        ));

        self::assertCount(1, $joined);
        self::assertStringNotContainsString('user_id', $joined[0]['sql']);
    }

    public function test_cache_keys_are_scoped_per_identity(): void
    {
        $keys = [];

        Functions\when('wp_cache_get')->alias(
            static function (string $key, string $group = '') use (&$keys): bool {
                $keys[] = $key;

                return false;
            },
        );

        $this->allowChat(false, 42);
        $GLOBALS['wpdb'] = $this->recordingWpdb();

        ob_start();
        AnalyticsPage::render();
        ob_end_clean();

        self::assertSame([
            'analytics_total_conversations_42',
            'analytics_total_messages_42',
            'analytics_active_conversations_42',
        ], $keys);
    }

    public function test_administrator_cache_keys_use_the_all_scope(): void
    {
        $keys = [];

        Functions\when('wp_cache_get')->alias(
            static function (string $key, string $group = '') use (&$keys): bool {
                $keys[] = $key;

                return false;
            },
        );

        $this->allowChat(true);
        $GLOBALS['wpdb'] = $this->recordingWpdb();

        ob_start();
        AnalyticsPage::render();
        ob_end_clean();

        self::assertSame([
            'analytics_total_conversations_all',
            'analytics_total_messages_all',
            'analytics_active_conversations_all',
        ], $keys);
    }

    public function test_render_uses_cached_values_and_skips_db(): void
    {
        Functions\when('wp_cache_get')->alias(static function (string $key, string $group = ''): int {
            return match ($key) {
                'analytics_total_conversations_all' => 42,
                'analytics_total_messages_all' => 999,
                'analytics_active_conversations_all' => 7,
                default => 0,
            };
        });

        $this->allowChat(true);
        $wpdb = $this->recordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        ob_start();
        AnalyticsPage::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('42', $html);
        self::assertStringContainsString('999', $html);
        self::assertStringContainsString('7', $html);
        self::assertSame([], $wpdb->queries, 'COUNT queries must not run when the cache is warm');
    }
}
