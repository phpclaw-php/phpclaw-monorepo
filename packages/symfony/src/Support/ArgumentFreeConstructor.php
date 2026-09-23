<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Support;

/**
 * Answers whether a class can be built with no arguments, so extension-supplied classes that need
 * constructor arguments are skipped rather than aborting the boot with an ArgumentCountError.
 */
final class ArgumentFreeConstructor
{
    /**
     * Whether the class exists and its constructor requires no arguments.
     *
     * @param  mixed  $class  Candidate class name from configuration.
     * @return bool True when `new $class` is safe.
     */
    public static function accepts(mixed $class): bool
    {
        if (! is_string($class) || ! class_exists($class)) {
            return false;
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0;
    }

    /**
     * Instantiate the class when it needs no constructor arguments, otherwise return null.
     *
     * @param  mixed  $class  Candidate class name from configuration.
     * @return object|null The instance, or null when the class cannot be built without arguments.
     */
    public static function make(mixed $class): ?object
    {
        if (! self::accepts($class)) {
            return null;
        }

        return new $class;
    }
}
