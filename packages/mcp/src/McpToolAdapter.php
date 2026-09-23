<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Converts a ToolInterface into the MCP tools/list schema shape.
 */
final class McpToolAdapter
{
    /**
     * Convert a single tool into an MCP descriptor array.
     *
     * @param  ToolInterface  $tool  The tool to describe.
     * @return array{name:string,description:string,inputSchema:array<string,mixed>} MCP descriptor.
     */
    public function toDescriptor(ToolInterface $tool): array
    {
        $schema = $tool->inputSchema();

        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass;
        }

        return [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $schema,
        ];
    }

    /**
     * Convert an array of tools into MCP tools/list payload.
     *
     * @param  ToolInterface[]  $tools  Tools to describe.
     * @return array{tools: list<array{name:string,description:string,inputSchema:array<string,mixed>}>} MCP tools/list payload.
     */
    public function toList(array $tools): array
    {
        return [
            'tools' => array_values(
                array_map(fn (ToolInterface $t) => $this->toDescriptor($t), $tools)
            ),
        ];
    }
}
