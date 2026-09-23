<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpZipBuilderTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpZipBuilderTool::class)]
final class WpZipBuilderToolTest extends TestCase
{
    use StubsCapabilities;

    private string $workspace;

    private WpZipBuilderTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
        $this->workspace = sys_get_temp_dir().'/phpclaw_wpzip_'.uniqid();
        mkdir($this->workspace, 0777, true);
        $this->tool = new WpZipBuilderTool($this->workspace);
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        $this->deleteTree($this->workspace);
        parent::tearDown();
    }

    public function test_valid_plugin_packages_and_returns_install_hint(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required to package.');
        }

        $this->scaffoldValidPlugin('simple-slider');

        $decoded = (array) json_decode($this->tool->execute(['plugin_dir' => 'simple-slider']), true);

        self::assertTrue($decoded['success']);
        self::assertSame('created', $decoded['data']['package']['status'] ?? null);
        self::assertStringContainsString('Upload Plugin', $decoded['meta']['install']);
        self::assertArrayHasKey('zip_path', $decoded['data']['package']);
        self::assertFileExists((string) $decoded['data']['package']['zip_path']);

        @unlink((string) $decoded['data']['package']['zip_path']);
    }

    public function test_bad_slug_is_rejected(): void
    {
        $decoded = json_decode((new WpZipBuilderTool($this->workspace))->execute(['plugin_dir' => 'Bad Slug!']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('INVALID_PLUGIN_DIR', $decoded['error']['code']);

    }

    public function test_traversal_slug_is_blocked(): void
    {
        $decoded = json_decode((new WpZipBuilderTool($this->workspace))->execute(['plugin_dir' => '../evil']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('INVALID_PLUGIN_DIR', $decoded['error']['code']);
    }

    public function test_missing_main_file_is_reported(): void
    {
        mkdir($this->workspace.'/orphan', 0777, true);
        $this->write('orphan/other.php', "<?php\ndefined('ABSPATH') || exit;\n");
        $decoded = json_decode($this->tool->execute(['plugin_dir' => 'orphan']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('PLUGIN_VALIDATION_FAILED', $decoded['error']['code']);
        self::assertStringContainsString('main file missing', implode(' ', $decoded['error']['problems']));
    }

    public function test_missing_plugin_header_is_reported(): void
    {
        $this->write('noheader/noheader.php', "<?php\ndefined('ABSPATH') || exit;\n");
        $decoded = json_decode($this->tool->execute(['plugin_dir' => 'noheader']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('PLUGIN_VALIDATION_FAILED', $decoded['error']['code']);
        self::assertStringContainsString('Plugin Name:', implode(' ', $decoded['error']['problems']));
    }

    public function test_missing_abspath_guard_names_the_file(): void
    {
        $this->scaffoldValidPlugin('leaky');
        $this->write('leaky/public/render.php', "<?php\nfunction leaky_render() { return ''; }\n");

        $decoded = json_decode($this->tool->execute(['plugin_dir' => 'leaky']), true);
        $problems = implode(' ', $decoded['error']['problems']);

        self::assertFalse($decoded['success']);
        self::assertSame('PLUGIN_VALIDATION_FAILED', $decoded['error']['code']);
        self::assertStringContainsString('public/render.php', $problems);
        self::assertStringContainsString('missing ABSPATH guard', $problems);
    }

    public function test_forbidden_call_is_reported(): void
    {
        $this->scaffoldValidPlugin('injected');
        $this->write(
            'injected/public/render.php',
            "<?php\ndefined('ABSPATH') || exit;\nfunction injected_render() { return eval('1'); }\n",
        );

        $decoded = json_decode($this->tool->execute(['plugin_dir' => 'injected']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('PLUGIN_VALIDATION_FAILED', $decoded['error']['code']);
        self::assertStringContainsString('forbidden call eval(', implode(' ', $decoded['error']['problems']));
    }

    public function test_syntax_error_is_reported_with_the_file(): void
    {
        $this->scaffoldValidPlugin('broken');
        $this->write(
            'broken/public/render.php',
            "<?php\ndefined('ABSPATH') || exit;\nfunction broken_render( {\n",
        );

        $decoded = json_decode($this->tool->execute(['plugin_dir' => 'broken']), true);
        $problems = implode(' ', $decoded['error']['problems']);

        self::assertFalse($decoded['success']);
        self::assertSame('PLUGIN_VALIDATION_FAILED', $decoded['error']['code']);
        self::assertStringContainsString('public/render.php', $problems);
        self::assertStringContainsString('syntax error', $problems);
    }

    private function scaffoldValidPlugin(string $slug): void
    {
        $header = "<?php\n/**\n * Plugin Name: ".ucwords(str_replace('-', ' ', $slug))
            ."\n * Version: 1.0.0\n */\ndefined('ABSPATH') || exit;\n";

        $this->write("{$slug}/{$slug}.php", $header);
        $this->write("{$slug}/admin/settings.php", "<?php\ndefined('ABSPATH') || exit;\n");
        $this->write("{$slug}/public/render.php", "<?php\ndefined('ABSPATH') || exit;\nfunction {$this->fn($slug)}_render() { return ''; }\n");
    }

    private function fn(string $slug): string
    {
        return str_replace('-', '_', $slug);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->workspace.'/'.$relative;
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    public function test_contract_surface_declares_a_write_risk_and_the_install_plugins_capability(): void
    {
        $tool = new WpZipBuilderTool;

        self::assertSame('wp_zip_plugin', $tool->name());
        self::assertSame('wordpress.package.build', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('write', $tool->risk());
        self::assertFalse($tool->isIdempotent(), 'building a ZIP twice is not a no-op');
        self::assertNotSame([], WpZipBuilderTool::examples());
        self::assertNotSame('', $tool->description());
    }

    public function test_input_schema_requires_only_the_plugin_directory(): void
    {
        $schema = (new WpZipBuilderTool)->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['plugin_dir'], array_keys($schema['properties']));
        self::assertSame(['plugin_dir'], $schema['required']);
        self::assertSame('string', $schema['properties']['plugin_dir']['type']);
        self::assertSame(1, $schema['properties']['plugin_dir']['minLength']);
        self::assertSame(191, $schema['properties']['plugin_dir']['maxLength']);
    }
}
