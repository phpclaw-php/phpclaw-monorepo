<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpCronTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpCronTool::class)]
final class WpCronToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        Functions\expect('wp_get_schedules')->zeroOrMoreTimes()->andReturn([
            'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
            'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
            'daily' => ['interval' => 86400, 'display' => 'Once Daily'],
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_name_is_wp_cron(): void
    {
        self::assertSame('wp_cron', (new WpCronTool)->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'WordPress scheduled cron events',
            (new WpCronTool)->description(),
        );
    }

    public function test_input_schema_has_search_and_limit(): void
    {
        $schema = (new WpCronTool)->inputSchema();
        self::assertArrayHasKey('search', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
    }

    public function test_execute_returns_events(): void
    {
        $future = time() + 3600;
        Functions\expect('_get_cron_array')->once()->andReturn([
            $future => [
                'wp_update_plugins' => ['hash1' => ['schedule' => 'twicedaily', 'interval' => 43200]],
            ],
        ]);

        $tool = new WpCronTool;
        $result = json_decode($tool->execute([]), true);

        self::assertArrayHasKey('events', $result['data']);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('wp_update_plugins', $result['data']['events'][0]['hook']);
        self::assertFalse($result['data']['events'][0]['is_overdue']);
    }

    public function test_execute_detects_overdue_events(): void
    {
        $past = time() - 7200;
        Functions\expect('_get_cron_array')->once()->andReturn([
            $past => [
                'my_backup' => ['hash1' => ['schedule' => 'daily', 'interval' => 86400]],
            ],
        ]);

        $result = json_decode((new WpCronTool)->execute([]), true);

        self::assertTrue($result['data']['events'][0]['is_overdue']);
    }

    public function test_execute_filters_by_search(): void
    {
        $future = time() + 3600;
        Functions\expect('_get_cron_array')->once()->andReturn([
            $future => [
                'wp_update_plugins' => ['h1' => ['schedule' => 'twicedaily', 'interval' => 43200]],
                'wc_scheduled_sales' => ['h2' => ['schedule' => 'daily', 'interval' => 86400]],
            ],
        ]);

        $result = json_decode((new WpCronTool)->execute(['search' => 'wc']), true);

        self::assertSame(1, $result['meta']['count']);
        self::assertSame('wc_scheduled_sales', $result['data']['events'][0]['hook']);
    }

    public function test_execute_returns_empty_when_no_crons(): void
    {
        Functions\expect('_get_cron_array')->once()->andReturn([]);

        $result = json_decode((new WpCronTool)->execute([]), true);

        self::assertSame([], $result['data']['events']);
        self::assertSame(0, $result['meta']['total']);
    }

    public function test_execute_handles_one_time_events(): void
    {
        $future = time() + 600;
        Functions\expect('_get_cron_array')->once()->andReturn([
            $future => [
                'my_one_time_hook' => ['hash1' => ['schedule' => false, 'interval' => 0]],
            ],
        ]);

        $result = json_decode((new WpCronTool)->execute([]), true);

        self::assertSame('one-time', $result['data']['events'][0]['schedule']);
    }

    public function test_schema_mode_returns_columns_and_capabilities(): void
    {
        Functions\expect('_get_cron_array')->zeroOrMoreTimes()->andReturn([]);

        $r = json_decode((new WpCronTool)->execute(['schema' => true]), true);

        self::assertSame('schema', $r['meta']['mode']);
        self::assertNotEmpty($r['data']['available_columns']);
        self::assertNotEmpty($r['data']['default_columns']);
        self::assertArrayHasKey('hook', $r['data']['column_descriptions']);
        self::assertContains('one-time', $r['data']['schedule_names']);
        self::assertContains('search', $r['data']['filters']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $now = time();
        Functions\expect('_get_cron_array')->once()->andReturn([
            $now + 3600 => [
                'wp_update_plugins' => ['h1' => ['schedule' => 'twicedaily', 'interval' => 43200]],
            ],
            $now - 7200 => [
                'old_overdue_hook' => ['h2' => ['schedule' => 'daily', 'interval' => 86400]],
            ],
            $now + 86400 => [
                'tomorrow_hook' => ['h3' => ['schedule' => 'daily', 'interval' => 86400]],
            ],
        ]);

        $r = json_decode((new WpCronTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $r['meta']['mode']);
        self::assertSame(3, $r['data']['total_events']);
        self::assertSame(1, $r['data']['overdue_count']);
        self::assertSame('wp_update_plugins', $r['data']['next_event']['hook']);
        self::assertArrayHasKey('twicedaily', $r['data']['by_schedule']);
        self::assertArrayHasKey('daily', $r['data']['by_schedule']);
    }

    public function test_hook_filter_matches_exact(): void
    {
        $future = time() + 3600;
        Functions\expect('_get_cron_array')->once()->andReturn([
            $future => [
                'a_hook' => ['h1' => ['schedule' => 'daily', 'interval' => 86400]],
                'b_hook' => ['h2' => ['schedule' => 'daily', 'interval' => 86400]],
            ],
        ]);

        $r = json_decode((new WpCronTool)->execute(['hook' => 'b_hook']), true);

        self::assertSame(1, $r['meta']['count']);
        self::assertSame('b_hook', $r['data']['events'][0]['hook']);
    }

    public function test_overdue_only_filter(): void
    {
        $now = time();
        Functions\expect('_get_cron_array')->once()->andReturn([
            $now - 3600 => ['past_hook' => ['h1' => ['schedule' => 'daily', 'interval' => 86400]]],
            $now + 3600 => ['future_hook' => ['h2' => ['schedule' => 'daily', 'interval' => 86400]]],
        ]);

        $r = json_decode((new WpCronTool)->execute(['overdue_only' => true]), true);

        self::assertSame(1, $r['meta']['count']);
        self::assertSame('past_hook', $r['data']['events'][0]['hook']);
    }

    public function test_limit_caps_results(): void
    {
        $future = time() + 3600;
        Functions\expect('_get_cron_array')->once()->andReturn([
            $future => [
                'a' => ['h1' => ['schedule' => 'daily', 'interval' => 86400]],
                'b' => ['h2' => ['schedule' => 'daily', 'interval' => 86400]],
                'c' => ['h3' => ['schedule' => 'daily', 'interval' => 86400]],
            ],
        ]);

        $r = json_decode((new WpCronTool)->execute(['limit' => 2]), true);

        self::assertSame(2, $r['meta']['count']);
        self::assertSame(3, $r['meta']['total']);
        self::assertTrue($r['meta']['has_more']);
    }

    public function test_collect_events_returns_empty_when_crons_not_array(): void
    {
        Functions\expect('_get_cron_array')->once()->andReturn(null);

        $r = json_decode((new WpCronTool)->execute([]), true);

        self::assertSame([], $r['data']['events']);
    }

    public function test_resolve_columns_reflection(): void
    {
        $tool = new WpCronTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        self::assertNotEmpty($m->invoke($tool, ['*']));
        self::assertContains('hook', $m->invoke($tool, []));
        self::assertContains('hook', $m->invoke($tool, 'string'));
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpCronTool)->execute([]));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new WpCronTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {
        Functions\when('_get_cron_array')->justReturn([]);

        $decoded = json_decode((new WpCronTool)->execute([]), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }

    public function test_description_names_the_capability_the_tool_checks(): void
    {
        $tool = (new WpCronTool);

        self::assertStringContainsString('"'.$tool->requiredCapability().'"', $tool->description());
    }
}
