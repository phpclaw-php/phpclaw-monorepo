<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Tools;

use Joomla\Database\DatabaseInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Shared base for all Joomla native query tools.
 */
abstract class AbstractJoomlaTool implements ToolInterface, ToolRoutingInterface
{
    protected const ALL_COLUMNS_MARKER = '*';

    protected const PRIMARY_KEY_COLUMN = 'id';

    protected const DEFAULT_COLUMNS = [];

    protected const AVAILABLE_COLUMNS = [];

    protected const BLOCKED_COLUMNS = [];

    /**
     * Create a new AbstractJoomlaTool instance.
     *
     * @param  DatabaseInterface  $db  Joomla database connection (auto-resolves `#__` prefix).
     */
    public function __construct(
        protected readonly DatabaseInterface $db,
    ) {}

    /**
     * Replace every :placeholder in $sql with the corresponding db->quote()'d value.
     *
     * @param  string  $sql
     * @param  array<string, mixed>  $bindings
     * @return string
     */
    protected function substituteBindings(string $sql, array $bindings): string
    {
        return (string) preg_replace_callback(
            '/:([A-Za-z_][A-Za-z0-9_]*)/',
            function (array $matches) use ($bindings): string {
                $colon = ':'.$matches[1];

                if (array_key_exists($colon, $bindings)) {
                    return $this->db->quote($bindings[$colon]);
                }

                if (array_key_exists($matches[1], $bindings)) {
                    return $this->db->quote($bindings[$matches[1]]);
                }

                return $matches[0];
            },
            $sql,
        );
    }

    /**
     * Worked examples for this tool, surfaced through schema discovery.
     *
     * Defaults to none so a tool that ships no examples cannot fatal here.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return [];
    }

    /**
     * Substitute bindings, execute via Joomla DatabaseInterface, and return rows.
     *
     * @param  string  $sql
     * @param  array<string, mixed>  $bindings
     * @param  bool  $fetchAll  true = all rows, false = single row.
     * @return array<int|string, mixed>
     *
     * @throws ToolException
     */
    protected function runStatement(string $sql, array $bindings, bool $fetchAll): array
    {
        try {
            $this->db->setQuery($this->substituteBindings($sql, $bindings));

            return $fetchAll
                ? ($this->db->loadAssocList() ?? [])
                : ($this->db->loadAssoc() ?? []);
        } catch (\Throwable $e) {
            error_log('phpClaw '.static::class.': '.$e->getMessage());
            throw new ToolException(
                $fetchAll
                    ? static::class.': query failed.'
                    : static::class.': aggregate failed.',
                previous: $e,
            );
        }
    }

    /**
     * Coerce a flexible "columns" input into a clean list of trimmed non-empty strings.
     *
     * @param  mixed  $requested
     * @return string[]
     */
    protected static function normaliseRequestedColumns(mixed $requested): array
    {
        if (is_string($requested)) {
            $trimmed = trim($requested);

            if ($trimmed !== '' && $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, associative: true);
                if (is_array($decoded)) {
                    return array_values(array_filter(
                        array_map('strval', $decoded),
                        static fn (string $s): bool => $s !== '',
                    ));
                }
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $trimmed)),
                static fn (string $s): bool => $s !== '',
            ));
        }

        if (! is_array($requested)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $requested),
            static fn (string $s): bool => $s !== '',
        ));
    }

    /**
     * Resolve requested columns - validate, strip blocked, fall back to defaults.
     *
     * @param  mixed  $requested
     * @return string[]
     */
    protected function resolveColumns(mixed $requested): array
    {
        $requested = self::normaliseRequestedColumns($requested);

        if ($requested === [self::ALL_COLUMNS_MARKER]) {
            return array_values(array_diff(static::AVAILABLE_COLUMNS, static::BLOCKED_COLUMNS));
        }

        if ($requested === []) {
            return static::DEFAULT_COLUMNS;
        }

        $available = static::AVAILABLE_COLUMNS;

        $valid = array_values(array_filter(
            $requested,
            static fn (string $c): bool => in_array($c, $available, strict: true),
        ));

        if ($valid === []) {
            return static::DEFAULT_COLUMNS;
        }

        if (! in_array(static::PRIMARY_KEY_COLUMN, $valid, strict: true)) {
            array_unshift($valid, static::PRIMARY_KEY_COLUMN);
        }

        return $valid;
    }

    /**
     * Clamp $n into [1, $max].
     *
     * @param  int  $n
     * @param  int  $max
     * @return int
     */
    protected static function clampLimit(int $n, int $max): int
    {
        return min(max(1, $n), $max);
    }

    /**
     * Clamp $n to a non-negative integer.
     *
     * @param  int  $n
     * @return int
     */
    protected static function clampOffset(int $n): int
    {
        return max(0, $n);
    }

    /**
     * Return $col when it exists in $allowed, otherwise $default.
     *
     * @param  string  $col
     * @param  string[]  $allowed
     * @param  string  $default
     * @return string
     */
    protected static function safeColumn(string $col, array $allowed, string $default): string
    {
        return in_array($col, $allowed, strict: true) ? $col : $default;
    }

    /**
     * Normalise $dir to 'ASC' or 'DESC', defaulting to $default.
     *
     * @param  string  $dir
     * @param  string  $default  'ASC' or 'DESC'
     * @return string
     */
    protected static function safeDirection(string $dir, string $default = 'DESC'): string
    {
        $upper = strtoupper($dir);

        return ($upper === 'ASC' || $upper === 'DESC') ? $upper : $default;
    }

    /**
     * JSON-encode helper that always throws on failure.
     *
     * @param  array<string, mixed>  $data
     * @return string
     */
    protected static function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Whether this tool may be offered to the model. Joomla evaluates authorisation when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by; subclasses override with their own domains, tags and intents.
     *
     * @return ToolRoutingMetadata Empty by default.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return ToolRoutingMetadata::empty();
    }
}
