<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpUserTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpUserTool::class)]
final class WpUserToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        if (! class_exists('WP_User_Query')) {
            eval('
            class WP_User_Query {
                public static array $testUsers = [];
                public static int   $testTotal = 0;
                public static array $lastArgs = [];
                private array $results;
                private int $total;

                public function __construct(array $args) {
                    self::$lastArgs = $args;
                    $this->results = self::$testUsers;
                    $this->total   = self::$testTotal ?: count(self::$testUsers);
                }
                public function get_results(): array { return $this->results; }
                public function get_total(): int { return $this->total; }
            }');
        }

        \WP_User_Query::$testUsers = [];
        \WP_User_Query::$testTotal = 0;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeUser(int $id, string $login, string $displayName, array $roles, string $registered): object
    {
        $u = new \WP_User;
        $u->ID = $id;
        $u->user_login = $login;
        $u->display_name = $displayName;
        $u->roles = $roles;
        $u->user_registered = $registered;

        return $u;
    }

    public function test_name_is_wp_users(): void
    {
        self::assertSame('wp_users', (new WpUserTool)->name());
    }

    public function test_description_mentions_sensitive_user_data(): void
    {
        $description = (new WpUserTool)->description();
        self::assertStringContainsString('user_email', $description);
    }

    public function test_input_schema_has_role_and_search(): void
    {
        $schema = (new WpUserTool)->inputSchema();
        self::assertArrayHasKey('role', $schema['properties']);
        self::assertArrayHasKey('search', $schema['properties']);
    }

    public function test_execute_returns_users_without_email(): void
    {
        \WP_User_Query::$testUsers = [
            $this->makeUser(1, 'admin', 'Admin', ['administrator'], '2025-01-01 00:00:00'),
        ];

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'get_user_meta' => fn () => '',
            'count_user_posts' => fn () => 5,
            'wp_roles' => function () {
                $roles = \Mockery::mock('stdClass');
                $roles->shouldReceive('get_names')->andReturn([]);

                return $roles;
            },
            'get_users' => fn () => [],
        ]);

        $result = json_decode((new WpUserTool)->execute([]), true);

        self::assertTrue($result['success']);
        self::assertArrayHasKey('users', $result['data']);
        self::assertSame(1, $result['data']['users'][0]['id']);
        self::assertSame('Admin', $result['data']['users'][0]['display_name']);
        self::assertArrayNotHasKey('email', $result['data']['users'][0]);
        self::assertArrayNotHasKey('mail', $result['data']['users'][0]);
    }

    public function test_execute_returns_empty_when_no_users(): void
    {
        \WP_User_Query::$testUsers = [];

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'wp_roles' => function () {
                $roles = \Mockery::mock('stdClass');
                $roles->shouldReceive('get_names')->andReturn([]);

                return $roles;
            },
            'get_users' => fn () => [],
        ]);

        $result = json_decode((new WpUserTool)->execute([]), true);

        self::assertSame([], $result['data']['users']);
        self::assertSame(0, $result['meta']['total']);
        self::assertFalse($result['meta']['has_more']);
    }

    public function test_schema_mode_returns_columns_and_filters(): void
    {
        $rolesMock = \Mockery::mock('stdClass');
        $rolesMock->shouldReceive('get_names')->andReturn(['administrator' => 'Administrator']);

        Functions\stubs(['wp_roles' => fn () => $rolesMock]);

        $r = json_decode((new WpUserTool)->execute(['schema' => true]), true);

        self::assertTrue($r['success']);
        self::assertSame('schema', $r['meta']['mode']);
        self::assertNotEmpty($r['data']['available_columns']);
        self::assertNotEmpty($r['data']['default_columns']);
    }

    public function test_aggregate_mode_returns_role_counts(): void
    {
        $rolesMock = \Mockery::mock('stdClass');
        $rolesMock->shouldReceive('get_names')->andReturn([
            'administrator' => 'Administrator',
            'subscriber' => 'Subscriber',
        ]);

        Functions\stubs([
            'wp_roles' => fn () => $rolesMock,
            'count_users' => fn () => [
                'total_users' => 15,
                'avail_roles' => ['administrator' => 3, 'subscriber' => 12],
            ],
        ]);

        $result = json_decode((new WpUserTool)->execute(['aggregate' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(15, $result['data']['total_users']);
        self::assertArrayHasKey('administrator', $result['data']['by_role']);
        self::assertSame('Administrator', $result['data']['by_role']['administrator']['label']);
        self::assertSame(3, $result['data']['by_role']['administrator']['count']);
        self::assertSame(12, $result['data']['by_role']['subscriber']['count']);
    }

    public function test_aggregate_skips_roles_with_a_non_string_slug(): void
    {
        $rolesMock = \Mockery::mock('stdClass');
        $rolesMock->shouldReceive('get_names')->andReturn(['administrator' => 'Administrator']);

        Functions\stubs([
            'wp_roles' => fn () => $rolesMock,
            'count_users' => fn () => [
                'total_users' => 5,
                'avail_roles' => ['administrator' => 5, '' => 2, 7 => 3],
            ],
        ]);

        $result = json_decode((new WpUserTool)->execute(['aggregate' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame(5, $result['data']['total_users']);
        self::assertSame(['administrator'], array_keys($result['data']['by_role']));
    }

    public function test_aggregate_warns_when_query_arguments_are_ignored(): void
    {
        $rolesMock = \Mockery::mock('stdClass');
        $rolesMock->shouldReceive('get_names')->andReturn(['administrator' => 'Administrator']);

        Functions\stubs([
            'wp_roles' => fn () => $rolesMock,
            'sanitize_key' => fn ($v) => $v,
            'count_users' => fn () => [
                'total_users' => 1,
                'avail_roles' => ['administrator' => 1],
            ],
        ]);

        $result = json_decode((new WpUserTool)->execute(['aggregate' => true, 'role' => 'administrator']), true);

        self::assertTrue($result['success']);
        self::assertSame('IGNORED_ARGUMENT', $result['warnings'][0]['code']);
    }

    public function test_execute_query_role_and_search_filters_pass_through(): void
    {
        \WP_User_Query::$testUsers = [];

        $rolesMock = \Mockery::mock('stdClass');
        $rolesMock->shouldReceive('get_names')->andReturn(['editor' => 'Editor']);

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => 'TXT:'.$v,
            'wp_roles' => fn () => $rolesMock,
        ]);

        (new WpUserTool)->execute([
            'role' => 'editor',
            'search' => 'jane',
            'orderby' => 'display_name',
            'order' => 'asc',
        ]);

        $args = \WP_User_Query::$lastArgs;

        self::assertSame('editor', $args['role']);
        self::assertStringContainsString('TXT:jane', (string) $args['search']);
        self::assertSame(['display_name' => 'ASC', 'ID' => 'ASC'], $args['orderby']);
    }

    public function test_execute_query_emits_privacy_notice_when_email_requested(): void
    {
        \WP_User_Query::$testUsers = [
            $this->makeUser(1, 'jane', 'Jane', ['editor'], '2025-01-01'),
        ];
        \WP_User_Query::$testTotal = 1;

        Functions\stubs([
            'sanitize_key' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v,
            'get_user_meta' => fn () => '',
            'count_user_posts' => fn () => 0,
        ]);

        $user = \WP_User_Query::$testUsers[0];
        $user->user_email = 'jane@example.com';
        $user->user_nicename = 'jane';
        $user->user_url = '';
        $user->user_status = 0;

        $r = json_decode((new WpUserTool)->execute(['columns' => ['id', 'display_name', 'user_email']]), true);

        self::assertTrue($r['success']);
        self::assertContains('user_email', $r['meta']['sensitive_fields_returned']);
        self::assertSame('SENSITIVE_DATA', $r['warnings'][0]['code']);
        self::assertSame('jane@example.com', $r['data']['users'][0]['user_email']);
    }

    public function test_execute_query_invalid_orderby_falls_back(): void
    {
        $tool = new WpUserTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('queryData');
        $m->setAccessible(true);

        \WP_User_Query::$testUsers = [];
        Functions\stubs(['sanitize_key' => fn ($v) => $v, 'sanitize_text_field' => fn ($v) => $v]);

        $m->invoke($tool, ['orderby' => 'evil_field', 'order' => 'ASC']);

        self::assertNotSame('evil_field', \WP_User_Query::$lastArgs['orderby']);
    }

    public function test_resolve_columns_reflection(): void
    {
        $tool = new WpUserTool;
        $ref = new \ReflectionClass($tool);
        $m = $ref->getMethod('resolveColumns');
        $m->setAccessible(true);

        self::assertNotEmpty($m->invoke($tool, ['*']));
        self::assertContains('id', $m->invoke($tool, []));
        self::assertContains('user_email', $m->invoke($tool, ['id', 'user_email']));
    }

    public function test_unknown_columns_are_rejected_not_silently_dropped(): void
    {
        Functions\stubs(['sanitize_key' => fn ($v) => $v, 'sanitize_text_field' => fn ($v) => $v]);

        $decoded = json_decode((new WpUserTool)->execute(['columns' => ['nope', 'evil']]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('UNKNOWN_COLUMN', $decoded['error']['code']);
        self::assertNull($decoded['data']);
        self::assertContains('id', $decoded['error']['available_columns']);
    }

    public function test_execute_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpUserTool)->execute([]));
    }

    public function test_forbidden_runs_no_user_query(): void
    {
        $this->denyAllCapabilities();
        \WP_User_Query::$lastArgs = ['sentinel' => true];

        (new WpUserTool)->execute(['limit' => 5]);

        self::assertSame(['sentinel' => true], \WP_User_Query::$lastArgs);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new WpUserTool;

        self::assertSame('wordpress.users.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], WpUserTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        Functions\when('wp_roles')->justReturn(new class
        {
            public function get_names(): array
            {
                return ['administrator' => 'Administrator', 'editor' => 'Editor'];
            }
        });

        $result = json_decode((new WpUserTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('wordpress.users.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }

    public function test_aggregate_mode_totals_users_and_labels_each_role(): void
    {
        Functions\when('wp_roles')->justReturn(new class
        {
            public function get_names(): array
            {
                return ['administrator' => 'Administrator', 'editor' => 'Editor'];
            }
        });
        Functions\when('count_users')->justReturn([
            'total_users' => 7,
            'avail_roles' => ['editor' => 2, 'administrator' => 1, '' => 4],
        ]);

        $result = json_decode((new WpUserTool)->execute(['aggregate' => true]), true);

        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(7, $result['data']['total_users']);
        self::assertSame(
            ['administrator', 'editor'],
            array_keys($result['data']['by_role']),
            'roles are sorted and the blank slug is dropped',
        );
        self::assertSame('Administrator', $result['data']['by_role']['administrator']['label']);
        self::assertSame(1, $result['data']['by_role']['administrator']['count']);
        self::assertSame('Editor', $result['data']['by_role']['editor']['label']);
        self::assertSame(2, $result['data']['by_role']['editor']['count']);
    }

    public function test_aggregate_mode_reports_a_tool_error_when_the_user_count_is_unusable(): void
    {
        Functions\when('wp_roles')->justReturn(new class
        {
            public function get_names(): array
            {
                return [];
            }
        });
        Functions\when('count_users')->justReturn(['avail_roles' => []]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Unable to calculate WordPress user aggregates.');

        (new WpUserTool)->execute(['aggregate' => true]);
    }

    public function test_an_unknown_argument_is_rejected_with_the_accepted_list(): void
    {
        $result = json_decode((new WpUserTool)->execute(['nope' => 1]), true);

        self::assertFalse($result['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $result['error']['code']);
        self::assertContains('role', $result['error']['accepted_arguments']);
    }

    public function test_a_mode_flag_passed_as_a_string_is_rejected_as_a_non_boolean(): void
    {
        $result = json_decode((new WpUserTool)->execute(['schema' => 'true']), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ARGUMENT', $result['error']['code']);
        self::assertStringContainsString('boolean', $result['error']['message']);
    }

    public function test_asking_for_schema_and_aggregate_together_is_rejected(): void
    {
        $result = json_decode((new WpUserTool)->execute(['schema' => true, 'aggregate' => true]), true);

        self::assertFalse($result['success']);
        self::assertSame('CONFLICTING_MODES', $result['error']['code']);
    }

    public function test_a_blank_role_is_rejected(): void
    {
        $result = json_decode((new WpUserTool)->execute(['role' => '   ']), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ROLE', $result['error']['code']);
    }

    public function test_an_unsupported_orderby_is_rejected_with_the_valid_list(): void
    {
        $result = json_decode((new WpUserTool)->execute(['orderby' => 'shoe_size']), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_ORDERBY', $result['error']['code']);
        self::assertNotSame([], $result['error']['valid_orderby']);
    }

    public function test_post_counts_are_resolved_in_one_batched_lookup(): void
    {
        $calls = 0;
        Functions\when('count_many_users_posts')->alias(
            static function (array $ids, string $type = 'post', bool $public = false) use (&$calls): array {
                $calls++;

                return [4 => '2', 9 => '0'];
            },
        );

        \WP_User_Query::$testUsers = [
            $this->makeUser(4, 'ada', 'Ada', ['author'], '2026-01-01 00:00:00'),
            $this->makeUser(9, 'bob', 'Bob', ['author'], '2026-01-02 00:00:00'),
        ];

        $result = json_decode((new WpUserTool)->execute(['columns' => ['id', 'post_count']]), true);

        self::assertTrue($result['success']);
        self::assertSame(1, $calls, 'one lookup covers every row on the page');
        self::assertSame(2, $result['data']['users'][0]['post_count']);
        self::assertSame(0, $result['data']['users'][1]['post_count']);
    }
}
