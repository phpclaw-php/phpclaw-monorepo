<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\AutoDiscovery\Attributes\Hook;
use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\AutoDiscovery\Attributes\Provider;
use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\AutoDiscovery\Attributes\Tool;
use ReflectionClass;

/**
 * Walks Composer's classmap, filters to the `PhpClaw\` namespace, reflects each class for any of the six phpClaw discovery attributes, and returns a structured inventory.
 *
 * @internal
 */
final class AttributeScanner
{
    /**
     * Walk every class in Composer's classmap whose FQCN starts with `PhpClaw\` and bucket attribute-carrying classes into a structured map.
     *
     * @param  list<string>|null  $classes  Classes.
     * @param  string|null  $classmapPath  Path to the Composer classmap file.
     * @param  string|null  $psr4Path  Path to the PSR-4 autoload map.
     * @return array<string, mixed>
     */
    public static function scan(?array $classes = null, ?string $classmapPath = null, ?string $psr4Path = null): array
    {
        $bucket = [
            'tools' => [],
            'providers' => [],
            'memory' => [],
            'skills' => [],
            'hooks' => [],
            'guards' => [],
        ];

        $candidates = $classes ?? self::candidateClasses($classmapPath, $psr4Path);

        foreach ($candidates as $class) {
            if (! self::isPhpClawClass($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            self::collectSingle($reflection, Tool::class, $bucket['tools']);
            self::collectSingle($reflection, Provider::class, $bucket['providers']);
            self::collectSingle($reflection, Memory::class, $bucket['memory']);
            self::collectSingle($reflection, Skill::class, $bucket['skills']);
            self::collectSingle($reflection, Guard::class, $bucket['guards']);
            self::collectRepeatable($reflection, Hook::class, $bucket['hooks']);
        }

        return $bucket;
    }

    /**
     * Collect candidate `PhpClaw\` classes from BOTH the classmap and the PSR-4 prefix table, de-duplicated as a flat list.
     *
     * @param  string|null  $classmapPath  Path to the Composer classmap file.
     * @param  string|null  $psr4Path  Path to the PSR-4 autoload map.
     * @return list<string>
     */
    private static function candidateClasses(?string $classmapPath, ?string $psr4Path = null): array
    {
        $classmapPath ??= self::defaultClassmapPath();
        $psr4Path ??= self::defaultPsr4Path();

        $candidates = [];

        foreach (self::classesFromClassmap($classmapPath) as $class) {
            $candidates[$class] = true;
        }

        foreach (self::classesFromPsr4($psr4Path) as $class) {
            $candidates[$class] = true;
        }

        $list = array_keys($candidates);

        return $list;
    }

    /**
     * Read Composer's generated classmap file; returns an empty list for missing or malformed files.
     *
     * @param  string  $classmapPath  Path to the Composer classmap file.
     * @return list<string>
     */
    private static function classesFromClassmap(string $classmapPath): array
    {
        if ($classmapPath === '' || ! is_file($classmapPath)) {
            return [];
        }

        $classmap = require $classmapPath;

        if (! is_array($classmap)) {
            return [];
        }

        $classes = [];
        foreach (array_keys($classmap) as $class) {
            if (is_string($class) && $class !== '' && self::isAutoDiscoverable($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Walk every PSR-4 prefix declared under `PhpClaw\` namespace and derive the FQCN of each `*.php` file from its relative path.
     *
     * @param  string  $psr4Path  Path to the PSR-4 autoload map.
     * @return list<string>
     */
    private static function classesFromPsr4(string $psr4Path): array
    {
        if ($psr4Path === '' || ! is_file($psr4Path)) {
            return [];
        }

        $psr4 = require $psr4Path;

        if (! is_array($psr4)) {
            return [];
        }

        $subRoots = [];
        foreach ($psr4 as $otherPrefix => $otherDirs) {
            if (! is_string($otherPrefix) || ! is_array($otherDirs)) {
                continue;
            }
            foreach ($otherDirs as $otherDir) {
                if (is_string($otherDir) && is_dir($otherDir)) {
                    $subRoots[] = rtrim($otherDir, '/\\');
                }
            }
        }

        $classes = [];

        foreach ($psr4 as $prefix => $dirs) {
            if (! is_string($prefix) || ! str_starts_with($prefix, 'PhpClaw\\')) {
                continue;
            }
            if (str_contains($prefix, '\\Tests\\') || str_ends_with($prefix, '\\Tests\\')) {
                continue;
            }
            if (! is_array($dirs)) {
                continue;
            }

            foreach ($dirs as $dir) {
                if (! is_string($dir) || ! is_dir($dir)) {
                    continue;
                }

                $skip = array_values(array_filter(
                    $subRoots,
                    static fn (string $sub): bool => $sub !== rtrim($dir, '/\\') && str_starts_with($sub, rtrim($dir, '/\\').'/'),
                ));

                foreach (self::derivePhpClassesUnder($prefix, $dir, $skip) as $class) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Derive the fully-qualified class names for PHP files under the given directory.
     *
     * @param  string  $prefix  Namespace prefix.
     * @param  string  $dir  Directory to scan.
     * @param  list<string>  $skipRoots  Sub-directory roots owned by a more-specific PSR-4 prefix.
     * @return list<string>
     */
    private static function derivePhpClassesUnder(string $prefix, string $dir, array $skipRoots = []): array
    {
        $dir = rtrim($dir, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            foreach ($skipRoots as $skip) {
                if (str_starts_with($path, $skip.'/')) {
                    continue 2;
                }
            }

            $relative = substr($path, strlen($dir) + 1);
            $relative = str_replace('/', '\\', $relative);
            $class = $prefix.substr($relative, 0, -4);

            if (str_contains($class, '_')) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Resolve the default `vendor/composer/autoload_classmap.php` path by walking up from this file's location through standard Composer layouts.
     *
     * @return string The resulting value.
     */
    private static function defaultClassmapPath(): string
    {
        return self::firstExistingComposerFile('autoload_classmap.php');
    }

    /**
     * Resolve the default `vendor/composer/autoload_psr4.php` path.
     *
     * @return string The resulting value.
     */
    private static function defaultPsr4Path(): string
    {
        return self::firstExistingComposerFile('autoload_psr4.php');
    }

    /**
     * Walk standard composer-vendor layouts up the tree and return the first matching `vendor/composer/<filename>` that exists.
     *
     * @param  string  $filename  File name to resolve.
     * @return string The resulting value.
     */
    private static function firstExistingComposerFile(string $filename): string
    {
        $candidates = [
            __DIR__.'/../../vendor/composer/'.$filename,
            __DIR__.'/../../../../../vendor/composer/'.$filename,
            __DIR__.'/../../../../../../vendor/composer/'.$filename,
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * Cheap namespace filter: keeps only classes inside the `PhpClaw\` namespace.
     *
     * @param  string  $class  Fully-qualified class name to test.
     * @return bool True on success.
     */
    private static function isPhpClawClass(string $class): bool
    {
        return str_starts_with($class, 'PhpClaw\\');
    }

    /**
     * Auto-discovery filter: excludes test-namespace classes that would otherwise be picked up by `composer dump-autoload` of an adapter's dev-deps.
     *
     * @param  string  $class  Fully-qualified class name to test.
     * @return bool True on success.
     */
    private static function isAutoDiscoverable(string $class): bool
    {
        return self::isPhpClawClass($class) && ! str_contains($class, '\\Tests\\');
    }

    /**
     * Collect a single (non-repeatable) attribute into the bucket as a name=>data map.
     *
     * @param  ReflectionClass<object>  $reflection  Reflection.
     * @param  class-string  $attributeClass  Attribute class.
     * @param  array<class-string, array<string, mixed>>  $bucket  Bucket.
     * @return void
     */
    private static function collectSingle(ReflectionClass $reflection, string $attributeClass, array &$bucket): void
    {
        $attrs = $reflection->getAttributes($attributeClass);

        if ($attrs === []) {
            return;
        }

        $instance = $attrs[0]->newInstance();
        $class = $reflection->getName();
        $bucket[$class] = self::serialiseAttribute($instance);
    }

    /**
     * Collect a repeatable attribute (Hook): every occurrence on the class is appended to the bucket entry for that class.
     *
     * @param  ReflectionClass<object>  $reflection  Reflection.
     * @param  class-string  $attributeClass  Attribute class.
     * @param  array<class-string, list<array<string, mixed>>>  $bucket  Bucket.
     * @return void
     */
    private static function collectRepeatable(ReflectionClass $reflection, string $attributeClass, array &$bucket): void
    {
        $attrs = $reflection->getAttributes($attributeClass);

        if ($attrs === []) {
            return;
        }

        $entries = [];
        foreach ($attrs as $attr) {
            $entries[] = self::serialiseAttribute($attr->newInstance());
        }

        $class = $reflection->getName();
        $bucket[$class] = $entries;
    }

    /**
     * Convert an attribute instance's public readonly properties to an array.
     *
     * @param  object  $instance  Attribute instance to serialise.
     * @return array<string, mixed>
     */
    private static function serialiseAttribute(object $instance): array
    {
        $reflection = new ReflectionClass($instance);
        $out = [];

        foreach ($reflection->getProperties() as $prop) {
            $out[$prop->getName()] = $prop->getValue($instance);
        }

        return $out;
    }
}
