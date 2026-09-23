<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Cloud\CloudScanGuard;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\InjectionGuard;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\WordPress\Admin\GuidePage;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuidePage::class)]
final class GuidePageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = new class
        {
            public string $prefix = 'wp_';
        };

        Functions\stubs([
            '__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_attr__' => static fn (string $s, string $d = ''): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'esc_attr' => static fn (string $s): string => $s,
            'esc_url' => static fn (string $s): string => $s,
            'esc_textarea' => static fn (string $s): string => $s,
            'sanitize_key' => static fn (string $s): string => preg_replace('/[^a-z0-9_]/', '', strtolower($s)) ?? '',
            'sanitize_text_field' => static fn (string $s): string => trim($s),
            'admin_url' => static fn (string $p): string => 'http://example.com/wp-admin/'.ltrim($p, '/'),
            'wp_create_nonce' => static fn (string $a): string => 'N_'.$a,
            'wp_kses' => static fn (string $s, array $allowed = []): string => $s,
            'get_option' => static fn (string $key, mixed $default = false): mixed => $default,
            'rest_url' => static fn (string $path = ''): string => 'http://example.com/wp-json/'.$path,
        ]);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $GLOBALS['wpdb'] = null;
        SkillRegistry::reset();
        GuardRegistry::reset();

        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_method_signature(): void
    {
        $ref = new \ReflectionClass(GuidePage::class);
        $m = $ref->getMethod('register');
        self::assertTrue($m->isPublic());
        self::assertTrue($m->isStatic());
    }

    public function test_render_dies_for_user_without_permission(): void
    {
        Functions\expect('current_user_can')->once()->andReturnFalse();
        Functions\expect('wp_die')->once()->andThrow(new \RuntimeException('died'));

        $this->expectException(\RuntimeException::class);
        GuidePage::render();
    }

    public function test_render_shows_quickstart_tab_by_default(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Developer Guide', $html);
        self::assertStringContainsString('Quickstart', $html);
        foreach (['Quickstart', 'Tools', 'Memory', 'Providers', 'REST API', 'WP-CLI', 'Privacy'] as $tab) {
            self::assertStringContainsString($tab, $html);
        }
    }

    public function test_render_renders_tools_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'tools';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Tools', $html);
    }

    public function test_render_renders_memory_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'memory';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Memory', $html);
    }

    public function test_render_renders_providers_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'providers';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Providers', $html);
    }

    public function test_render_renders_rest_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'rest';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('REST', $html);
    }

    public function test_render_renders_cli_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'cli';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('CLI', $html);
    }

    public function test_render_renders_privacy_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'privacy';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Privacy', $html);
    }

    public function test_render_falls_back_to_quickstart_for_unknown_tab(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'evil_tab';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Quickstart', $html);
    }

    public function test_render_includes_admin_footer_community_card(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Built for the PHP community', $html);
    }

    public function test_render_guards_tab_lists_the_discovered_guards(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'guards';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Guards', $html);

        self::assertStringContainsString('<table', $html);
        self::assertStringNotContainsString('No guards discovered', $html);
    }

    public function test_render_hooks_tab_lists_the_discovered_hook_listeners(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'hooks';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Hooks', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringNotContainsString('No hook listeners discovered', $html);
    }

    public function test_render_skills_tab_lists_the_discovered_skills(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'skills';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Skills', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringNotContainsString('No skills discovered', $html);
    }

    public function test_render_tools_tab_with_woocommerce_not_detected(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'tools';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        if (! class_exists('WooCommerce', false)) {
            self::assertStringContainsString('WooCommerce not detected', $html);
        } else {
            self::assertStringContainsString('WooCommerce Tools', $html);
        }
    }

    public function test_render_memory_tab_shows_database_tables_section(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'memory';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Memory', $html);
        self::assertStringContainsString('Database Tables', $html);
    }

    public function test_render_privacy_tab_uses_wpdb_prefix(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'privacy';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Privacy', $html);
        self::assertStringContainsString('wp_phpclaw_conversations', $html);
    }

    public function test_render_hooks_tab_outputs_registered_listeners_header(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'hooks';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Registered Hook Listeners', $html);
    }

    public function test_render_skills_tab_outputs_discovered_skills_header(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'skills';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Discovered Skills', $html);
    }

    public function test_render_skills_tab_shows_no_remote_skills_message_when_unconfigured(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = 'skills';

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('Remote Skills', $html);
        self::assertStringContainsString('No remote skill URLs configured', $html);
    }

    public function test_render_skills_tab_lists_a_registered_remote_skill(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn(['remote_skill_urls' => ['https://example.com/skills/html-everything/SKILL.md']]);
        $_GET['tab'] = 'skills';

        SkillRegistry::register(new ArraySkill('skill_md', '/html-everything', ['recipe'], 'Recipe content.'));

        ob_start();
        GuidePage::render();
        $html = ob_get_clean();

        self::assertStringContainsString('skill_md', $html);
        self::assertStringContainsString('/html-everything', $html);
        self::assertStringNotContainsString('No remote skill URLs configured', $html);
    }

    public function test_rest_tab_is_identical_for_admin_and_non_admin(): void
    {
        $_GET['tab'] = 'rest';

        Functions\when('current_user_can')->justReturn(true);
        ob_start();
        GuidePage::render();
        $adminHtml = (string) ob_get_clean();

        Functions\when('current_user_can')->alias(
            static fn (string $cap): bool => $cap !== 'phpclaw_use_admin_chat',
        );
        ob_start();
        GuidePage::render();
        $userHtml = (string) ob_get_clean();

        self::assertSame($adminHtml, $userHtml);

        unset($_GET['tab']);
    }

    public function test_rest_tab_documents_send_and_stream(): void
    {
        $_GET['tab'] = 'rest';
        Functions\when('current_user_can')->justReturn(true);

        ob_start();
        GuidePage::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('/send', $html);
        self::assertStringContainsString('/chat/stream', $html);

        unset($_GET['tab']);
    }

    public function test_cli_tab_documents_send_and_mcp_server(): void
    {
        $_GET['tab'] = 'cli';
        Functions\when('current_user_can')->justReturn(true);

        ob_start();
        GuidePage::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('wp phpclaw send', $html);
        self::assertStringContainsString('wp phpclaw mcp-server', $html);
        self::assertStringContainsString('owned by nobody', $html);

        unset($_GET['tab']);
    }

    public function test_settings_link_is_plain_text_for_non_admin(): void
    {
        Functions\when('current_user_can')->alias(
            static fn (string $cap): bool => $cap !== 'phpclaw_use_admin_chat',
        );

        ob_start();
        GuidePage::render();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('admin.php?page=phpclaw"', $html);
    }

    public function test_memory_tab_lists_a_plugin_contributed_driver(): void
    {
        $this->stubExtras('phpclaw_extra_memory_drivers', ['acme_cache' => static fn (): ?object => null]);

        $html = $this->renderTab('memory');

        self::assertStringContainsString('acme_cache', $html);
    }

    public function test_guards_tab_lists_a_plugin_contributed_guard(): void
    {
        $this->stubExtras('phpclaw_extra_guards', [new InjectionGuard]);

        $html = $this->renderTab('guards');

        self::assertStringContainsString('InjectionGuard', $html);
    }

    public function test_guards_tab_skips_an_extra_that_is_not_an_object(): void
    {
        $this->stubExtras('phpclaw_extra_guards', ['acme-not-an-object', 123]);

        $html = $this->renderTab('guards');

        self::assertStringNotContainsString('acme-not-an-object', $html);
    }

    public function test_hooks_tab_lists_a_plugin_contributed_listener(): void
    {
        $this->stubExtras('phpclaw_extra_hooks', [
            ['event' => 'agent.start', 'handler' => [new InjectionGuard, 'onAgentStart'], 'priority' => 5],
            ['handler' => 'AcmeNoEvent'],
        ]);

        $html = $this->renderTab('hooks');

        self::assertStringContainsString('agent.start', $html);
        self::assertStringContainsString('onAgentStart', $html);
        self::assertStringNotContainsString('AcmeNoEvent', $html);
    }

    public function test_skills_tab_lists_a_plugin_contributed_skill(): void
    {
        $this->stubExtras('phpclaw_extra_skills', [
            new ArraySkill('acme_skill', 'Acme skill', ['acme', 'billing'], 'content'),
        ]);

        $html = $this->renderTab('skills');

        self::assertStringContainsString('acme_skill', $html);
        self::assertStringContainsString('acme, billing', $html);
    }

    public function test_cli_tab_states_cli_conversations_are_stored_with_user_id_zero(): void
    {
        $html = $this->renderTab('cli');

        self::assertStringContainsString('stored with user ID 0', $html);
    }

    public function test_guards_tab_lists_the_cloud_scan_guard_when_it_is_registered(): void
    {
        GuardRegistry::register(new CloudScanGuard('test-key'));

        $html = $this->renderTab('guards');

        self::assertStringContainsString(CloudScanGuard::class, $html);
    }

    public function test_guards_tab_omits_the_cloud_scan_guard_when_it_is_not_registered(): void
    {
        $html = $this->renderTab('guards');

        self::assertStringNotContainsString(CloudScanGuard::class, $html);
    }

    public function test_tools_tab_lists_a_core_tool_the_engine_registers_even_when_not_default(): void
    {
        $plugin = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Plugin::class, 'config'))->setValue($plugin, require __DIR__.'/../../../config/phpclaw.php');
        (new \ReflectionProperty(Plugin::class, 'instance'))->setValue(null, $plugin);

        try {
            $html = $this->renderTab('tools');
        } finally {
            (new \ReflectionProperty(Plugin::class, 'instance'))->setValue(null, null);
        }

        self::assertStringContainsString('ZipPackagerTool', $html);
    }

    private function stubExtras(string $hook, mixed $items): void
    {
        Functions\when('apply_filters')->alias(
            static fn (string $name, mixed $value = null, mixed ...$rest): mixed => $name === $hook
                ? $items
                : $value,
        );
    }

    private function renderTab(string $tab): string
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['tab'] = $tab;

        ob_start();
        GuidePage::render();

        return (string) ob_get_clean();
    }

    public function test_providers_tab_shows_the_model_the_gemini_provider_actually_declares(): void
    {
        $html = $this->renderTab('providers');

        self::assertStringContainsString('gemini-3.5-flash-lite', $html);
    }

    public function test_rest_tab_documents_every_error_code_the_controllers_return(): void
    {
        $emitted = [];

        foreach (glob(__DIR__.'/../../../src/Rest/*.php') as $file) {
            preg_match_all(
                "/new \\\\WP_Error\\(\\s*'(phpclaw_[a-z_]+)'/",
                (string) file_get_contents((string) $file),
                $m,
            );
            $emitted = array_merge($emitted, $m[1]);
        }

        $emitted = array_values(array_unique($emitted));
        self::assertNotSame([], $emitted, 'the scan must find at least one error code');

        $html = $this->renderTab('rest');

        foreach ($emitted as $code) {
            self::assertStringContainsString(
                '<code>'.$code.'</code>',
                $html,
                "the Guide REST error table must document {$code}",
            );
        }
    }
}
