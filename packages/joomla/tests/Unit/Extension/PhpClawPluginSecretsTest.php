<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Extension;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use PhpClaw\Joomla\Extension\PhpClawPlugin;
use PHPUnit\Framework\TestCase;

final class PhpClawPluginSecretsTest extends TestCase
{
    private const MASK = '**********';

    protected function tearDown(): void
    {
        PluginHelper::$plugin = false;
    }

    public function test_the_settings_form_shows_a_mask_instead_of_each_stored_secret(): void
    {
        $data = $this->formData(['api_key' => 'sk-live', 'fallback_api_key' => 'fb-key', 'cloud_key' => 'ck-key', 'cloud_signing_secret' => 'sign', 'model' => 'qwen2.5:7b']);

        (new PhpClawPlugin([]))->prepareSettingsForm(new Event('onContentPrepareForm', ['subject' => $this->form('com_plugins.plugin'), 'data' => $data]));

        self::assertSame(['api_key' => self::MASK, 'fallback_api_key' => self::MASK, 'cloud_key' => self::MASK, 'cloud_signing_secret' => self::MASK, 'model' => 'qwen2.5:7b'], $data->params);
    }

    public function test_an_empty_secret_is_left_empty(): void
    {
        $data = $this->formData(['api_key' => '', 'cloud_key' => 'ck-key']);

        (new PhpClawPlugin([]))->prepareSettingsForm(new Event('onContentPrepareForm', ['subject' => $this->form('com_plugins.plugin'), 'data' => $data]));

        self::assertSame(['api_key' => '', 'cloud_key' => self::MASK], $data->params);
    }

    public function test_another_form_is_not_masked(): void
    {
        $data = $this->formData(['api_key' => 'sk-live']);

        (new PhpClawPlugin([]))->prepareSettingsForm(new Event('onContentPrepareForm', ['subject' => $this->form('com_content.article'), 'data' => $data]));

        self::assertSame(['api_key' => 'sk-live'], $data->params);
    }

    public function test_saving_a_masked_or_empty_secret_keeps_the_stored_one_and_a_new_value_replaces_it(): void
    {
        PluginHelper::$plugin = (object) ['params' => json_encode(['api_key' => 'sk-live', 'fallback_api_key' => 'fb-key', 'cloud_key' => 'ck-key', 'cloud_signing_secret' => 'old-sign'])];
        $table = (object) ['folder' => 'system', 'element' => 'phpclaw', 'params' => json_encode(['api_key' => self::MASK, 'fallback_api_key' => '', 'cloud_key' => self::MASK, 'cloud_signing_secret' => 'new-sign', 'model' => 'qwen2.5:7b'])];

        (new PhpClawPlugin([]))->keepStoredSecrets(new Event('onExtensionBeforeSave', ['context' => 'com_plugins.plugin', 'subject' => $table, 'isNew' => false, 'data' => []]));

        self::assertSame(['api_key' => 'sk-live', 'fallback_api_key' => 'fb-key', 'cloud_key' => 'ck-key', 'cloud_signing_secret' => 'new-sign', 'model' => 'qwen2.5:7b'], json_decode($table->params, true));
    }

    public function test_saving_another_plugin_is_not_touched(): void
    {
        PluginHelper::$plugin = (object) ['params' => json_encode(['api_key' => 'sk-live'])];
        $params = json_encode(['api_key' => self::MASK]);
        $table = (object) ['folder' => 'system', 'element' => 'other', 'params' => $params];

        (new PhpClawPlugin([]))->keepStoredSecrets(new Event('onExtensionBeforeSave', ['context' => 'com_plugins.plugin', 'subject' => $table, 'isNew' => false, 'data' => []]));

        self::assertSame($params, $table->params);
    }

    private function form(string $name): Form
    {
        $form = new Form;
        $form->name = $name;

        return $form;
    }

    private function formData(array $params): object
    {
        return (object) ['folder' => 'system', 'element' => 'phpclaw', 'params' => $params];
    }
}
