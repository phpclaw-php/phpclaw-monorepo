<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\ClawConfig;
use PhpClaw\Drupal\Cache\DrupalCache;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PHPUnit\Framework\TestCase;

final class PhpClawServiceFactoryPrimitivesTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1_800_000_000);
        $time->method('getCurrentTime')->willReturn(1_800_000_000);
        $this->bootDrupalContainerWithUser(services: [
            'cache.phpclaw' => new MemoryBackend($time),
            'datetime.time' => $time,
        ]);
    }

    public function test_agent_primitives_stay_off_by_default(): void
    {
        $config = $this->engineConfig(['provider' => 'ollama']);

        self::assertSame([], $config->fallbacks);
        self::assertSame(0, $config->requestsPerMinute);
        self::assertNull($config->responseCache);
        self::assertSame(0, $config->maxTokenBudget);
    }

    public function test_a_fallback_with_the_main_tool_format_is_applied(): void
    {
        $config = $this->engineConfig(['provider' => 'ollama', 'fallback_provider' => 'groq', 'fallback_model' => 'llama-3.1-8b-instant', 'fallback_api_key' => 'gsk-key']);

        self::assertSame([['provider' => 'groq', 'model' => 'llama-3.1-8b-instant', 'apiKey' => 'gsk-key']], $config->fallbacks);
    }

    public function test_a_fallback_with_another_tool_format_is_skipped(): void
    {
        self::assertSame([], $this->engineConfig(['provider' => 'ollama', 'fallback_provider' => 'anthropic', 'fallback_api_key' => 'sk-ant'])->fallbacks);
    }

    public function test_a_custom_fallback_is_skipped(): void
    {
        self::assertSame([], $this->engineConfig(['provider' => 'openai', 'api_key' => 'sk', 'fallback_provider' => 'custom'])->fallbacks);
    }

    public function test_an_empty_main_provider_resolves_to_the_auto_detected_one(): void
    {
        $applied = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], fn (): ClawConfig => $this->engineConfig(['provider' => '', 'fallback_provider' => 'groq', 'fallback_api_key' => 'gsk-key']));
        $skipped = $this->withOnlyEnv(['OPENAI_API_KEY' => 'sk-openai'], fn (): ClawConfig => $this->engineConfig(['provider' => '', 'fallback_provider' => 'anthropic', 'fallback_api_key' => 'sk-ant']));

        self::assertSame([['provider' => 'groq', 'model' => '', 'apiKey' => 'gsk-key']], $applied->fallbacks);
        self::assertSame([], $skipped->fallbacks);
    }

    public function test_the_rate_limit_uses_the_drupal_cache_store(): void
    {
        $config = $this->engineConfig(['provider' => 'ollama', 'rate_limit_rpm' => 30]);

        self::assertSame(30, $config->requestsPerMinute);
        self::assertInstanceOf(DrupalCache::class, $config->rateLimitStore);
    }

    public function test_the_response_cache_uses_the_drupal_cache_store_and_ttl(): void
    {
        $config = $this->engineConfig(['provider' => 'ollama', 'response_cache' => true, 'response_cache_ttl' => 600]);

        self::assertInstanceOf(DrupalCache::class, $config->responseCache);
        self::assertSame(600, $config->responseCacheTtl);
    }

    public function test_a_disabled_response_cache_stays_off(): void
    {
        self::assertNull($this->engineConfig(['provider' => 'ollama', 'response_cache' => false, 'response_cache_ttl' => 600])->responseCache);
    }

    public function test_the_token_budget_is_applied(): void
    {
        self::assertSame(5000, $this->engineConfig(['provider' => 'ollama', 'max_token_budget' => 5000])->maxTokenBudget);
    }

    private function engineConfig(array $settings): ClawConfig
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(static fn (string $key): mixed => ($settings + ['model' => 'qwen2.5:7b', 'store_messages' => false, 'max_iterations' => 10, 'shell_allowlist' => []])[$key] ?? null);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->with('phpclaw.settings')->willReturn($config);

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModuleList')->willReturn([]);
        $extensions = $this->createMock(ModuleExtensionList::class);
        $extensions->method('getAllInstalledInfo')->willReturn([]);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturn(0);
        $workers = $this->createMock(QueueWorkerManagerInterface::class);
        $workers->method('getDefinitions')->willReturn([]);

        $ctx = new DrupalAgentContext(
            $factory,
            $this->registrar(),
            $this->createMock(Connection::class),
            $this->createMock(EntityTypeManagerInterface::class),
            $moduleHandler,
            $extensions,
            $state,
            $this->createMock(QueueFactory::class),
            $workers,
            \Drupal::service('datetime.time'),
        );

        return PhpClawServiceFactory::create($ctx)->config();
    }

    private function registrar(): PhpClawRegistrar
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturn(null);
        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($config);
        $container = $this->createMock(DrupalContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);
        $container->method('getParameter')->willReturn([]);
        $container->method('get')->willReturn(null);

        $registrar = new PhpClawRegistrar($factory, $this->createMock(Connection::class), $this->createMock(CacheBackendInterface::class), $this->createMock(TimeInterface::class), $container);
        $registrar->boot();

        return $registrar;
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
