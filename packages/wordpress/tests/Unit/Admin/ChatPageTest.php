<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\WordPress\Admin\ChatPage;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatPage::class)]
final class ChatPageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_attr__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_attr' => static fn (string $s): string => $s,
            'esc_url' => static fn (string $s): string => $s,
            'admin_url' => static fn (string $p): string => 'http://example.com/wp-admin/'.ltrim($p, '/'),
            'wp_create_nonce' => static fn (string $a): string => 'N_'.$a,
        ]);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Monkey\tearDown();
        parent::tearDown();
    }

    private function injectPlugin(mixed $engineOrException, array $config = []): void
    {
        $plugin = Mockery::mock(Plugin::class);
        $plugin->allows('config')->andReturn($config);

        if ($engineOrException instanceof \Throwable) {
            $plugin->allows('engine')->andThrow($engineOrException);
        } else {
            $plugin->allows('engine')->andReturn($engineOrException);
        }

        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $plugin);
    }

    public function test_register_method_signature(): void
    {
        $ref = new \ReflectionClass(ChatPage::class);
        $m = $ref->getMethod('register');
        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    public function test_render_dies_for_user_without_permission(): void
    {
        Functions\expect('current_user_can')->once()->andReturnFalse();
        Functions\expect('wp_die')->once()->andThrow(new \RuntimeException('died'));

        $this->expectException(\RuntimeException::class);
        ChatPage::render();
    }

    public function test_render_shows_engine_error_when_engine_fails(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([]);

        $this->injectPlugin(new \RuntimeException('init failed'));

        ob_start();
        ChatPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Engine error', $html);
        self::assertStringContainsString('disabled', $html);
    }

    public function test_render_shows_empty_sidebar_when_no_conversations(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'anthropic',
            'model' => 'claude',
            'store_messages' => '1',
        ]);

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->allows('all')->andReturn([]);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $this->injectPlugin($engine);

        ob_start();
        ChatPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('No conversations yet', $html);
        self::assertStringContainsString('What can I help you with?', $html);
        self::assertStringContainsString('Anthropic · claude', $html);
    }

    public function test_render_shows_conversation_list_when_present(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'store_messages' => '1',
        ]);

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->allows('all')->andReturn([
            'conv1' => [
                'title' => 'Hello world',
                'updated_at' => gmdate('Y-m-d H:i:s', time() - 60),
            ],
        ]);
        $memory->allows('get')->andReturn([
            'history' => [
                ['role' => 'user',      'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
            ],
        ]);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $this->injectPlugin($engine);

        ob_start();
        ChatPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Hello world', $html);
        self::assertStringContainsString('data-conv-id="conv1"', $html);
        self::assertStringContainsString('phpclaw-bubble user', $html);
        self::assertStringContainsString('phpclaw-bubble assistant', $html);
    }

    public function test_render_skips_conversations_when_store_messages_off(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'openai',
            'store_messages' => '0',
        ]);

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->shouldNotReceive('all');

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $this->injectPlugin($engine);

        ob_start();
        ChatPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('No conversations yet', $html);
    }

    public function test_render_swallows_initial_message_load_error(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'anthropic',
            'store_messages' => '1',
        ]);

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->allows('all')->andReturn(['c1' => ['title' => 'X', 'updated_at' => '2026-04-19 10:00:00']]);
        $memory->allows('get')->andThrow(new \RuntimeException('memory broken'));

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $this->injectPlugin($engine);

        ob_start();
        ChatPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Conversation started', $html);
        self::assertStringContainsString('No messages stored yet', $html);
    }

    public function test_auto_load_uses_own_most_recent(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'anthropic',
            'store_messages' => '1',
        ]);

        $ownConvId = '01OWNCONV0000000000000000A';

        $memory = Mockery::mock(MemoryInterface::class);
        $memory->allows('all')->andReturn([
            $ownConvId => [
                'title' => 'My latest conversation',
                'updated_at' => gmdate('Y-m-d H:i:s', time() - 30),
            ],
        ]);
        $memory->allows('get')->andReturn([
            'history' => [
                ['role' => 'user', 'content' => 'My private message'],
            ],
        ]);

        $engine = Mockery::mock(PhpClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $this->injectPlugin($engine);

        ob_start();
        ChatPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('data-init-conv-id="'.$ownConvId.'"', $html);
        self::assertStringContainsString('My latest conversation', $html);
    }

    public function test_fmt_provider_known_keys(): void
    {
        $ref = new \ReflectionClass(ChatPage::class);
        $m = $ref->getMethod('fmtProvider');
        $m->setAccessible(true);

        self::assertSame('Anthropic', $m->invoke(null, 'anthropic'));
        self::assertSame('OpenAI', $m->invoke(null, 'openai'));
        self::assertSame('Groq', $m->invoke(null, 'groq'));
        self::assertSame('Google Gemini', $m->invoke(null, 'gemini'));
        self::assertSame('Mistral AI', $m->invoke(null, 'mistral'));
        self::assertSame('Ollama', $m->invoke(null, 'ollama'));
        self::assertSame('Not configured', $m->invoke(null, ''));
        self::assertSame('Deepseek Plus', $m->invoke(null, 'deepseek-plus'));
    }

    public function test_relative_time_buckets(): void
    {
        $ref = new \ReflectionClass(ChatPage::class);
        $m = $ref->getMethod('relativeTime');
        $m->setAccessible(true);

        self::assertSame('', $m->invoke(null, ''));
        self::assertSame('just now', $m->invoke(null, gmdate('Y-m-d H:i:s', time() - 30)));
        self::assertSame('5m ago', $m->invoke(null, gmdate('Y-m-d H:i:s', time() - 5 * 60)));
        self::assertSame('2h ago', $m->invoke(null, gmdate('Y-m-d H:i:s', time() - 2 * 3600)));
        self::assertSame('3d ago', $m->invoke(null, gmdate('Y-m-d H:i:s', time() - 3 * 86400)));
        $oldDate = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $relMonth = $m->invoke(null, $oldDate);
        self::assertMatchesRegularExpression('/^[A-Z][a-z]+ \d+$/', $relMonth);
    }
}
