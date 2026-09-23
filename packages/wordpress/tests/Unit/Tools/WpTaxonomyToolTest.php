<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpTaxonomyTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpTaxonomyTool::class)]
final class WpTaxonomyToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeTerm(int $id, string $name, string $slug, int $count, int $parent = 0, string $taxonomy = 'category'): object
    {
        $t = new \stdClass;
        $t->term_id = $id;
        $t->name = $name;
        $t->slug = $slug;
        $t->count = $count;
        $t->parent = $parent;
        $t->taxonomy = $taxonomy;
        $t->description = '';

        return $t;
    }

    public function test_name_is_wp_taxonomy(): void
    {
        self::assertSame('wp_taxonomy', (new WpTaxonomyTool)->name());
    }

    public function test_input_schema_has_taxonomy_and_search(): void
    {
        $schema = (new WpTaxonomyTool)->inputSchema();
        self::assertArrayHasKey('taxonomy', $schema['properties']);
        self::assertArrayHasKey('search', $schema['properties']);
    }

    public function test_execute_returns_terms_with_counts(): void
    {
        $catTax = new \stdClass;
        $catTax->name = 'category';
        $catTax->label = 'Categories';
        $catTax->public = true;

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'is_wp_error' => fn () => false,
        ]);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn(['category' => $catTax]);
        Functions\expect('taxonomy_exists')->with('category')->once()->andReturn(true);
        Functions\expect('get_terms')->once()->andReturn([
            $this->makeTerm(1, 'Uncategorized', 'uncategorized', 12),
            $this->makeTerm(2, 'Tutorials', 'tutorials', 8),
        ]);
        Functions\expect('wp_count_terms')->once()->andReturn(2);

        $result = json_decode((new WpTaxonomyTool)->execute(['taxonomy' => 'category']), true);

        self::assertSame('category', $result['meta']['taxonomy']);
        self::assertCount(2, $result['data']['terms']);
        self::assertSame('Tutorials', $result['data']['terms'][1]['name']);
    }

    public function test_execute_returns_available_taxonomies_for_unknown(): void
    {
        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
        ]);
        Functions\expect('taxonomy_exists')->with('foobar')->once()->andReturn(false);

        $tax = new \stdClass;
        $tax->name = 'category';
        $tax->label = 'Categories';
        $tax->public = true;

        Functions\expect('get_taxonomies')->once()->andReturn(['category' => $tax]);
        Functions\expect('wp_count_terms')->once()->andReturn(5);

        $result = json_decode((new WpTaxonomyTool)->execute(['taxonomy' => 'foobar']), true);

        self::assertFalse($result['success']);
        self::assertSame('UNKNOWN_TAXONOMY', $result['error']['code']);
        self::assertSame('category', $result['error']['valid_taxonomies'][0]['slug']);
    }

    public function test_schema_mode_returns_columns_and_filters(): void
    {
        $r = json_decode((new WpTaxonomyTool)->execute(['schema' => true]), true);

        self::assertSame('schema', $r['meta']['mode']);
        self::assertNotEmpty($r['data']['available_columns']);
        self::assertNotEmpty($r['data']['default_columns']);
        self::assertContains('taxonomy', $r['data']['filters']);
    }

    public function test_aggregate_mode_returns_per_taxonomy_counts(): void
    {
        $catTax = (object) ['name' => 'category', 'label' => 'Categories', 'public' => true];
        $tagTax = (object) ['name' => 'post_tag', 'label' => 'Tags',       'public' => true];

        Functions\stubs(['sanitize_key' => fn ($v) => $v]);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn([
            'category' => $catTax,
            'post_tag' => $tagTax,
        ]);

        $counts = ['all_cat' => 12, 'nonempty_cat' => 10, 'all_tag' => 30, 'nonempty_tag' => 25];
        $callCount = 0;
        Functions\expect('wp_count_terms')->zeroOrMoreTimes()->andReturnUsing(function (array $args) use (&$callCount) {
            $callCount++;

            return [12, 10, 30, 25][$callCount - 1] ?? 0;
        });

        $r = json_decode((new WpTaxonomyTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $r['meta']['mode']);
        self::assertCount(2, $r['data']['by_taxonomy']);
        self::assertSame(42, $r['data']['total_terms']);
        self::assertSame(7, $r['data']['empty_terms']);
    }

    public function test_aggregate_with_taxonomy_filter(): void
    {
        $catTax = (object) ['name' => 'category', 'label' => 'Categories', 'public' => true];
        $tagTax = (object) ['name' => 'post_tag', 'label' => 'Tags',       'public' => true];

        Functions\stubs(['sanitize_key' => fn ($v) => $v]);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn([
            'category' => $catTax,
            'post_tag' => $tagTax,
        ]);
        Functions\expect('wp_count_terms')->zeroOrMoreTimes()->andReturn(5);

        $r = json_decode((new WpTaxonomyTool)->execute(['aggregate' => true, 'taxonomy' => 'category']), true);

        self::assertCount(1, $r['data']['by_taxonomy']);
        self::assertSame('category', $r['data']['by_taxonomy'][0]['taxonomy']);
    }

    public function test_execute_query_lists_all_when_no_taxonomy_specified(): void
    {
        $catTax = (object) ['name' => 'category', 'label' => 'Categories', 'public' => true];

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'is_wp_error' => fn () => false,
        ]);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn(['category' => $catTax]);
        Functions\expect('get_terms')->once()->andReturn([
            $this->makeTerm(1, 'Cat A', 'cat-a', 5),
        ]);
        Functions\expect('wp_count_terms')->once()->andReturn(1);

        $r = json_decode((new WpTaxonomyTool)->execute([]), true);

        self::assertCount(1, $r['data']['terms']);
    }

    public function test_execute_query_search_and_parent_filters(): void
    {
        $catTax = (object) ['name' => 'category', 'label' => 'Categories', 'public' => true];

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => 'CLEAN:'.$v,
            'is_wp_error' => fn () => false,
        ]);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn(['category' => $catTax]);
        Functions\expect('taxonomy_exists')->once()->andReturn(true);
        Functions\expect('get_terms')
            ->once()
            ->with(\Mockery::on(static fn (array $args): bool => ($args['search'] ?? '') === 'CLEAN:hello'
                && ($args['parent'] ?? -1) === 3
            ))
            ->andReturn([]);
        Functions\expect('wp_count_terms')->once()->andReturn(0);

        json_decode((new WpTaxonomyTool)->execute([
            'taxonomy' => 'category',
            'search' => 'hello',
            'parent' => 3,
        ]), true);
    }

    public function test_execute_query_throwable_wraps_in_tool_exception(): void
    {
        $catTax = (object) ['name' => 'category', 'label' => 'Categories', 'public' => true];

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
        ]);
        Functions\expect('taxonomy_exists')->zeroOrMoreTimes()->andReturn(true);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn(['category' => $catTax]);
        Functions\expect('get_terms')->twice()->andThrow(new \RuntimeException('boom'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/query failed/');

        (new WpTaxonomyTool)->execute(['taxonomy' => 'category']);
    }

    public function test_resolve_columns_wildcard_expands_to_every_column(): void
    {
        $tool = new WpTaxonomyTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        $cols = $m->invoke($tool, ['*']);

        self::assertContains('id', $cols);
        self::assertContains('count', $cols);
        self::assertContains('parent_name', $cols);
    }

    public function test_execute_returns_empty_terms(): void
    {
        $catTax = new \stdClass;
        $catTax->name = 'category';
        $catTax->label = 'Categories';
        $catTax->public = true;

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'is_wp_error' => fn () => false,
        ]);
        Functions\expect('get_taxonomies')->zeroOrMoreTimes()->andReturn(['category' => $catTax]);
        Functions\expect('taxonomy_exists')->with('category')->once()->andReturn(true);
        Functions\expect('get_terms')->once()->andReturn([]);
        Functions\expect('wp_count_terms')->once()->andReturn(0);

        $result = json_decode((new WpTaxonomyTool)->execute(['taxonomy' => 'category']), true);

        self::assertSame([], $result['data']['terms']);
        self::assertSame(0, $result['meta']['total']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpTaxonomyTool)->execute(['schema' => true]));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new WpTaxonomyTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {

        $decoded = json_decode((new WpTaxonomyTool)->execute(['schema' => true]), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }
}
