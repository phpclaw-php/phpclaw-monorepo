<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\OcTablePrefix;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Shared base for all OpenCart native query tools.
 */
abstract class AbstractOpenCartTool implements ToolInterface, ToolRoutingInterface
{
    protected const MAX_OUTPUT_BYTES = ToolOutputEncoder::MAX_OUTPUT_BYTES;

    protected const MAX_LIMIT = 100;

    protected const DEFAULT_LIMIT = 25;

    protected const ERROR_LABEL = 'query';

    protected readonly string $tablePrefix;

    /**
     * Bind the database handle, the caller's module grant, and the OpenCart table prefix.
     *
     * @param  OcDbInterface|null  $db  OpenCart native DB instance.
     * @param  string|null  $tablePrefix  OC table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     */
    public function __construct(
        protected readonly ?OcDbInterface $db,
        ?string $tablePrefix,
        protected readonly bool $callerMayUseModule,
    ) {
        $this->tablePrefix = OcTablePrefix::resolve($tablePrefix);
    }

    /**
     * Clamp a caller-supplied limit into [1, static::MAX_LIMIT].
     *
     * @param  array<string, mixed>  $input  Tool input parameters.
     * @return int Clamped limit in [1, static::MAX_LIMIT].
     */
    protected function clampLimit(array $input): int
    {
        return min(max(1, (int) ($input['limit'] ?? static::DEFAULT_LIMIT)), static::MAX_LIMIT);
    }

    /**
     * JSON-encode helper that always throws on failure.
     *
     * @param  array<string, mixed>  $data  Data to encode.
     * @return string
     *
     * @throws \JsonException If encoding fails.
     */
    protected static function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Execute a query and return a single row.
     *
     * @param  string  $sql  SQL statement.
     * @param  list<mixed>  $params  Bind parameters.
     * @return array<string, mixed> Single row or empty array.
     *
     * @throws ToolException If the query fails.
     */
    protected function fetchOne(string $sql, array $params): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            $row = $this->db->query($sql, $params)->row;

            return is_array($row) && $row !== [] ? $row : [];
        } catch (\Throwable $e) {
            throw new ToolException(static::ERROR_LABEL.': operation failed.', previous: $e);
        }
    }

    /**
     * Execute a query and return all result rows.
     *
     * @param  string  $sql  SQL statement.
     * @param  list<mixed>  $params  Bind parameters.
     * @return list<array<string, mixed>>
     *
     * @throws ToolException If the query fails.
     */
    protected function fetchRows(string $sql, array $params): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            return $this->db->query($sql, $params)->rows;
        } catch (\Throwable $e) {
            throw new ToolException(static::ERROR_LABEL.': operation failed.', previous: $e);
        }
    }

    /**
     * Whether this tool may be offered to the model. OpenCart evaluates module access when the tool runs, so every tool stays eligible for routing.
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
