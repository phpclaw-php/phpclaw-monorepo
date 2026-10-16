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

    protected const MODE_FLAGS = ['schema', 'aggregate'];

    protected const ROW_ARGUMENTS = ['columns', 'limit', 'offset', 'order_by', 'order_dir'];

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
     * Build the JSON error envelope; each tool's execution contract supplies it.
     *
     * @param  string  $code  Machine-readable error code.
     * @param  string  $message  Human-readable explanation.
     * @param  array<string, mixed>  $context  Additional correction hints.
     * @return string JSON-encoded error envelope.
     */
    abstract protected function error(string $code, string $message, array $context = []): string;

    /**
     * Refuse a mode flag that is not a boolean, and a request that sets both schema and aggregate.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @param  list<string>  $flags  Arguments that must be booleans when present.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    protected function validateModeFlags(array $input, array $flags = self::MODE_FLAGS): ?string
    {
        foreach ($flags as $flag) {
            if (array_key_exists($flag, $input) && ! is_bool($input[$flag])) {
                return $this->error('INVALID_ARGUMENT', sprintf('"%s" must be a boolean.', $flag));
            }
        }

        if (($input['schema'] ?? false) === true && ($input['aggregate'] ?? false) === true) {
            return $this->error('CONFLICTING_MODES', 'Set only one of "schema" or "aggregate".');
        }

        return null;
    }

    /**
     * Validate the columns argument against the tool's available and blocked lists.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    protected function validateColumns(array $input): ?string
    {
        if (! array_key_exists('columns', $input)) {
            return null;
        }

        if (! is_array($input['columns']) && ! is_string($input['columns'])) {
            return $this->error('INVALID_COLUMNS', '"columns" must be an array or a string of field names.');
        }

        foreach (self::normaliseRequestedColumns($input['columns']) as $column) {
            if ($column === self::ALL_COLUMNS_MARKER) {
                continue;
            }

            if (in_array($column, static::BLOCKED_COLUMNS, true)) {
                return $this->error(
                    'BLOCKED_COLUMN',
                    sprintf('Field "%s" is blocked and cannot be read by this tool.', $column),
                    ['blocked_columns' => static::BLOCKED_COLUMNS],
                );
            }

            if (! in_array($column, static::AVAILABLE_COLUMNS, true)) {
                return $this->error(
                    'UNKNOWN_COLUMN',
                    sprintf('Field "%s" is not available from this tool.', $column),
                    ['available_columns' => static::AVAILABLE_COLUMNS],
                );
            }
        }

        return null;
    }

    /**
     * One IGNORED_ARGUMENT warning per row-shaping argument sent with aggregate mode.
     *
     * @param  array<string, mixed>  $input  Validated input.
     * @return list<array{code: string, message: string}>
     */
    protected static function ignoredArgumentWarnings(array $input): array
    {
        $warnings = [];

        foreach (self::ROW_ARGUMENTS as $argument) {
            if (array_key_exists($argument, $input)) {
                $warnings[] = [
                    'code' => 'IGNORED_ARGUMENT',
                    'message' => sprintf('"%s" was ignored because aggregate mode returns totals only.', $argument),
                ];
            }
        }

        return $warnings;
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
                        static fn (string $name): bool => $name !== '',
                    ));
                }
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $trimmed)),
                static fn (string $name): bool => $name !== '',
            ));
        }

        if (! is_array($requested)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $requested),
            static fn (string $name): bool => $name !== '',
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
            static fn (string $column): bool => in_array($column, $available, strict: true),
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
     * Clamp $limit into [1, $max].
     *
     * @param  int  $limit
     * @param  int  $max
     * @return int
     */
    protected static function clampLimit(int $limit, int $max): int
    {
        return min(max(1, $limit), $max);
    }

    /**
     * Clamp $offset to a non-negative integer.
     *
     * @param  int  $offset
     * @return int
     */
    protected static function clampOffset(int $offset): int
    {
        return max(0, $offset);
    }

    /**
     * Return $column when it exists in $allowed, otherwise $default.
     *
     * @param  string  $column
     * @param  string[]  $allowed
     * @param  string  $default
     * @return string
     */
    protected static function safeColumn(string $column, array $allowed, string $default): string
    {
        return in_array($column, $allowed, strict: true) ? $column : $default;
    }

    /**
     * Normalise $direction to 'ASC' or 'DESC', defaulting to $default.
     *
     * @param  string  $direction
     * @param  string  $default  'ASC' or 'DESC'
     * @return string
     */
    protected static function safeDirection(string $direction, string $default = 'DESC'): string
    {
        $upper = strtoupper($direction);

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
