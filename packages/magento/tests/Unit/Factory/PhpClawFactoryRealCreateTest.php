<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Magento\Factory\PhpClawFactory;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class IdentityResolverStub extends IdentityResolver
{
    public function __construct() {}

    public function runningInConsole(): bool
    {
        return true;
    }

    public function manageAll(): bool
    {
        return true;
    }
}

final class ConsoleOverrideFactory extends PhpClawFactory
{
    public function __construct(
        Config $config,
        PhpClawRegistrar $registrar,
        ResourceConnection $resourceConnection,
        TypeListInterface $cache,
        RouterMemory $routerMemory,
        private readonly bool $forcedIsConsole,
    ) {
        parent::__construct(
            $config,
            $registrar,
            $resourceConnection,
            $cache,
            $routerMemory,
            new class implements AuthorizationInterface
            {
                public function isAllowed(string $resource, mixed $privilege = null): bool
                {
                    return true;
                }
            },
            new IdentityResolverStub,
        );
    }

    protected function isConsole(): bool
    {
        return $this->forcedIsConsole;
    }
}

final class PhpClawFactoryRealCreateTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::reset();
    }

    private function makeFactory(array $overrides = []): PhpClawFactory
    {
        $defaults = [
            'getBaseUrl' => '',
            'getProvider' => 'anthropic',
            'getModel' => 'claude-haiku-4-5-20251001',
            'getApiKey' => 'sk-test-key',
            'getCloudKey' => '',
            'getCloudDisable' => '',
            'getSystemPrompt' => '',
            'getMaxIterations' => 20,
            'getShellAllowlist' => [],
            'getRemoteSkillUrls' => [],
            'isStoreMessages' => true,
        ];

        $cfg = array_merge($defaults, $overrides);

        $config = $this->createMock(Config::class);
        foreach ($cfg as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        $registrar = $this->createMock(PhpClawRegistrar::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $cache = $this->createMock(TypeListInterface::class);
        $routerMemory = $this->createMock(RouterMemory::class);
        $authorization = $this->createMock(AuthorizationInterface::class);

        return new PhpClawFactory($config, $registrar, $resourceConnection, $cache, $routerMemory, $authorization, $this->createMock(IdentityResolver::class));
    }

    private function makeFactoryWithConsole(bool $forcedIsConsole, array $overrides = []): PhpClawFactory
    {
        $defaults = [
            'getBaseUrl' => '',
            'getProvider' => 'anthropic',
            'getModel' => 'claude-haiku-4-5-20251001',
            'getApiKey' => 'sk-test-key',
            'getCloudKey' => '',
            'getCloudDisable' => '',
            'getSystemPrompt' => '',
            'getMaxIterations' => 20,
            'getShellAllowlist' => [],
            'getRemoteSkillUrls' => [],
            'isStoreMessages' => true,
        ];

        $cfg = array_merge($defaults, $overrides);

        $config = $this->createMock(Config::class);
        foreach ($cfg as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        return new ConsoleOverrideFactory(
            $config,
            $this->createMock(PhpClawRegistrar::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(RouterMemory::class),
            $forcedIsConsole,
        );
    }

    public function test_console_context_wires_approval_gate(): void
    {
        $engine = $this->makeFactoryWithConsole(true)->create();

        self::assertInstanceOf(CliApprovalGate::class, $engine->approvalGate());
    }

    public function test_web_context_still_wires_approval_gate(): void
    {
        $engine = $this->makeFactoryWithConsole(false)->create();

        self::assertInstanceOf(CliApprovalGate::class, $engine->approvalGate());
    }

    public function test_real_create_with_standard_provider_ignores_base_url(): void
    {
        $factory = $this->makeFactory([
            'getProvider' => 'anthropic',
            'getBaseUrl' => 'https://some-url.example.com',
            'getApiKey' => 'sk-test-key',
        ]);

        $method = new \ReflectionMethod(PhpClawFactory::class, 'customProviderOverride');
        $method->setAccessible(true);

        self::assertNull(
            $method->invoke($factory),
            'a base_url is only honoured for the custom provider',
        );
        self::assertInstanceOf(PhpClawInterface::class, $factory->create());
    }

    public function test_is_console_reads_the_area_code_not_php_sapi(): void
    {
        self::assertSame('cli', \PHP_SAPI, 'this test proves nothing unless the suite runs under CLI');

        $identity = $this->createMock(IdentityResolver::class);
        $identity->method('runningInConsole')->willReturn(false);

        $factory = new PhpClawFactory(
            $this->createMock(Config::class),
            $this->createMock(PhpClawRegistrar::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(RouterMemory::class),
            $this->createMock(AuthorizationInterface::class),
            $identity,
        );

        $isConsole = new \ReflectionMethod($factory, 'isConsole');
        $isConsole->setAccessible(true);

        self::assertFalse(
            $isConsole->invoke($factory),
            'an HTTP area must not be treated as a console session just because the process is CLI',
        );

        $consoleIdentity = $this->createMock(IdentityResolver::class);
        $consoleIdentity->method('runningInConsole')->willReturn(true);

        $consoleFactory = new PhpClawFactory(
            $this->createMock(Config::class),
            $this->createMock(PhpClawRegistrar::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(RouterMemory::class),
            $this->createMock(AuthorizationInterface::class),
            $consoleIdentity,
        );

        self::assertTrue($isConsole->invoke($consoleFactory));
    }
}
