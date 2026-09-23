<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WooCommerce\Tools\TaxTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxTool::class)]
final class TaxToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->sql = [];

        \WC_Tax::$testClasses = ['reduced-rate', 'zero-rate'];
        \WC_Tax::$testRates = [];

        $this->grantCapability('phpclaw_use_chat');

        Functions\stubs([
            'sanitize_title' => fn ($v) => strtolower(str_replace(' ', '-', (string) $v)),
            'get_option' => function (string $key, $default = '') {
                return match ($key) {
                    'woocommerce_calc_taxes' => 'yes',
                    'woocommerce_prices_include_tax' => 'no',
                    'woocommerce_tax_display_shop' => 'excl',
                    default => $default,
                };
            },
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockDb(array $rows = [], int $total = 0, array $locations = []): object
    {
        global $wpdb;

        $recorder = function (string $sql): void {
            $this->sql[] = preg_replace('/\s+/', ' ', trim($sql));
        };

        $wpdb = \Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(
            static fn (string $sql, ...$args): string => vsprintf(
                str_replace(['%d', '%s'], ['%d', "'%s'"], $sql),
                $args,
            ),
        );
        $wpdb->shouldReceive('get_var')->andReturnUsing(
            static function (string $sql) use ($recorder, $total, $locations): int {
                $recorder($sql);

                return str_contains($sql, 'tax_rate_locations') ? count($locations) : $total;
            },
        );
        $wpdb->shouldReceive('get_results')->andReturnUsing(
            static function (string $sql) use ($recorder, $rows, $locations): array {
                $recorder($sql);

                return str_contains($sql, 'tax_rate_locations') ? $locations : $rows;
            },
        );

        return $wpdb;
    }

    private function rateRow(array $overrides = []): object
    {
        return (object) array_merge([
            'tax_rate_id' => 1,
            'tax_rate_country' => 'US',
            'tax_rate_state' => 'CA',
            'tax_rate' => '8.2500',
            'tax_rate_name' => 'CA Tax',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order' => 0,
            'tax_rate_class' => '',
        ], $overrides);
    }

    private function recordedSql(): string
    {
        return implode(' || ', $this->sql);
    }

    public function test_name_is_wc_tax(): void
    {
        self::assertSame('wc_tax', (new TaxTool)->name());
    }

    public function test_description_states_read_only_and_required_capability(): void
    {
        $description = (new TaxTool)->description();

        self::assertStringContainsString('READ-ONLY', $description);
        self::assertStringContainsString('phpclaw_use_chat', $description);
        self::assertStringContainsString('NEVER USE FOR', $description);
    }

    public function test_it_requires_the_chat_capability(): void
    {
        self::assertSame('phpclaw_use_chat', (new TaxTool)->requiredCapability());
    }

    public function test_it_returns_forbidden_without_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new TaxTool)->execute([]));
    }

    public function test_schema_mode_performs_no_database_query(): void
    {
        $this->mockDb();

        $data = json_decode((new TaxTool)->execute(['schema' => true]), true);

        self::assertTrue($data['success']);
        self::assertSame('schema', $data['meta']['mode']);
        self::assertFalse($data['meta']['database_query_performed']);
        self::assertSame([], $this->sql);
        self::assertContains('standard', $data['data']['tax_classes']);
        self::assertContains('reduced-rate', $data['data']['tax_classes']);
    }

    public function test_schema_states_there_is_no_sensitive_or_blocked_tier(): void
    {
        $data = json_decode((new TaxTool)->execute(['schema' => true]), true);

        self::assertSame([], $data['data']['sensitive_columns']);
        self::assertSame([], $data['data']['blocked_columns']);
        self::assertSame(['postcode_count', 'city_count'], $data['data']['expensive_columns']);
    }

    public function test_query_mode_returns_mapped_rates(): void
    {
        $this->mockDb([$this->rateRow()], 1);

        $data = json_decode((new TaxTool)->execute([]), true);

        self::assertTrue($data['success']);
        self::assertSame('query', $data['meta']['mode']);
        self::assertCount(1, $data['data']['rates']);
        self::assertSame('US', $data['data']['rates'][0]['country']);
        self::assertSame('CA', $data['data']['rates'][0]['state']);
        self::assertSame('8.2500%', $data['data']['rates'][0]['rate']);
        self::assertSame('standard', $data['data']['rates'][0]['class']);
        self::assertTrue($data['meta']['tax_enabled']);
    }

    public function test_empty_country_and_state_map_to_star(): void
    {
        $this->mockDb([
            $this->rateRow(['tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate_compound' => 1]),
        ], 1);

        $rate = json_decode((new TaxTool)->execute(['columns' => ['*']]), true)['data']['rates'][0];

        self::assertSame('*', $rate['country']);
        self::assertSame('*', $rate['state']);
        self::assertTrue($rate['compound']);
        self::assertTrue($rate['shipping']);
    }

    public function test_total_comes_from_a_count_query_not_the_page(): void
    {
        $this->mockDb([$this->rateRow(), $this->rateRow(['tax_rate_id' => 2])], 1206);

        $meta = json_decode((new TaxTool)->execute(['limit' => 2]), true)['meta'];

        self::assertSame(1206, $meta['total']);
        self::assertSame(2, $meta['count']);
        self::assertTrue($meta['has_more']);
        self::assertSame(2, $meta['next_offset']);
        self::assertStringContainsString('SELECT COUNT(*)', $this->recordedSql());
    }

    public function test_last_page_reports_no_more_rows(): void
    {
        $this->mockDb([$this->rateRow()], 3);

        $meta = json_decode((new TaxTool)->execute(['limit' => 5, 'offset' => 2]), true)['meta'];

        self::assertFalse($meta['has_more']);
        self::assertNull($meta['next_offset']);
    }

    public function test_page_query_is_bounded_and_stably_ordered(): void
    {
        $this->mockDb([$this->rateRow()], 1);

        (new TaxTool)->execute(['limit' => 10, 'offset' => 20]);

        $sql = $this->recordedSql();

        self::assertStringContainsString(
            'ORDER BY tax_rate_priority ASC, tax_rate_id ASC',
            $sql,
        );
        self::assertStringContainsString('LIMIT 10 OFFSET 20', $sql);
    }

    public function test_country_filter_is_applied_in_the_query(): void
    {
        $this->mockDb([$this->rateRow(['tax_rate_country' => 'GB'])], 1);

        $data = json_decode((new TaxTool)->execute(['country' => 'gb']), true);

        self::assertStringContainsString("tax_rate_country = 'GB'", $this->recordedSql());
        self::assertSame('GB', $data['meta']['filters']['country']);
    }

    public function test_tax_class_filter_is_applied_in_the_query(): void
    {
        $this->mockDb([$this->rateRow(['tax_rate_class' => 'reduced-rate'])], 1);

        (new TaxTool)->execute(['tax_class' => 'reduced-rate']);

        self::assertStringContainsString("tax_rate_class = 'reduced-rate'", $this->recordedSql());
    }

    public function test_a_display_name_tax_class_is_filtered_by_its_slug(): void
    {
        \WC_Tax::$testClasses = ['Reduced rate', 'Zero rate'];

        $this->mockDb([$this->rateRow(['tax_rate_class' => 'reduced-rate'])], 1);

        $data = json_decode((new TaxTool)->execute(['tax_class' => 'Reduced rate']), true);

        self::assertTrue($data['success'], json_encode($data['error'] ?? []));
        self::assertStringContainsString("tax_rate_class = 'reduced-rate'", $this->recordedSql());
        self::assertSame('reduced-rate', $data['meta']['filters']['tax_class']);
    }

    public function test_schema_reports_tax_classes_as_filterable_slugs(): void
    {
        \WC_Tax::$testClasses = ['Reduced rate', 'Zero rate'];

        $classes = json_decode((new TaxTool)->execute(['schema' => true]), true)['data']['tax_classes'];

        self::assertSame(['standard', 'reduced-rate', 'zero-rate'], $classes);
        self::assertNotContains('Reduced rate', $classes);
    }

    public function test_standard_tax_class_filters_on_the_empty_class(): void
    {
        $this->mockDb([$this->rateRow()], 1);

        (new TaxTool)->execute(['tax_class' => 'standard']);

        self::assertStringContainsString("tax_rate_class = ''", $this->recordedSql());
    }

    public function test_count_query_carries_the_same_filter_as_the_page_query(): void
    {
        $this->mockDb([$this->rateRow(['tax_rate_country' => 'GB'])], 1);

        (new TaxTool)->execute(['country' => 'GB']);

        $countStatements = array_values(array_filter(
            $this->sql,
            static fn (string $sql): bool => str_contains($sql, 'COUNT(*)'),
        ));

        self::assertCount(1, $countStatements);
        self::assertStringContainsString("tax_rate_country = 'GB'", $countStatements[0]);
    }

    public function test_star_columns_exclude_expensive_fields(): void
    {
        $this->mockDb([$this->rateRow()], 1);

        $data = json_decode((new TaxTool)->execute(['columns' => ['*']]), true);

        self::assertNotContains('postcode_count', $data['meta']['columns_returned']);
        self::assertNotContains('city_count', $data['meta']['columns_returned']);
        self::assertArrayNotHasKey('postcode_count', $data['data']['rates'][0]);
        self::assertStringNotContainsString('tax_rate_locations', $this->recordedSql());
    }

    public function test_location_counts_use_one_batched_lookup(): void
    {
        $this->mockDb(
            [$this->rateRow(), $this->rateRow(['tax_rate_id' => 2])],
            2,
            [
                (object) ['tax_rate_id' => 1, 'location_type' => 'postcode', 'c' => 1200],
                (object) ['tax_rate_id' => 1, 'location_type' => 'city', 'c' => 3],
            ],
        );

        $data = json_decode(
            (new TaxTool)->execute(['columns' => ['id', 'postcode_count', 'city_count']]),
            true,
        );

        $lookups = array_filter(
            $this->sql,
            static fn (string $sql): bool => str_contains($sql, 'tax_rate_locations'),
        );

        self::assertCount(1, $lookups);
        self::assertSame(1200, $data['data']['rates'][0]['postcode_count']);
        self::assertSame(3, $data['data']['rates'][0]['city_count']);
        self::assertSame(0, $data['data']['rates'][1]['postcode_count']);
    }

    public function test_aggregate_mode_returns_totals_and_coverage(): void
    {
        $this->mockDb(
            [(object) ['country' => 'US', 'rate_count' => 1202]],
            1206,
            [(object) ['x' => 1]],
        );

        $data = json_decode((new TaxTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $data['meta']['mode']);
        self::assertSame(1206, $data['data']['total_rates']);
        self::assertSame(1202, $data['data']['rates_by_country']['US']);
        self::assertTrue($data['data']['tax_enabled']);
        self::assertFalse($data['data']['prices_include_tax']);
        self::assertSame('excl', $data['data']['display_in_shop']);
        self::assertArrayNotHasKey('rates', $data['data']);
    }

    public function test_aggregate_mode_warns_about_ignored_paging_arguments(): void
    {
        $this->mockDb([], 0);

        $data = json_decode((new TaxTool)->execute(['aggregate' => true, 'limit' => 5]), true);

        self::assertSame('IGNORED_ARGUMENT', $data['warnings'][0]['code']);
    }

    public function test_it_rejects_both_modes_at_once(): void
    {
        $data = json_decode((new TaxTool)->execute(['schema' => true, 'aggregate' => true]), true);

        self::assertFalse($data['success']);
        self::assertSame('CONFLICTING_MODES', $data['error']['code']);
    }

    public function test_it_rejects_an_unknown_argument(): void
    {
        $data = json_decode((new TaxTool)->execute(['tax_clas' => 'standard']), true);

        self::assertFalse($data['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $data['error']['code']);
    }

    public function test_it_rejects_an_unknown_column(): void
    {
        $data = json_decode((new TaxTool)->execute(['columns' => ['id', 'tax_rate_secret']]), true);

        self::assertFalse($data['success']);
        self::assertSame('UNKNOWN_COLUMN', $data['error']['code']);
        self::assertContains('priority', $data['error']['available_columns']);
    }

    public function test_it_rejects_an_unknown_tax_class(): void
    {
        $data = json_decode((new TaxTool)->execute(['tax_class' => 'luxury-rate']), true);

        self::assertFalse($data['success']);
        self::assertSame('UNKNOWN_TAX_CLASS', $data['error']['code']);
        self::assertContains('standard', $data['error']['valid_tax_classes']);
        self::assertSame([], $this->sql);
    }

    public function test_it_rejects_a_malformed_country(): void
    {
        $data = json_decode((new TaxTool)->execute(['country' => 'USA']), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_COUNTRY', $data['error']['code']);
    }

    public function test_it_rejects_a_limit_above_the_maximum(): void
    {
        $data = json_decode((new TaxTool)->execute(['limit' => 5000]), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_LIMIT', $data['error']['code']);
    }

    public function test_it_throws_when_the_rate_query_fails(): void
    {
        global $wpdb;

        $wpdb = \Mockery::mock('stdClass');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $sql): string => $sql);
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function () use (&$wpdb) {
            $wpdb->last_error = 'Table wp_woocommerce_tax_rates does not exist';

            return null;
        });

        $this->expectException(ToolException::class);

        (new TaxTool)->execute([]);
    }

    public function test_input_schema_forbids_additional_properties(): void
    {
        $schema = (new TaxTool)->inputSchema();

        self::assertFalse($schema['additionalProperties']);
        self::assertSame([], $schema['required']);
        self::assertArrayHasKey('tax_class', $schema['properties']);
        self::assertArrayHasKey('country', $schema['properties']);
    }

    public function test_it_is_an_idempotent_read(): void
    {
        $tool = new TaxTool;

        self::assertTrue($tool->isIdempotent());
        self::assertSame('read', $tool->risk());
        self::assertSame('woocommerce.tax.read', $tool->capability());
    }
}
