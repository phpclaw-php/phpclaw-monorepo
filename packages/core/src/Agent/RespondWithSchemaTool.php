<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * One-off tool offered by StructuredOutputRunner: the model answers by calling it with data matching the
 * caller's schema; the runner reads the call's input and never executes the tool.
 */
final class RespondWithSchemaTool implements ToolInterface
{
    public const NAME = 'respond_with_schema';

    /**
     * Build the tool around the schema the model's call must satisfy.
     *
     * @param  array<string, mixed>  $schema  JSON Schema used as the tool's input schema.
     * @return void
     */
    public function __construct(private readonly array $schema) {}

    /**
     * Unique machine-readable tool name.
     *
     * @return string
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Human-readable description telling the model to answer by calling this tool.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Call this with the final answer, matching the given schema exactly. Do not answer in plain text.';
    }

    /**
     * JSON Schema defining the tool's input parameters: the caller's original schema.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return $this->schema;
    }

    /**
     * Never invoked: StructuredOutputRunner reads the tool call's input directly, it never executes.
     *
     * @param  array<string, mixed>  $input  Unused.
     * @return string
     */
    public function execute(array $input): string
    {
        return '';
    }
}
