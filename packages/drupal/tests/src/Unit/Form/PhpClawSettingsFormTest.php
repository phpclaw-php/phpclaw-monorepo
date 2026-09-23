<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Drupal\Form\PhpClawSettingsForm;
use PHPUnit\Framework\TestCase;

final class PhpClawSettingsFormTest extends TestCase
{
    private function createForm(array $configData = [], ?Config $configSpy = null): PhpClawSettingsForm
    {
        $config = $configSpy ?? $this->createMock(Config::class);

        if ($configSpy === null) {
            $config->method('get')->willReturnCallback(
                static fn (string $key): mixed => $configData[$key] ?? null
            );
            $config->method('set')->willReturnSelf();
        }

        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('getEditable')->willReturn($config);
        $configFactory->method('get')->willReturn($config);

        $typedConfig = $this->createMock(TypedConfigManagerInterface::class);

        $form = new PhpClawSettingsForm($configFactory, $typedConfig);

        $translation = $this->createMock(TranslationInterface::class);
        $translation->method('translateString')->willReturnCallback(
            static fn (TranslatableMarkup $s): string => $s->getUntranslatedString()
        );
        $form->setStringTranslation($translation);
        $form->setMessenger($this->createMock(MessengerInterface::class));

        return $form;
    }

    private function createFormState(array $values = []): FormStateInterface
    {
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $values[$key] ?? $default
        );
        $state->method('get')->willReturn(null);

        return $state;
    }

    public function test_get_form_id_returns_correct_id(): void
    {
        $this->assertSame('phpclaw_settings_form', $this->createForm()->getFormId());
    }

    public function test_get_editable_config_names_returns_settings_key(): void
    {
        $form = $this->createForm();
        $ref = new \ReflectionMethod($form, 'getEditableConfigNames');
        $ref->setAccessible(true);

        $this->assertSame(['phpclaw.settings'], $ref->invoke($form));
    }

    public function test_build_form_returns_array_with_provider_field(): void
    {
        $form = $this->createForm();
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertArrayHasKey('provider', $built);
        $this->assertSame('select', $built['provider']['#type']);
    }

    public function test_build_form_includes_all_standard_fields(): void
    {
        $form = $this->createForm();
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        foreach (['model', 'api_key', 'base_url', 'system_prompt', 'store_messages', 'max_iterations', 'remote_skill_urls'] as $field) {
            $this->assertArrayHasKey($field, $built, "Missing field: $field");
        }
    }

    public function test_build_form_remote_skill_urls_is_textarea(): void
    {
        $form = $this->createForm(['remote_skill_urls' => 'https://example.com/skill.md']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertArrayHasKey('remote_skill_urls', $built);
        $this->assertSame('textarea', $built['remote_skill_urls']['#type']);
        $this->assertSame('https://example.com/skill.md', $built['remote_skill_urls']['#default_value']);
    }

    public function test_build_form_badge_connected_for_ollama_with_host(): void
    {
        $form = $this->createForm(['provider' => 'ollama']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $markup = (string) $built['badge']['#markup'];
        $this->assertStringContainsString('phpclaw-badge--ok', $markup);
        $this->assertStringContainsString('Connected', $markup);
    }

    public function test_build_form_badge_not_configured_for_custom_without_base_url(): void
    {
        $form = $this->createForm(['provider' => 'custom', 'base_url' => '']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $markup = (string) $built['badge']['#markup'];
        $this->assertStringContainsString('phpclaw-badge--error', $markup);
    }

    public function test_build_form_badge_connected_for_api_provider_with_key(): void
    {
        $form = $this->createForm(['provider' => 'anthropic', 'api_key' => 'sk-test']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $markup = (string) $built['badge']['#markup'];
        $this->assertStringContainsString('phpclaw-badge--ok', $markup);
    }

    public function test_build_form_badge_not_configured_for_api_provider_without_key(): void
    {
        $form = $this->createForm(['provider' => 'anthropic', 'api_key' => '']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $markup = (string) $built['badge']['#markup'];
        $this->assertStringContainsString('phpclaw-badge--error', $markup);
    }

    public function test_build_form_badge_not_configured_when_no_provider(): void
    {
        $form = $this->createForm(['provider' => '']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $markup = (string) $built['badge']['#markup'];
        $this->assertStringContainsString('phpclaw-badge--error', $markup);
    }

    public function test_build_form_api_key_placeholder_masked_when_key_exists(): void
    {
        $form = $this->createForm(['api_key' => 'existing-key']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertSame('Saved. Leave blank to keep current value', (string) $built['api_key']['#placeholder']);
    }

    public function test_build_form_api_key_placeholder_empty_when_no_key(): void
    {
        $form = $this->createForm(['api_key' => '']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertSame('', $built['api_key']['#placeholder']);
    }

    public function test_build_form_store_messages_default_true_when_null(): void
    {
        $form = $this->createForm(['store_messages' => null]);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertTrue($built['store_messages']['#default_value']);
    }

    public function test_build_form_max_iterations_default_20_when_null(): void
    {
        $form = $this->createForm(['max_iterations' => null]);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertSame(20, $built['max_iterations']['#default_value']);
    }

    public function test_build_form_cloud_disable_string_shown_when_array_in_config(): void
    {
        $form = $this->createForm(['cloud_disable' => ['guards', 'webhooks']]);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        if (class_exists(CloudManager::class)) {
            $this->assertArrayHasKey('cloud_disable', $built);
            $this->assertSame('guards, webhooks', $built['cloud_disable']['#default_value']);
        } else {
            $this->assertArrayNotHasKey('cloud_disable', $built);
        }
    }

    public function test_build_form_includes_test_connection_button(): void
    {
        $form = $this->createForm();
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertArrayHasKey('test_connection', $built);
    }

    public function test_build_form_attaches_admin_settings_library(): void
    {
        $form = $this->createForm();
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        $this->assertContains('phpclaw/admin.settings', $built['#attached']['library']);
    }

    public function test_validate_max_iterations_too_low_sets_error(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 0,
            'provider' => '',
            default => null,
        });
        $state->expects($this->once())
            ->method('setErrorByName')
            ->with('max_iterations', $this->anything());

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_validate_max_iterations_too_high_sets_error(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 51,
            'provider' => '',
            default => null,
        });
        $state->expects($this->once())
            ->method('setErrorByName')
            ->with('max_iterations', $this->anything());

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_validate_max_iterations_boundary_1_is_valid(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 1,
            'provider' => '',
            default => null,
        });
        $state->expects($this->never())->method('setErrorByName');

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_validate_max_iterations_boundary_50_is_valid(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 50,
            'provider' => '',
            default => null,
        });
        $state->expects($this->never())->method('setErrorByName');

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_validate_unknown_provider_sets_error(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 20,
            'provider' => 'totally_unknown_provider_xyz',
            default => null,
        });
        $state->expects($this->once())
            ->method('setErrorByName')
            ->with('provider', $this->anything());

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_validate_empty_provider_skips_provider_check(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 20,
            'provider' => '',
            default => null,
        });
        $state->expects($this->never())->method('setErrorByName');

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_validate_base_url_invalid_scheme_sets_error(): void
    {
        $form = $this->createForm();
        $state = $this->createMock(FormStateInterface::class);
        $state->method('getValue')->willReturnCallback(static fn (string $k): mixed => match ($k) {
            'max_iterations' => 20,
            'provider' => 'anthropic',
            'base_url' => 'localhost:11434',
            default => null,
        });
        $state->expects($this->once())
            ->method('setErrorByName')
            ->with('base_url', $this->anything());

        $formArray = [];
        $form->validateForm($formArray, $state);
    }

    public function test_submit_saves_api_key_when_non_empty(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'api_key' => 'sk-my-key',
            'cloud_key' => '',
            'store_messages' => '1',
            'cloud_disable' => '',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'max_iterations' => 20,
            'system_prompt' => '',
            'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertArrayHasKey('api_key', $savedData);
        $this->assertSame('sk-my-key', $savedData['api_key']);
    }

    public function test_submit_skips_api_key_when_empty(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'api_key' => '',
            'cloud_key' => '',
            'store_messages' => '1',
            'cloud_disable' => '',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'max_iterations' => 20,
            'system_prompt' => '',
            'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertArrayNotHasKey('api_key', $savedData);
    }

    public function test_submit_saves_cloud_key_when_non_empty(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'api_key' => '',
            'cloud_key' => 'cloud-abc',
            'cloud_signing_secret' => '',
            'store_messages' => '1',
            'cloud_disable' => '',
            'provider' => 'ollama',
            'model' => 'llama3.1:8b',
            'max_iterations' => 10,
            'system_prompt' => '',
            'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertArrayHasKey('cloud_key', $savedData);
        $this->assertSame('cloud-abc', $savedData['cloud_key']);
    }

    public function test_submit_skips_cloud_key_when_empty(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'api_key' => '',
            'cloud_key' => '',
            'cloud_signing_secret' => '',
            'store_messages' => '1',
            'cloud_disable' => '',
            'provider' => 'ollama',
            'model' => '',
            'max_iterations' => 20,
            'system_prompt' => '',
            'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertArrayNotHasKey('cloud_key', $savedData);
    }

    public function test_submit_saves_cloud_signing_secret_when_non_empty(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'api_key' => '',
            'cloud_key' => '',
            'cloud_signing_secret' => 'my-signing-secret',
            'store_messages' => '1',
            'cloud_disable' => '',
            'provider' => 'ollama',
            'model' => '',
            'max_iterations' => 20,
            'system_prompt' => '',
            'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertArrayHasKey('cloud_signing_secret', $savedData);
        $this->assertSame('my-signing-secret', $savedData['cloud_signing_secret']);
    }

    public function test_submit_skips_cloud_signing_secret_when_empty(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'api_key' => '',
            'cloud_key' => '',
            'cloud_signing_secret' => '',
            'store_messages' => '1',
            'cloud_disable' => '',
            'provider' => 'ollama',
            'model' => '',
            'max_iterations' => 20,
            'system_prompt' => '',
            'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertArrayNotHasKey('cloud_signing_secret', $savedData);
    }

    public function test_build_form_cloud_signing_secret_placeholder_masked_when_value_exists(): void
    {
        $form = $this->createForm(['cloud_signing_secret' => 'existing-secret']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        if (class_exists(CloudManager::class)) {
            $this->assertArrayHasKey('cloud_signing_secret', $built);
            $this->assertSame('Saved. Leave blank to keep current value', (string) $built['cloud_signing_secret']['#placeholder']);
        } else {
            $this->assertArrayNotHasKey('cloud_signing_secret', $built);
        }
    }

    public function test_build_form_cloud_signing_secret_placeholder_empty_when_no_value(): void
    {
        $form = $this->createForm(['cloud_signing_secret' => '']);
        $state = $this->createFormState();
        $built = $form->buildForm([], $state);

        if (class_exists(CloudManager::class)) {
            $this->assertArrayHasKey('cloud_signing_secret', $built);
            $this->assertSame('', $built['cloud_signing_secret']['#placeholder']);
        } else {
            $this->assertArrayNotHasKey('cloud_signing_secret', $built);
        }
    }

    public function test_submit_store_messages_from_string_one(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'store_messages' => '1',
            'api_key' => '', 'cloud_key' => '', 'cloud_disable' => '',
            'provider' => '', 'model' => '',
            'max_iterations' => 20, 'system_prompt' => '', 'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertTrue($savedData['store_messages']);
    }

    public function test_submit_store_messages_from_integer_zero(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'store_messages' => 0,
            'api_key' => '', 'cloud_key' => '', 'cloud_disable' => '',
            'provider' => '', 'model' => '',
            'max_iterations' => 20, 'system_prompt' => '', 'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertFalse($savedData['store_messages']);
    }

    public function test_submit_parses_cloud_disable_from_comma_string(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'cloud_disable' => 'guards, webhooks , tracing',
            'store_messages' => '1', 'api_key' => '', 'cloud_key' => '',
            'provider' => '', 'model' => '',
            'max_iterations' => 20, 'system_prompt' => '', 'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertSame(['guards', 'webhooks', 'tracing'], $savedData['cloud_disable']);
    }

    public function test_submit_parses_cloud_disable_from_array(): void
    {
        $savedData = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnCallback(function (string $k, mixed $v) use ($config, &$savedData) {
            $savedData[$k] = $v;

            return $config;
        });

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'cloud_disable' => ['guards', ' webhooks ', ''],
            'store_messages' => '1', 'api_key' => '', 'cloud_key' => '',
            'provider' => '', 'model' => '',
            'max_iterations' => 20, 'system_prompt' => '', 'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);

        $this->assertSame(['guards', 'webhooks'], $savedData['cloud_disable']);
    }

    public function test_submit_calls_save(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(null);
        $config->method('set')->willReturnSelf();
        $config->expects($this->once())->method('save');

        $form = $this->createForm([], $config);

        $state = $this->createFormState([
            'store_messages' => '1', 'api_key' => '', 'cloud_key' => '', 'cloud_disable' => '',
            'provider' => '', 'model' => '',
            'max_iterations' => 20, 'system_prompt' => '', 'remote_skill_urls' => '',
        ]);

        $formArray = [];
        $form->submitForm($formArray, $state);
    }

    public function test_cloud_fields_are_hidden_when_store_messages_is_off_and_shown_when_on(): void
    {
        $form = $this->createForm(['store_messages' => true]);
        $built = $form->buildForm([], $this->createFormState());

        if (! array_key_exists('cloud_key', $built)) {
            $this->markTestSkipped('Cloud package not installed.');
        }

        $expected = ['visible' => [':input[name="store_messages"]' => ['checked' => true]]];

        foreach (['cloud_key', 'cloud_signing_secret', 'cloud_disable'] as $field) {
            $this->assertArrayHasKey('#states', $built[$field], $field.' must be conditionally visible.');
            $this->assertSame($expected, $built[$field]['#states'], $field.' must be visible only while Store Messages is on.');
        }

        $this->assertArrayHasKey('cloud_off_notice', $built);
        $this->assertSame(
            ['visible' => [':input[name="store_messages"]' => ['checked' => false]]],
            $built['cloud_off_notice']['#states'],
            'The explanatory notice must appear only while Store Messages is off.',
        );
    }

    public function test_cloud_fields_stay_in_the_form_so_their_saved_values_survive(): void
    {
        $form = $this->createForm(['store_messages' => false, 'cloud_key' => 'stored-key']);
        $built = $form->buildForm([], $this->createFormState());

        if (! array_key_exists('cloud_key', $built)) {
            $this->markTestSkipped('Cloud package not installed.');
        }

        foreach (['cloud_key', 'cloud_signing_secret', 'cloud_disable'] as $field) {
            $this->assertArrayHasKey($field, $built, $field.' must remain in the form while hidden, never removed.');
            $this->assertArrayNotHasKey('#access', $built[$field], $field.' must be hidden by #states, not stripped by #access.');
        }
    }
}
