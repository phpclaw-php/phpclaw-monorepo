<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Factory;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Magento\Factory\PhpClawFactory;
use PhpClaw\Magento\Memory\RouterMemory;
use PhpClaw\Magento\Model\Config;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Registry\PhpClawRegistrar;
use PhpClaw\Magento\Service\MagentoCache;
use PhpClaw\Magento\Tests\Unit\Support\ArrayAppCache;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Tools\ProjectTool;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PhpClawFactoryProjectRootTest extends TestCase
{
    private string $site = '';

    protected function setUp(): void
    {
        $this->site = sys_get_temp_dir().'/phpclaw_mage_root_'.uniqid();
        mkdir($this->site.'/bin', 0777, true);
        file_put_contents($this->site.'/bin/magento', "#!/usr/bin/env php\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->site.'/bin/magento');
        @rmdir($this->site.'/bin');
        @rmdir($this->site);
        ProviderRegistry::reset();
    }

    private function makeFactory(array $overrides = []): PhpClawFactory
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
        $authorization = $this->createMock(AuthorizationInterface::class);

        return new PhpClawFactory(
            $config,
            $registrar,
            $resourceConnection,
            $cacheTypeList,
            $routerMemory,
            $authorization,
            $this->createMock(IdentityResolver::class),
            new MagentoCache(new ArrayAppCache),
        );
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_project_info_describes_the_magento_root_when_the_process_starts_at_the_filesystem_root(): void
    {
        ini_set('error_log', '/dev/null');
        define('BP', $this->site);
        (new ReflectionProperty(DiscoveryCache::class, 'memoryCache'))->setValue(null, [
            'tools' => [ProjectTool::class => ['name' => 'project_info', 'default' => true, 'needsConfig' => ['projectRoot' => 'string']]],
            'providers' => [], 'memory' => [], 'skills' => [], 'hooks' => [], 'guards' => [],
        ]);
        chdir('/');

        $tools = array_values(array_filter($this->makeFactory()->registeredTools(), static fn (object $t): bool => $t instanceof ProjectTool));
        $result = json_decode($tools[0]->execute([]), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($result['success']);
        self::assertSame($this->site, $result['data']['root']);
        self::assertSame('magento', $result['data']['framework']);
    }
}
