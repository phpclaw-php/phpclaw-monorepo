<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Admin;

use PhpClaw\ClawConfig;
use PhpClaw\OpenCart\Admin\SettingsPage;
use PhpClaw\OpenCart\OcEventFirer;
use PhpClaw\OpenCart\Tools\OcProductTool;
use PhpClaw\Providers\ProviderCatalogue;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    public function test_providers_returns_at_least_a_select_entry(): void
    {
        $providers = SettingsPage::providers();
        self::assertArrayHasKey('', $providers);
    }

    public function test_providers_contains_custom_key_when_catalogue_available(): void
    {
        if (! class_exists(ProviderCatalogue::class)) {
            self::markTestSkipped('ProviderCatalogue not available.');
        }
        $providers = SettingsPage::providers();
        self::assertArrayHasKey('custom', $providers);
    }

    public function test_merge_returns_all_settings_fields(): void
    {
        $merged = SettingsPage::merge([]);
        $expected = [
            'provider', 'model', 'api_key', 'max_iterations',
            'base_url', 'store_messages', 'system_prompt',
            'cloud_key', 'cloud_signing_secret', 'cloud_disable',
            'remote_skill_urls',
        ];

        sort($expected);
        $actual = array_keys($merged);
        sort($actual);

        self::assertSame($expected, $actual, 'merge() must return exactly these fields, no more');
    }

    public function test_merge_cloud_signing_secret_defaults_to_empty(): void
    {
        $merged = SettingsPage::merge([]);
        self::assertSame('', $merged['cloud_signing_secret']);
    }

    public function test_merge_uses_saved_cloud_signing_secret(): void
    {
        $merged = SettingsPage::merge(['cloud_signing_secret' => 'mysecret']);
        self::assertSame('mysecret', $merged['cloud_signing_secret']);
    }

    public function test_merge_defaults_store_messages_to_true_when_empty(): void
    {
        $merged = SettingsPage::merge([]);
        self::assertSame('1', $merged['store_messages']);
    }

    public function test_merge_uses_saved_provider(): void
    {
        $merged = SettingsPage::merge(['provider' => 'openai']);
        self::assertSame('openai', $merged['provider']);
    }

    public function test_merge_store_messages_false_when_saved_zero(): void
    {
        $merged = SettingsPage::merge(['store_messages' => '0']);
        self::assertSame('0', $merged['store_messages']);
    }

    public function test_merge_store_messages_true_when_saved_one(): void
    {
        $merged = SettingsPage::merge(['store_messages' => '1']);
        self::assertSame('1', $merged['store_messages']);
    }

    public function test_merge_defaults_max_iterations_when_saved_zero(): void
    {
        $merged = SettingsPage::merge(['max_iterations' => 0]);
        self::assertSame(20, $merged['max_iterations']);
    }

    public function test_validate_valid_post_returns_no_errors(): void
    {
        $result = SettingsPage::validate([
            'provider' => 'anthropic',
            'model' => 'claude-haiku',
            'api_key' => 'sk-ant-test',
            'max_iterations' => 10,
            'store_messages' => '1',
            'system_prompt' => 'You are helpful.',
            'cloud_key' => '',
            'cloud_signing_secret' => '',
            'cloud_disable' => '',
            'base_url' => '',
        ]);
        self::assertSame([], $result['errors']);
    }

    public function test_validate_preserves_blank_api_key_from_current(): void
    {
        $result = SettingsPage::validate(
            ['max_iterations' => 10, 'api_key' => ''],
            ['api_key' => 'sk-stored-secret'],
        );

        self::assertSame('sk-stored-secret', $result['data']['api_key']);
    }

    public function test_validate_overrides_current_api_key_when_submitted(): void
    {
        $result = SettingsPage::validate(
            ['max_iterations' => 10, 'api_key' => 'sk-new'],
            ['api_key' => 'sk-stored-secret'],
        );

        self::assertSame('sk-new', $result['data']['api_key']);
    }

    public function test_validate_preserves_blank_cloud_secrets_from_current(): void
    {
        $result = SettingsPage::validate(
            ['max_iterations' => 10, 'cloud_key' => '', 'cloud_signing_secret' => ''],
            ['cloud_key' => 'ck-stored', 'cloud_signing_secret' => 'css-stored'],
        );

        self::assertSame('ck-stored', $result['data']['cloud_key']);
        self::assertSame('css-stored', $result['data']['cloud_signing_secret']);
    }

    public function test_validate_trims_cloud_signing_secret(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10, 'cloud_signing_secret' => '  mysecret  ']);
        self::assertSame('mysecret', $result['data']['cloud_signing_secret']);
    }

    public function test_validate_cloud_signing_secret_defaults_to_empty(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10]);
        self::assertSame('', $result['data']['cloud_signing_secret']);
    }

    public function test_validate_rejects_max_iterations_zero(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 0]);
        self::assertArrayHasKey('max_iterations', $result['errors']);
    }

    public function test_validate_rejects_max_iterations_above_50(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 100]);
        self::assertArrayHasKey('max_iterations', $result['errors']);
    }

    public function test_validate_out_of_range_max_iterations_falls_back_to_canonical_default(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 100]);
        self::assertSame(ClawConfig::DEFAULT_MAX_ITERATIONS, $result['data']['max_iterations']);
    }

    public function test_validate_rejects_invalid_base_url(): void
    {
        $result = SettingsPage::validate(['provider' => 'custom', 'max_iterations' => 10, 'base_url' => 'not-a-url']);
        self::assertArrayHasKey('base_url', $result['errors']);
    }

    public function test_validate_rejects_file_scheme_base_url(): void
    {
        $result = SettingsPage::validate(['provider' => 'custom', 'max_iterations' => 10, 'base_url' => 'file:///etc/passwd']);
        self::assertArrayHasKey('base_url', $result['errors']);
    }

    public function test_validate_accepts_valid_https_base_url(): void
    {
        $result = SettingsPage::validate(['provider' => 'custom', 'max_iterations' => 10, 'base_url' => 'https://api.example.com/v1/chat/completions']);
        self::assertArrayNotHasKey('base_url', $result['errors']);
        self::assertSame('https://api.example.com/v1/chat/completions', $result['data']['base_url']);
    }

    public function test_validate_empty_base_url_is_accepted(): void
    {
        $result = SettingsPage::validate(['provider' => 'custom', 'max_iterations' => 10, 'base_url' => '']);
        self::assertArrayNotHasKey('base_url', $result['errors']);
        self::assertSame('', $result['data']['base_url']);
    }

    public function test_validate_ignores_base_url_for_non_custom_provider(): void
    {
        $result = SettingsPage::validate(['provider' => 'ollama', 'max_iterations' => 10, 'base_url' => 'not-a-url']);
        self::assertArrayNotHasKey('base_url', $result['errors']);
        self::assertSame('', $result['data']['base_url']);
    }

    public function test_validate_store_messages_defaults_to_zero(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10]);
        self::assertSame('0', $result['data']['store_messages']);
    }

    public function test_validate_store_messages_one_when_set(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10, 'store_messages' => '1']);
        self::assertSame('1', $result['data']['store_messages']);
    }

    public function test_validate_returns_data_key(): void
    {
        $result = SettingsPage::validate([]);
        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('errors', $result);
    }

    public function test_validate_cloud_disable_array_input_normalised(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10, 'cloud_disable' => ['scan', 'observability']]);
        self::assertSame(['scan', 'observability'], $result['data']['cloud_disable']);
    }

    public function test_validate_cloud_disable_empty_array_stays_empty(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10, 'cloud_disable' => []]);
        self::assertSame([], $result['data']['cloud_disable']);
    }

    public function test_validate_cloud_disable_csv_string_split_to_array(): void
    {
        $result = SettingsPage::validate(['max_iterations' => 10, 'cloud_disable' => 'scan, observability ,, ']);
        self::assertSame(['scan', 'observability'], $result['data']['cloud_disable']);
    }

    public function test_validate_cloud_disable_array_with_non_scalar_drops_non_scalar(): void
    {
        $result = SettingsPage::validate([
            'max_iterations' => 10,
            'cloud_disable' => ['scan', ['nested'], 'observability', new \stdClass],
        ]);
        self::assertSame(['scan', 'observability'], $result['data']['cloud_disable']);
    }

    public function test_validate_store_messages_non_string_truthy_not_accepted(): void
    {
        $post = ['max_iterations' => 10, 'store_messages' => 1];
        $result = SettingsPage::validate($post);
        self::assertSame('0', $result['data']['store_messages']);
    }

    public function test_validate_store_messages_bool_not_accepted(): void
    {
        $post = ['max_iterations' => 10, 'store_messages' => true];
        $result = SettingsPage::validate($post);
        self::assertSame('0', $result['data']['store_messages']);
    }

    public function test_validate_store_messages_string_truthy_word(): void
    {
        $post = ['max_iterations' => 10, 'store_messages' => 'true'];
        $result = SettingsPage::validate($post);
        self::assertSame('1', $result['data']['store_messages']);
    }

    public function test_validate_store_messages_zero_string_stays_zero(): void
    {
        $post = ['max_iterations' => 10, 'store_messages' => '0'];
        $result = SettingsPage::validate($post);
        self::assertSame('0', $result['data']['store_messages']);
    }

    public function test_merge_cloud_disable_handles_legacy_array_string_poison(): void
    {
        $merged = SettingsPage::merge(['cloud_disable' => 'Array']);
        self::assertSame([], $merged['cloud_disable']);
    }

    public function test_merge_cloud_disable_handles_null(): void
    {
        $merged = SettingsPage::merge(['cloud_disable' => null]);
        self::assertSame([], $merged['cloud_disable']);
    }

    public function test_merge_cloud_disable_from_csv_string(): void
    {
        $merged = SettingsPage::merge(['cloud_disable' => 'scan, observability']);
        self::assertSame(['scan', 'observability'], $merged['cloud_disable']);
    }

    public function test_merge_cloud_disable_filters_array_poison_inside_array(): void
    {
        $merged = SettingsPage::merge(['cloud_disable' => ['scan', 'Array', 'observability']]);
        self::assertSame(['scan', 'observability'], $merged['cloud_disable']);
    }

    public function test_normalise_cloud_disable_handles_null_input(): void
    {
        self::assertSame([], SettingsPage::normaliseCloudDisable(null));
    }

    public function test_normalise_cloud_disable_handles_empty_string(): void
    {
        self::assertSame([], SettingsPage::normaliseCloudDisable(''));
    }

    public function test_normalise_cloud_disable_handles_literal_array_poison(): void
    {
        self::assertSame([], SettingsPage::normaliseCloudDisable('Array'));
    }

    public function test_normalise_cloud_disable_array_with_mixed_types(): void
    {
        $result = SettingsPage::normaliseCloudDisable(['scan', null, 0, '', 'observability', false]);
        self::assertSame(['scan', '0', 'observability'], $result);
    }

    public function test_remote_skill_urls_filters_to_https_only(): void
    {
        $result = SettingsPage::validate([
            'max_iterations' => 10,
            'remote_skill_urls' => "https://ok.example/skills.json\nhttp://insecure.example/x\nnot-a-url\n  https://two.example/SKILL.md  ",
        ]);
        self::assertSame(
            ['https://ok.example/skills.json', 'https://two.example/SKILL.md'],
            $result['data']['remote_skill_urls'],
        );
    }

    public function test_providers_returns_select_entry_as_first_option(): void
    {
        $providers = SettingsPage::providers();
        $keys = array_keys($providers);
        self::assertSame('', $keys[0], 'First key must be empty string (the Select-a-provider entry).');
    }

    public function test_guide_providers_returns_list(): void
    {
        self::assertIsArray(SettingsPage::guideProviders());
    }

    public function test_guide_providers_rows_have_expected_shape(): void
    {
        $rows = SettingsPage::guideProviders();
        if ($rows === []) {
            self::markTestSkipped('ProviderCatalogue not available.');
        }
        foreach ($rows as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('key', $row);
            self::assertArrayHasKey('signup', $row);
            self::assertArrayHasKey('notes', $row);
        }
    }

    public function test_guide_providers_anthropic_has_curated_meta(): void
    {
        $rows = SettingsPage::guideProviders();
        $keys = array_column($rows, 'key');
        if (! in_array('anthropic', $keys, true)) {
            self::markTestSkipped('anthropic not in catalogue.');
        }
        $anthropic = $rows[array_search('anthropic', $keys, true)];
        self::assertSame('Anthropic', $anthropic['name']);
        self::assertStringContainsString('console.anthropic.com', $anthropic['signup']);
    }

    public function test_core_utility_tools_rows_have_expected_shape(): void
    {
        $rows = SettingsPage::coreUtilityTools();
        self::assertCount(7, $rows);

        foreach ($rows as $row) {
            self::assertArrayHasKey('tool', $row);
            self::assertArrayHasKey('desc', $row);
            self::assertArrayHasKey('deprecated', $row);
            self::assertIsBool($row['deprecated']);
        }
    }

    public function test_core_utility_tools_sorted_by_tool_name(): void
    {
        $rows = SettingsPage::coreUtilityTools();
        $names = array_column($rows, 'tool');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
    }

    public function test_guide_tool_rows_empty_when_no_tools(): void
    {
        self::assertSame([], SettingsPage::guideToolRows([]));
    }

    public function test_guide_tool_rows_skips_non_objects(): void
    {
        self::assertSame([], SettingsPage::guideToolRows(['not-an-object', 42]));
    }

    public function test_guide_tool_rows_skips_core_phpclaw_tools_namespace(): void
    {
        $coreTool = new class
        {
            public function name(): string
            {
                return 'phpclaw_core_tool';
            }
        };
        $rows = SettingsPage::guideToolRows([$coreTool]);
        self::assertCount(1, $rows);
        self::assertSame('phpclaw_core_tool', $rows[0]['name']);
    }

    public function test_guide_tool_rows_uses_curated_meta_for_known_tool(): void
    {
        $tool = new OcProductTool(null, 'oc_', true);
        $rows = SettingsPage::guideToolRows([$tool]);
        self::assertCount(1, $rows);
        self::assertSame('Product Tool', $rows[0]['name']);
        self::assertStringContainsString('SKU', $rows[0]['description']);
        self::assertNotSame('', $rows[0]['prompts']);
    }

    public function test_guide_tool_rows_falls_back_to_tool_methods_for_unknown_tool(): void
    {
        $tool = new class
        {
            public function name(): string
            {
                return 'my_custom_tool';
            }

            public function description(): string
            {
                return 'A custom third-party tool.';
            }
        };
        $rows = SettingsPage::guideToolRows([$tool]);
        self::assertSame('my_custom_tool', $rows[0]['name']);
        self::assertSame('A custom third-party tool.', $rows[0]['description']);
        self::assertSame('', $rows[0]['prompts']);
    }

    public function test_normalise_remote_skill_urls_handles_null(): void
    {
        self::assertSame([], SettingsPage::normaliseRemoteSkillUrls(null));
    }

    public function test_normalise_remote_skill_urls_handles_array_poison(): void
    {
        self::assertSame([], SettingsPage::normaliseRemoteSkillUrls('Array'));
    }

    public function test_normalise_remote_skill_urls_array_input(): void
    {
        $result = SettingsPage::normaliseRemoteSkillUrls(['https://a.example/skills.json', 'not-https', 'https://b.example/SKILL.md']);
        self::assertSame(['https://a.example/skills.json', 'https://b.example/SKILL.md'], $result);
    }

    public function test_normalise_remote_skill_urls_drops_http_and_no_extension_keeps_valid(): void
    {
        $result = SettingsPage::normaliseRemoteSkillUrls([
            'http://a.example/x.md',
            'https://b.example/skills.json',
            'https://c.example/no-extension',
            'https://d.example/skill.JSON',
            'https://e.example/x.md',
        ]);
        self::assertSame([
            'https://b.example/skills.json',
            'https://d.example/skill.JSON',
            'https://e.example/x.md',
        ], $result);
    }

    public function test_build_capability_records_returns_expected_shape(): void
    {
        $records = SettingsPage::buildCapabilityRecords();
        self::assertArrayHasKey('memory', $records);
        self::assertArrayHasKey('skills', $records);
        self::assertArrayHasKey('guards', $records);
        self::assertArrayHasKey('hooks', $records);
        self::assertIsArray($records['memory']);
        self::assertIsArray($records['skills']);
        self::assertIsArray($records['guards']);
        self::assertIsArray($records['hooks']);
    }

    public function test_build_capability_records_null_event_firer_skips_extras(): void
    {
        $records = SettingsPage::buildCapabilityRecords(null);
        foreach ($records['memory'] as $entry) {
            self::assertNotSame('opencart', $entry['source'] ?? null, 'No registry means no opencart-sourced extras.');
        }
    }

    public function test_build_capability_records_with_event_firer_merges_extras(): void
    {
        $eventFirer = new OcEventFirer(null);
        $records = SettingsPage::buildCapabilityRecords($eventFirer);
        self::assertIsArray($records['memory']);
    }
}
