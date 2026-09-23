<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;

/**
 * Dispatches JSON-RPC 2.0 requests to the correct MCP handler.
 */
final class McpRouter
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private const SERVER_INFO = [
        'name' => 'phpclaw-mcp',
        'version' => '1.0.0',
    ];

    /**
     * Construct the router with the active tool registry and descriptor adapter.
     *
     * @param  ToolRegistry  $registry  The tool registry used to look up and list tools.
     * @param  McpToolAdapter  $adapter  Converts ToolInterface objects to MCP descriptors.
     * @param  string[]  $allow  Tool-name allow list (empty = allow all not denied).
     * @param  string[]  $deny  Tool-name deny list (always wins over allow).
     * @return void
     */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly McpToolAdapter $adapter,
        private readonly array $allow = [],
        private readonly array $deny = [],
    ) {}

    /**
     * Dispatch a validated request and return a response.
     *
     * @param  McpRequest  $request  The incoming JSON-RPC 2.0 request.
     * @return McpResponse Success or error envelope to write back to the client.
     */
    public function dispatch(McpRequest $request): McpResponse
    {
        try {
            $result = match ($request->method) {
                'initialize' => $this->handleInitialize(),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($request),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($request),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($request),
                default => throw new McpException(
                    "Method not found: {$request->method}",
                    McpException::METHOD_NOT_FOUND,
                ),
            };

            return McpResponse::success($request->id, $result);

        } catch (McpException $e) {
            return McpResponse::error($request->id, $e->rpcCode, $e->getMessage());

        } catch (GuardException $e) {
            HookRegistry::fire(LifecycleEvent::GuardBlocked->value, [
                'message' => $e->getMessage(),
                'reason' => $e->getMessage(),
                'source' => 'mcp',
            ]);

            return McpResponse::success($request->id, [
                'content' => [['type' => 'text', 'text' => 'Blocked: '.$e->getMessage()]],
                'isError' => true,
            ]);

        } catch (ToolException $e) {
            return McpResponse::success($request->id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);

        } catch (\Throwable) {
            return McpResponse::error($request->id, McpException::INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * Build the capability handshake response for the initialize method.
     *
     * @return array<string, mixed> MCP initialize result payload.
     */
    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => self::SERVER_INFO,
        ];
    }

    /**
     * Return the MCP tools/list payload for every registered tool.
     *
     * @return array<string, mixed> MCP tools/list result.
     */
    private function handleToolsList(): array
    {
        $allowed = array_values(array_filter(
            $this->registry->all(),
            fn (ToolInterface $tool): bool => McpSecurity::isToolAllowed($tool->name(), $this->allow, $this->deny),
        ));

        return $this->adapter->toList($allowed);
    }

    /**
     * Return the MCP resources/list payload for every registered resource.
     *
     * @return array<string, mixed> MCP resources/list result.
     */
    private function handleResourcesList(): array
    {
        return ['resources' => ResourceRegistry::schemas()];
    }

    /**
     * Read a resource by URI and return its content envelope.
     *
     * @param  McpRequest  $request  Must include a "uri" param.
     * @return array<string, mixed> MCP resources/read result with the resource contents.
     *
     * @throws McpException If "uri" is missing (-32600), not found (-32601), or read fails (-32603).
     */
    private function handleResourcesRead(McpRequest $request): array
    {
        $uri = $request->getParam('uri');

        if (! is_string($uri) || $uri === '') {
            throw new McpException('resources/read requires a non-empty "uri" param', McpException::INVALID_REQUEST);
        }

        if (! ResourceRegistry::has($uri)) {
            throw new McpException("Resource not found: {$uri}", McpException::METHOD_NOT_FOUND);
        }

        try {
            $content = ResourceRegistry::read($uri);
        } catch (\RuntimeException $e) {
            error_log('[phpClaw MCP] resource read failed: '.$e->getMessage());
            throw new McpException('Resource read failed', McpException::INTERNAL_ERROR);
        }

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'text' => $content,
                ],
            ],
        ];
    }

    /**
     * Return the MCP prompts/list payload for every registered prompt.
     *
     * @return array<string, mixed> MCP prompts/list result.
     */
    private function handlePromptsList(): array
    {
        return ['prompts' => PromptRegistry::schemas()];
    }

    /**
     * Render a registered prompt with the supplied arguments.
     *
     * @param  McpRequest  $request  Must include "name" and optional "arguments" params.
     * @return array<string, mixed> MCP prompts/get result with description and messages.
     *
     * @throws McpException If "name" is missing (-32600), not found (-32601), or render fails (-32603).
     */
    private function handlePromptsGet(McpRequest $request): array
    {
        $name = $request->getParam('name');
        $arguments = $request->getParam('arguments') ?? [];

        if (! is_string($name) || $name === '') {
            throw new McpException('prompts/get requires a non-empty "name" param', McpException::INVALID_REQUEST);
        }

        if (! PromptRegistry::has($name)) {
            throw new McpException("Prompt not found: {$name}", McpException::METHOD_NOT_FOUND);
        }

        try {
            return PromptRegistry::render($name, is_array($arguments) ? $arguments : []);
        } catch (\RuntimeException $e) {
            error_log('[phpClaw MCP] prompt render failed: '.$e->getMessage());
            throw new McpException('Prompt render failed', McpException::INTERNAL_ERROR);
        }
    }

    /**
     * Execute a registered tool with the supplied arguments.
     *
     * @param  McpRequest  $request  Must include "name" and optional "arguments" params.
     * @return array<string, mixed> MCP tools/call result with the tool's text output.
     *
     * @throws McpException If "name" is missing (-32600) or tool not registered (-32601).
     * @throws GuardException If prompt injection is detected in the arguments.
     * @throws ToolException If the tool execution fails.
     */
    private function handleToolsCall(McpRequest $request): array
    {
        $name = $request->getParam('name');
        $arguments = $request->getParam('arguments') ?? [];

        if (! is_string($name) || $name === '') {
            throw new McpException('tools/call requires a non-empty "name" param', McpException::INVALID_REQUEST);
        }

        if (! McpSecurity::isToolAllowed($name, $this->allow, $this->deny)) {
            throw new McpException("Tool '{$name}' is not permitted for this session", McpException::METHOD_NOT_FOUND);
        }

        if (! $this->registry->has($name)) {
            throw new McpException("Tool not found: {$name}", McpException::METHOD_NOT_FOUND);
        }

        GuardRegistry::scanToolArguments(implode(' ', self::collectStringLeaves($arguments)));

        $toolInput = is_array($arguments) ? $arguments : [];
        $tool = $this->registry->get($name);

        HookRegistry::fire(LifecycleEvent::ToolBefore->value, [
            'tool_name' => $name,
            'tool_input' => $toolInput,
            'source' => 'mcp',
        ]);

        try {
            $result = $tool->execute($toolInput);
        } catch (ToolException $e) {
            HookRegistry::fire(LifecycleEvent::ToolError->value, [
                'tool_name' => $name,
                'tool_input' => $toolInput,
                'error' => $e->getMessage(),
                'source' => 'mcp',
            ]);

            throw $e;
        }

        HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
            'tool_name' => $name,
            'tool_input' => $toolInput,
            'tool_result' => $result,
            'source' => 'mcp',
        ]);

        return [
            'content' => [['type' => 'text', 'text' => $result]],
            'isError' => false,
        ];
    }

    /**
     * Recursively collect every string leaf from a nested argument structure so the guard scans all depths.
     *
     * @param  mixed  $value  Arbitrary tool-argument value.
     * @return string[] Every string leaf found at any depth.
     */
    private static function collectStringLeaves(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $leaves = [];
        foreach ($value as $item) {
            $leaves = [...$leaves, ...self::collectStringLeaves($item)];
        }

        return $leaves;
    }
}
