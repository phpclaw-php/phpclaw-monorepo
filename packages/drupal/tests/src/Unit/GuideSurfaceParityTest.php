<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use PhpClaw\Drupal\Controller\Admin\PhpClawGuideController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class GuideSurfaceParityTest extends TestCase
{
    private function renderGuide(bool $manageAll): string
    {
        if (! class_exists(Environment::class)) {
            self::markTestSkipped('Twig not available.');
        }

        $dir = dirname(__DIR__, 3).'/templates';
        $twig = new Environment(new FilesystemLoader($dir), ['autoescape' => false, 'cache' => false]);

        $html = $twig->render('phpclaw-admin-guide.html.twig', [
            'manage_all' => $manageAll,
            'tools' => [],
            'skills' => [],
            'providers' => [],
            'hooks' => [],
            'memory_drivers' => [],
        ]);

        self::assertNotSame('', $html, 'the guide template rendered nothing');

        return $html;
    }

    public function test_cli_tab_documents_the_two_shipped_commands(): void
    {
        $guide = $this->renderGuide(manageAll: true);

        self::assertStringContainsString('drush phpclaw:run', $guide);
        self::assertStringContainsString('drush phpclaw:mcp-server', $guide);
    }

    public function test_cli_tab_states_drush_conversations_are_unowned(): void
    {
        self::assertStringContainsString(
            'stored with owner <code>0</code>',
            $this->renderGuide(manageAll: true),
        );
    }

    public function test_rest_tab_documents_the_chat_permission_not_the_admin_one(): void
    {
        $guide = $this->renderGuide(manageAll: true);
        $restTab = substr($guide, (int) strpos($guide, 'id="guide-rest"'));
        $restTab = substr($restTab, 0, (int) strpos($restTab, 'id="guide-cli"'));

        self::assertStringContainsString('use phpclaw chat', $restTab);

        preg_match_all('#/api/phpclaw[a-z0-9/_-]*#', $restTab, $paths);
        $documented = array_values(array_unique($paths[0]));
        sort($documented);

        self::assertSame(
            ['/api/phpclaw/chat/stream', '/api/phpclaw/send'],
            $documented,
            'the REST tab must document the two API routes and nothing else',
        );
    }

    public function test_rest_tab_documents_exactly_the_two_shipped_endpoints(): void
    {
        $guide = $this->renderGuide(manageAll: true);

        self::assertStringContainsString('/api/phpclaw/send', $guide);
        self::assertStringContainsString('/api/phpclaw/chat/stream', $guide);
    }

    public function test_rest_tab_states_the_authentication_setting(): void
    {
        $guide = $this->renderGuide(manageAll: true);
        $restTab = substr($guide, (int) strpos($guide, 'id="guide-rest"'));
        $restTab = substr($restTab, 0, (int) strpos($restTab, 'id="guide-cli"'));

        self::assertStringContainsString('api_auth_providers', $restTab);
        self::assertStringContainsString('basic_auth', $restTab);
    }

    public function test_the_guide_never_offers_a_settings_link_to_a_non_admin(): void
    {
        $needle = '<a href="/admin/config/phpclaw/settings"';

        $admin = $this->renderGuide(manageAll: true);
        $nonAdmin = $this->renderGuide(manageAll: false);

        self::assertGreaterThan(
            0,
            substr_count($admin, $needle),
            'a manage-all caller must be offered the Settings link, or this test proves nothing',
        );
        self::assertSame(
            0,
            substr_count($nonAdmin, $needle),
            'the rendered Guide offered a Settings link to a caller without manage-all',
        );
        self::assertStringContainsString(
            'Settings',
            $nonAdmin,
            'the non-admin Guide must still name Settings as plain text, only without a link',
        );
    }

    public function test_the_guide_controller_passes_the_real_capability_into_the_render_array(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('');

        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->willReturn($config);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => $id === 'config.factory'
                ? $configFactory
                : throw new \RuntimeException("Service '{$id}' not mocked."),
        );
        \Drupal::setContainer($container);

        $built = (new PhpClawGuideController(configFactory: $configFactory))->index();

        self::assertArrayHasKey('#manage_all', $built, 'the Guide must receive the acting capability, never assume it');
        self::assertFalse(
            $built['#manage_all'],
            'resolved under the console sentinel, so manage-all must come back false rather than be hardcoded true',
        );

        \Drupal::unsetContainer();
    }

    public function test_agent_context_service_is_public(): void
    {
        $services = (array) Yaml::parse((string) file_get_contents(dirname(__DIR__, 3).'/phpclaw.services.yml'));

        self::assertTrue(
            $services['services']['phpclaw.agent_context']['public'] ?? false,
            'phpclaw.agent_context is injected into a drush.services.yml command, which resolves it through the container and fails on a private service.',
        );
    }
}
