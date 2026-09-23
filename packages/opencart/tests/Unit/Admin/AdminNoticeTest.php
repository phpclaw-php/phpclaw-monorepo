<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\OpenCart\Admin\AdminNotice;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AdminNoticeTest extends TestCase
{
    public function test_should_not_show_when_api_key_present(): void
    {
        self::assertFalse(AdminNotice::shouldShow(['api_key' => 'sk-abc123']));
    }

    public function test_should_not_show_for_ollama_provider(): void
    {
        self::assertFalse(AdminNotice::shouldShow(['provider' => 'ollama']));
    }

    public function test_should_show_when_no_api_key_and_no_constants(): void
    {
        self::assertTrue(AdminNotice::shouldShow([]));
    }

    public function test_should_show_when_api_key_is_empty_string(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['api_key' => '']));
    }

    public function test_should_show_when_provider_is_empty(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['provider' => '']));
    }

    public function test_should_show_when_provider_is_anthropic_without_key(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['provider' => 'anthropic']));
    }

    public function test_should_not_show_when_provider_ollama_overrides_missing_key(): void
    {
        self::assertFalse(AdminNotice::shouldShow(['provider' => 'ollama', 'api_key' => '']));
    }

    public function test_data_returns_expected_keys(): void
    {
        $data = AdminNotice::data('http://example.com/settings');
        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('url', $data);
        self::assertArrayHasKey('label', $data);
    }

    public function test_data_url_matches_argument(): void
    {
        $data = AdminNotice::data('http://example.com/settings');
        self::assertSame('http://example.com/settings', $data['url']);
    }

    public function test_data_message_mentions_phpclaw(): void
    {
        $data = AdminNotice::data('http://example.com/');
        self::assertStringContainsString('phpClaw', $data['message']);
    }

    public function test_data_label_non_empty(): void
    {
        $data = AdminNotice::data('http://example.com/');
        self::assertNotEmpty($data['label']);
    }

    public function test_data_url_preserves_query_string(): void
    {
        $url = 'http://example.com/admin?route=phpclaw&user_token=abc';
        $data = AdminNotice::data($url);
        self::assertSame($url, $data['url']);
    }

    public function test_data_url_preserves_empty_string(): void
    {
        $data = AdminNotice::data('');
        self::assertSame('', $data['url']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_should_not_show_when_phpclaw_api_key_constant_defined(): void
    {
        define('PHPCLAW_API_KEY', 'sk-from-constant');
        self::assertFalse(AdminNotice::shouldShow([]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_should_not_show_when_anthropic_api_key_constant_defined(): void
    {
        define('ANTHROPIC_API_KEY', 'sk-ant-abc');
        self::assertFalse(AdminNotice::shouldShow([]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_should_not_show_when_openai_api_key_constant_defined(): void
    {
        define('OPENAI_API_KEY', 'sk-openai-abc');
        self::assertFalse(AdminNotice::shouldShow([]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_should_not_show_when_groq_api_key_constant_defined(): void
    {
        define('GROQ_API_KEY', 'gsk-groq-abc');
        self::assertFalse(AdminNotice::shouldShow([]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_should_not_show_when_gemini_api_key_constant_defined(): void
    {
        define('GEMINI_API_KEY', 'gemini-abc');
        self::assertFalse(AdminNotice::shouldShow([]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_empty_string_constant_still_shows_notice(): void
    {
        define('PHPCLAW_API_KEY', '');
        self::assertTrue(AdminNotice::shouldShow([]));
    }
}
