<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Controller\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Controller\Admin\PhpClawGuideController;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class PhpClawGuideControllerTest extends TestCase
{
    private ?object $csrfTokenMock = null;

    private ?object $loggerMock = null;

    private function makeController(?ClawInterface $agent = null, ?PhpClawRegistrar $registrar = null): PhpClawGuideController
    {
        return new PhpClawGuideController($agent, $this->loggerMock, $this->csrfTokenMock, null, $registrar);
    }

    private function makeRegistrarFixture(array $externals): PhpClawRegistrar
    {
        $reflection = new \ReflectionClass(PhpClawRegistrar::class);
        $registrar = $reflection->newInstanceWithoutConstructor();

        foreach ($externals as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($registrar, $value);
        }

        return $registrar;
    }

    protected function tearDown(): void
    {
        if (class_exists(\Drupal::class)) {
            \Drupal::unsetContainer();
        }
        SkillRegistry::reset();
    }

    private function bootDrupalConfig(array $settings = [], bool $csrfValid = true): void
    {
        if (! class_exists(\Drupal::class)) {
            return;
        }

        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(fn (string $k) => $settings[$k] ?? '');

        $editableConfig = $this->createMock(Config::class);
        $editableConfig->method('get')->willReturnCallback(fn (string $k) => $settings[$k] ?? '');
        $editableConfig->method('set')->willReturnSelf();
        $editableConfig->method('save')->willReturnSelf();

        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->willReturn($config);
        $configFactory->method('getEditable')->willReturn($editableConfig);

        $csrfToken = $this->createMock(CsrfTokenGenerator::class);
        $csrfToken->method('validate')->willReturn($csrfValid);
        $csrfToken->method('get')->willReturn('test-csrf-token');

        $logger = $this->createMock(LoggerInterface::class);
        $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
        $loggerFactory->method('get')->willReturn($logger);

        $this->csrfTokenMock = $csrfToken;
        $this->loggerMock = $logger;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($configFactory, $csrfToken, $logger, $loggerFactory): object {
            return match ($id) {
                'config.factory' => $configFactory,
                'csrf_token' => $csrfToken,
                'logger.channel.phpclaw' => $logger,
                'logger.factory' => $loggerFactory,
                default => throw new \RuntimeException("Service '{$id}' not mocked."),
            };
        });
        $container->method('has')->willReturn(false);

        \Drupal::setContainer($container);
    }

    public function test_index_returns_render_array_with_tools(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $controller = $this->makeController();
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_guide', $result['#theme']);
        $this->assertArrayHasKey('#tools', $result);
        $this->assertIsArray($result['#tools']);
        $this->assertNotEmpty($result['#tools']);
        $this->assertSame(0, $result['#cache']['max-age']);
    }

    public function test_index_tools_respect_tool_deny_when_agent_context_provided(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agentContext = $this->buildAgentContext(['tool_deny' => ['drupal_cron']]);
        $controller = new PhpClawGuideController(null, $this->loggerMock, $this->csrfTokenMock, null, null, $agentContext);

        $tools = $controller->index()['#tools'];
        $names = array_column($tools, 'name');

        $this->assertNotContains('Cron', $names);
        $this->assertContains('Entity', $names);
        $this->assertContains('Database', $names);
    }

    public function test_index_tools_respect_group_deny_when_agent_context_provided(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agentContext = $this->buildAgentContext(['tool_deny' => ['group:system']]);
        $controller = new PhpClawGuideController(null, $this->loggerMock, $this->csrfTokenMock, null, null, $agentContext);

        $tools = $controller->index()['#tools'];
        $names = array_column($tools, 'name');

        $this->assertNotContains('Cron', $names);
        $this->assertNotContains('Module', $names);
        $this->assertNotContains('Cache', $names);
        $this->assertNotContains('Config', $names);
        $this->assertContains('Entity', $names);
    }

    private function buildAgentContext(array $settings): DrupalAgentContext
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(fn (string $k) => $settings[$k] ?? null);

        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('phpclaw.settings')->willReturn($config);

        $registrarConfig = $this->createMock(ImmutableConfig::class);
        $registrarConfig->method('get')->willReturn(null);
        $registrarFactory = $this->createMock(ConfigFactoryInterface::class);
        $registrarFactory->method('get')->willReturn($registrarConfig);

        $cache = $this->createMock(CacheBackendInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(time());
        $container = $this->createMock(\Drupal\Component\DependencyInjection\ContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);
        $container->method('getParameter')->willReturn([]);
        $container->method('get')->willReturn(null);

        $database = $this->createMock(Connection::class);
        $registrar = new PhpClawRegistrar($registrarFactory, $database, $cache, $time, $container);
        $registrar->boot();

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModuleList')->willReturn([]);
        $moduleExtensionList = $this->createMock(ModuleExtensionList::class);
        $moduleExtensionList->method('getAllInstalledInfo')->willReturn([]);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturn(0);
        $queueFactory = $this->createMock(QueueFactory::class);
        $queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
        $queueWorkerManager->method('getDefinitions')->willReturn([]);

        return new DrupalAgentContext(
            $configFactory,
            $registrar,
            $database,
            $entityTypeManager,
            $moduleHandler,
            $moduleExtensionList,
            $state,
            $queueFactory,
            $queueWorkerManager,
            $time,
        );
    }

    public function test_index_tools_have_expected_keys(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $controller = $this->makeController();
        $tools = $controller->index()['#tools'];

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('desc', $tool);
            $this->assertArrayHasKey('prompts', $tool);
        }
    }

    public function test_test_connection_returns_403_when_csrf_invalid(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig(csrfValid: false);

        $controller = $this->makeController(null);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'bad-token');
        $response = $controller->testConnection($request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['ok']);
    }

    public function test_test_connection_returns_503_when_agent_is_null(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $controller = $this->makeController(null);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'test-csrf-token');
        $response = $controller->testConnection($request);

        $this->assertSame(503, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['ok']);
    }

    public function test_test_connection_returns_ok_on_success(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agentResponse = new AgentResponse(
            text: '4',
            provider: 'anthropic',
            model: 'claude-haiku',
            iterations: 1,
            inputTokens: 10,
            outputTokens: 5,
        );

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn($agentResponse);

        $controller = $this->makeController($agent);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'test-csrf-token');
        $response = $controller->testConnection($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame('anthropic', $data['provider']);
    }

    public function test_test_connection_returns_422_on_guard_exception(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException(new GuardException('blocked'));

        $controller = $this->makeController($agent);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'test-csrf-token');
        $response = $controller->testConnection($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_test_connection_returns_502_on_provider_exception(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException(new ProviderException('provider down'));

        $controller = $this->makeController($agent);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'test-csrf-token');
        $response = $controller->testConnection($request);

        $this->assertSame(502, $response->getStatusCode());
    }

    public function test_test_connection_returns_500_on_throwable(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willThrowException(new \RuntimeException('unexpected'));

        $controller = $this->makeController($agent);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'test-csrf-token');
        $response = $controller->testConnection($request);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_test_connection_returns_ok_when_provider_responds(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $agentResponse = new AgentResponse(
            text: '4',
            provider: 'groq',
            model: 'llama3-8b-8192',
            iterations: 1,
            inputTokens: 5,
            outputTokens: 2,
        );

        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn($agentResponse);

        $controller = $this->makeController($agent);
        $request = Request::create('/', 'POST');
        $request->headers->set('X-CSRF-Token', 'test-csrf-token');
        $response = $controller->testConnection($request);

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame('groq', $data['provider']);
        $this->assertSame('llama3-8b-8192', $data['model']);
    }

    public function test_create_returns_instance_with_agent_when_service_exists(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === 'phpclaw.agent',
        );
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $csrfToken = $this->createMock(CsrfTokenGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => match ($id) {
                'config.factory' => $configFactory,
                'logger.channel.phpclaw' => $logger,
                'csrf_token' => $csrfToken,
                default => $agent,
            },
        );

        $instance = PhpClawGuideController::create($container);

        $this->assertInstanceOf(PhpClawGuideController::class, $instance);
    }

    public function test_create_returns_instance_without_agent_when_service_absent(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $instance = PhpClawGuideController::create($container);

        $this->assertInstanceOf(PhpClawGuideController::class, $instance);
    }

    public function test_index_merges_external_tool_with_name_and_description_methods(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $tool = new class
        {
            public function name(): string
            {
                return 'extra_tool';
            }

            public function description(): string
            {
                return 'An extra tool.';
            }
        };

        $registrar = $this->makeRegistrarFixture(['externalTools' => [$tool]]);

        $controller = $this->makeController(registrar: $registrar);
        $tools = $controller->index()['#tools'];

        $names = array_column($tools, 'name');
        $this->assertContains('extra_tool', $names);
    }

    public function test_index_merges_external_tool_without_name_or_description_methods(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $tool = new class {};

        $registrar = $this->makeRegistrarFixture(['externalTools' => [$tool, 'not-an-object']]);

        $controller = $this->makeController(registrar: $registrar);
        $tools = $controller->index()['#tools'];

        $found = false;
        foreach ($tools as $t) {
            if ($t['desc'] === '' && str_contains($t['name'], 'class@anonymous')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Anonymous class tool should fall back to shortName() with empty description.');
    }

    public function test_index_capability_records_include_external_memory_skills_guards_hooks(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $skill = new class
        {
            public function name(): string
            {
                return 'extra_skill';
            }

            public function tags(): array
            {
                return ['tag1', 'tag2'];
            }
        };
        $guard = new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };

        $registrar = $this->makeRegistrarFixture([
            'externalMemoryDrivers' => ['extra_mem' => 'Some\\Extra\\MemoryClass'],
            'externalSkills' => [$skill, 'not-an-object'],
            'externalGuards' => [$guard],
            'externalHooks' => [
                ['event' => 'agent.before', 'priority' => 5, 'class' => 'Some\\Extra\\HookClass'],
            ],
        ]);

        $controller = $this->makeController(registrar: $registrar);
        $records = $controller->index()['#capability_records'];

        $memoryDrivers = array_column($records['memory'], 'class', 'driver');
        $this->assertSame('Some\\Extra\\MemoryClass', $memoryDrivers['extra_mem']);

        $skillNames = array_column($records['skills'], 'name');
        $this->assertContains('extra_skill', $skillNames);

        $this->assertNotEmpty($records['guards']);
        $this->assertTrue($records['guards'][count($records['guards']) - 1]['enabled_by_default']);

        $externalHook = $records['hooks'][count($records['hooks']) - 1];
        $this->assertSame('agent.before', $externalHook['event']);
        $this->assertSame(5, $externalHook['priority']);
    }

    public function test_get_remote_skill_urls_empty_when_unconfigured(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig();

        $controller = $this->makeController();

        $this->assertSame([], $controller->getRemoteSkillUrls());
        $this->assertSame([], $controller->getRemoteSkills());
    }

    public function test_get_remote_skill_urls_parses_comma_separated_config(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig([
            'remote_skill_urls' => ' https://example.com/a.md , https://example.com/b.json ',
        ]);

        $controller = $this->makeController();

        $this->assertSame(
            ['https://example.com/a.md', 'https://example.com/b.json'],
            $controller->getRemoteSkillUrls(),
        );
    }

    public function test_get_remote_skills_empty_when_agent_null_even_if_urls_configured(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig(['remote_skill_urls' => 'https://example.com/a.md']);

        $controller = $this->makeController(null);

        $this->assertSame([], $controller->getRemoteSkills());
    }

    public function test_get_remote_skills_surfaces_registered_skill_not_in_discovered(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $this->bootDrupalConfig(['remote_skill_urls' => 'https://example.com/a.md']);

        SkillRegistry::register(new ArraySkill('remote_demo', 'demo remote skill', ['demo'], 'content'));

        $agent = $this->createMock(ClawInterface::class);
        $controller = $this->makeController($agent);

        $remote = $controller->getRemoteSkills();

        $this->assertCount(1, $remote);
        $this->assertSame('remote_demo', $remote[0]['name']);
        $this->assertSame('demo remote skill', $remote[0]['description']);
    }
}
