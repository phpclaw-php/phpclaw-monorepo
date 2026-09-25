<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\Contracts\PromptOnlyGuardInterface;
use PhpClaw\Guards\Contracts\RawInputGuardInterface;

/**
 * Static registry for prompt-injection guards, run in ascending priority order.
 */
final class GuardRegistry
{
    public const DEFAULT_PRIORITY = 10;

    private static array $guards = [];

    private static bool $isSorted = true;

    private static bool $defaultsRegistered = false;

    /**
     * Register a guard with a given priority, optionally replacing an already-registered instance of the same class.
     *
     * @param  GuardInterface  $guard  The guard implementation to register.
     * @param  int  $priority  Execution order: lower numbers run first.
     * @param  bool  $replace  True to remove and replace an existing guard of the same class instead of skipping.
     * @return void
     */
    public static function register(GuardInterface $guard, int $priority = self::DEFAULT_PRIORITY, bool $replace = false): void
    {
        if (self::hasClass($guard::class)) {
            if (! $replace) {
                error_log('phpClaw GuardRegistry: guard '.self::displayClass($guard::class).' already registered: registration skipped (pass replace: true to override).');

                return;
            }

            self::$guards = array_values(array_filter(
                self::$guards,
                static fn (array $entry): bool => $guard::class !== $entry['guard']::class,
            ));
        }

        self::$guards[] = ['priority' => $priority, 'guard' => $guard];
        self::$isSorted = false;
    }

    /**
     * Render a class name for logging, stripping the embedded NUL-terminated file path PHP appends to anonymous class names.
     *
     * @param  string  $class  Raw class name, possibly an anonymous class identifier.
     * @return string
     */
    private static function displayClass(string $class): string
    {
        $nulPosition = strpos($class, "\0");

        return $nulPosition === false ? $class : substr($class, 0, $nulPosition).' (anonymous)';
    }

    /**
     * Whether a guard of the given class is already registered.
     *
     * @param  class-string<GuardInterface>  $class  Fully-qualified class name to look up.
     * @return bool
     */
    public static function hasClass(string $class): bool
    {
        foreach (self::$guards as $entry) {
            if ($class === $entry['guard']::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Install every attribute-discovered guard whose `#[Guard(enabledByDefault: true)]` flag is set.
     *
     * @return void
     */
    public static function registerDefaults(): void
    {
        if (self::$defaultsRegistered) {
            return;
        }
        self::$defaultsRegistered = true;

        foreach (Bootstrap::guards() as $entry) {
            $class = $entry['class'];
            $attr = DiscoveryCache::load()['guards'][$class] ?? null;

            if ($attr === null || ! ($attr['enabledByDefault'] ?? false)) {
                continue;
            }

            if (! class_exists($class) || ! is_a($class, GuardInterface::class, true)) {
                continue;
            }

            self::register(new $class, $entry['priority']);
        }
    }

    /**
     * Run every registered guard against the message in ascending priority order.
     *
     * @param  string  $message  Augmented message (memory + skill context + user input) scanned by content guards.
     * @param  string|null  $rawUserMessage  Raw user input; guards implementing RawInputGuardInterface scan this instead when provided.
     * @return void
     *
     * @throws GuardException When any guard detects a violation.
     */
    public static function scan(string $message, ?string $rawUserMessage = null): void
    {
        self::ensureSorted();

        foreach (self::$guards as ['guard' => $guard]) {
            $text = ($rawUserMessage !== null && $guard instanceof RawInputGuardInterface)
                ? $rawUserMessage
                : $message;
            $guard->scan($text);
        }
    }

    /**
     * Run every guard that is not PromptOnlyGuardInterface against a tool call's arguments, in
     * ascending priority order.
     *
     * @param  string  $arguments  Concatenated string values from the tool call's arguments.
     * @return void
     *
     * @throws GuardException When any applicable guard detects a violation.
     */
    public static function scanToolArguments(string $arguments): void
    {
        self::ensureSorted();

        foreach (self::$guards as ['guard' => $guard]) {
            if ($guard instanceof PromptOnlyGuardInterface) {
                continue;
            }

            $guard->scan($arguments);
        }
    }

    /**
     * Number of registered guards.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(self::$guards);
    }

    /**
     * Remove all registered guards. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$guards = [];
        self::$isSorted = true;
        self::$defaultsRegistered = false;
    }

    /**
     * Lazily sort the registered guards by ascending priority.
     *
     * @return void
     */
    private static function ensureSorted(): void
    {
        if (self::$isSorted) {
            return;
        }

        usort(self::$guards, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);
        self::$isSorted = true;
    }
}
