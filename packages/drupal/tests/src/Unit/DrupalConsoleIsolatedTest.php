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
use PhpClaw\Claw;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Drupal\Tools\DrupalCacheTool;
use PhpClaw\Tools\FileWriteTool;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DrupalConsoleIsolatedTest extends TestCase
{
    private const SETTINGS = [
        'api_key' => 'sk-test-key',
        'provider' => 'anthropic',
        'model' => '',
        'store_messages' => false,
        'max_iterations' => 10,
        'shell_allowlist' => [],
    ];

    public function test_the_marker_is_absent_before_any_isolated_test_runs(): void
    {
        self::assertFalse(DrupalConsole::isActive());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_marking_the_process_turns_the_console_answer_on(): void
    {
        self::assertFalse(DrupalConsole::isActive());

        DrupalConsole::mark();

        self::assertTrue(DrupalConsole::isActive());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_marking_twice_does_not_fail(): void
    {
        DrupalConsole::mark();
        DrupalConsole::mark();

        self::assertTrue(DrupalConsole::isActive());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_marked_process_reaches_a_tool_with_no_drupal_container(): void
    {
        DrupalConsole::mark();

        self::assertNotSame('FORBIDDEN', $this->cacheToolOutcome());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_unmarked_process_is_refused_by_the_same_tool(): void
    {
        self::assertSame('FORBIDDEN', $this->cacheToolOutcome());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_baked_php_write_flag_agrees_with_the_live_console_answer(): void
    {
        DrupalConsole::mark();

        $engine = PhpClawServiceFactory::create($this->buildContext());

        self::assertSame(
            DrupalConsole::isActive(),
            $this->fileWriteAllowsPhp($engine),
            'allowPhpWrite is captured once at tool construction while runningInConsole() is read per '
            .'guard call. They express the same fact and must never disagree.',
        );
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_mcp_registry_denies_php_write_even_in_a_marked_process(): void
    {
        DrupalConsole::mark();

        self::assertFalse($this->registryFileWriteAllowsPhp());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_mcp_registry_denies_php_write_in_an_unmarked_process(): void
    {
        self::assertFalse($this->registryFileWriteAllowsPhp());
    }

    /**
     * @depends test_marking_the_process_turns_the_console_answer_on
     */
    #[Depends('test_marking_the_process_turns_the_console_answer_on')]
    public function test_the_isolated_marker_never_reaches_the_shared_process(): void
    {
        self::assertFalse(
            DrupalConsole::isActive(),
            'process isolation leaked '.DrupalConsole::MARKER.' into the shared PHPUnit process, so '
            .'every test after it would silently inherit the console exemption',
        );
    }

    private function cacheToolOutcome(): string
    {
        $tool = new DrupalCacheTool($this->createMock(Connection::class));

        try {
            $decoded = json_decode((string) $tool->execute([]), true);
        } catch (\Throwable) {
            return 'REACHED_BODY';
        }

        return (string) ($decoded['error']['code'] ?? 'REACHED_BODY');
    }

    private function registryFileWriteAllowsPhp(): bool
    {
        foreach (PhpClawServiceFactory::buildToolRegistry($this->buildContext())->all() as $tool) {
            if ($tool instanceof FileWriteTool) {
                return (bool) (new \ReflectionProperty($tool, 'allowPhpWrite'))->getValue($tool);
            }
        }

        self::fail('FileWriteTool not present in the built registry.');
    }

    private function fileWriteAllowsPhp(?Claw $engine): bool
    {
        $config = (new \ReflectionProperty($engine, 'config'))->getValue($engine);

        foreach ((array) $config->tools as $tool) {
            if ($tool instanceof FileWriteTool) {
                return (bool) (new \ReflectionProperty($tool, 'allowPhpWrite'))->getValue($tool);
            }
        }

        self::fail('FileWriteTool not present in the built engine.');
    }

    private function buildContext(): DrupalAgentContext
    {
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('moduleExists')->willReturn(false);
        $moduleHandler->method('getModuleList')->willReturn([]);

        return new DrupalAgentContext(
            $this->buildFactory(self::SETTINGS),
            $this->buildRegistrar(),
            $this->createMock(Connection::class),
            $this->createMock(EntityTypeManagerInterface::class),
            $moduleHandler,
            $this->createMock(ModuleExtensionList::class),
            $this->createMock(StateInterface::class),
            $this->createMock(QueueFactory::class),
            $this->createMock(QueueWorkerManagerInterface::class),
            $this->createMock(TimeInterface::class),
        );
    }

    private function buildFactory(array $settings): ConfigFactoryInterface
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->with('phpclaw.settings')->willReturn($config);

        return $factory;
    }

    private function buildRegistrar(): PhpClawRegistrar
    {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturn(null);

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($config);

        $container = $this->createMock(DrupalContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);
        $container->method('getParameter')->willReturn([]);
        $container->method('get')->willReturn(null);

        $registrar = new PhpClawRegistrar(
            $factory,
            $this->createMock(Connection::class),
            $this->createMock(CacheBackendInterface::class),
            $this->createMock(TimeInterface::class),
            $container,
        );
        $registrar->boot();

        return $registrar;
    }
}
