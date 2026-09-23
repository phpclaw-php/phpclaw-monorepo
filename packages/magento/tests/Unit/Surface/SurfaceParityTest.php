<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Surface;

use PHPUnit\Framework\TestCase;

final class SurfaceParityTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = $this->root().'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_web_api_exposes_exactly_two_routes(): void
    {
        preg_match_all('/<route url="([^"]+)" method="([^"]+)"/', $this->read('etc/webapi.xml'), $m);

        self::assertSame(['/V1/phpclaw/send', '/V1/phpclaw/chat/stream'], $m[1]);
        self::assertSame(['POST', 'POST'], $m[2]);
    }

    public function test_both_web_api_routes_sit_on_the_chat_resource(): void
    {
        preg_match_all('/<resource ref="([^"]+)"\\/>/', $this->read('etc/webapi.xml'), $m);

        self::assertSame(
            ['PhpClaw_Magento::phpclaw_chat', 'PhpClaw_Magento::phpclaw_chat'],
            $m[1],
        );
    }

    public function test_console_registers_exactly_two_commands(): void
    {
        preg_match('/<argument name="commands".*?<\\/argument>/s', $this->read('etc/di.xml'), $block);
        preg_match_all('/<item name="([^"]+)"[^>]*>([^<]+)</', $block[0] ?? '', $m);

        self::assertSame(['phpclaw_run', 'phpclaw_mcp_server'], $m[1]);
        self::assertSame([
            'PhpClaw\\Magento\\Console\\Command\\PhpClawRunCommand',
            'PhpClaw\\Magento\\Console\\Command\\PhpClawMcpServerCommand',
        ], $m[2]);
    }

    public function test_console_ships_exactly_two_command_classes(): void
    {
        $found = glob($this->root().'/Console/Command/*.php') ?: [];
        $names = array_map(static fn (string $p): string => basename($p, '.php'), $found);
        sort($names);

        self::assertSame(['PhpClawMcpServerCommand', 'PhpClawRunCommand'], $names);
    }

    public function test_acl_declares_exactly_the_five_page_resources(): void
    {
        preg_match_all('/<resource id="(PhpClaw_Magento::[^"]+)"/', $this->read('etc/acl.xml'), $m);

        self::assertSame([
            'PhpClaw_Magento::phpclaw',
            'PhpClaw_Magento::phpclaw_settings',
            'PhpClaw_Magento::phpclaw_chat',
            'PhpClaw_Magento::phpclaw_analytics',
            'PhpClaw_Magento::phpclaw_guide',
            'PhpClaw_Magento::phpclaw_about',
        ], $m[1]);
    }

    public function test_guide_documents_only_the_two_shipped_console_commands(): void
    {
        preg_match_all(
            '/bin\\/magento (phpclaw:[a-z-]+)/',
            $this->read('view/adminhtml/templates/guide.phtml'),
            $m,
        );

        self::assertSame(['phpclaw:run', 'phpclaw:mcp-server'], array_values(array_unique($m[1])));
    }

    public function test_guide_states_that_console_conversations_are_unowned(): void
    {
        self::assertStringContainsString(
            'stored with owner <code>0</code>',
            $this->read('view/adminhtml/templates/guide.phtml'),
        );
    }

    public function test_the_guide_never_offers_a_settings_link_to_a_non_admin(): void
    {
        $guide = $this->read('view/adminhtml/templates/guide.phtml');

        self::assertStringNotContainsString(
            "getUrl('phpclaw/settings')",
            $guide,
            'A Settings URL is built inline in the template; it must go through settingsLink() so a non-admin gets plain text.',
        );

        $mentions = substr_count($guide, '$block->settingsLink()');
        self::assertGreaterThan(0, $mentions);

        $block = $this->read('Block/Adminhtml/Guide.php');
        self::assertStringContainsString('public function settingsLink(', $block);
        self::assertStringContainsString('if (! $this->canManageAll())', $block);
        self::assertStringContainsString('public function canManageAll(', $block);
    }

    public function test_conversations_table_carries_the_ownership_column_and_index(): void
    {
        $schema = $this->read('etc/db_schema.xml');

        self::assertStringContainsString('name="admin_user_id"', $schema);
        self::assertStringContainsString('PHPCLAW_CONV_USER_NS_UPDATED', $schema);
    }

    public function test_settings_actions_stay_on_the_administrator_resource(): void
    {
        foreach (['Settings/Index', 'Settings/Save', 'Settings/TestConnection'] as $controller) {
            self::assertStringContainsString(
                "ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_settings'",
                $this->read('Controller/Adminhtml/'.$controller.'.php'),
                $controller,
            );
        }
    }

    public function test_chat_analytics_guide_and_about_stay_off_the_administrator_resource(): void
    {
        foreach ([
            'Chat/Index', 'Chat/Send', 'Chat/Stream', 'Chat/History',
            'Analytics/Index', 'Guide/Index', 'About/Index',
        ] as $controller) {
            self::assertStringNotContainsString(
                "ADMIN_RESOURCE = 'PhpClaw_Magento::phpclaw_settings'",
                $this->read('Controller/Adminhtml/'.$controller.'.php'),
                $controller,
            );
        }
    }
}
