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
 */
final class AttributeScanner
{
    public const BUCKET_KEYS = ['tools', 'providers', 'memory', 'skills', 'hooks', 'guards'];

    /**
     * Walk every class in Composer's classmap whose FQCN starts with `PhpClaw\` and bucket attribute-carrying classes into a structured map.
     *
     * @param  list<string>|null  $classes  Pre-resolved class list; when null, built from classmap and PSR-4.
     * @param  string|null  $classmapPath  Path to the Composer classmap file.
     * @param  string|null  $psr4Path  Path to the PSR-4 autoload map.
     * @return array<string, mixed>
     */
    public static function scan(?array $classes = null, ?string $classmapPath = null, ?string $psr4Path = null): array
    {
        $bucket = array_fill_keys(self::BUCKET_KEYS, []);

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
     * Build candidate file paths under `vendor/composer/` for a given filename, covering the monorepo dev layout and a single installed-package layout.
     *
     * @param  string  $filename  File or path component to append to each candidate directory.
     * @return list<string> Absolute candidate paths (may or may not exist).
     */
    public static function composerVendorPaths(string $filename): array
    {
        return [
            __DIR__.'/../../vendor/composer/'.$filename,
            __DIR__.'/../../../../../vendor/composer/'.$filename,
            __DIR__.'/../../../../../../vendor/composer/'.$filename,
        ];
    }

    /**
     * Collect candidate `PhpClaw\` classes from BOTH the classmap and the PSR-4 prefix table, de-duplicated as a flat list.
     *
     * @param  string|null  $classmapPath  Path to the Composer classmap file.
     * @param  string|null  $psr4Path  Path to the PSR-4 autoload map.
     * @return list<string>
     */
    private static function candidateClasses(?string $classmapPath, ?string $psr4Path): array
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

        return array_keys($candidates);
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
        $psr4 = self::loadPsr4Map($psr4Path);

        if ($psr4 === false) {
            return [];
        }

        $subRoots = self::collectSubRoots($psr4);
        $classes = [];

        foreach ($psr4 as $prefix => $dirs) {
            if (! is_string($prefix) || ! str_starts_with($prefix, 'PhpClaw\\') || str_contains($prefix, '\\Tests\\') || ! is_array($dirs)) {
                continue;
            }

            $classes = [...$classes, ...self::classesFromPhpClawPrefix($prefix, $dirs, $subRoots)];
        }

        return $classes;
    }

    /**
     * Load and validate a PSR-4 autoload map file.
     *
     * @param  string  $psr4Path  Path to the PSR-4 autoload map.
     * @return array<string, mixed>|false The loaded map, or false when the file is missing or malformed.
     */
    private static function loadPsr4Map(string $psr4Path): array|false
    {
        if ($psr4Path === '' || ! is_file($psr4Path)) {
            return false;
        }

        $psr4 = require $psr4Path;

        return is_array($psr4) ? $psr4 : false;
    }

    /**
     * Collect every distinct directory root from all prefix→dirs entries in the PSR-4 map.
     *
     * @param  array<string, mixed>  $psr4  Loaded PSR-4 autoload map.
     * @return list<string> Normalised directory paths without trailing separator.
     */
    private static function collectSubRoots(array $psr4): array
    {
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

        return $subRoots;
    }

    /**
     * Derive FQCNs for every PHP file under the given dirs that belongs to $prefix, skipping sub-roots owned by a more-specific PSR-4 entry.
     *
     * @param  string  $prefix  Namespace prefix.
     * @param  array<mixed>  $dirs  Directories mapped to this prefix.
     * @param  list<string>  $subRoots  All sub-directory roots from the full PSR-4 map.
     * @return list<string>
     */
    private static function classesFromPhpClawPrefix(string $prefix, array $dirs, array $subRoots): array
    {
        $classes = [];

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
    private static function derivePhpClassesUnder(string $prefix, string $dir, array $skipRoots): array
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
     * @return string Absolute path to the classmap file, or empty string when not found.
     */
    private static function defaultClassmapPath(): string
    {
        return self::firstExistingComposerFile('autoload_classmap.php');
    }

    /**
     * Resolve the default `vendor/composer/autoload_psr4.php` path.
     *
     * @return string Absolute path to the PSR-4 map file, or empty string when not found.
     */
    private static function defaultPsr4Path(): string
    {
        return self::firstExistingComposerFile('autoload_psr4.php');
    }

    /**
     * Walk standard composer-vendor layouts up the tree and return the first matching `vendor/composer/<filename>` that exists.
     *
     * @param  string  $filename  File name to resolve.
     * @return string Absolute path to the first matching file, or empty string when none found.
     */
    private static function firstExistingComposerFile(string $filename): string
    {
        foreach (self::composerVendorPaths($filename) as $path) {
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
     * @return bool True when the class belongs to the PhpClaw namespace.
     */
    private static function isPhpClawClass(string $class): bool
    {
        return str_starts_with($class, 'PhpClaw\\');
    }

    /**
     * Auto-discovery filter: excludes test-namespace classes that would otherwise be picked up by `composer dump-autoload` of an adapter's dev-deps.
     *
     * @param  string  $class  Fully-qualified class name to test.
     * @return bool True when the class is a non-test PhpClaw class.
     */
    private static function isAutoDiscoverable(string $class): bool
    {
        return self::isPhpClawClass($class) && ! str_contains($class, '\\Tests\\');
    }

    /**
     * Collect a single (non-repeatable) attribute into the bucket as a class=>data map.
     *
     * @param  ReflectionClass<object>  $reflection  Reflection of the class being scanned.
     * @param  class-string  $attributeClass  Fully-qualified attribute class name to look for.
     * @param  array<class-string, array<string, mixed>>  $bucket  Output map, mutated in-place.
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
     * @param  ReflectionClass<object>  $reflection  Reflection of the class being scanned.
     * @param  class-string  $attributeClass  Fully-qualified attribute class name to look for.
     * @param  array<class-string, list<array<string, mixed>>>  $bucket  Output map, mutated in-place.
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
