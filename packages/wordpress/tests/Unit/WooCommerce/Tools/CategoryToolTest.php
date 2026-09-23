<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WooCommerce\Tools\CategoryTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryTool::class)]
final class CategoryToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeTerm(int $id, string $name, string $slug, int $count): object
    {
        $t = new \stdClass;
        $t->term_id = $id;
        $t->name = $name;
        $t->slug = $slug;
        $t->count = $count;
        $t->parent = 0;

        return $t;
    }

    public function test_name_is_wc_categories(): void
    {
        self::assertSame('wc_categories', (new CategoryTool)->name());
    }

    public function test_execute_returns_categories_with_counts(): void
    {
        Functions\stubs([
            'sanitize_text_field' => fn ($v) => $v,
        ]);
        Functions\expect('get_terms')->once()->andReturn([
            $this->makeTerm(1, 'Electronics', 'electronics', 15),
            $this->makeTerm(2, 'Clothing', 'clothing', 8),
        ]);

        $result = json_decode((new CategoryTool)->execute([]), true);

        self::assertCount(2, $result['data']['categories']);
        self::assertSame('Clothing', $result['data']['categories'][0]['name'], 'rows are sorted by name');
        self::assertSame(8, $result['data']['categories'][0]['product_count']);
        self::assertSame('Electronics', $result['data']['categories'][1]['name']);
        self::assertSame(15, $result['data']['categories'][1]['product_count']);
        self::assertSame(0, $result['meta']['empty_count']);
    }

    public function test_execute_detects_empty_categories(): void
    {
        Functions\stubs([
            'sanitize_text_field' => fn ($v) => $v,
        ]);
        Functions\expect('get_terms')->once()->andReturn([
            $this->makeTerm(1, 'Empty Cat', 'empty', 0),
        ]);

        $result = json_decode((new CategoryTool)->execute([]), true);

        self::assertSame(1, $result['meta']['empty_count']);
        self::assertTrue($result['data']['categories'][0]['empty']);
    }

    public function test_execute_returns_empty_when_no_categories(): void
    {
        Functions\stubs([
            'sanitize_text_field' => fn ($v) => $v,
        ]);
        Functions\expect('get_terms')->once()->andReturn([]);

        $result = json_decode((new CategoryTool)->execute([]), true);

        self::assertSame([], $result['data']['categories']);
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'WooCommerce product categories',
            (new CategoryTool)->description(),
        );
    }

    public function test_input_schema_describes_params(): void
    {
        $s = (new CategoryTool)->inputSchema();
        self::assertArrayHasKey('search', $s['properties']);
        self::assertArrayHasKey('hide_empty', $s['properties']);
        self::assertArrayHasKey('limit', $s['properties']);
        self::assertSame(30, $s['properties']['limit']['default']);
        self::assertSame(50, $s['properties']['limit']['maximum']);
    }

    public function test_search_filter_passes_through_sanitized(): void
    {
        Functions\stubs(['sanitize_text_field' => fn ($v) => 'CLEAN:'.$v]);
        Functions\expect('get_terms')
            ->once()
            ->with(\Mockery::on(static fn (array $args): bool => ($args['search'] ?? '') === 'CLEAN:books'))
            ->andReturn([]);

        (new CategoryTool)->execute(['search' => 'books']);
    }

    public function test_hide_empty_flag_passes_through(): void
    {
        Functions\stubs(['sanitize_text_field' => fn ($v) => $v]);
        Functions\expect('get_terms')
            ->once()
            ->with(\Mockery::on(static fn (array $args): bool => ($args['hide_empty'] ?? null) === true))
            ->andReturn([]);

        (new CategoryTool)->execute(['hide_empty' => true]);
    }

    public function test_limit_above_max_is_rejected_not_clamped(): void
    {
        $r = json_decode((new CategoryTool)->execute(['limit' => 999]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LIMIT', $r['error']['code']);
    }

    public function test_get_terms_throwable_wraps_in_tool_exception(): void
    {
        Functions\stubs(['sanitize_text_field' => fn ($v) => $v]);
        Functions\expect('get_terms')->twice()->andThrow(new \RuntimeException('boom'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/WC category query failed/');

        (new CategoryTool)->execute([]);
    }

    private function termFetcher(array $terms): callable
    {
        return static function (array $args) use ($terms): mixed {
            if (($args['fields'] ?? '') === 'count') {
                return count($terms);
            }

            if (isset($args['include'])) {
                return array_values(array_filter(
                    $terms,
                    static fn (object $t): bool => in_array((int) $t->term_id, (array) $args['include'], true),
                ));
            }

            $number = (int) ($args['number'] ?? 0);
            $offset = (int) ($args['offset'] ?? 0);

            return $number === 0 ? $terms : array_slice($terms, $offset, $number);
        };
    }

    private function termDouble(int $id, string $name, int $count, int $parent = 0): object
    {
        $t = new \stdClass;
        $t->term_id = $id;
        $t->name = $name;
        $t->slug = strtolower(str_replace(' ', '-', $name));
        $t->description = 'Description for '.$name;
        $t->count = $count;
        $t->parent = $parent;

        return $t;
    }

    public function test_query_orders_deterministically_despite_get_terms_limits(): void
    {
        $passed = [];
        $tool = new CategoryTool(function (array $args) use (&$passed): mixed {
            $passed = $args;

            return [];
        });

        $tool->execute([]);

        self::assertSame('name', $passed['orderby'], 'get_terms() throws on an array orderby');
        self::assertSame('product_cat', $passed['taxonomy']);
    }

    public function test_ties_on_name_are_broken_by_term_id(): void
    {
        $terms = [
            $this->termDouble(9, 'Same Name', 1),
            $this->termDouble(3, 'Same Name', 2),
            $this->termDouble(7, 'Same Name', 3),
        ];

        $r = json_decode((new CategoryTool(static fn (array $a): array => $terms))->execute([
            'columns' => ['id', 'name'],
        ]), true);

        self::assertSame([3, 7, 9], array_column($r['data']['categories'], 'id'));
    }

    public function test_total_reflects_the_filters_not_the_whole_taxonomy(): void
    {
        $passed = [];
        $terms = [$this->termDouble(1, 'A', 1), $this->termDouble(2, 'B', 2)];

        $tool = new CategoryTool(function (array $args) use (&$passed, $terms): array {
            $passed = $args;

            return $terms;
        });

        $r = json_decode($tool->execute(['search' => 'phpclaw', 'hide_empty' => true]), true);

        self::assertSame('phpclaw', $passed['search']);
        self::assertTrue($passed['hide_empty']);
        self::assertSame(2, $r['meta']['total']);
    }

    public function test_pagination_uses_a_real_total(): void
    {
        $terms = [];
        for ($i = 1; $i <= 5; $i++) {
            $terms[] = $this->termDouble($i, 'Cat '.$i, $i);
        }

        $r = json_decode((new CategoryTool($this->termFetcher($terms)))->execute(['limit' => 2]), true);

        self::assertSame(5, $r['meta']['total']);
        self::assertSame(2, $r['meta']['count']);
        self::assertTrue($r['meta']['has_more']);
        self::assertSame(2, $r['meta']['next_offset']);
    }

    public function test_parent_name_resolves_through_one_batched_lookup(): void
    {
        $terms = [
            $this->termDouble(1, 'Parent', 5),
            $this->termDouble(2, 'Child', 2, 1),
        ];

        $includeCalls = 0;
        $tool = new CategoryTool(function (array $args) use (&$includeCalls, $terms): array {
            if (isset($args['include'])) {
                $includeCalls++;

                return array_values(array_filter(
                    $terms,
                    static fn (object $t): bool => in_array((int) $t->term_id, (array) $args['include'], true),
                ));
            }

            return $terms;
        });

        $r = json_decode($tool->execute(['columns' => ['id', 'parent_name']]), true);

        self::assertSame(1, $includeCalls);
        self::assertSame('Parent', $r['data']['categories'][0]['parent_name']);
    }

    public function test_wildcard_excludes_expensive_fields(): void
    {
        $r = json_decode((new CategoryTool($this->termFetcher([
            $this->termDouble(1, 'Cat', 1),
        ])))->execute(['columns' => ['*']]), true);

        self::assertNotContains('parent_name', $r['meta']['columns_returned']);
        self::assertNotContains('permalink', $r['meta']['columns_returned']);
        self::assertContains('name', $r['meta']['columns_returned']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new CategoryTool)->execute([]));
    }

    public function test_forbidden_reads_no_categories(): void
    {
        $this->denyAllCapabilities();
        $fetched = false;

        $tool = new CategoryTool(function (array $args) use (&$fetched): mixed {
            $fetched = true;

            return [];
        });

        $tool->execute([]);

        self::assertFalse($fetched);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $r = json_decode((new CategoryTool)->execute(['phpclaw_bogus' => 1]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $r['error']['code']);
    }

    public function test_unknown_column_is_rejected(): void
    {
        $r = json_decode((new CategoryTool)->execute(['columns' => ['thumbnail_id']]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_COLUMN', $r['error']['code']);
    }

    public function test_limit_above_the_maximum_is_rejected(): void
    {
        $r = json_decode((new CategoryTool)->execute(['limit' => 999]), true);

        self::assertFalse($r['success']);
        self::assertSame('INVALID_LIMIT', $r['error']['code']);
    }

    public function test_a_wp_error_from_get_terms_throws(): void
    {
        Functions\when('is_wp_error')->justReturn(true);

        $error = new class
        {
            public function get_error_message(): string
            {
                return 'invalid taxonomy';
            }
        };
        $tool = new CategoryTool(static fn (array $args): mixed => $error);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new CategoryTool;

        self::assertSame('woocommerce.categories.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], CategoryTool::examples());
    }

    public function test_schema_mode_returns_metadata_without_reading_categories(): void
    {
        Functions\expect('get_terms')->never();

        $result = json_decode((new CategoryTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertFalse($result['meta']['database_query_performed']);
        self::assertSame(['schema', 'aggregate', 'query'], $result['data']['modes']);
        self::assertSame('product_cat', $result['data']['taxonomy']);
        self::assertSame('phpclaw_use_chat', $result['data']['woocommerce_capability']);
    }

    public function test_aggregate_mode_totals_categories_and_warns_about_ignored_arguments(): void
    {
        Functions\expect('get_terms')->once()->andReturn([
            $this->makeTerm(1, 'Electronics', 'electronics', 15),
            $this->makeTerm(2, 'Clothing', 'clothing', 0),
        ]);

        $result = json_decode((new CategoryTool)->execute(['aggregate' => true, 'limit' => 5]), true);

        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(2, $result['data']['total_categories']);
        self::assertSame(1, $result['data']['empty_categories']);
        self::assertSame(2, $result['data']['top_level_categories']);
        self::assertSame(15, $result['data']['assigned_products']);
        self::assertSame('IGNORED_ARGUMENT', $result['warnings'][0]['code']);
        self::assertStringContainsString('limit', $result['warnings'][0]['message']);
    }
}
