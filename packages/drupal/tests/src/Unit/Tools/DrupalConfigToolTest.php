<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DrupalConfigTool;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PHPUnit\Framework\TestCase;

final class DrupalConfigToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildTool(array $data): DrupalConfigTool
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('getRawData')->willReturn($data);

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($config);

        return new DrupalConfigTool($factory);
    }

    private function ask(DrupalConfigTool $tool, array $args): array
    {
        return json_decode($tool->execute($args), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_name_returns_correct_value(): void
    {
        self::assertSame('drupal_config', $this->buildTool([])->name());
    }

    public function test_description_states_the_reading_is_allowlisted(): void
    {
        self::assertStringContainsString('allowlisted', $this->buildTool([])->description());
    }

    public function test_input_schema_rejects_unknown_properties(): void
    {
        $schema = $this->buildTool([])->inputSchema();

        self::assertSame(['config_name', 'key', 'schema'], array_keys($schema['properties']));
        self::assertFalse($schema['additionalProperties']);
        self::assertSame([], $schema['required']);
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);
        $decoded = $this->ask($this->buildTool([]), ['config_name' => 'system.site']);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
        self::assertNull($decoded['data']);
    }

    public function test_an_object_outside_the_allowlist_is_refused_as_not_allowlisted(): void
    {
        $decoded = $this->ask($this->buildTool(['api_key' => 'sk-live']), ['config_name' => 'smtp.settings']);

        self::assertFalse($decoded['success']);
        self::assertSame('CONFIG_NOT_ALLOWLISTED', $decoded['error']['code']);
        self::assertContains('system.site', $decoded['error']['readable_objects']);
        self::assertContains('image.style.', $decoded['error']['readable_prefixes']);
        self::assertNull($decoded['data']);
    }

    public function test_a_settings_object_of_a_contrib_module_is_refused(): void
    {
        foreach (['phpclaw_probe_api.settings', 'mailchimp.settings', 's3fs.settings'] as $name) {
            $decoded = $this->ask($this->buildTool(['secret' => 'x']), ['config_name' => $name]);

            self::assertSame('CONFIG_NOT_ALLOWLISTED', $decoded['error']['code'], $name.' must not be readable');
        }
    }

    public function test_an_allowlisted_prefix_is_readable(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['name' => 'Large', 'effects' => []]),
            ['config_name' => 'image.style.large'],
        );

        self::assertTrue($decoded['success']);
        self::assertSame('Large', $decoded['data']['values']['name']);
    }

    public function test_field_storage_is_not_a_prefix(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['type' => 'string']),
            ['config_name' => 'field.storage.node.body'],
        );

        self::assertSame('CONFIG_NOT_ALLOWLISTED', $decoded['error']['code']);
        self::assertNotContains('field.storage.', $decoded['error']['readable_prefixes']);
    }

    public function test_every_allowed_prefix_has_its_schema_in_core(): void
    {
        self::assertSame(
            ['core.date_format.', 'core.entity_view_mode.', 'image.style.', 'filter.format.'],
            $this->allowedPrefixes(),
        );
    }

    public function test_settings_is_never_a_prefix(): void
    {
        $prefixes = $this->allowedPrefixes();

        self::assertNotSame([], $prefixes);

        foreach ($prefixes as $prefix) {
            self::assertStringNotContainsString('.settings', $prefix, 'a settings object must never be readable by prefix');
        }
    }

    private function allowedPrefixes(): array
    {
        return (array) (new \ReflectionClass(DrupalConfigTool::class))
            ->getReflectionConstant('ALLOWED_PREFIXES')
            ->getValue();
    }

    public function test_a_credential_key_is_withheld_from_the_whole_object_view(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['name' => 'My site', 'uuid' => 'abc', 'mail' => 'a@b.c']),
            ['config_name' => 'system.site'],
        );

        self::assertSame('My site', $decoded['data']['values']['name']);
        self::assertSame('***withheld***', $decoded['data']['values']['uuid']);
        self::assertSame(['uuid'], $decoded['meta']['withheld_keys']);
        self::assertContains('KEYS_WITHHELD', array_column($decoded['warnings'], 'code'));
    }

    public function test_asking_for_the_parent_key_does_not_defeat_the_filter(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['page' => ['front' => '/node', 'api_key' => 'sk-live-LEAK']]),
            ['config_name' => 'system.site', 'key' => 'page'],
        );

        self::assertSame('/node', $decoded['data']['value']['front']);
        self::assertSame('***withheld***', $decoded['data']['value']['api_key']);
        self::assertSame(['page.api_key'], $decoded['meta']['withheld_keys']);
    }

    public function test_a_credential_key_asked_for_directly_is_withheld(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['name' => 'My site', 'secret' => 'sk-live-LEAK']),
            ['config_name' => 'system.site', 'key' => 'secret'],
        );

        self::assertSame('***withheld***', $decoded['data']['value']);
    }

    public function test_credentials_embedded_in_a_url_are_stripped(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['endpoint' => 'https://admin:hunter2@api.example.com/v1']),
            ['config_name' => 'system.site', 'key' => 'endpoint'],
        );

        self::assertSame('https://***withheld***@api.example.com/v1', $decoded['data']['value']);
        self::assertContains('URL_CREDENTIALS_REDACTED', array_column($decoded['warnings'], 'code'));
    }

    public function test_a_url_without_credentials_is_untouched(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['endpoint' => 'https://api.example.com/v1']),
            ['config_name' => 'system.site', 'key' => 'endpoint'],
        );

        self::assertSame('https://api.example.com/v1', $decoded['data']['value']);
        self::assertNotContains('URL_CREDENTIALS_REDACTED', array_column($decoded['warnings'], 'code'));
    }

    public function test_a_credential_nested_in_a_list_is_withheld(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['servers' => [['host' => 'a.example.com', 'pwd' => 'LEAK']]]),
            ['config_name' => 'system.site', 'key' => 'servers'],
        );

        self::assertSame('a.example.com', $decoded['data']['value'][0]['host']);
        self::assertSame('***withheld***', $decoded['data']['value'][0]['pwd']);
    }

    public function test_a_missing_key_reports_not_found_rather_than_guessing(): void
    {
        $decoded = $this->ask(
            $this->buildTool(['name' => 'My site']),
            ['config_name' => 'system.site', 'key' => 'nope'],
        );

        self::assertTrue($decoded['success']);
        self::assertNull($decoded['data']['value']);
        self::assertFalse($decoded['data']['found']);
    }

    public function test_schema_mode_lists_what_can_be_read(): void
    {
        $decoded = $this->ask($this->buildTool([]), ['schema' => true]);

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertContains('system.site', $decoded['data']['readable_objects']);
        self::assertContains('image.style.', $decoded['data']['readable_prefixes']);
        self::assertContains('password', $decoded['data']['withheld_key_names']);
        self::assertSame('use phpclaw chat', $decoded['data']['drupal_permission']);
        self::assertNotSame([], $decoded['data']['examples']);
    }

    public function test_config_name_is_required_unless_schema_is_asked_for(): void
    {
        $decoded = $this->ask($this->buildTool([]), []);

        self::assertSame('INVALID_ARGUMENT', $decoded['error']['code']);
        self::assertStringContainsString('config_name', $decoded['error']['message']);
    }

    public function test_unknown_argument_is_refused(): void
    {
        $decoded = $this->ask($this->buildTool([]), ['config_name' => 'system.site', 'nope' => 1]);

        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
    }

    public function test_values_are_always_declared_untrusted(): void
    {
        $decoded = $this->ask($this->buildTool(['name' => 'My site']), ['config_name' => 'system.site']);

        self::assertContains('UNTRUSTED_CONTENT', array_column($decoded['warnings'], 'code'));
    }

    public function test_the_tool_is_read_only(): void
    {
        self::assertArrayNotHasKey(MutatingToolInterface::class, (array) class_implements(DrupalConfigTool::class));
        self::assertFalse(method_exists(DrupalConfigTool::class, 'requiresApproval'));

        $config = $this->createMock(ImmutableConfig::class);
        $config->method('getRawData')->willReturn(['name' => 'Site']);

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($config);
        $factory->expects($this->never())->method('getEditable');

        $this->ask(new DrupalConfigTool($factory), ['config_name' => 'system.site']);
    }
}
