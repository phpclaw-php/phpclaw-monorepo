<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Admin\AdminNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminNotice::class)]
final class AdminNoticeTest extends TestCase
{
    public function test_should_show_true_when_no_key_anywhere(): void
    {
        self::assertTrue(AdminNotice::shouldShow([]));
    }

    public function test_should_show_false_when_api_key_in_saved(): void
    {
        self::assertFalse(AdminNotice::shouldShow(['api_key' => 'sk-test', 'model' => 'gpt-4o']));
    }

    public function test_should_show_true_when_api_key_in_saved_but_model_missing(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['api_key' => 'sk-test']));
    }

    public function test_should_show_true_when_saved_api_key_is_empty_string(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['api_key' => '']));
    }

    public function test_should_show_true_when_saved_api_key_is_whitespace(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['api_key' => '   ']));
    }

    public function test_should_show_false_for_ollama_without_api_key(): void
    {
        self::assertFalse(AdminNotice::shouldShow(['provider' => 'ollama', 'api_key' => '', 'model' => 'qwen2.5:7b']));
    }

    public function test_should_show_true_for_ollama_without_model(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['provider' => 'ollama', 'api_key' => '']));
    }

    public function test_should_show_true_for_non_ollama_without_api_key(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['provider' => 'openai', 'api_key' => '', 'model' => 'gpt-4o']));
    }

    public function test_data_returns_array_with_required_keys(): void
    {
        $data = AdminNotice::data('https://example.com/settings');

        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('url', $data);
        self::assertArrayHasKey('label', $data);
    }

    public function test_data_url_matches_input(): void
    {
        $url = 'https://example.com/admin/settings';
        $data = AdminNotice::data($url);

        self::assertSame($url, $data['url']);
    }

    public function test_data_message_tells_the_merchant_what_to_configure(): void
    {
        self::assertSame(
            'phpClaw is not configured. Set your AI provider and API key to start using AI agents in your store.',
            AdminNotice::data('https://example.com')['message'],
        );
    }

    public function test_data_label_is_the_call_to_action(): void
    {
        self::assertSame('Configure phpClaw', AdminNotice::data('https://example.com')['label']);
    }

    public function test_data_with_empty_url(): void
    {
        $data = AdminNotice::data('');

        self::assertSame('', $data['url']);
        self::assertNotEmpty($data['message']);
    }
}
