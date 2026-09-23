<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

use PhpClaw\Exceptions\ToolException;

/**
 * Contract every phpClaw tool must implement. Frozen until v2.0.
 */
interface ToolInterface
{
    /**
     * Unique machine-readable tool name (snake_case, e.g. 'shell_exec', 'http_fetch', 'file_read').
     *
     * @return string
     */
    public function name(): string;

    /**
     * Human-readable description for the LLM to understand when to use this tool.
     *
     * @return string
     */
    public function description(): string;

    /**
     * JSON Schema defining the tool's input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with the given input and return a string result.
     *
     * @param  array<string, mixed>  $input  Validated by the LLM against inputSchema().
     * @return string
     *
     * @throws ToolException On execution failure.
     */
    public function execute(array $input): string;
}
