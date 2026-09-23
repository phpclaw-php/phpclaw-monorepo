<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Validation;

/**
 * Validates the boot-critical wiring of a generated Joomla plugin.
 */
final class PluginWiringValidator
{
    /**
     * Collect plugin wiring problems, empty when the plugin is correctly wired.
     *
     * @param  string  $dir  Absolute extension directory.
     * @param  string  $slug  Extension slug.
     * @return string[] Actionable problem messages.
     */
    public function validate(string $dir, string $slug): array
    {
        $problems = [];

        $manifest = $this->readManifest($dir, $slug);
        $manifestNs = '';

        $problems = array_merge($problems, $this->checkManifest($manifest, $slug, $manifestNs));
        $problems = array_merge($problems, $this->checkProvider($dir));
        $problems = array_merge($problems, $this->checkClasses($dir, $manifestNs));

        return $problems;
    }

    /**
     * Read the extension's root manifest XML.
     *
     * @param  string  $dir  Absolute extension directory.
     * @param  string  $slug  Extension slug.
     * @return string Manifest contents, or an empty string when none is found.
     */
    private function readManifest(string $dir, string $slug): string
    {
        $manifestFile = is_file($dir.'/'.$slug.'.xml')
            ? $dir.'/'.$slug.'.xml'
            : ((glob($dir.'/*.xml') ?: [''])[0]);

        return $manifestFile !== '' ? (string) file_get_contents($manifestFile) : '';
    }

    /**
     * Validate the manifest's filename, PSR-4 namespace, and language folder.
     *
     * @param  string  $manifest  Manifest contents.
     * @param  string  $slug  Extension slug.
     * @param  string  $manifestNs  Populated by reference with the declared PSR-4 namespace.
     * @return string[] Problems found in the manifest.
     */
    private function checkManifest(string $manifest, string $slug, string &$manifestNs): array
    {
        $problems = [];

        if (preg_match('~<filename\s+plugin="([^"]+)"~i', $manifest, $m) === 1) {
            if ($m[1] !== $slug) {
                $problems[] = "manifest <filename plugin=\"{$m[1]}\"> must equal the folder slug \"{$slug}\" (short name, never plg_ prefixed).";
            }
        } else {
            $problems[] = "manifest <files> must contain <filename plugin=\"{$slug}\">{$slug}.php</filename>.";
        }

        if (preg_match('~<namespace\b[^>]*\bpath="src"[^>]*>\s*([^<\s]+)\s*</namespace>~i', $manifest, $m) === 1) {
            $manifestNs = trim($m[1]);
        } else {
            $problems[] = 'manifest missing <namespace path="src">{Vendor}\\Plugin\\{Group}\\{Name}</namespace>. Without it the class cannot autoload and the first front-end request fatals (HTTP 500).';
        }

        if (str_contains($manifest, '<languages') && preg_match('~<languages\b[^>]*\bfolder="language"~i', $manifest) !== 1) {
            $problems[] = 'manifest <languages> block must carry folder="language", or every label renders as its raw constant.';
        }

        return $problems;
    }

    /**
     * Validate the DI provider's interface key, factory closure, and setApplication call.
     *
     * @param  string  $dir  Absolute extension directory.
     * @return string[] Problems found in services/provider.php.
     */
    private function checkProvider(string $dir): array
    {
        $providerFile = $dir.'/services/provider.php';
        if (! is_file($providerFile)) {
            return ['services/provider.php is missing.'];
        }

        $problems = [];
        $p = (string) file_get_contents($providerFile);

        if (str_contains($p, 'Joomla\\CMS\\Plugin\\PluginInterface')) {
            $problems[] = 'services/provider.php uses Joomla\\CMS\\Plugin\\PluginInterface, which does not exist. Register under Joomla\\CMS\\Extension\\PluginInterface.';
        } elseif (! str_contains($p, 'Joomla\\CMS\\Extension\\PluginInterface')) {
            $problems[] = 'services/provider.php must register the plugin under Joomla\\CMS\\Extension\\PluginInterface.';
        }

        if (! str_contains($p, 'setApplication')) {
            $problems[] = 'services/provider.php must call $plugin->setApplication(Factory::getApplication()). Without it getApplication() is null and the plugin\'s events do nothing.';
        }

        if (preg_match('~function\s*\(\s*Container~', $p) !== 1) {
            $problems[] = 'services/provider.php must register a factory closure (function (Container $container) { ... }), not a bare instance.';
        }

        if (preg_match('~new\s+\w+\s*\(\s*(\(array\)|PluginHelper::getPlugin)~', $p) === 1) {
            $problems[] = 'services/provider.php constructs the plugin with the config array as the first argument (the Joomla 3 signature). The Joomla 4/5/6 CMSPlugin constructor takes the dispatcher first: obtain it via $container->get(DispatcherInterface::class) and call new {Name}($dispatcher, []).';
        } elseif (preg_match('~new\s+\w+\s*\(~', $p) === 1 && ! str_contains($p, 'DispatcherInterface')) {
            $problems[] = 'services/provider.php builds the plugin but never obtains the event dispatcher. The factory closure must get Joomla\\Event\\DispatcherInterface from the container and pass it as the first constructor argument: new {Name}($dispatcher, []).';
        }

        return $problems;
    }

    /**
     * Validate every src/ class: namespace-to-location match, constructor signature, and API misuse.
     *
     * @param  string  $dir  Absolute extension directory.
     * @param  string  $manifestNs  Declared PSR-4 namespace (empty when the manifest omitted it).
     * @return string[] Problems found in the plugin's classes.
     */
    private function checkClasses(string $dir, string $manifestNs): array
    {
        $srcDir = $dir.'/src';
        if (! is_dir($srcDir)) {
            return [];
        }

        $problems = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iter as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('~\bclass\s+\w+~', $code) !== 1) {
                continue;
            }

            $relFromSrc = substr($file->getPathname(), strlen($srcDir) + 1);
            $subDir = trim((string) dirname($relFromSrc), './');
            $expectedNs = $manifestNs !== ''
                ? rtrim($manifestNs.($subDir !== '' ? '\\'.str_replace('/', '\\', $subDir) : ''), '\\')
                : '';

            if (preg_match('~^\s*namespace\s+([^;]+);~m', $code, $m) === 1) {
                $declared = trim($m[1]);
                if ($manifestNs !== '' && $declared !== $expectedNs) {
                    $problems[] = "src/{$relFromSrc}: namespace {$declared} does not match its location. Expected {$expectedNs} (PSR-4 root {$manifestNs} maps to src/, so src/Extension/ is {$manifestNs}\\Extension).";
                }
            } else {
                $problems[] = "src/{$relFromSrc}: missing namespace declaration.";
            }

            if (preg_match('~function\s+__construct\s*\([^)]*&\s*\$~', $code) === 1) {
                $problems[] = "src/{$relFromSrc}: constructor takes a by-reference parameter (&\$subject). This is the Joomla 3 signature. Under PHP 8 the provider's config array cannot be passed by reference (fatal). Drop the custom constructor or use __construct(array \$config = []).";
            }

            if (str_contains($code, 'getCustomTag')) {
                $problems[] = "src/{$relFromSrc}: calls \$document->getCustomTag(), which does not exist (fatal). Append to the page with onAfterRender + \$app->getBody()/setBody() instead.";
            }
        }

        return $problems;
    }
}
