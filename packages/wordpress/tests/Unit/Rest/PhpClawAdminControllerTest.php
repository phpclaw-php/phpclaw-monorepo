<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;
use PhpClaw\WordPress\Rest\PhpClawAdminController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpClawAdminController::class)]
final class PhpClawAdminControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! class_exists('WP_REST_Server')) {
            eval('final class WP_REST_Server { const READABLE = "GET"; const CREATABLE = "POST"; }');
        }

        if (! class_exists('WP_REST_Request')) {
            eval('class WP_REST_Request {
                private array $params = [];
                public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
                public function setParam(string $k, mixed $v): void { $this->params[$k] = $v; }
            }');
        }

        if (! class_exists('WP_REST_Response')) {
            eval('class WP_REST_Response {
                public function __construct(public readonly array $data, public readonly int $status) {}
            }');
        }

        if (! class_exists('WP_Error')) {
            eval('class WP_Error {
                public function __construct(
                    public readonly string $code,
                    public readonly string $message,
                    public readonly array $data = [],
                ) {}
            }');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_permission_check_passes_for_capable_user(): void
    {
        Functions\expect('apply_filters')->once()->andReturn('manage_options');
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $controller = new PhpClawAdminController($engine, []);
        $request = new \WP_REST_Request;

        self::assertTrue($controller->checkPermission($request));
    }

    public function test_permission_check_fails_for_non_capable_user(): void
    {
        Functions\expect('apply_filters')->once()->andReturn('manage_options');
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
        Functions\expect('__')->zeroOrMoreTimes()->andReturnFirstArg();

        $engine = \Mockery::mock(PhpClawInterface::class);
        $controller = new PhpClawAdminController($engine, []);

        $result = $controller->checkPermission(new \WP_REST_Request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('phpclaw_forbidden', $result->code);
        self::assertSame(403, $result->data['status']);
    }

    public function test_register_registers_only_the_chat_stream_route(): void
    {
        Functions\expect('register_rest_route')
            ->once()
            ->with('phpclaw', '/chat/stream', \Mockery::type('array'))
            ->andReturn(true);

        $controller = new PhpClawAdminController(null, []);
        $controller->register();

    }

    public function test_chat_stream_returns_503_when_engine_null(): void
    {
        Functions\expect('__')->zeroOrMoreTimes()->andReturnFirstArg();

        $controller = new PhpClawAdminController(null, []);
        $request = new \WP_REST_Request;
        $request->setParam('message', 'hello');

        $result = $controller->handleChatStream($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('phpclaw_not_configured', $result->code);
        self::assertSame(503, $result->data['status']);
    }

    public function test_chat_stream_returns_400_for_empty_message(): void
    {
        Functions\expect('__')->zeroOrMoreTimes()->andReturnFirstArg();

        $engine = \Mockery::mock(PhpClawInterface::class);
        $controller = new PhpClawAdminController($engine, []);
        $request = new \WP_REST_Request;
        $request->setParam('message', '   ');

        $result = $controller->handleChatStream($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('phpclaw_empty_message', $result->code);
        self::assertSame(400, $result->data['status']);
    }

    public function test_check_permission_uses_filter_override(): void
    {
        Functions\expect('apply_filters')
            ->once()
            ->with('phpclaw_rest_capability', 'edit_others_posts')
            ->andReturn('edit_others_posts');
        Functions\expect('current_user_can')->once()->with('edit_others_posts')->andReturn(true);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $controller = new PhpClawAdminController($engine, ['rest_capability' => 'edit_others_posts']);

        self::assertTrue($controller->checkPermission(new \WP_REST_Request));
    }

    private function runInChild(callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required for SSE tests');
        }

        $outFile = tempnam(sys_get_temp_dir(), 'pcw_admin_sse_');
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            ob_start(static function (string $buf) use ($outFile): string {
                if ($buf !== '') {
                    file_put_contents($outFile, $buf, FILE_APPEND);
                }

                return '';
            }, 1);
            try {
                $work();
            } catch (\Throwable) {
            }
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        $output = (string) @file_get_contents($outFile);
        @unlink($outFile);

        return ['output' => $output, 'exit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1];
    }

    public function test_chat_stream_emits_done_frame_on_success(): void
    {
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'streamed reply',
            provider: 'ollama',
            model: 'qwen',
            iterations: 1,
            inputTokens: 5,
            outputTokens: 3,
        );

        $conv = new Conversation('01HX0000000000000000000000', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturn($turn);

        $controller = new PhpClawAdminController($engine, []);
        $request = new \WP_REST_Request;
        $request->setParam('message', 'hi');
        $request->setParam('conversation_id', '');

        $result = $this->runInChild(fn () => $controller->handleChatStream($request));

        self::assertStringContainsString('event: done', $result['output']);
        self::assertStringContainsString('streamed reply', $result['output']);
        self::assertStringContainsString('"provider":"ollama"', $result['output']);
    }

    public function test_chat_stream_denies_a_conversation_owned_by_another_user(): void
    {
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));
        Functions\when('__')->returnArg();

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andThrow(new ConversationAccessDeniedException('denied'));

        $controller = new PhpClawAdminController($engine, []);
        $request = new \WP_REST_Request;
        $request->setParam('message', 'hi');
        $request->setParam('conversation_id', 'someone-elses-conversation');

        $result = $this->runInChild(fn () => $controller->handleChatStream($request));

        self::assertStringContainsString('event: error', $result['output']);
        self::assertStringContainsString(
            'You do not have permission to access this resource.',
            $result['output'],
        );
    }

    public function test_chat_stream_emits_error_frame_on_throwable(): void
    {
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));
        Functions\when('__')->returnArg();

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andThrow(new \RuntimeException('boom'));

        $controller = new PhpClawAdminController($engine, []);
        $request = new \WP_REST_Request;
        $request->setParam('message', 'hi');

        $result = $this->runInChild(fn () => $controller->handleChatStream($request));

        self::assertStringContainsString('event: error', $result['output']);
    }

    public function test_send_sse_headers_runs_without_throwing(): void
    {
        $sent = [];
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('header')->alias(static function (string $h) use (&$sent): void {
            $sent[] = $h;
        });

        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'sendSseHeaders');
        $ref->setAccessible(true);

        $ref->invoke($controller);

        self::assertContains('Content-Type: text/event-stream', $sent);
        self::assertContains('Connection: keep-alive', $sent);
        self::assertContains('X-Accel-Buffering: no', $sent);
    }

    public function test_stream_chat_emits_done_frame_on_success_in_process(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'in-process reply',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 2,
            inputTokens: 7,
            outputTokens: 4,
        );

        $conv = new Conversation('01HX0000000000000000000000', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->with('')->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturn($turn);

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };
        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, 'hi', '', $emit);

        self::assertNotEmpty($captured);
        $last = $captured[count($captured) - 1];
        self::assertSame('done', $last['event']);
        self::assertSame('in-process reply', $last['data']['text']);
        self::assertSame('anthropic', $last['data']['provider']);
        self::assertSame(11, $last['data']['tokens']);
        self::assertSame(2, $last['data']['iterations']);
        self::assertSame($conv->id, $last['data']['conversation_id']);
    }

    public function test_stream_chat_emits_error_frame_on_throwable_in_process(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));
        Functions\when('__')->returnArg();

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andThrow(new \RuntimeException('boom'));

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, 'hi', '', $emit);

        self::assertCount(1, $captured);
        self::assertSame('error', $captured[0]['event']);
        self::assertArrayHasKey('message', $captured[0]['data']);
    }

    public function test_build_emitter_returns_callable_that_writes_sse_frame(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));
        Functions\when('ob_get_level')->justReturn(0);

        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'buildEmitter');
        $ref->setAccessible(true);

        $emit = $ref->invoke($controller);
        self::assertIsCallable($emit);

        ob_start();
        $emit('done', ['text' => 'ok']);
        $out = (string) ob_get_clean();

        self::assertStringContainsString('event: done', $out);
        self::assertStringContainsString('data: {"text":"ok"}', $out);
    }

    public function test_stream_chat_hook_listeners_emit_tool_and_chunk_frames(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'final',
            provider: 'ollama',
            model: 'qwen',
            iterations: 1,
            inputTokens: 1,
            outputTokens: 1,
        );

        $conv = new Conversation('01HX0000000000000000000001', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function ($c, $msg, $onToken) use ($turn) {
                HookRegistry::fire('tool.before', ['tool_name' => 'list_posts', 'tool_input' => ['n' => 3]]);
                HookRegistry::fire('tool.after', ['tool_name' => 'list_posts', 'tool_input' => ['n' => 3], 'tool_result' => '[]']);
                HookRegistry::fire('provider.token', ['token' => 'hi']);
                HookRegistry::fire('provider.token', ['token' => '']);

                return $turn;
            }
        );

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, 'list 3 posts', '', $emit);

        $events = array_column($captured, 'event');
        self::assertContains('tool_before', $events);
        self::assertContains('tool_after', $events);
        self::assertContains('chunk', $events);
        self::assertContains('done', $events);

        self::assertSame(1, count(array_filter($events, static fn (string $e): bool => $e === 'chunk')));
    }

    public function test_build_emitter_flushes_when_ob_active(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'buildEmitter');
        $ref->setAccessible(true);

        $emit = $ref->invoke($controller);
        self::assertIsCallable($emit);

        $captured = '';
        ob_start(static function (string $buf) use (&$captured): string {
            $captured .= $buf;

            return '';
        });

        $emit('ping', ['ok' => true]);
        ob_end_clean();

        self::assertStringContainsString('event: ping', $captured);
        self::assertStringContainsString('"ok":true', $captured);
    }

    public function test_find_last_assistant_index_returns_correct_index(): void
    {
        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $messages = [
            ['role' => 'user',      'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi'],
            ['role' => 'user',      'content' => 'more'],
        ];

        $index = $ref->invoke($controller, $messages);
        self::assertSame(1, $index);
    }

    public function test_find_last_assistant_index_returns_count_when_no_assistant(): void
    {
        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $messages = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'user', 'content' => 'again'],
        ];

        $index = $ref->invoke($controller, $messages);
        self::assertSame(2, $index);
    }

    public function test_find_last_assistant_index_returns_zero_for_empty_messages(): void
    {
        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $index = $ref->invoke($controller, []);
        self::assertSame(0, $index);
    }

    public function test_find_last_assistant_index_skips_non_array_entries(): void
    {
        $controller = new PhpClawAdminController(null, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $messages = [
            'not-an-array',
            ['role' => 'assistant', 'content' => 'hi'],
            'also-not-array',
        ];

        $index = $ref->invoke($controller, $messages);
        self::assertSame(1, $index);
    }

    public function test_stream_chat_before_persist_callback_splices_tool_calls_into_history(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'done',
            provider: 'anthropic',
            model: 'claude-3',
            iterations: 1,
            inputTokens: 2,
            outputTokens: 2,
        );

        $conv = new Conversation('01HX0000000000000000000002', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist) use ($turn) {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'wp_post',
                    'tool_input' => ['id' => 1],
                    'tool_result' => 'Post title',
                ]);

                if ($beforePersist !== null) {
                    $beforePersist([
                        'history' => [
                            ['role' => 'user',      'content' => 'show post'],
                            ['role' => 'assistant', 'content' => 'here it is'],
                        ],
                        'title' => 'existing title',
                    ]);
                }

                return $turn;
            }
        );

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, 'show post', 'EXISTING_ID', $emit);

        $doneFrames = array_filter($captured, static fn (array $f): bool => $f['event'] === 'done');
        self::assertNotEmpty($doneFrames);
        $done = array_values($doneFrames)[0];
        self::assertNotEmpty($done['data']['tool_calls']);
        self::assertSame('wp_post', $done['data']['tool_calls'][0]['tool_name']);
    }

    public function test_stream_chat_before_persist_callback_sets_title_for_new_conversation(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'reply',
            provider: 'openai',
            model: 'gpt-4o',
            iterations: 1,
            inputTokens: 1,
            outputTokens: 1,
        );

        $conv = new Conversation('01HX0000000000000000000003', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->with('')->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist) use ($turn) {
                if ($beforePersist !== null) {
                    $beforePersist(['history' => [], 'title' => '']);
                }

                return $turn;
            }
        );

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, 'short message', '', $emit);

        $doneFrames = array_filter($captured, static fn (array $f): bool => $f['event'] === 'done');
        $done = array_values($doneFrames)[0];
        self::assertSame('short message', $done['data']['title']);
        self::assertTrue($done['data']['is_new']);
    }

    public function test_stream_chat_before_persist_callback_truncates_long_title(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'reply',
            provider: 'openai',
            model: 'gpt-4o',
            iterations: 1,
            inputTokens: 1,
            outputTokens: 1,
        );

        $conv = new Conversation('01HX0000000000000000000004', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $longMessage = str_repeat('a', 61);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->with('')->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist) use ($turn) {
                if ($beforePersist !== null) {
                    $beforePersist(['history' => [], 'title' => '']);
                }

                return $turn;
            }
        );

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, $longMessage, '', $emit);

        $doneFrames = array_filter($captured, static fn (array $f): bool => $f['event'] === 'done');
        $done = array_values($doneFrames)[0];
        self::assertStringEndsWith("\u{2026}", $done['data']['title']);
        self::assertLessThanOrEqual(61, mb_strlen($done['data']['title']));
    }

    public function test_stream_chat_before_persist_callback_splices_using_find_last_assistant_index(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $response = new AgentResponse(
            text: 'result',
            provider: 'openai',
            model: 'gpt-4o',
            iterations: 1,
            inputTokens: 1,
            outputTokens: 1,
        );

        $conv = new Conversation('01HX0000000000000000000005', [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->with('EXISTING')->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist) use ($turn) {
                HookRegistry::fire('tool.after', [
                    'tool_name' => 'db_query',
                    'tool_input' => ['sql' => 'SELECT 1'],
                    'tool_result' => '1',
                ]);

                if ($beforePersist !== null) {
                    $result = $beforePersist([
                        'history' => [
                            ['role' => 'user',      'content' => 'query'],
                            ['role' => 'assistant', 'content' => 'running'],
                        ],
                        'title' => 'old title',
                    ]);

                    self::assertIsArray($result);
                    $roles = array_column($result['history'], 'role');
                    self::assertContains('tool', $roles);
                }

                return $turn;
            }
        );

        $captured = [];
        $emit = static function (string $event, array $data) use (&$captured): void {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $controller = new PhpClawAdminController($engine, []);
        $ref = new \ReflectionMethod(PhpClawAdminController::class, 'streamChat');
        $ref->setAccessible(true);

        $ref->invoke($controller, 'run query', 'EXISTING', $emit);

        $events = array_column($captured, 'event');
        self::assertContains('done', $events);
    }
}
