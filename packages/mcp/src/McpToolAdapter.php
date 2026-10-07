<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\PerInvocationMutabilityInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Converts a ToolInterface into the MCP tools/list schema shape.
 */
final class McpToolAdapter
{
    /**
     * Convert a tool into an MCP descriptor, marking a tool that can change data as destructive.
     *
     * @param  ToolInterface  $tool  The tool to describe.
     * @return array{name:string,description:string,inputSchema:array<string,mixed>,annotations?:array{readOnlyHint:bool,destructiveHint:bool}} MCP descriptor.
     */
    public function toDescriptor(ToolInterface $tool): array
    {
        $schema = $tool->inputSchema();

        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass;
        }

        $descriptor = [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $schema,
        ];

        if ($tool instanceof MutatingToolInterface || $tool instanceof PerInvocationMutabilityInterface) {
            $descriptor['annotations'] = ['readOnlyHint' => false, 'destructiveHint' => true];
        }

        return $descriptor;
    }

    /**
     * Convert an array of tools into MCP tools/list payload.
     *
     * @param  ToolInterface[]  $tools  Tools to describe.
     * @return array{tools: list<array{name:string,description:string,inputSchema:array<string,mixed>,annotations?:array{readOnlyHint:bool,destructiveHint:bool}}>} MCP tools/list payload.
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
