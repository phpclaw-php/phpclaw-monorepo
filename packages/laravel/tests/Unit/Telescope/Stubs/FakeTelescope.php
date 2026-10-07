<?php

declare(strict_types=1);

namespace Laravel\Telescope;

final class IncomingEntry
{
    public string $type = '';

    public function __construct(public array $content) {}

    public static function make(array $content): self
    {
        return new self($content);
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }
}

final class EntryType
{
    public const EVENT = 'event';
}

final class Telescope
{
    public static array $recorded = [];

    public static array $filters = [];

    public static function filter(\Closure $callback): void
    {
        self::$filters[] = $callback;
    }

    public static function keeps(IncomingEntry $entry): bool
    {
        foreach (self::$filters as $filter) {
            if (! $filter($entry)) {
                return false;
            }
        }

        return true;
    }

    public static function recordEvent(IncomingEntry $entry): void
    {
        self::$recorded[] = $entry->type('event');
    }
}
