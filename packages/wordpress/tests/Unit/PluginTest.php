<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class AjaxHalt extends \Exception {}

#[CoversClass(Plugin::class)]
final class PluginTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! defined('PHPCLAW_PLUGIN_FILE')) {
            define('PHPCLAW_PLUGIN_FILE', '/tmp/phpclaw/phpclaw.php');
        }

        Functions\stubs([
            '__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_url' => static fn (string $s): string => $s,
            'admin_url' => static fn (string $p = ''): string => 'http://example.com/wp-admin/'.ltrim($p, '/'),
            'plugins_url' => static fn (string $p, string $f): string => 'http://example.com/plugin/'.$p,
            'plugin_basename' => static fn (string $f): string => 'phpclaw/'.basename($f),
            'sanitize_text_field' => static fn (string $s): string => trim($s),
            'sanitize_textarea_field' => static fn (string $s): string => trim($s),
            'wp_unslash' => static fn (string $s): string => $s,
            'wp_send_json_success' => static function (array $data): void {
                if (! isset($GLOBALS['phpclaw_test_ajax_result'])) {
                    $GLOBALS['phpclaw_test_ajax_result'] = ['type' => 'success', 'data' => $data];
                }
                throw new AjaxHalt;
            },
            'wp_send_json_error' => static function (array $data, int $status = 400): void {
                if (! isset($GLOBALS['phpclaw_test_ajax_result'])) {
                    $GLOBALS['phpclaw_test_ajax_result'] = ['type' => 'error', 'status' => $status, 'data' => $data];
                }
                throw new AjaxHalt;
            },
            'check_ajax_referer' => static fn (...$args): bool => true,
        ]);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        unset($GLOBALS['phpclaw_test_ajax_result'], $_POST);

        Monkey\tearDown();
        parent::tearDown();
    }

    private function ajaxResult(): array
    {
        return $GLOBALS['phpclaw_test_ajax_result'] ?? [];
    }

    private function invokeAjax(callable $handler): void
    {
        try {
            $handler();
        } catch (AjaxHalt) {
        }
    }

    private function makePluginWithoutConstructor(?PhpClawInterface $engine = null): Plugin
    {
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($plugin, ['provider' => 'ollama', 'model' => 'qwen']);

        if ($engine !== null) {
            $engineProp = $ref->getProperty('engine');
            $engineProp->setAccessible(true);
            $engineProp->setValue($plugin, $engine);
        }

        $instanceProp = $ref->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $plugin);

        return $plugin;
    }

    public function test_engine_returns_cached_instance(): void
    {
        $mockEngine = Mockery::mock(PhpClawInterface::class);

        $plugin = $this->makePluginWithoutConstructor($mockEngine);

        self::assertSame($mockEngine, $plugin->engine());
    }

    public function test_engine_throws_cached_error_on_repeated_call(): void
    {
        $plugin = $this->makePluginWithoutConstructor();

        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('engineError');
        $prop->setAccessible(true);
        $prop->setValue($plugin, new \RuntimeException('init failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('init failed');

        $plugin->engine();
    }

    public function test_config_returns_injected_array(): void
    {
        $plugin = $this->makePluginWithoutConstructor();

        $config = $plugin->config();

        self::assertSame('ollama', $config['provider']);
    }

    public function test_extra_tool_classes_returns_array_via_filter(): void
    {
        Functions\when('apply_filters')->alias(static function (string $tag, mixed $value): mixed {
            return $value;
        });

        $classes = Plugin::extraToolClasses();

        self::assertSame(array_values($classes), array_unique($classes), 'no duplicate tool classes');
        foreach ($classes as $class) {
            self::assertIsString($class);
        }
    }

    public function test_plugin_action_links_prepends_settings_and_about(): void
    {
        $links = Plugin::pluginActionLinks(['<a href="#">Deactivate</a>']);

        self::assertCount(3, $links);
        self::assertStringContainsString('About', $links[0]);
        self::assertStringContainsString('Settings', $links[1]);
        self::assertStringContainsString('Deactivate', $links[2]);
    }

    public function test_plugin_row_meta_appends_view_details_for_phpclaw_plugin(): void
    {
        $links = Plugin::pluginRowMeta([], 'phpclaw/phpclaw.php');

        self::assertCount(1, $links);
        self::assertStringContainsString('View details', $links[0]);
    }

    public function test_plugin_row_meta_returns_links_unchanged_for_other_plugins(): void
    {
        $original = ['existing'];
        $r = Plugin::pluginRowMeta($original, 'other/plugin.php');

        self::assertSame($original, $r);
    }

    public function test_enqueue_admin_assets_skips_non_phpclaw_pages(): void
    {
        $enqueued = false;
        Functions\when('wp_enqueue_style')->alias(static function (...$args) use (&$enqueued): void {
            $enqueued = true;
        });

        Plugin::enqueueAdminAssets('edit.php');

        self::assertFalse($enqueued);
    }

    public function test_enqueue_admin_assets_loads_css_on_phpclaw_page(): void
    {
        $styles = [];
        Functions\when('wp_enqueue_style')->alias(static function (string $handle, ...$args) use (&$styles): void {
            $styles[] = $handle;
        });
        Functions\when('wp_enqueue_script')->alias(static function (...$args): void {});
        Functions\when('wp_localize_script')->alias(static function (...$args): void {});
        Functions\when('wp_create_nonce')->alias(static fn (string $a): string => 'N_'.$a);

        Plugin::enqueueAdminAssets('toplevel_page_phpclaw');

        self::assertContains('phpclaw-admin', $styles);
    }

    public function test_enqueue_admin_assets_loads_chat_js_on_chat_page(): void
    {
        $scripts = [];
        Functions\when('wp_enqueue_style')->alias(static function (...$args): void {});
        Functions\when('wp_enqueue_script')->alias(static function (string $handle, ...$args) use (&$scripts): void {
            $scripts[] = $handle;
        });
        Functions\when('wp_localize_script')->alias(static function (...$args): void {});
        Functions\when('wp_create_nonce')->alias(static fn (string $a): string => 'N_'.$a);

        Plugin::enqueueAdminAssets('phpclaw_page_phpclaw-chat');

        self::assertContains('phpclaw-chat', $scripts);
    }

    public function test_enqueue_admin_assets_registers_inline_guide_data_on_guide_page(): void
    {
        $scripts = [];
        $inline = [];
        Functions\when('wp_enqueue_style')->alias(static function (...$args): void {});
        Functions\when('wp_register_script')->alias(static function (...$args): void {});
        Functions\when('wp_enqueue_script')->alias(static function (string $handle, ...$args) use (&$scripts): void {
            $scripts[] = $handle;
        });
        Functions\when('wp_add_inline_script')->alias(static function (string $handle, string $data, string $pos = 'after') use (&$inline): void {
            $inline[] = ['handle' => $handle, 'data' => $data, 'position' => $pos];
        });
        Functions\when('wp_localize_script')->alias(static function (...$args): void {});
        Functions\when('wp_create_nonce')->alias(static fn (string $a): string => 'N_'.$a);
        Functions\when('wp_json_encode')->alias(static fn (mixed $v): string => (string) json_encode($v));

        Plugin::enqueueAdminAssets('phpclaw_page_phpclaw-guide');

        self::assertContains('phpclaw-guide', $scripts);
        self::assertCount(1, $inline);
        self::assertSame('phpclaw-guide', $inline[0]['handle']);
        self::assertSame('before', $inline[0]['position']);
        self::assertStringContainsString('phpClawGuideData', $inline[0]['data']);
        self::assertStringContainsString('admin-ajax.php', $inline[0]['data']);
        self::assertStringContainsString('N_phpclaw_nonce', $inline[0]['data']);
    }

    public function test_handle_ajax_send_rejects_user_without_permission(): void
    {
        Functions\expect('current_user_can')->once()->andReturnFalse();

        $plugin = $this->makePluginWithoutConstructor();
        $_POST = ['message' => 'hello'];

        $this->invokeAjax(fn () => $plugin->handleAjaxSend());

        self::assertSame('error', $this->ajaxResult()['type']);
        self::assertSame(403, $this->ajaxResult()['status']);
    }

    public function test_handle_ajax_send_rejects_empty_message(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $plugin = $this->makePluginWithoutConstructor();
        $_POST = ['message' => ''];

        $this->invokeAjax(fn () => $plugin->handleAjaxSend());

        self::assertSame('error', $this->ajaxResult()['type']);
        self::assertSame(400, $this->ajaxResult()['status']);
    }

    public function test_handle_ajax_send_succeeds_with_engine_response(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $response = new AgentResponse(
            text: 'Hello back!',
            provider: 'ollama',
            model: 'qwen',
            iterations: 1,
            inputTokens: 10,
            outputTokens: 5,
        );

        $convId = '01HX0000000000000000000000';
        $conv = new Conversation($convId, [], new \DateTimeImmutable);
        $turn = new ConversationTurn($response, $conv);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist = null) use ($turn) {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $turn;
            },
        );

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['message' => 'Hi', 'conversation_id' => ''];

        $this->invokeAjax(fn () => $plugin->handleAjaxSend());

        self::assertSame('success', $this->ajaxResult()['type']);
        self::assertSame('Hello back!', $this->ajaxResult()['data']['response']);
        self::assertSame(15, $this->ajaxResult()['data']['tokens']);
    }

    public function test_handle_ajax_send_returns_422_on_guard_exception(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andThrow(new GuardException('blocked'));

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['message' => 'evil', 'conversation_id' => ''];

        $this->invokeAjax(fn () => $plugin->handleAjaxSend());

        self::assertSame(422, $this->ajaxResult()['status']);
    }

    public function test_handle_ajax_send_returns_500_on_generic_throwable(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andThrow(new \RuntimeException('boom'));

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['message' => 'hi', 'conversation_id' => ''];

        $this->invokeAjax(fn () => $plugin->handleAjaxSend());

        self::assertSame(500, $this->ajaxResult()['status']);
    }

    public function test_handle_ajax_load_conversation_rejects_empty_conv_id(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $plugin = $this->makePluginWithoutConstructor();
        $_POST = ['conversation_id' => ''];

        $this->invokeAjax(fn () => $plugin->handleAjaxLoadConversation());

        self::assertSame(400, $this->ajaxResult()['status']);
    }

    public function test_handle_ajax_load_conversation_returns_404_for_missing(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->allows('get')->andReturnNull();

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['conversation_id' => 'nope'];

        $this->invokeAjax(fn () => $plugin->handleAjaxLoadConversation());

        self::assertSame(404, $this->ajaxResult()['status']);
    }

    public function test_handle_ajax_load_conversation_returns_messages(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->allows('get')->andReturn([
            'title' => 'My Chat',
            'history' => [
                ['role' => 'user',      'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
                ['role' => 'system',    'content' => 'skipped'],
            ],
        ]);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['conversation_id' => 'conv1'];

        $this->invokeAjax(fn () => $plugin->handleAjaxLoadConversation());

        self::assertSame('success', $this->ajaxResult()['type']);
        $r = $this->ajaxResult()['data'];
        self::assertSame('My Chat', $r['title']);
        self::assertCount(2, $r['messages']);
        self::assertSame('Hello!', $r['messages'][1]['content']);
    }

    public function test_handle_ajax_test_connection_denies_caller_without_the_admin_chat_capability(): void
    {
        Functions\expect('current_user_can')->once()->with('phpclaw_use_admin_chat')->andReturnFalse();
        Functions\expect('get_option')->never();

        $plugin = $this->makePluginWithoutConstructor();
        $this->invokeAjax(fn () => $plugin->handleAjaxTestConnection());

        self::assertSame('error', $this->ajaxResult()['type']);
        self::assertSame('Permission denied.', $this->ajaxResult()['data']['message']);
    }

    public function test_handle_ajax_test_connection_rejects_when_no_provider(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([]);

        $plugin = $this->makePluginWithoutConstructor();
        $this->invokeAjax(fn () => $plugin->handleAjaxTestConnection());

        self::assertSame('error', $this->ajaxResult()['type']);
        self::assertStringContainsString('No provider', $this->ajaxResult()['data']['message']);
    }

    public function test_handle_ajax_test_connection_rejects_when_no_api_key_for_non_ollama(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn(['provider' => 'openai', 'api_key' => '']);

        $plugin = $this->makePluginWithoutConstructor();
        $this->invokeAjax(fn () => $plugin->handleAjaxTestConnection());

        self::assertStringContainsString('API key', $this->ajaxResult()['data']['message']);
    }

    public function test_handle_ajax_test_connection_succeeds_with_engine_send(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn(['provider' => 'ollama', 'api_key' => '']);

        $response = new AgentResponse(text: 'OK', provider: 'ollama', model: 'qwen', iterations: 1);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('send')->andReturn($response);

        $plugin = $this->makePluginWithoutConstructor($engine);
        $this->invokeAjax(fn () => $plugin->handleAjaxTestConnection());

        self::assertSame('success', $this->ajaxResult()['type']);
        self::assertSame('OK', $this->ajaxResult()['data']['text']);
    }

    public function test_handle_ajax_test_connection_returns_error_on_engine_failure(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn(['provider' => 'ollama', 'api_key' => '']);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('send')->andThrow(new \RuntimeException('network'));

        $plugin = $this->makePluginWithoutConstructor($engine);
        $this->invokeAjax(fn () => $plugin->handleAjaxTestConnection());

        self::assertSame('error', $this->ajaxResult()['type']);
        self::assertStringContainsString('Connection test failed', $this->ajaxResult()['data']['message']);
    }

    public function test_activate_method_signature(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $m = $ref->getMethod('activate');

        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    public function test_register_admin_menu_method_signature(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $m = $ref->getMethod('registerAdminMenu');

        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    private function runInChild(callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required for SSE tests');
        }

        $outFile = tempnam(sys_get_temp_dir(), 'pcw_sse_');
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

    public function test_find_last_assistant_index_returns_index_of_last_assistant(): void
    {
        $plugin = $this->makePluginWithoutConstructor();
        $ref = new \ReflectionMethod(Plugin::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $messages = [
            ['role' => 'user',      'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
            ['role' => 'user',      'content' => 'list articles'],
            ['role' => 'assistant', 'content' => 'sure'],
        ];

        self::assertSame(3, $ref->invoke($plugin, $messages));
    }

    public function test_find_last_assistant_index_returns_count_when_no_assistant(): void
    {
        $plugin = $this->makePluginWithoutConstructor();
        $ref = new \ReflectionMethod(Plugin::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        self::assertSame(0, $ref->invoke($plugin, []));
        self::assertSame(2, $ref->invoke($plugin, [
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ]));
    }

    public function test_find_last_assistant_index_ignores_non_array_entries(): void
    {
        $plugin = $this->makePluginWithoutConstructor();
        $ref = new \ReflectionMethod(Plugin::class, 'findLastAssistantIndex');
        $ref->setAccessible(true);

        $messages = [
            ['role' => 'assistant', 'content' => 'first'],
            'not-an-array',
            ['role' => 'user',      'content' => 'next'],
        ];

        self::assertSame(0, $ref->invoke($plugin, $messages));
    }

    public function test_handle_ajax_stream_rejects_user_without_permission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $plugin = $this->makePluginWithoutConstructor();
        $_POST = ['message' => 'hi'];

        $result = $this->runInChild(fn () => $plugin->handleAjaxStream());

        self::assertStringContainsString('event: error', $result['output']);
        self::assertStringContainsString('Insufficient permissions', $result['output']);
    }

    public function test_handle_ajax_stream_rejects_empty_message(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $plugin = $this->makePluginWithoutConstructor();
        $_POST = ['message' => ''];

        $result = $this->runInChild(fn () => $plugin->handleAjaxStream());

        self::assertStringContainsString('event: error', $result['output']);
        self::assertStringContainsString('Message is required', $result['output']);
    }

    public function test_handle_ajax_stream_emits_done_frame_on_success(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
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

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturnUsing(
            function ($c, $msg, $onToken, $beforePersist = null) use ($turn) {
                if (is_callable($beforePersist)) {
                    $beforePersist(['history' => []]);
                }

                return $turn;
            },
        );

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['message' => 'hi', 'conversation_id' => ''];

        $result = $this->runInChild(fn () => $plugin->handleAjaxStream());

        self::assertStringContainsString('event: done', $result['output']);
        self::assertStringContainsString('streamed reply', $result['output']);
        self::assertStringContainsString('"is_new":true', $result['output']);
    }

    public function test_handle_ajax_stream_emits_error_frame_on_guard_exception(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andThrow(new GuardException('blocked'));

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['message' => 'evil'];

        $result = $this->runInChild(fn () => $plugin->handleAjaxStream());

        self::assertStringContainsString('event: error', $result['output']);
    }

    public function test_handle_ajax_stream_emits_error_frame_on_throwable(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('conversation')->andThrow(new \RuntimeException('boom'));

        $plugin = $this->makePluginWithoutConstructor($engine);
        $_POST = ['message' => 'hi'];

        $result = $this->runInChild(fn () => $plugin->handleAjaxStream());

        self::assertStringContainsString('event: error', $result['output']);
    }

    public function test_emit_sse_error_outputs_error_frame_via_reflection(): void
    {
        Functions\when('headers_sent')->justReturn(false);
        Functions\when('header')->justReturn(null);
        Functions\when('ob_get_level')->justReturn(0);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static fn (array $d) => json_encode($d));

        $plugin = $this->makePluginWithoutConstructor();
        $ref = new \ReflectionMethod(Plugin::class, 'emitSseError');
        $ref->setAccessible(true);

        $result = $this->runInChild(fn () => $ref->invoke($plugin, 'boom', 500));

        self::assertStringContainsString('event: error', $result['output']);
        self::assertStringContainsString('boom', $result['output']);
    }

    public function test_send_sse_headers_runs_without_throwing(): void
    {
        $sent = [];
        Functions\when('header')->alias(static function (string $h) use (&$sent): void {
            $sent[] = $h;
        });
        Functions\when('ob_get_level')->justReturn(0);

        $plugin = $this->makePluginWithoutConstructor();
        $ref = new \ReflectionMethod(Plugin::class, 'sendSseHeaders');
        $ref->setAccessible(true);

        $ref->invoke($plugin);

        self::assertContains('Content-Type: text/event-stream; charset=utf-8', $sent);
        self::assertContains('X-Accel-Buffering: no', $sent);
    }

    public function test_helpers_phpclaw_returns_engine_when_no_message(): void
    {
        require_once dirname(__DIR__, 2).'/src/helpers.php';

        $engine = Mockery::mock(PhpClawInterface::class);
        $this->makePluginWithoutConstructor($engine);

        self::assertSame($engine, \phpclaw());
    }

    public function test_helpers_phpclaw_sends_message_when_provided(): void
    {
        require_once dirname(__DIR__, 2).'/src/helpers.php';

        $response = new AgentResponse(text: 'pong', provider: 'ollama', model: 'qwen', iterations: 1);
        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->expects('send')->with('ping')->andReturn($response);

        $this->makePluginWithoutConstructor($engine);

        self::assertSame($response, \phpclaw('ping'));
    }
}
