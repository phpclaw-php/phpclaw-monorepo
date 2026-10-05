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
            'cloud_key', 'cloud_signing_secret', 'fallback_api_key',
            'fallback_model', 'fallback_provider', 'max_iterations',
            'max_token_budget', 'model', 'provider', 'rate_limit_rpm',
            'remote_skill_urls', 'response_cache', 'response_cache_ttl',
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

    public function test_merge_defaults_the_agent_primitives_to_off(): void
    {
        $merged = SettingsPage::merge([], []);

        self::assertSame('', $merged['fallback_provider']);
        self::assertSame('', $merged['fallback_model']);
        self::assertSame('', $merged['fallback_api_key']);
        self::assertSame(0, $merged['rate_limit_rpm']);
        self::assertFalse($merged['response_cache']);
        self::assertSame(3600, $merged['response_cache_ttl']);
        self::assertSame(0, $merged['max_token_budget']);
    }

    public function test_validate_reads_the_agent_primitives(): void
    {
        $result = SettingsPage::validate([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'max_iterations' => '20',
            'fallback_provider' => 'groq',
            'fallback_model' => ' llama-3.1-8b-instant ',
            'fallback_api_key' => 'gsk-new',
            'rate_limit_rpm' => '30',
            'response_cache' => '1',
            'response_cache_ttl' => '600',
            'max_token_budget' => '50000',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('groq', $result['data']['fallback_provider']);
        self::assertSame('llama-3.1-8b-instant', $result['data']['fallback_model']);
        self::assertSame('gsk-new', $result['data']['fallback_api_key']);
        self::assertSame(30, $result['data']['rate_limit_rpm']);
        self::assertSame('1', $result['data']['response_cache']);
        self::assertSame(600, $result['data']['response_cache_ttl']);
        self::assertSame(50000, $result['data']['max_token_budget']);
    }

    public function test_validate_turns_the_response_cache_off_when_unticked(): void
    {
        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => '20']);

        self::assertSame('0', $result['data']['response_cache']);
    }

    public function test_validate_clamps_values_above_the_limits(): void
    {
        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => '20', 'rate_limit_rpm' => '1000', 'response_cache_ttl' => '100000', 'max_token_budget' => '20000000']);

        self::assertSame(600, $result['data']['rate_limit_rpm']);
        self::assertSame(86400, $result['data']['response_cache_ttl']);
        self::assertSame(10000000, $result['data']['max_token_budget']);
    }

    public function test_validate_clamps_values_below_the_limits(): void
    {
        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => '20', 'rate_limit_rpm' => '-5', 'response_cache_ttl' => '30', 'max_token_budget' => '-1']);

        self::assertSame(0, $result['data']['rate_limit_rpm']);
        self::assertSame(60, $result['data']['response_cache_ttl']);
        self::assertSame(0, $result['data']['max_token_budget']);
    }

    public function test_validate_reads_an_empty_ttl_as_one_hour(): void
    {
        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => '20', 'response_cache_ttl' => '']);

        self::assertSame(3600, $result['data']['response_cache_ttl']);
    }

    public function test_validate_keeps_the_stored_fallback_key_when_left_blank(): void
    {
        \Configuration::reset();
        \Configuration::updateValue('PHPCLAW_FALLBACK_API_KEY', 'gsk-stored');

        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => '20', 'fallback_api_key' => '']);

        self::assertSame('gsk-stored', $result['data']['fallback_api_key']);
        \Configuration::reset();
    }

    public function test_validate_rejects_a_custom_fallback(): void
    {
        $result = SettingsPage::validate(['provider' => 'openai', 'max_iterations' => '20', 'fallback_provider' => 'custom']);

        self::assertArrayHasKey('fallback_provider', $result['errors']);
    }

    public function test_validate_rejects_a_fallback_with_another_tool_format(): void
    {
        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => '20', 'fallback_provider' => 'anthropic']);

        self::assertSame('Fallback provider must use the same tool format as the primary provider, and cannot be Custom.', $result['errors']['fallback_provider']);
    }

    public function test_validate_checks_an_empty_primary_against_the_auto_detected_provider(): void
    {
        $accepted = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], static fn (): array => SettingsPage::validate(['provider' => '', 'max_iterations' => '20', 'fallback_provider' => 'groq']));
        $rejected = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], static fn (): array => SettingsPage::validate(['provider' => '', 'max_iterations' => '20', 'fallback_provider' => 'anthropic']));

        self::assertArrayNotHasKey('fallback_provider', $accepted['errors']);
        self::assertArrayHasKey('fallback_provider', $rejected['errors']);
    }

    public function test_fallback_providers_match_the_primary_tool_format_and_never_list_custom(): void
    {
        $options = SettingsPage::fallbackProviders('ollama');

        self::assertArrayHasKey('', $options);
        self::assertArrayHasKey('groq', $options);
        self::assertArrayNotHasKey('anthropic', $options);
        self::assertArrayNotHasKey('custom', $options);
    }

    public function test_fallback_script_data_lists_every_provider_except_custom_with_its_tool_format(): void
    {
        $data = SettingsPage::fallbackScriptData();

        self::assertSame('Off', $data['offLabel']);
        self::assertArrayNotHasKey('custom', $data['providers']);
        self::assertArrayNotHasKey('', $data['providers']);
        self::assertSame(array_values(array_diff(array_keys(SettingsPage::providers()), ['', 'custom'])), array_keys($data['providers']));
        self::assertSame('openai', $data['formats']['ollama']);
        self::assertSame('openai', $data['formats']['groq']);
        self::assertSame('anthropic', $data['formats']['anthropic']);
        self::assertSame('openai', $data['formats']['deepseek']);
        self::assertSame('openai', $data['formats']['custom']);
    }

    public function test_fallback_script_data_auto_format_follows_the_auto_detected_provider(): void
    {
        $openai = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], static fn (): array => SettingsPage::fallbackScriptData());
        $anthropic = $this->withOnlyEnv(['ANTHROPIC_API_KEY' => 'sk-ant'], static fn (): array => SettingsPage::fallbackScriptData());

        self::assertSame('openai', $openai['autoFormat']);
        self::assertSame('anthropic', $anthropic['autoFormat']);
    }

    private function withOnlyEnv(array $values, callable $callback): mixed
    {
        $names = ['PHPCLAW_PROVIDER', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GROQ_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY', 'OLLAMA_HOST'];
        $saved = [];

        foreach ($names as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $name => [$env, $envArray, $server]) {
                $env === false ? putenv($name) : putenv("{$name}={$env}");
                unset($_ENV[$name], $_SERVER[$name]);

                if ($envArray !== null) {
                    $_ENV[$name] = $envArray;
                }

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }
}
