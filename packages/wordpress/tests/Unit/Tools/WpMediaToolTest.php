<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpMediaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpMediaTool::class)]
final class WpMediaToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        if (! defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        if (! class_exists('WP_Query')) {
            eval('
            class WP_Query {
                public static array $testPosts       = [];
                public static int   $testFoundPosts  = 0;
                public static int   $testMaxNumPages = 1;
                public static array $lastArgs        = [];
                public array $posts        = [];
                public int   $found_posts  = 0;
                public int   $max_num_pages = 1;
                public function __construct(array $args) {
                    self::$lastArgs      = $args;
                    $this->posts         = self::$testPosts;
                    $this->found_posts   = self::$testFoundPosts;
                    $this->max_num_pages = self::$testMaxNumPages;
                }
            }');
        }

        \WP_Query::$testPosts = [];
        \WP_Query::$testFoundPosts = 0;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeAttachment(int $id, string $title, string $mime, int $parent = 0): \WP_Post
    {
        $p = \WP_Post::fromData([
            'ID' => $id,
            'post_title' => $title,
            'post_mime_type' => $mime,
            'post_date' => '2026-04-19 10:00:00',
            'post_modified' => '2026-04-19 10:00:00',
            'post_parent' => $parent,
            'post_author' => 1,
            'post_excerpt' => '',
            'post_content' => '',
            'post_status' => 'inherit',
            'post_type' => 'attachment',
        ]);

        return $p;
    }

    public function test_name_is_wp_media(): void
    {
        self::assertSame('wp_media', (new WpMediaTool)->name());
    }

    public function test_input_schema_has_mime_type_and_post_parent(): void
    {
        $schema = (new WpMediaTool)->inputSchema();
        self::assertArrayHasKey('mime_type', $schema['properties']);
        self::assertArrayHasKey('post_parent', $schema['properties']);
    }

    public function test_execute_returns_media_items(): void
    {
        \WP_Query::$testPosts = [$this->makeAttachment(1, 'photo.jpg', 'image/jpeg', 5)];
        \WP_Query::$testFoundPosts = 1;

        Functions\stubs([
            'sanitize_mime_type' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'get_attached_file' => fn () => '/tmp/photo.jpg',
            'wp_get_attachment_metadata' => fn () => ['width' => 800, 'height' => 600],
            'wp_get_attachment_url' => fn () => 'https://example.com/photo.jpg',
            'get_post_meta' => fn () => '',
        ]);

        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_results')->andReturn([
            ['post_mime_type' => 'image/jpeg', 'cnt' => 1],
        ]);

        $result = json_decode((new WpMediaTool)->execute([]), true);

        self::assertArrayHasKey('items', $result['data']);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('photo.jpg', $result['data']['items'][0]['title']);
    }

    public function test_execute_returns_empty_when_no_media(): void
    {
        \WP_Query::$testPosts = [];
        \WP_Query::$testFoundPosts = 0;

        Functions\stubs([
            'sanitize_mime_type' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
        ]);

        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $result = json_decode((new WpMediaTool)->execute([]), true);

        self::assertSame([], $result['data']['items']);
        self::assertSame(0, $result['meta']['total']);
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'WordPress media library',
            (new WpMediaTool)->description(),
        );
    }

    public function test_input_schema_describes_all_params(): void
    {
        $s = (new WpMediaTool)->inputSchema();

        foreach (['columns', 'schema', 'aggregate', 'mime_type', 'search', 'post_parent', 'author', 'date_after', 'date_before', 'orderby', 'order', 'limit'] as $k) {
            self::assertArrayHasKey($k, $s['properties']);
        }
        self::assertSame(20, $s['properties']['limit']['default']);
        self::assertSame(100, $s['properties']['limit']['maximum']);
    }

    public function test_schema_mode_returns_columns_and_filters(): void
    {
        $result = json_decode((new WpMediaTool)->execute(['schema' => true]), true);

        self::assertSame('schema', $result['meta']['mode']);
        self::assertContains('id', $result['data']['available_columns']);
        self::assertContains('width', $result['data']['available_columns']);
        self::assertContains('user_pass', $result['data']['blocked_columns']);
        self::assertSame(['id', 'title', 'url', 'mime_type', 'date'], $result['data']['default_columns']);
        self::assertContains('mime_type', $result['data']['filters']);
    }

    public function test_aggregate_mode_returns_category_counts(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn ($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([
            ['post_mime_type' => 'image/jpeg',      'cnt' => 12],
            ['post_mime_type' => 'video/mp4',       'cnt' => 3],
            ['post_mime_type' => 'audio/mpeg',      'cnt' => 2],
            ['post_mime_type' => 'application/pdf', 'cnt' => 5],
            ['post_mime_type' => 'text/plain',      'cnt' => 1],
        ]);
        $wpdb->shouldReceive('get_var')->andReturn(4);

        Functions\stubs(['sanitize_text_field' => fn ($v) => $v]);

        $result = json_decode((new WpMediaTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(23, $result['data']['stats']['total']);
        self::assertSame(12, $result['data']['stats']['images']);
        self::assertSame(3, $result['data']['stats']['videos']);
        self::assertSame(2, $result['data']['stats']['audio']);
        self::assertSame(5, $result['data']['stats']['documents']);
        self::assertSame(1, $result['data']['stats']['other']);
        self::assertSame(4, $result['data']['stats']['unattached']);
        self::assertArrayHasKey('image/jpeg', $result['data']['by_mime_type']);
    }

    public function test_aggregate_with_filters_in_where_clause(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->posts = 'wp_posts';
        $seen = [];
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn ($s) => $s);
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturnUsing(function (string $sql) use (&$seen): array {
            $seen[] = $sql;

            return [];
        });
        $wpdb->shouldReceive('get_var')->andReturn(0);

        Functions\stubs(['sanitize_text_field' => fn ($v) => $v]);

        (new WpMediaTool)->execute([
            'aggregate' => true,
            'search' => 'logo',
            'author' => 5,
            'date_after' => '2026-04-01',
            'date_before' => '2026-04-30',
        ]);

        self::assertNotEmpty($seen);
        $sql = $seen[0];

        self::assertStringContainsString('post_author', $sql);
        self::assertStringContainsString('post_date', $sql);
        self::assertStringContainsString('post_title LIKE', $sql);
    }

    public function test_aggregate_throws_wraps_in_tool_exception(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_results')->andThrow(new \RuntimeException('boom'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/aggregate failed/');

        (new WpMediaTool)->execute(['aggregate' => true]);
    }

    public function test_execute_query_applies_mime_type_post_parent_search_date_filters(): void
    {
        \WP_Query::$testPosts = [];
        \WP_Query::$testFoundPosts = 0;

        Functions\stubs([
            'sanitize_mime_type' => fn ($v) => 'sanitized:'.$v,
            'sanitize_text_field' => fn ($v) => 'sanitized:'.$v,
        ]);

        global $wpdb;
        $wpdb = \Mockery::mock('stdClass');
        $wpdb->posts = 'wp_posts';
        $wpdb->shouldReceive('get_results')->andReturn([]);

        (new WpMediaTool)->execute([
            'mime_type' => 'image',
            'post_parent' => 0,
            'author' => 3,
            'search' => 'logo',
            'date_after' => '2026-04-01',
            'date_before' => '2026-04-30',
        ]);

        $args = \WP_Query::$lastArgs;

        self::assertSame('sanitized:image', $args['post_mime_type']);
        self::assertSame(0, $args['post_parent']);
        self::assertSame(3, $args['author']);
        self::assertSame('sanitized:logo', $args['s']);
        self::assertSame('sanitized:2026-04-01', $args['date_query'][0]['after']);
        self::assertSame('sanitized:2026-04-30', $args['date_query'][0]['before']);
    }

    public function test_safe_order_by_unknown_falls_back_to_date(): void
    {
        $tool = new WpMediaTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('safeOrderBy');
        $m->setAccessible(true);

        self::assertSame('date', $m->invoke($tool, 'unknown'));
        self::assertSame('title', $m->invoke($tool, 'title'));
        self::assertSame('mime_type', $m->invoke($tool, 'mime_type'));
    }

    public function test_human_size_formats(): void
    {
        $ref = new \ReflectionClass(WpMediaTool::class);
        $m = $ref->getMethod('humanSize');
        $m->setAccessible(true);

        self::assertStringContainsString('B', $m->invoke(null, 500));
        self::assertStringContainsString('KB', $m->invoke(null, 1536));
        self::assertStringContainsString('MB', $m->invoke(null, 2 * 1024 * 1024));
        self::assertStringContainsString('GB', $m->invoke(null, 3 * 1024 * 1024 * 1024));
    }

    public function test_resolve_columns_wildcard_excludes_blocked(): void
    {
        $tool = new WpMediaTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        $cols = $m->invoke($tool, ['*']);

        self::assertContains('width', $cols);
        self::assertNotContains('user_pass', $cols);
        self::assertNotContains('user_activation_key', $cols);
    }

    public function test_resolve_columns_empty_returns_defaults(): void
    {
        $tool = new WpMediaTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        self::assertSame(['id', 'title', 'url', 'mime_type', 'date'], $m->invoke($tool, []));
    }

    public function test_resolve_columns_invalid_filtered_with_id_prepended(): void
    {
        $tool = new WpMediaTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        $cols = $m->invoke($tool, ['title', 'evil', 'width']);

        self::assertSame(['id', 'title', 'width'], $cols);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpMediaTool)->execute(['schema' => true]));
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $decoded = json_decode((new WpMediaTool)->execute(['phpclaw_bogus_arg' => 1]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
        self::assertNotEmpty($decoded['error']['accepted_arguments']);
    }

    public function test_successful_envelope_has_exactly_the_contract_keys(): void
    {

        $decoded = json_decode((new WpMediaTool)->execute(['schema' => true]), true);

        self::assertTrue($decoded['success']);
        self::assertSame(['success', 'data', 'meta', 'warnings'], array_keys($decoded));
        self::assertArrayHasKey('mode', $decoded['meta']);
    }
}
