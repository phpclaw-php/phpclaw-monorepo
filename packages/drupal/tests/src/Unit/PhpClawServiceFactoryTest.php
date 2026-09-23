<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw;
use PhpClaw\ClawConfig;
use PhpClaw\Config\ToolConfig;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\LogTool;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Tools\FileWriteTool;
use PHPUnit\Framework\TestCase;

final class PhpClawServiceFactoryTest extends TestCase
{
    use BootsDrupalContainer;

    private Connection $database;

    private EntityTypeManagerInterface $entityTypeManager;

    private ModuleHandlerInterface $moduleHandler;

    private ModuleExtensionList $moduleExtensionList;

    private StateInterface $state;

    private QueueFactory $queueFactory;

    private QueueWorkerManagerInterface $queueWorkerManager;

    private TimeInterface $time;

    protected function setUp(): void
    {
        $this->database = $this->buildStubConnection();
        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $this->moduleExtensionList = $this->createMock(ModuleExtensionList::class);
        $this->state = $this->createMock(StateInterface::class);
        $this->queueFactory = $this->createMock(QueueFactory::class);
        $this->queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
        $this->time = $this->createMock(TimeInterface::class);

        $this->moduleHandler->method('getModuleList')->willReturn([]);
        $this->moduleExtensionList->method('getAllInstalledInfo')->willReturn([]);
        $this->state->method('get')->willReturn(0);
        $this->queueWorkerManager->method('getDefinitions')->willReturn([]);
        $this->time->method('getRequestTime')->willReturn(time());
    }

    protected function tearDown(): void
    {
        putenv('OLLAMA_HOST=');
    }

    public function test_it_returns_phpclaw_instance(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(Claw::class, $instance);
    }

    public function test_the_approval_gate_is_wired_on_every_agent(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ]));

        $engine = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
    }

    public function test_non_console_context_disables_php_write_but_still_wires_approval_gate(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ]));

        $engine = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        $this->assertFalse($this->fileWriteAllowsPhp($engine));
    }

    private function approvalGate(?Claw $engine): ?object
    {
        return $this->readProperty($this->readProperty($engine, 'config'), 'approvalGate');
    }

    private function fileWriteAllowsPhp(?Claw $engine): bool
    {
        $config = $this->readProperty($engine, 'config');
        foreach ((array) $this->readProperty($config, 'tools') as $tool) {
            if ($tool instanceof FileWriteTool) {
                return (bool) $this->readProperty($tool, 'allowPhpWrite');
            }
        }
        $this->fail('FileWriteTool not present in the built engine.');
    }

    private function readProperty(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    public function test_it_reads_anthropic_api_key_from_config(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'config-sk-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        self::assertSame('config-sk-key', $instance->config()->apiKey);
        self::assertSame('anthropic', $instance->config()->providerName);
    }

    public function test_it_uses_default_max_iterations_when_config_zero(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 0,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        self::assertSame(ClawConfig::DEFAULT_MAX_ITERATIONS, $instance->config()->maxIterations);
    }

    public function test_max_iterations_fallback_equals_core_constant(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(Claw::class, $instance);
        $this->assertSame(ClawConfig::DEFAULT_MAX_ITERATIONS, $instance->config()->maxIterations);
    }

    public function test_shell_allowlist_falls_back_to_core_default_when_empty(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(Claw::class, $instance);
        $this->assertSame(ToolConfig::DEFAULT_SHELL_ALLOWLIST, $instance->config()->shellAllowlist);
    }

    public function test_it_passes_shell_allowlist_from_config(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => ['ls', 'pwd', 'drush'],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        self::assertSame(['ls', 'pwd', 'drush'], $instance->config()->shellAllowlist);
    }

    public function test_create_chat_returns_phpclaw_instance(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 5,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::createChat($ctx);

        $this->assertInstanceOf(Claw::class, $instance);
    }

    public function test_custom_provider_override_creates_instance(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'provider' => 'custom',
            'model' => 'custom-model',
            'base_url' => 'http://localhost:11434/v1/chat/completions',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
            'api_key' => '',
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(Claw::class, $instance);
    }

    public function test_cloud_config_is_read(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'cloud_key' => 'cloud-key-test',
            'cloud_signing_secret' => 'sign-secret-test',
            'cloud_disable' => 'guards',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertInstanceOf(Claw::class, $instance);
    }

    public function test_cloud_signing_secret_is_passed_to_builder(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'cloud_key' => 'real-cloud-key',
            'cloud_signing_secret' => 'my-signing-secret',
            'store_messages' => true,
            'max_iterations' => 5,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertSame('my-signing-secret', $instance->config()->cloudSigningSecret);
    }

    public function test_cloud_signing_secret_passed_even_when_store_messages_false(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'cloud_key' => 'real-cloud-key',
            'cloud_signing_secret' => 'secret-value',
            'store_messages' => false,
            'max_iterations' => 5,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertSame('secret-value', $instance->config()->cloudSigningSecret);
    }

    public function test_cloud_signing_secret_empty_when_not_configured(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => true,
            'max_iterations' => 5,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertSame('', $instance->config()->cloudSigningSecret);
    }

    public function test_cloud_key_is_blanked_when_store_messages_is_false(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'cloud_key' => 'real-cloud-key',
            'store_messages' => false,
            'max_iterations' => 5,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertSame('', $instance->config()->cloudKey);
    }

    public function test_cloud_key_is_preserved_when_store_messages_is_enabled(): void
    {
        $storeOn = true;
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'cloud_key' => 'real-cloud-key',
            'store_messages' => $storeOn,
            'max_iterations' => 5,
            'shell_allowlist' => [],
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        $this->assertSame('real-cloud-key', $instance->config()->cloudKey);
    }

    public function test_resolve_api_key_returns_empty_string_when_null(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => null,
            'provider' => 'ollama',
            'model' => 'llama3.1:8b',
            'store_messages' => true,
        ]));

        $instance = PhpClawServiceFactory::create($ctx);

        self::assertSame('', $instance->config()->apiKey);
    }

    public function test_custom_provider_override_returns_null_for_invalid_scheme(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'base_url' => 'ftp://invalid-scheme.example.com/api',
                default => null,
            };
        });

        $method = new \ReflectionMethod(PhpClawServiceFactory::class, 'customProviderOverride');
        $result = $method->invoke(null, 'custom', 'sk-key', 'claude-haiku', '', $config);

        $this->assertNull($result);
    }

    public function test_custom_provider_override_rejects_external_http_base_url(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'base_url' => 'http://api.evil.example/v1',
                default => null,
            };
        });

        $method = new \ReflectionMethod(PhpClawServiceFactory::class, 'customProviderOverride');
        $result = $method->invoke(null, 'custom', 'sk-key', 'claude-haiku', '', $config);

        $this->assertNull($result);
    }

    public function test_custom_provider_override_returns_null_for_empty_base_url(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'base_url' => '',
                default => null,
            };
        });

        $method = new \ReflectionMethod(PhpClawServiceFactory::class, 'customProviderOverride');
        $result = $method->invoke(null, 'custom', 'sk-key', 'claude-haiku', '', $config);

        $this->assertNull($result);
    }

    public function test_custom_provider_override_returns_null_for_non_custom_provider(): void
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'base_url' => 'https://api.example.com/v1',
                default => null,
            };
        });

        $method = new \ReflectionMethod(PhpClawServiceFactory::class, 'customProviderOverride');
        $result = $method->invoke(null, 'anthropic', 'sk-key', 'gpt-4o', '', $config);

        $this->assertNull($result);
    }

    public function test_create_chat_with_store_messages_false_wraps_privacy_memory(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => '',
            'provider' => 'ollama',
            'model' => '',
            'store_messages' => false,
        ]));

        $instance = PhpClawServiceFactory::createChat($ctx);

        self::assertInstanceOf(PrivacyAwareMemory::class, $instance->config()->memory);
    }

    public function test_build_tool_registry_applies_tool_deny_from_config(): void
    {
        $ctx = $this->buildContext($this->buildFactory([
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'tool_deny' => ['shell_exec'],
        ]));

        $registry = PhpClawServiceFactory::buildToolRegistry($ctx);

        $this->assertFalse($registry->has('shell_exec'), 'buildToolRegistry() must apply tool_deny for the MCP path.');
    }

    public function test_context_object_carries_all_dependencies(): void
    {
        $factory = $this->buildFactory(['api_key' => 'sk-test', 'provider' => 'anthropic']);
        $registrar = $this->buildRegistrar();
        $ctx = new DrupalAgentContext(
            $factory,
            $registrar,
            $this->database,
            $this->entityTypeManager,
            $this->moduleHandler,
            $this->moduleExtensionList,
            $this->state,
            $this->queueFactory,
            $this->queueWorkerManager,
            $this->time,
        );

        $this->assertSame($factory, $ctx->configFactory);
        $this->assertSame($registrar, $ctx->registrar);
        $this->assertSame($this->database, $ctx->database);
        $this->assertSame($this->entityTypeManager, $ctx->entityTypeManager);
        $this->assertSame($this->moduleHandler, $ctx->moduleHandler);
        $this->assertSame($this->moduleExtensionList, $ctx->moduleExtensionList);
        $this->assertSame($this->state, $ctx->state);
        $this->assertSame($this->queueFactory, $ctx->queueFactory);
        $this->assertSame($this->queueWorkerManager, $ctx->queueWorkerManager);
        $this->assertSame($this->time, $ctx->time);
    }

    private function buildRegistrar(): PhpClawRegistrar
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturn(null);

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($config);

        $cache = $this->createMock(CacheBackendInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $container = $this->createMock(DrupalContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);
        $container->method('getParameter')->willReturn([]);
        $container->method('get')->willReturn(null);

        $registrar = new PhpClawRegistrar($factory, $this->database, $cache, $time, $container);
        $registrar->boot();

        return $registrar;
    }

    private function buildStubConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    private function buildFactory(array $settings): ConfigFactoryInterface
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(
            fn (string $key) => $settings[$key] ?? null
        );

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')
            ->with('phpclaw.settings')
            ->willReturn($config);

        return $factory;
    }

    private function buildContext(ConfigFactoryInterface $factory, ?bool $moduleExists = null): DrupalAgentContext
    {
        $moduleHandler = $this->moduleHandler;

        if ($moduleExists !== null) {
            $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
            $moduleHandler->method('moduleExists')->willReturn($moduleExists);
            $moduleHandler->method('getModuleList')->willReturn([]);
        }

        return new DrupalAgentContext(
            $factory,
            $this->buildRegistrar(),
            $this->database,
            $this->entityTypeManager,
            $moduleHandler,
            $this->moduleExtensionList,
            $this->state,
            $this->queueFactory,
            $this->queueWorkerManager,
            $this->time,
        );
    }

    public function test_the_console_status_is_marked_not_detected(): void
    {
        self::assertSame('cli', PHP_SAPI);

        self::assertFalse(
            DrupalConsole::isActive(),
            'PHPUnit runs under the CLI SAPI, so anything that sniffed the environment instead of '
            .'reading the marker phpClaw sets in its own Drush entry point would answer true here',
        );

        $ctx = $this->buildContext($this->buildFactory($this->workingSettings()));

        foreach (PhpClawServiceFactory::buildToolRegistry($ctx)->all() as $tool) {
            if ($tool instanceof FileWriteTool) {
                self::assertFalse((bool) $this->readProperty($tool, 'allowPhpWrite'));
            }
        }
    }

    private function workingSettings(): array
    {
        return [
            'api_key' => 'sk-test-key',
            'provider' => 'anthropic',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ];
    }

    public function test_a_tool_whose_module_is_absent_is_not_registered(): void
    {
        $ctx = $this->buildContext($this->buildFactory($this->workingSettings()), moduleExists: false);

        $names = array_map(
            static fn (object $tool): string => $tool->name(),
            PhpClawServiceFactory::buildToolRegistry($ctx)->all(),
        );

        foreach (['read_log', 'drupal_menus', 'drupal_media', 'drupal_views', 'drupal_blocks', 'drupal_moderation', 'drupal_webform'] as $absent) {
            self::assertNotContains($absent, $names, $absent.' must not be offered when its module is absent');
        }

        foreach (['drupal_entity', 'drupal_config', 'db_query', 'drupal_modules', 'drupal_cron', 'drupal_roles', 'drupal_path_aliases', 'drupal_cache'] as $always) {
            self::assertContains($always, $names, $always.' has no module dependency and must always be offered');
        }
    }

    public function test_every_module_dependent_tool_is_registered_when_its_module_exists(): void
    {
        $ctx = $this->buildContext($this->buildFactory($this->workingSettings()), moduleExists: true);

        $names = array_map(
            static fn (object $tool): string => $tool->name(),
            PhpClawServiceFactory::buildToolRegistry($ctx)->all(),
        );

        foreach (['read_log', 'drupal_menus', 'drupal_media', 'drupal_views', 'drupal_blocks', 'drupal_moderation', 'drupal_webform'] as $conditional) {
            self::assertContains($conditional, $names, $conditional.' must be offered when its module is installed');
        }
    }

    public function test_the_execute_time_guard_still_answers_when_a_tool_is_reached_directly(): void
    {
        $handler = $this->createMock(ModuleHandlerInterface::class);
        $handler->method('moduleExists')->willReturn(false);

        $this->bootDrupalContainerWithUser();

        $tool = new LogTool($this->database, $handler);
        $decoded = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('MODULE_NOT_INSTALLED', $decoded['error']['code']);
    }
}
