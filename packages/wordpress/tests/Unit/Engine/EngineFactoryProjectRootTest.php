<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Tools\ProjectTool;
use PhpClaw\WordPress\Engine\EngineFactory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class EngineFactoryProjectRootTest extends TestCase
{
    private string $site = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_user_can')->justReturn(true);

        if (! MemoryRegistry::has('wpdb_router')) {
            MemoryRegistry::register('wpdb_router', fn () => new ArrayMemory);
        }

        $this->site = sys_get_temp_dir().'/phpclaw_wp_root_'.uniqid();
        mkdir($this->site, 0777, true);
        file_put_contents($this->site.'/wp-config.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->site.'/wp-config.php');
        @rmdir($this->site);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function projectInfo(): array
    {
        foreach (EngineFactory::registeredTools(['workspace_root' => $this->site], ['provider' => 'ollama']) as $tool) {
            if ($tool instanceof ProjectTool) {
                return json_decode($tool->execute([]), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        self::fail('project_info was not registered.');
    }

    #[RunInSeparateProcess]
    public function test_project_info_describes_the_wordpress_site_when_the_process_starts_at_the_filesystem_root(): void
    {
        ini_set('error_log', '/dev/null');
        define('ABSPATH', $this->site.'/');
        chdir('/');

        $result = $this->projectInfo();

        self::assertTrue($result['success']);
        self::assertSame($this->site, $result['data']['root']);
        self::assertSame('wordpress', $result['data']['framework']);
    }
}
