<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Validation;

use PhpClaw\Joomla\Component\Administrator\Validation\PluginWiringValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginWiringValidator::class)]
final class PluginWiringValidatorTest extends TestCase
{
    private string $dir;

    private PluginWiringValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/phpclaw_pwv_'.uniqid();
        $this->validator = new PluginWiringValidator;
        $this->writeValidPlugin();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->dir);
        parent::tearDown();
    }

    public function test_valid_plugin_has_no_problems(): void
    {
        $this->assertSame([], $this->validator->validate($this->dir, 'widget'));
    }

    public function test_flags_missing_manifest_namespace(): void
    {
        $this->put('widget.xml', '<?xml version="1.0"?><extension type="plugin" group="system" method="upgrade">'
            .'<name>x</name><files><filename plugin="widget">widget.php</filename><folder>src</folder></files></extension>');

        $this->assertProblemContains('missing <namespace path="src">');
    }

    public function test_flags_class_namespace_location_mismatch(): void
    {
        $this->put('src/Extension/Widget.php', "<?php\nnamespace PhpClaw\\Plugin\\System\\Widget;\nfinal class Widget {}\n");

        $this->assertProblemContains('does not match its location');
    }

    public function test_flags_by_reference_constructor(): void
    {
        $this->put('src/Extension/Widget.php', "<?php\nnamespace PhpClaw\\Plugin\\System\\Widget\\Extension;\nfinal class Widget {\n public function __construct(&\$subject, \$config = []) {}\n}\n");

        $this->assertProblemContains('by-reference parameter');
    }

    public function test_flags_wrong_plugininterface_fqcn(): void
    {
        $this->put('services/provider.php', "<?php\nuse Joomla\\CMS\\Plugin\\PluginInterface;\n\$f = function (Container \$c) { return (new Widget())->setApplication(); };\n");

        $this->assertProblemContains('does not exist');
    }

    public function test_flags_getcustomtag(): void
    {
        $this->put('src/Extension/Widget.php', "<?php\nnamespace PhpClaw\\Plugin\\System\\Widget\\Extension;\nfinal class Widget {\n public function r() { \$this->doc->getCustomTag('body'); }\n}\n");

        $this->assertProblemContains('getCustomTag');
    }

    public function test_flags_config_first_constructor(): void
    {
        $this->put('services/provider.php', "<?php\n\\defined('_JEXEC') or die;\nuse Joomla\\CMS\\Extension\\PluginInterface;\n\$f = function (Container \$c) { return (new Widget((array) PluginHelper::getPlugin('system', 'widget')))->setApplication(Factory::getApplication()); };\n");

        $this->assertProblemContains('dispatcher first');
    }

    public function test_flags_provider_without_dispatcher(): void
    {
        $this->put('services/provider.php', "<?php\n\\defined('_JEXEC') or die;\nuse Joomla\\CMS\\Extension\\PluginInterface;\n\$f = function (Container \$c) { return (new Widget())->setApplication(Factory::getApplication()); };\n");

        $this->assertProblemContains('never obtains the event dispatcher');
    }

    private function assertProblemContains(string $needle): void
    {
        $problems = $this->validator->validate($this->dir, 'widget');
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString($needle, implode("\n", $problems));
    }

    private function writeValidPlugin(): void
    {
        $ns = 'PhpClaw\\Plugin\\System\\Widget';
        $this->put('widget.xml', '<?xml version="1.0"?><extension type="plugin" group="system" method="upgrade">'
            .'<name>x</name><namespace path="src">'.$ns.'</namespace>'
            .'<files><filename plugin="widget">widget.php</filename><folder>services</folder><folder>src</folder></files>'
            .'<languages folder="language"><language tag="en-GB">en-GB/plg_system_widget.ini</language></languages></extension>');
        $this->put('widget.php', "<?php\n\\defined('_JEXEC') or die;\n");
        $this->put('services/provider.php', "<?php\n\\defined('_JEXEC') or die;\nuse Joomla\\CMS\\Extension\\PluginInterface;\nuse Joomla\\Event\\DispatcherInterface;\n\$f = function (Container \$c) { \$d = \$c->get(DispatcherInterface::class); return (new Widget(\$d, []))->setApplication(Factory::getApplication()); };\n");
        $this->put('src/Extension/Widget.php', "<?php\nnamespace {$ns}\\Extension;\nfinal class Widget {}\n");
    }

    private function put(string $rel, string $content): void
    {
        $path = $this->dir.'/'.$rel;
        @mkdir(dirname($path), 0777, true);
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
