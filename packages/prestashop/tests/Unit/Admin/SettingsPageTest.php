<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Admin\SettingsPage;
use PhpClaw\Providers\ProviderCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsPage::class)]
final class SettingsPageTest extends TestCase
{
    public function test_providers_are_sourced_from_the_provider_catalogue(): void
    {
        $expected = [];
        foreach (ProviderCatalogue::all() as $slug => $info) {
            $expected[(string) $slug] = (string) $info['label'];
        }

        self::assertSame($expected, SettingsPage::providers());
    }

    public function test_providers_keys_are_slugs(): void
    {
        foreach (array_keys(SettingsPage::providers()) as $key) {
            self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $key);
        }
    }

    public function test_validate_no_errors_on_valid_input(): void
    {
        $result = SettingsPage::validate([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'api_key' => 'sk-test',
            'max_iterations' => 10,
            'store_messages' => true,
        ]);

        self::assertEmpty($result['errors']);
    }

    public function test_validate_returns_trimmed_values(): void
    {
        $result = SettingsPage::validate(['provider' => '  openai  ', 'api_key' => '  sk-key  ']);

        self::assertSame('openai', $result['data']['provider']);
        self::assertSame('sk-key', $result['data']['api_key']);
    }

    public function test_validate_error_when_max_iterations_zero(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 0]);

        self::assertArrayHasKey('max_iterations', $result['errors']);
        self::assertSame(10, $result['data']['max_iterations']);
    }

    public function test_validate_error_when_max_iterations_too_large(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 100]);

        self::assertArrayHasKey('max_iterations', $result['errors']);
    }

    public function test_validate_no_error_on_max_iterations_boundary(): void
    {
        $r1 = SettingsPage::validate(['max_iterations' => 1]);
        $r2 = SettingsPage::validate(['max_iterations' => 50]);

        self::assertArrayNotHasKey('max_iterations', $r1['errors']);
        self::assertArrayNotHasKey('max_iterations', $r2['errors']);
    }

    public function test_validate_store_messages_true_when_truthy(): void
    {
        $result = SettingsPage::validate(['store_messages' => '1']);

        self::assertSame('1', $result['data']['store_messages']);
    }

    public function test_validate_store_messages_false_when_absent(): void
    {
        $result = SettingsPage::validate([]);

        self::assertSame('0', $result['data']['store_messages']);
    }

    public function test_merge_uses_saved_over_config(): void
    {
        $merged = SettingsPage::merge(
            ['provider' => 'openai', 'model' => 'gpt-4o'],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'],
        );

        self::assertSame('openai', $merged['provider']);
        self::assertSame('gpt-4o', $merged['model']);
    }

    public function test_merge_falls_back_to_config_when_saved_empty(): void
    {
        $merged = SettingsPage::merge(
            ['provider' => ''],
            ['provider' => 'groq', 'model' => 'llama-3.1-8b-instant'],
        );

        self::assertSame('groq', $merged['provider']);
    }

    public function test_merge_max_iterations_defaults_to_20(): void
    {
        $merged = SettingsPage::merge([], []);

        self::assertSame(20, $merged['max_iterations']);
    }

    public function test_merge_returns_exactly_the_supported_settings_fields(): void
    {
        $merged = SettingsPage::merge([], []);
        $keys = array_keys($merged);
        sort($keys);

        self::assertSame([
            'api_key', 'base_url', 'cloud_disable',
            'cloud_key', 'cloud_signing_secret', 'max_iterations',
            'model', 'provider', 'remote_skill_urls',
            'store_messages', 'system_prompt',
        ], $keys);
    }

    public function test_validate_trims_cloud_signing_secret(): void
    {
        $result = SettingsPage::validate(['cloud_signing_secret' => '  my-secret  ']);

        self::assertSame('my-secret', $result['data']['cloud_signing_secret']);
    }

    public function test_validate_cloud_signing_secret_defaults_to_empty_string(): void
    {
        $result = SettingsPage::validate([]);

        self::assertSame('', $result['data']['cloud_signing_secret']);
    }

    public function test_merge_cloud_signing_secret_returns_saved_value(): void
    {
        $merged = SettingsPage::merge(['cloud_signing_secret' => 'hmac-abc'], []);

        self::assertSame('hmac-abc', $merged['cloud_signing_secret']);
    }

    public function test_merge_cloud_signing_secret_defaults_to_empty_string(): void
    {
        $merged = SettingsPage::merge([], []);

        self::assertSame('', $merged['cloud_signing_secret']);
    }

    public function test_validate_keeps_https_md_and_json_remote_skill_urls(): void
    {
        $result = SettingsPage::validate([
            'remote_skill_urls' => "https://example.com/a.md\nhttps://example.com/b.json",
        ]);

        self::assertSame(
            ['https://example.com/a.md', 'https://example.com/b.json'],
            $result['data']['remote_skill_urls'],
        );
    }

    public function test_validate_drops_non_https_and_wrong_extension_remote_skill_urls(): void
    {
        $result = SettingsPage::validate([
            'remote_skill_urls' => "http://example.com/a.md\nhttps://example.com/b.txt\nftp://x/c.json\n   ",
        ]);

        self::assertSame([], $result['data']['remote_skill_urls']);
    }

    public function test_validate_accepts_remote_skill_urls_as_array(): void
    {
        $result = SettingsPage::validate([
            'remote_skill_urls' => ['https://example.com/a.md', 'not-a-url'],
        ]);

        self::assertSame(['https://example.com/a.md'], $result['data']['remote_skill_urls']);
    }

    public function test_merge_remote_skill_urls_returns_saved_array(): void
    {
        $merged = SettingsPage::merge(['remote_skill_urls' => ['https://example.com/a.md']], []);

        self::assertSame(['https://example.com/a.md'], $merged['remote_skill_urls']);
    }

    public function test_merge_remote_skill_urls_defaults_to_empty_array(): void
    {
        $merged = SettingsPage::merge([], []);

        self::assertSame([], $merged['remote_skill_urls']);
    }

    public function test_validate_keeps_the_stored_secret_when_the_field_is_submitted_blank(): void
    {
        \Configuration::updateValue('PHPCLAW_CLOUD_KEY', 'sk_live_stored');
        \Configuration::updateValue('PHPCLAW_CLOUD_SIGNING_SECRET', 'whsec_stored');
        \Configuration::updateValue('PHPCLAW_API_KEY', 'sk_api_stored');

        $result = SettingsPage::validate([
            'cloud_key' => '',
            'cloud_signing_secret' => '',
            'api_key' => '',
        ]);

        self::assertSame('sk_live_stored', $result['data']['cloud_key']);
        self::assertSame('whsec_stored', $result['data']['cloud_signing_secret']);
        self::assertSame('sk_api_stored', $result['data']['api_key']);
    }

    public function test_validate_overwrites_the_stored_secret_when_a_new_one_is_submitted(): void
    {
        \Configuration::updateValue('PHPCLAW_CLOUD_KEY', 'sk_live_stored');

        $result = SettingsPage::validate(['cloud_key' => 'sk_live_replaced']);

        self::assertSame('sk_live_replaced', $result['data']['cloud_key']);
    }

    public function test_validate_returns_an_empty_secret_when_none_is_stored_or_submitted(): void
    {
        \Configuration::deleteByName('PHPCLAW_CLOUD_KEY');

        $result = SettingsPage::validate(['cloud_key' => '']);

        self::assertSame('', $result['data']['cloud_key']);
    }
}
