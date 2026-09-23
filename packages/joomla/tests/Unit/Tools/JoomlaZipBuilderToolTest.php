<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Tools;

use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaZipBuilderTool;
use PhpClaw\Joomla\Tests\Support\StubsJoomlaAccess;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JoomlaZipBuilderTool::class)]
final class JoomlaZipBuilderToolTest extends TestCase
{
    use StubsJoomlaAccess;

    private string $workspace;

    private JoomlaZipBuilderTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantJoomlaAccess();
        $this->workspace = sys_get_temp_dir().'/phpclaw_jzip_'.uniqid();
        mkdir($this->workspace, 0777, true);
        $this->tool = new JoomlaZipBuilderTool($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workspace);
        parent::tearDown();
    }

    public function test_name_is_joomla_zip_extension(): void
    {
        $this->assertSame('joomla_zip_extension', $this->tool->name());
    }

    public function test_is_a_mutating_tool(): void
    {
        $this->assertInstanceOf(MutatingToolInterface::class, $this->tool);
    }

    public function test_valid_module_packages_and_returns_install_hint(): void
    {
        $this->writeExtension('testmod', [
            'testmod.xml' => '<?xml version="1.0"?><extension type="module" client="site"><name>Test</name></extension>',
            'testmod.php' => "<?php\n\\defined('_JEXEC') or die;\necho 'hi';\n",
        ]);

        $out = json_decode($this->tool->execute(['extension_dir' => 'testmod']), true);

        $this->assertTrue($out['success']);
        $this->assertSame('build', $out['meta']['mode']);
        $this->assertTrue($out['meta']['wrote_to_disk']);
        $this->assertFalse($out['meta']['idempotent']);
        $this->assertSame('module', $out['data']['extension_type']);
        $this->assertStringContainsString('Upload Package File', $out['meta']['install']);
        $this->assertSame('created', $out['data']['package']['status']);
        $this->assertFileExists($out['data']['package']['zip_path']);
    }

    public function test_valid_plugin_packages_and_namespaced_class_needs_no_jexec(): void
    {
        $this->writeValidPlugin('widget');

        $out = json_decode($this->tool->execute(['extension_dir' => 'widget']), true);

        $this->assertTrue($out['success']);
        $this->assertSame('plugin', $out['data']['extension_type']);
        $this->assertSame('created', $out['data']['package']['status']);
    }

    public function test_rejects_missing_manifest_namespace(): void
    {
        $this->writeValidPlugin('widget', [
            'widget.xml' => '<?xml version="1.0"?><extension type="plugin" group="system" method="upgrade">'
                .'<name>x</name>'
                .'<files><filename plugin="widget">widget.php</filename><folder>services</folder><folder>src</folder></files>'
                .'</extension>',
        ]);

        $this->assertValidationProblem(['extension_dir' => 'widget'], 'manifest missing <namespace path="src">');
    }

    public function test_rejects_class_namespace_location_mismatch(): void
    {
        $this->writeValidPlugin('widget', [
            'src/Extension/Widget.php' => "<?php\nnamespace PhpClaw\\Plugin\\System\\Widget;\nuse Joomla\\CMS\\Plugin\\CMSPlugin;\nfinal class Widget extends CMSPlugin {}\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'widget'], 'does not match its location');
    }

    public function test_rejects_by_reference_constructor(): void
    {
        $this->writeValidPlugin('widget', [
            'src/Extension/Widget.php' => "<?php\nnamespace PhpClaw\\Plugin\\System\\Widget\\Extension;\nuse Joomla\\CMS\\Plugin\\CMSPlugin;\nfinal class Widget extends CMSPlugin {\n public function __construct(&\$subject, \$config = []) { parent::__construct(\$subject, \$config); }\n}\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'widget'], 'by-reference parameter');
    }

    public function test_rejects_wrong_plugininterface_fqcn(): void
    {
        $this->writeValidPlugin('widget', [
            'services/provider.php' => "<?php\n\\defined('_JEXEC') or die;\nuse Joomla\\CMS\\Plugin\\PluginInterface;\nuse Joomla\\CMS\\Factory;\nuse Joomla\\DI\\Container;\n\$f = function (Container \$container) { return (new Widget())->setApplication(Factory::getApplication()); };\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'widget'], 'Joomla\\CMS\\Plugin\\PluginInterface, which does not exist');
    }

    public function test_rejects_provider_without_set_application(): void
    {
        $this->writeValidPlugin('widget', [
            'services/provider.php' => "<?php\n\\defined('_JEXEC') or die;\nuse Joomla\\CMS\\Extension\\PluginInterface;\nuse Joomla\\DI\\Container;\n\$f = function (Container \$container) { return new Widget(); };\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'widget'], 'must call $plugin->setApplication');
    }

    public function test_rejects_getcustomtag_call(): void
    {
        $this->writeValidPlugin('widget', [
            'src/Extension/Widget.php' => "<?php\nnamespace PhpClaw\\Plugin\\System\\Widget\\Extension;\nuse Joomla\\CMS\\Plugin\\CMSPlugin;\nfinal class Widget extends CMSPlugin {\n public function run() { \$x = \$this->getApplication()->getDocument()->getCustomTag('body'); }\n}\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'widget'], 'getCustomTag');
    }

    public function test_rejects_bad_slug(): void
    {
        $this->assertRefused(['extension_dir' => 'bad slug!']);
    }

    public function test_rejects_missing_manifest(): void
    {
        $this->writeExtension('nomani', [
            'nomani.php' => "<?php\n\\defined('_JEXEC') or die;\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'nomani'], 'No Joomla manifest found at the extension root');
    }

    public function test_rejects_missing_jexec_guard(): void
    {
        $this->writeExtension('noguard', [
            'noguard.xml' => '<?xml version="1.0"?><extension type="module"><name>x</name></extension>',
            'noguard.php' => "<?php\necho 'no guard here';\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'noguard'], 'missing _JEXEC guard');
    }

    public function test_rejects_forbidden_call(): void
    {
        $this->writeExtension('evilext', [
            'evilext.xml' => '<?xml version="1.0"?><extension type="module"><name>x</name></extension>',
            'evilext.php' => "<?php\n\\defined('_JEXEC') or die;\neval('1');\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'evilext'], 'forbidden call');
    }

    public function test_rejects_forbidden_call_with_whitespace(): void
    {
        $this->writeExtension('evilext', [
            'evilext.xml' => '<?xml version="1.0"?><extension type="module"><name>x</name></extension>',
            'evilext.php' => "<?php\n\\defined('_JEXEC') or die;\neval ('1');\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'evilext'], 'forbidden call');
    }

    public function test_rejects_syntax_error(): void
    {
        $this->writeExtension('brokenext', [
            'brokenext.xml' => '<?xml version="1.0"?><extension type="module"><name>x</name></extension>',
            'brokenext.php' => "<?php\n\\defined('_JEXEC') or die;\nfunction (\n",
        ]);

        $this->assertValidationProblem(['extension_dir' => 'brokenext'], 'syntax error');
    }

    public function test_rejects_folder_outside_workspace(): void
    {
        $this->assertRefused(['extension_dir' => '../../etc']);
    }

    private function writeValidPlugin(string $slug, array $overrides = []): void
    {
        $name = ucfirst($slug);
        $ns = 'PhpClaw\\Plugin\\System\\'.$name;

        $files = [
            "{$slug}.xml" => '<?xml version="1.0"?><extension type="plugin" group="system" method="upgrade">'
                .'<name>x</name>'
                .'<namespace path="src">'.$ns.'</namespace>'
                .'<files><filename plugin="'.$slug.'">'.$slug.'.php</filename><folder>services</folder><folder>src</folder></files>'
                .'<languages folder="language"><language tag="en-GB">en-GB/plg_system_'.$slug.'.ini</language></languages>'
                .'</extension>',
            "{$slug}.php" => "<?php\n\\defined('_JEXEC') or die;\n",
            'services/provider.php' => "<?php\n\\defined('_JEXEC') or die;\n"
                ."use Joomla\\CMS\\Extension\\PluginInterface;\nuse Joomla\\CMS\\Factory;\nuse Joomla\\DI\\Container;\nuse Joomla\\Event\\DispatcherInterface;\n"
                ."\$factory = function (Container \$container) { \$d = \$container->get(DispatcherInterface::class); return (new {$name}(\$d, []))->setApplication(Factory::getApplication()); };\n",
            "src/Extension/{$name}.php" => "<?php\nnamespace {$ns}\\Extension;\nuse Joomla\\CMS\\Plugin\\CMSPlugin;\nfinal class {$name} extends CMSPlugin {}\n",
        ];

        foreach ($overrides as $rel => $content) {
            $files[$rel] = $content;
        }

        $this->writeExtension($slug, $files);
    }

    private function writeExtension(string $slug, array $files): void
    {
        foreach ($files as $rel => $content) {
            $path = $this->workspace.'/'.$slug.'/'.$rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        }
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
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    public function test_risk_and_idempotence_reflect_that_it_writes(): void
    {
        $this->assertSame('write', $this->tool->risk());
        $this->assertFalse($this->tool->isIdempotent());
    }

    public function test_capability_floor_is_the_chat_action_on_com_phpclaw(): void
    {
        $this->assertSame('phpclaw.chat.use', $this->tool->requiredCapability());
    }

    public function test_forbidden_when_the_caller_lacks_the_action(): void
    {
        $this->denyJoomlaAccess();
        $this->writeValidPlugin('widget');

        $this->assertForbiddenEnvelope($this->tool->execute(['extension_dir' => 'widget']));
    }

    public function test_nothing_is_written_when_the_caller_is_refused(): void
    {
        $this->writeValidPlugin('widget');

        $out = json_decode($this->tool->execute(['extension_dir' => 'widget']), true, 512, JSON_THROW_ON_ERROR);
        $path = $out['data']['package']['zip_path'];

        $this->assertFileExists($path);
        unlink($path);

        $this->denyJoomlaAccess();
        $this->assertForbiddenEnvelope($this->tool->execute(['extension_dir' => 'widget']));
        $this->assertFileDoesNotExist($path);
    }

    public function test_unknown_argument_is_refused_before_anything_is_written(): void
    {
        $this->writeValidPlugin('widget');

        $out = $this->assertRefused(['extension_dir' => 'widget', 'output_name' => '../../pwned']);

        $this->assertSame('UNKNOWN_ARGUMENT', $out['error']['code']);
    }

    public function test_a_traversal_slug_is_refused_by_shape_not_by_lookup(): void
    {
        foreach (['../secret', './../secret', 'widget/../../secret', '.', '..', 'A', 'a b'] as $slug) {
            $out = $this->assertRefused(['extension_dir' => $slug]);

            $this->assertSame('INVALID_EXTENSION_DIR', $out['error']['code'], $slug);
        }
    }

    public function test_a_missing_folder_is_reported_as_missing_not_as_invalid(): void
    {
        $out = $this->assertRefused(['extension_dir' => 'absent']);

        $this->assertSame('EXTENSION_DIR_NOT_FOUND', $out['error']['code']);
    }

    public function test_the_built_archive_contains_only_the_extension_folder(): void
    {
        $this->writeValidPlugin('widget');

        $out = json_decode($this->tool->execute(['extension_dir' => 'widget']), true, 512, JSON_THROW_ON_ERROR);
        $path = $out['data']['package']['zip_path'];

        $this->assertFileExists($path);

        $zip = new \ZipArchive;
        $zip->open($path);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
        unlink($path);

        $this->assertNotSame([], $names);

        foreach ($names as $name) {
            $this->assertStringStartsWith('widget/', $name);
        }

        $this->assertFileDoesNotExist($path);
    }

    private function assertRefused(array $input): array
    {
        $out = json_decode($this->tool->execute($input), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($out['success']);
        $this->assertNull($out['data']);
        $this->assertSame('error', $out['meta']['mode']);

        return $out;
    }

    private function assertValidationProblem(array $input, string $problem): void
    {
        $out = $this->assertRefused($input);

        $this->assertStringContainsString(
            $problem,
            implode("\n", $out['error']['problems'] ?? [$out['error']['message']]),
        );
    }
}
