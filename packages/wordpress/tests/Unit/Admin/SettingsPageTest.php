<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Tools\Contracts\ConfigurableToolInterface;
use PhpClaw\WordPress\Admin\SettingsPage;
use PhpClaw\WordPress\Plugin;
use PhpClaw\WordPress\Tests\Stubs\FieldedToolStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsPage::class)]
final class SettingsPageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            '__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_attr__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_attr' => static fn (string $s): string => $s,
            'esc_textarea' => static fn (string $s): string => $s,
            'checked' => static fn (mixed $a, mixed $b, bool $echo = true): string => $a == $b ? ' checked' : '',
            'selected' => static fn (mixed $a, mixed $b, bool $echo = true): string => $a == $b ? ' selected' : '',
            'sanitize_text_field' => static fn (string $s): string => trim($s),
            'sanitize_textarea_field' => static fn (string $s): string => $s,
            'esc_url_raw' => static fn (string $s): string => $s,
            'register_setting' => static function (...$a): void {},
            'add_settings_section' => static function (...$a): void {},
            'add_settings_field' => static function (...$a): void {},
            'settings_fields' => static function (...$a): void {},
            'do_settings_sections' => static function (...$a): void {},
            'submit_button' => static function (...$a): void {
                echo '<button type="submit">Save</button>';
            },
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_method_signature(): void
    {
        $ref = new \ReflectionClass(SettingsPage::class);
        $m = $ref->getMethod('register');
        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    public function test_register_settings_registers_setting_section_and_fields(): void
    {
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = []): mixed => $d);
        $calls = ['setting' => 0, 'section' => 0, 'field' => 0];
        $fieldIds = [];

        Functions\when('register_setting')->alias(static function (...$args) use (&$calls): void {
            $calls['setting']++;
        });
        Functions\when('add_settings_section')->alias(static function (...$args) use (&$calls): void {
            $calls['section']++;
        });
        Functions\when('add_settings_field')->alias(static function (...$args) use (&$calls, &$fieldIds): void {
            $calls['field']++;
            $fieldIds[] = $args[0] ?? '';
        });

        SettingsPage::registerSettings();

        $cloudInstalled = class_exists(CloudManager::class);

        $dynamicFields = 0;
        foreach (Plugin::extraToolClasses() as $class) {
            if (is_string($class) && class_exists($class)
                && is_a($class, ConfigurableToolInterface::class, true)) {
                $dynamicFields += count($class::settingsFields());
            }
        }

        $expectedCount = ($cloudInstalled ? 11 : 8) + $dynamicFields;

        self::assertSame(1, $calls['setting']);
        self::assertSame(1, $calls['section']);
        self::assertSame($expectedCount, $calls['field']);

        $alwaysPresent = [
            'phpclaw_provider', 'phpclaw_model', 'phpclaw_api_key', 'phpclaw_base_url',
            'phpclaw_system_prompt', 'phpclaw_store_messages', 'phpclaw_max_iterations',
            'phpclaw_remote_skill_urls',
        ];
        foreach ($alwaysPresent as $expected) {
            self::assertContains($expected, $fieldIds);
        }

        if ($cloudInstalled) {
            self::assertContains('phpclaw_cloud_key', $fieldIds);
            self::assertContains('phpclaw_cloud_signing_secret', $fieldIds);
            self::assertContains('phpclaw_cloud_disable', $fieldIds);
        } else {
            self::assertNotContains('phpclaw_cloud_key', $fieldIds);
            self::assertNotContains('phpclaw_cloud_signing_secret', $fieldIds);
            self::assertNotContains('phpclaw_cloud_disable', $fieldIds);
        }
    }

    public function test_register_settings_register_setting_receives_sanitize_callback(): void
    {
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = []): mixed => $d);
        $captured = null;
        Functions\when('register_setting')->alias(static function (string $group, string $name, array $args) use (&$captured): void {
            $captured = $args;
        });
        Functions\when('add_settings_section')->alias(static function (...$args): void {});
        Functions\when('add_settings_field')->alias(static function (...$args): void {});

        SettingsPage::registerSettings();

        self::assertIsArray($captured);
        self::assertSame([SettingsPage::class, 'sanitize'], $captured['sanitize_callback']);
        self::assertSame([], $captured['default']);
        self::assertSame('no', $captured['autoload']);
    }

    public function test_register_settings_add_settings_field_passes_field_args(): void
    {
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = []): mixed => $d);
        $fieldArgs = [];
        Functions\when('register_setting')->alias(static function (...$args): void {});
        Functions\when('add_settings_section')->alias(static function (...$args): void {});
        Functions\when('add_settings_field')->alias(static function (string $id, string $label, $cb, string $page, string $section, array $args) use (&$fieldArgs): void {
            $fieldArgs[$id] = $args;
        });

        SettingsPage::registerSettings();

        self::assertSame('provider', $fieldArgs['phpclaw_provider']['field']);
        self::assertSame('phpclaw_provider', $fieldArgs['phpclaw_provider']['label_for']);
        self::assertSame('store_messages', $fieldArgs['phpclaw_store_messages']['field']);
    }

    public function test_render_tab_settings_outputs_form(): void
    {
        ob_start();
        $this->invoke('renderTabSettings');
        $html = ob_get_clean();

        self::assertStringContainsString('<form action="options.php"', $html);
        self::assertStringContainsString('Test Connection', $html);
        self::assertStringContainsString('Save your settings first', $html);
    }

    public function test_render_does_nothing_without_permission(): void
    {
        Functions\expect('current_user_can')->once()->andReturnFalse();

        ob_start();
        SettingsPage::render();
        $html = ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_render_outputs_header_and_form_when_authorized(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->atLeast()->once()->andReturn(['provider' => 'ollama']);

        ob_start();
        SettingsPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('phpClaw', $html);
        self::assertStringContainsString('<form action="options.php"', $html);
        self::assertStringContainsString('Test Connection', $html);
        self::assertStringContainsString('Built for the PHP community', $html);
    }

    public function test_render_shows_connection_badge_when_ollama(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->atLeast()->once()->andReturn(['provider' => 'ollama', 'model' => 'qwen2.5:7b']);

        ob_start();
        SettingsPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Connected', $html);
        self::assertStringContainsString('ollama', $html);
    }

    public function test_render_shows_not_configured_when_no_provider(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        ob_start();
        SettingsPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Not configured', $html);
    }

    public function test_sanitize_returns_empty_array_for_non_array_input(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn(['provider' => 'ollama']);

        self::assertSame(['provider' => 'ollama'], SettingsPage::sanitize('not-array'));
        self::assertSame(['provider' => 'ollama'], SettingsPage::sanitize(null));
    }

    public function test_sanitize_remote_skill_urls_keeps_https_only(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize([
            'remote_skill_urls' => "https://a.example/skills.json\nhttp://insecure.example/x.json\n\nftp://nope.example/y\nhttps://b.example/skill.md",
        ]);

        self::assertSame(
            ['https://a.example/skills.json', 'https://b.example/skill.md'],
            $r['remote_skill_urls'],
        );
    }

    public function test_sanitize_remote_skill_urls_defaults_to_empty(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize(['provider' => 'ollama']);

        self::assertSame([], $r['remote_skill_urls']);
    }

    public function test_sanitize_provider_only_allows_known_values(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize(['provider' => 'evil_provider']);
        self::assertSame('', $r['provider']);

        $r = SettingsPage::sanitize(['provider' => 'anthropic']);
        self::assertSame('anthropic', $r['provider']);

        $r = SettingsPage::sanitize(['provider' => 'ollama']);
        self::assertSame('ollama', $r['provider']);
    }

    public function test_sanitize_max_iterations_clamps_to_1_50(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        self::assertSame(50, SettingsPage::sanitize(['max_iterations' => 9999])['max_iterations']);
        self::assertSame(1, SettingsPage::sanitize(['max_iterations' => -5])['max_iterations']);
        self::assertSame(15, SettingsPage::sanitize(['max_iterations' => '15'])['max_iterations']);
    }

    public function test_sanitize_base_url_rejects_non_http_url(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        self::assertSame('', SettingsPage::sanitize(['base_url' => 'file:///etc/passwd'])['base_url']);
        self::assertSame('', SettingsPage::sanitize(['base_url' => 'ftp://example.com'])['base_url']);
        self::assertSame('', SettingsPage::sanitize(['base_url' => ''])['base_url']);
        self::assertSame('https://api.minimax.io/v1/chat/completions', SettingsPage::sanitize(['base_url' => 'https://api.minimax.io/v1/chat/completions'])['base_url']);
    }

    public function test_sanitize_store_messages_normalizes_to_0_or_1(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        self::assertSame('1', SettingsPage::sanitize(['store_messages' => '1'])['store_messages']);
        self::assertSame('0', SettingsPage::sanitize(['store_messages' => '0'])['store_messages']);
        self::assertSame('0', SettingsPage::sanitize([])['store_messages']);
        self::assertSame('0', SettingsPage::sanitize(['store_messages' => 'on'])['store_messages']);
    }

    public function test_sanitize_cloud_disable_splits_csv_into_array(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize(['cloud_disable' => 'guards, webhooks, hooks']);

        self::assertSame(['guards', 'webhooks', 'hooks'], $r['cloud_disable']);
    }

    public function test_sanitize_cloud_disable_strips_empty_values(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize(['cloud_disable' => 'guards, , webhooks, ,']);

        self::assertSame(['guards', 'webhooks'], $r['cloud_disable']);
    }

    public function test_sanitize_passes_through_api_key_and_cloud_key(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize([
            'api_key' => '  sk-test  ',
            'cloud_key' => 'pgc_xxx',
            'model' => 'qwen',
            'system_prompt' => 'Hello',
        ]);

        self::assertSame('sk-test', $r['api_key']);
        self::assertSame('pgc_xxx', $r['cloud_key']);
        self::assertSame('qwen', $r['model']);
        self::assertSame('Hello', $r['system_prompt']);
    }

    public function test_sanitize_preserves_stored_secret_when_submission_is_blank(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([
            'api_key' => 'sk-stored',
            'cloud_key' => 'pgc-stored',
        ]);

        $r = SettingsPage::sanitize([
            'api_key' => '',
            'cloud_key' => '',
        ]);

        self::assertSame('sk-stored', $r['api_key']);
        self::assertSame('pgc-stored', $r['cloud_key']);
    }

    public function test_render_shows_the_cloud_hidden_notice_when_store_messages_is_off(): void
    {
        if (! class_exists(CloudManager::class)) {
            self::markTestSkipped('Cloud package is not installed.');
        }

        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->atLeast()->once()->andReturn(['store_messages' => '0']);

        ob_start();
        SettingsPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('notice notice-info', $html);
        self::assertStringContainsString('the cloud fields are hidden', $html);
        self::assertStringNotContainsString('id="phpclaw-cloud-off-notice" style="display:none"', $html);
    }

    public function test_render_keeps_the_cloud_notice_hidden_when_store_messages_is_on(): void
    {
        Functions\expect('current_user_can')->once()->andReturnTrue();
        Functions\expect('get_option')->atLeast()->once()->andReturn(['store_messages' => '1']);

        ob_start();
        SettingsPage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('id="phpclaw-cloud-off-notice"', $html);
        self::assertStringContainsString('style="display:none"', $html);
    }

    public function test_cloud_fields_are_registered_when_store_messages_is_on(): void
    {
        if (! class_exists(CloudManager::class)) {
            self::markTestSkipped('Cloud package is not installed.');
        }

        Functions\when('get_option')->alias(static fn (string $k, mixed $d = []): mixed => ['store_messages' => '1']);

        $fieldIds = [];
        Functions\when('register_setting')->alias(static function (...$a): void {});
        Functions\when('add_settings_section')->alias(static function (...$a): void {});
        Functions\when('add_settings_field')->alias(static function (...$a) use (&$fieldIds): void {
            $fieldIds[] = $a[0] ?? '';
        });

        SettingsPage::registerSettings();

        self::assertContains('phpclaw_cloud_key', $fieldIds);
        self::assertContains('phpclaw_cloud_signing_secret', $fieldIds);
        self::assertContains('phpclaw_cloud_disable', $fieldIds);
        self::assertContains('phpclaw_store_messages', $fieldIds);
    }

    public function test_cloud_fields_stay_registered_when_store_messages_is_off(): void
    {
        if (! class_exists(CloudManager::class)) {
            self::markTestSkipped('Cloud package is not installed.');
        }

        Functions\when('get_option')->alias(static fn (string $k, mixed $d = []): mixed => ['store_messages' => '0']);

        $fieldIds = [];
        Functions\when('register_setting')->alias(static function (...$a): void {});
        Functions\when('add_settings_section')->alias(static function (...$a): void {});
        Functions\when('add_settings_field')->alias(static function (...$a) use (&$fieldIds): void {
            $fieldIds[] = $a[0] ?? '';
        });

        SettingsPage::registerSettings();

        self::assertContains('phpclaw_cloud_key', $fieldIds);
        self::assertContains('phpclaw_cloud_signing_secret', $fieldIds);
        self::assertContains('phpclaw_cloud_disable', $fieldIds);
        self::assertContains('phpclaw_store_messages', $fieldIds);
    }

    public function test_turning_store_messages_back_on_restores_the_saved_cloud_values(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([
            'cloud_key' => 'pgc-round-trip',
            'cloud_signing_secret' => 'whsec-round-trip',
            'cloud_disable' => ['observability'],
            'store_messages' => '1',
        ]);

        $off = SettingsPage::sanitize(['model' => 'qwen']);

        self::assertSame('0', $off['store_messages']);
        self::assertSame('pgc-round-trip', $off['cloud_key']);
        self::assertSame('whsec-round-trip', $off['cloud_signing_secret']);
        self::assertSame(['observability'], $off['cloud_disable']);

        $on = SettingsPage::sanitize(['model' => 'qwen', 'store_messages' => '1']);

        self::assertSame('1', $on['store_messages']);
        self::assertSame('pgc-round-trip', $on['cloud_key']);
        self::assertSame('whsec-round-trip', $on['cloud_signing_secret']);
        self::assertSame(['observability'], $on['cloud_disable']);
    }

    public function test_sanitize_preserves_cloud_values_when_they_are_not_submitted(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([
            'cloud_key' => 'pgc-stored-key',
            'cloud_signing_secret' => 'whsec-stored',
            'cloud_disable' => ['scan'],
            'store_messages' => '0',
        ]);

        $r = SettingsPage::sanitize(['model' => 'qwen']);

        self::assertSame('0', $r['store_messages']);
        self::assertSame('pgc-stored-key', $r['cloud_key']);
        self::assertSame('whsec-stored', $r['cloud_signing_secret']);
        self::assertSame(['scan'], $r['cloud_disable']);
    }

    public function test_render_field_api_key_outputs_password_input(): void
    {
        Functions\expect('get_option')->once()->andReturn(['api_key' => 'sk-real']);

        ob_start();
        SettingsPage::renderField(['field' => 'api_key']);
        $html = ob_get_clean();

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('id="phpclaw_api_key"', $html);
        self::assertStringNotContainsString('sk-real', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('Saved', $html);
    }

    public function test_render_field_provider_renders_every_provider_option(): void
    {
        Functions\expect('get_option')->once()->andReturn(['provider' => 'ollama']);

        ob_start();
        SettingsPage::renderField(['field' => 'provider']);
        $html = ob_get_clean();

        self::assertStringContainsString('<select', $html);
        self::assertSame(
            count($this->invoke('providerOptions')),
            substr_count($html, '<option'),
            'every provider option must be rendered',
        );
        foreach (['anthropic', 'openai', 'groq', 'gemini', 'mistral', 'ollama'] as $p) {
            self::assertStringContainsString($p, $html);
        }
    }

    public function test_render_field_base_url(): void
    {
        Functions\expect('get_option')->once()->andReturn(['base_url' => 'https://api.example.com/v1/chat/completions']);

        ob_start();
        SettingsPage::renderField(['field' => 'base_url']);
        $html = ob_get_clean();

        self::assertStringContainsString('id="phpclaw_base_url"', $html);
        self::assertStringContainsString('https://api.example.com/v1/chat/completions', $html);
    }

    public function test_render_field_system_prompt(): void
    {
        Functions\expect('get_option')->once()->andReturn(['system_prompt' => 'Be helpful.']);

        ob_start();
        SettingsPage::renderField(['field' => 'system_prompt']);
        $html = ob_get_clean();

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('Be helpful.', $html);
    }

    public function test_render_field_store_messages_checked_when_truthy(): void
    {
        Functions\expect('get_option')->once()->andReturn(['store_messages' => '1']);

        ob_start();
        SettingsPage::renderField(['field' => 'store_messages']);
        $html = ob_get_clean();

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('checked', $html);
    }

    public function test_render_field_max_iterations(): void
    {
        Functions\expect('get_option')->once()->andReturn(['max_iterations' => '15']);

        ob_start();
        SettingsPage::renderField(['field' => 'max_iterations']);
        $html = ob_get_clean();

        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('15', $html);
    }

    public function test_render_field_cloud_key(): void
    {
        Functions\expect('get_option')->once()->andReturn(['cloud_key' => 'pgc_abc']);

        ob_start();
        SettingsPage::renderField(['field' => 'cloud_key']);
        $html = ob_get_clean();

        self::assertStringContainsString('id="phpclaw_cloud_key"', $html);
        self::assertStringNotContainsString('pgc_abc', $html);
        self::assertStringContainsString('value=""', $html);
    }

    public function test_render_field_cloud_disable_with_array_value(): void
    {
        Functions\expect('get_option')->once()->andReturn(['cloud_disable' => ['guards', 'webhooks']]);

        ob_start();
        SettingsPage::renderField(['field' => 'cloud_disable']);
        $html = ob_get_clean();

        self::assertStringContainsString('guards, webhooks', $html);
    }

    public function test_render_field_model_falls_back_to_dynamic_field(): void
    {
        Functions\expect('get_option')->once()->andReturn(['model' => 'gpt-4o']);

        ob_start();
        SettingsPage::renderField(['field' => 'model']);
        $html = ob_get_clean();

        self::assertStringContainsString('gpt-4o', $html);
    }

    private function invoke(string $name, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass(SettingsPage::class);
        $m = $ref->getMethod($name);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    public function test_provider_options_lists_the_blank_placeholder_plus_every_catalogue_provider(): void
    {
        $expected = array_merge([''], ProviderCatalogue::keys());
        sort($expected);

        $actual = array_keys($this->invoke('providerOptions'));
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function test_render_select_outputs_select_with_options(): void
    {
        ob_start();
        $this->invoke('renderSelect', 'phpclaw_settings[provider]', 'openai', ['' => '- Select -', 'openai' => 'OpenAI', 'ollama' => 'Ollama'], 'phpclaw_provider');
        $html = ob_get_clean();

        self::assertStringContainsString('<select id="phpclaw_provider"', $html);
        self::assertStringContainsString('OpenAI', $html);
        self::assertStringContainsString(' selected', $html);
    }

    public function test_render_dynamic_field_model_fallback_shows_placeholder(): void
    {
        ob_start();
        $this->invoke('renderDynamicField', 'model', 'phpclaw_settings[model]', '');
        $html = ob_get_clean();

        self::assertStringContainsString('phpclaw_settings[model]', $html);
        self::assertStringContainsString('llama3.1:8b', $html);
    }

    public function test_render_dynamic_field_unknown_field_outputs_text_input(): void
    {
        ob_start();
        $this->invoke('renderDynamicField', 'unknown_field', 'phpclaw_settings[unknown_field]', 'val');
        $html = ob_get_clean();

        self::assertStringContainsString('<input', $html);
        self::assertStringContainsString('val', $html);
    }

    public function test_render_connection_badge_when_connected(): void
    {
        Functions\expect('get_option')->once()->andReturn(['provider' => 'ollama', 'model' => 'qwen2.5:7b']);

        ob_start();
        $this->invoke('renderConnectionBadge');
        $html = ob_get_clean();

        self::assertStringContainsString('Connected', $html);
        self::assertStringContainsString('ollama', $html);
    }

    public function test_render_connection_badge_when_not_configured(): void
    {
        Functions\expect('get_option')->once()->andReturn([]);

        ob_start();
        $this->invoke('renderConnectionBadge');
        $html = ob_get_clean();

        self::assertStringContainsString('Not configured', $html);
    }

    public function test_render_field_cloud_signing_secret_hides_the_saved_value(): void
    {
        Functions\expect('get_option')->once()->andReturn(['cloud_signing_secret' => 'shhh-secret']);

        ob_start();
        SettingsPage::renderField(['field' => 'cloud_signing_secret']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="phpclaw_cloud_signing_secret"', $html);
        self::assertStringNotContainsString('shhh-secret', $html);
        self::assertStringContainsString('Saved. Leave blank to keep current value', $html);
    }

    public function test_render_field_remote_skill_urls_lists_saved_urls_one_per_line(): void
    {
        Functions\expect('get_option')->once()->andReturn([
            'remote_skill_urls' => ['https://a.example/pack.json', 'https://b.example/skill.md'],
        ]);

        ob_start();
        SettingsPage::renderField(['field' => 'remote_skill_urls']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="phpclaw_remote_skill_urls"', $html);
        self::assertStringContainsString("https://a.example/pack.json\nhttps://b.example/skill.md", $html);
    }

    public function test_sanitize_preserves_the_stored_cloud_signing_secret_when_blank(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn(['cloud_signing_secret' => 'kept-secret']);

        $r = SettingsPage::sanitize(['cloud_signing_secret' => '   ']);

        self::assertSame('kept-secret', $r['cloud_signing_secret']);
    }

    public function test_sanitize_cloud_disable_accepts_an_array_submission(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);

        $r = SettingsPage::sanitize(['cloud_disable' => [' guards ', '', 'webhooks', 42]]);

        self::assertSame(['guards', 'webhooks', '42'], $r['cloud_disable']);
    }

    public function test_filter_remote_skill_urls_rejects_paths_that_are_not_md_or_json(): void
    {
        self::assertSame(
            ['https://a.example/pack.json', 'https://a.example/skill.md'],
            SettingsPage::filterRemoteSkillUrls([
                'https://a.example/pack.json',
                'https://a.example/notes.txt',
                'https://a.example/',
                'https://a.example/skill.md',
            ]),
        );
    }

    public function test_filter_remote_skill_urls_caps_the_list_at_fifty(): void
    {
        $urls = [];
        for ($i = 0; $i < 60; $i++) {
            $urls[] = "https://a.example/pack{$i}.json";
        }

        $filtered = SettingsPage::filterRemoteSkillUrls($urls);

        self::assertCount(50, $filtered);
        self::assertSame('https://a.example/pack49.json', $filtered[49]);
    }

    public function test_register_settings_adds_fields_contributed_by_a_configurable_tool(): void
    {
        Functions\when('get_option')->alias(static fn (string $k, mixed $d = []): mixed => $d);
        $this->stubExtraTools();

        $fieldIds = [];
        Functions\when('add_settings_field')->alias(
            static function (string $id, ...$rest) use (&$fieldIds): void {
                $fieldIds[] = $id;
            },
        );

        SettingsPage::registerSettings();

        self::assertContains('phpclaw_stub_endpoint', $fieldIds);
        self::assertContains('phpclaw_stub_limit', $fieldIds);
        self::assertContains('phpclaw_stub_note', $fieldIds);
    }

    public function test_sanitize_normalises_fields_contributed_by_a_configurable_tool(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);
        $this->stubExtraTools();

        $r = SettingsPage::sanitize([
            'stub_endpoint' => ' https://ok.example/hook ',
            'stub_limit' => ' 42abc ',
            'stub_note' => '  hello  ',
        ]);

        self::assertSame('https://ok.example/hook', $r['stub_endpoint']);
        self::assertSame('42', $r['stub_limit']);
        self::assertSame('hello', $r['stub_note']);
    }

    public function test_sanitize_blanks_a_configurable_tool_url_field_that_is_not_a_url(): void
    {
        Functions\expect('get_option')->atLeast()->once()->andReturn([]);
        $this->stubExtraTools();

        $r = SettingsPage::sanitize(['stub_endpoint' => 'not a url']);

        self::assertSame('', $r['stub_endpoint']);
    }

    public function test_render_dynamic_field_renders_a_configurable_tool_field(): void
    {
        $this->stubExtraTools();

        ob_start();
        $this->invoke(
            'renderDynamicField',
            'stub_endpoint',
            'phpclaw_settings[stub_endpoint]',
            'https://saved.example/hook',
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString('type="url"', $html);
        self::assertStringContainsString('https://saved.example/hook', $html);
        self::assertStringContainsString('https://example.com/hook', $html);
        self::assertStringContainsString('Stub endpoint URL.', $html);
    }

    public function test_render_dynamic_field_omits_the_description_paragraph_when_there_is_none(): void
    {
        $this->stubExtraTools();

        ob_start();
        $this->invoke('renderDynamicField', 'stub_limit', 'phpclaw_settings[stub_limit]', '7');
        $html = (string) ob_get_clean();

        self::assertStringContainsString('type="number"', $html);
        self::assertStringNotContainsString('<p class="description">', $html);
    }

    private function stubExtraTools(): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value = null, mixed ...$rest): mixed => $hook === 'phpclaw_extra_tools'
                ? [FieldedToolStub::class]
                : $value,
        );
    }
}
