<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Mcp\Contracts\TransportInterface;
use PhpClaw\Mcp\Exceptions\McpException;
use PhpClaw\Tools\ToolRegistry;

/**
 * Pure-PHP MCP server with zero framework dependencies.
 */
final class PhpClawMcpServer
{
    private readonly McpRouter $router;

    /**
     * Construct the MCP server and boot guards, hooks, and the router.
     *
     * @param  ToolRegistry  $registry  The tool registry exposed via MCP methods.
     * @return void
     */
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {
        GuardRegistry::registerDefaults();
        $this->bootUserGuards();
        $this->bootUserHooks();

        $this->router = new McpRouter(
            registry: $this->registry,
            adapter: new McpToolAdapter,
            allow: self::parseToolPolicy('PHPCLAW_TOOL_ALLOW'),
            deny: self::parseToolPolicy('PHPCLAW_TOOL_DENY'),
        );
    }

    /**
     * Handle a single McpRequest and return an McpResponse.
     *
     * @param  McpRequest  $request  The incoming JSON-RPC 2.0 request.
     * @return McpResponse Success or error envelope produced by the router.
     */
    public function handle(McpRequest $request): McpResponse
    {
        return $this->router->dispatch($request);
    }

    /**
     * Run the serve loop over a transport until the transport closes.
     *
     * @param  TransportInterface  $transport  The active transport (stdio or HTTP).
     * @return void
     */
    public function serve(TransportInterface $transport): void
    {
        while ($transport->isOpen()) {
            if (! $this->serveOnce($transport)) {
                break;
            }
        }
    }

    /**
     * Parse a JSON array of tool names from an env var into a string list (C-2 tool policy).
     *
     * @param  string  $envVar  Env var name holding a JSON array of tool names.
     * @return string[] Validated tool names ([] when unset/invalid).
     */
    private static function parseToolPolicy(string $envVar): array
    {
        $raw = (string) (getenv($envVar) ?: '[]');
        $list = json_decode($raw, associative: true);

        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * Handle one request from the transport.
     *
     * @param  TransportInterface  $transport  The active transport.
     * @return bool False when a caught error coincides with a closed transport (stop the loop); true otherwise.
     */
    private function serveOnce(TransportInterface $transport): bool
    {
        try {
            $request = $transport->read();
            $response = $this->handle($request);

            if (! $request->isNotification) {
                $transport->write($response);
            }

        } catch (McpException $e) {
            if (! $transport->isOpen()) {
                return false;
            }
            $transport->write(McpResponse::error(null, $e->rpcCode, $e->getMessage()));

        } catch (\Throwable) {
            if (! $transport->isOpen()) {
                return false;
            }
            $transport->write(McpResponse::error(null, McpException::INTERNAL_ERROR, 'Internal error'));
        }

        return true;
    }

    /**
     * Register user-configured guards from the PHPCLAW_GUARDS env var.
     *
     * @return void
     */
    private function bootUserGuards(): void
    {
        $raw = (string) (getenv('PHPCLAW_GUARDS') ?: '[]');
        $guards = json_decode($raw, associative: true) ?? [];

        if (! is_array($guards)) {
            return;
        }

        foreach ($guards as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            if (! is_a($entry['class'], GuardInterface::class, true)) {
                error_log("phpClaw: PHPCLAW_GUARDS class {$entry['class']} does not implement GuardInterface, skipped.");

                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            GuardRegistry::register(new $entry['class'], $priority);
        }
    }

    /**
     * Register user-configured hooks from the PHPCLAW_HOOKS env var.
     *
     * @return void
     */
    private function bootUserHooks(): void
    {
        $raw = (string) (getenv('PHPCLAW_HOOKS') ?: '[]');
        $hooks = json_decode($raw, associative: true) ?? [];

        if (! is_array($hooks)) {
            return;
        }

        foreach ($hooks as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            if (! is_string($entry['handler']) || ! is_a($entry['handler'], HookInterface::class, true)) {
                error_log("phpClaw: PHPCLAW_HOOKS handler for '{$entry['event']}' is not a HookInterface class, skipped.");

                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            HookRegistry::on($entry['event'], new $entry['handler'], $priority);
        }
    }
}
