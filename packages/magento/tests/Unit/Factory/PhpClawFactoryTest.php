<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Magento\Factory\PhpClawFactory;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class TestablePhpClawFactory extends PhpClawFactory
{
    private PhpClawInterface $returnValue;

    public function __construct(
        Config $config,
        PhpClawRegistrar $registrar,
        ResourceConnection $resourceConnection,
        TypeListInterface $cacheTypeList,
        RouterMemory $routerMemory,
        PhpClawInterface $mockPhpClaw,
    ) {
        parent::__construct(
            $config,
            $registrar,
            $resourceConnection,
            $cacheTypeList,
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
        $this->returnValue = $mockPhpClaw;
    }

    private function cfg(): Config
    {
        return (fn () => $this->config)->bindTo($this, PhpClawFactory::class)();
    }
}

final class PhpClawFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::reset();
    }

    private function makeFactory(array $overrides = []): TestablePhpClawFactory
    {
        $defaults = [
            'getBaseUrl' => '',
            'getProvider' => 'anthropic',
            'getModel' => 'claude-haiku-4-5-20251001',
            'getApiKey' => 'sk-test',
            'getCloudKey' => '',
            'getCloudSigningSecret' => '',
            'getCloudDisable' => '',
            'getSystemPrompt' => '',
            'getMaxIterations' => 20,
            'getShellAllowlist' => [],
            'getRemoteSkillUrls' => [],
            'getToolDeny' => [],
            'isStoreMessages' => true,
        ];

        $cfg = array_merge($defaults, $overrides);

        $config = $this->createMock(Config::class);
        foreach ($cfg as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        $registrar = $this->createMock(PhpClawRegistrar::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $routerMemory = $this->createMock(RouterMemory::class);
        $mockPhpClaw = $this->createMock(PhpClawInterface::class);

        return new TestablePhpClawFactory(
            $config,
            $registrar,
            $resourceConnection,
            $cacheTypeList,
            $routerMemory,
            $mockPhpClaw,
        );
    }

    public function test_resolve_memory_returns_injected_router_memory(): void
    {
        $factory = $this->makeFactory();
        $memory = $factory->resolveMemory();

        self::assertInstanceOf(RouterMemory::class, $memory);
    }

    public function test_build_tools_reads_tool_deny_from_config(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getBaseUrl')->willReturn('');
        $config->method('getProvider')->willReturn('anthropic');
        $config->method('getModel')->willReturn('claude-haiku-4-5-20251001');
        $config->method('getApiKey')->willReturn('sk-test');
        $config->method('getShellAllowlist')->willReturn([]);
        $config->expects(self::atLeastOnce())->method('getToolDeny')->willReturn(['http_request']);

        $registrar = $this->createMock(PhpClawRegistrar::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $routerMemory = $this->createMock(RouterMemory::class);
        $mockPhpClaw = $this->createMock(PhpClawInterface::class);

        $factory = new TestablePhpClawFactory(
            $config,
            $registrar,
            $resourceConnection,
            $cacheTypeList,
            $routerMemory,
            $mockPhpClaw,
        );

        $factory->buildTools();
    }
}
