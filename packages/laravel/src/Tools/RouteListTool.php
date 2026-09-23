<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use Illuminate\Support\Facades\Route;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that lists registered routes via Route::getRoutes(), truncated at 8 KB (read-only).
 */
final class RouteListTool extends AbstractLaravelTool
{
    private const ALLOWED_KEYS = ['filter'];

    /**
     * Return the tool identifier used by the agent to invoke this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'route_list';
    }

    /**
     * Return the human-readable description shown to the LLM for tool selection.
     *
     * @return string
     */
    public function description(): string
    {
        return 'LIST all registered Laravel routes with their method, URI, name, and action. Use to inspect routing, find endpoints, or debug 404s. Read-only, never mutates routes.';
    }

    /**
     * Return the JSON Schema describing the tool's accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Optional substring to filter routes by URI or name',
                ],
            ],
        ];
    }

    /**
     * Return the platform capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::CHAT_ABILITY;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['routing', 'http'],
            tags: ['route', 'routes', 'url', 'urls', 'uri', 'endpoint', 'endpoints', 'path', 'paths', 'controller', 'middleware', 'method', 'get', 'post', 'named'],
            intents: ['list routes', 'show endpoints', 'what urls does this app serve'],
            examples: ['list the registered routes'],
        );
    }

    /**
     * Guard the caller, reject unknown arguments, and validate input before collecting routes.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('list the registered routes');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        if (array_key_exists('filter', $input) && ! is_string($input['filter'])) {
            return [
                'input' => $input,
                'result' => $this->error('INVALID_ARGUMENT', '"filter" must be a string.'),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Collect all registered routes matching the optional filter.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{routes: array<int, array<string, mixed>>, total: int, filter: string}
     */
    protected function perform(array $input): array
    {
        $filter = strtolower($this->stringInput($input, 'filter'));

        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $name = (string) $route->getName();
            $action = $route->getActionName();

            if ($filter !== '' && ! str_contains(strtolower($uri), $filter) && ! str_contains(strtolower($name), $filter)) {
                continue;
            }

            $routes[] = [
                'methods' => implode('|', $route->methods()),
                'uri' => $uri,
                'name' => $name !== '' ? $name : null,
                'action' => $action,
            ];
        }

        $total = count($routes);
        $capped = $this->capRowsToOutputBytes($routes);

        return ['routes' => $capped, 'total' => $total, 'filter' => $filter];
    }

    /**
     * Assert that the execution produced a valid route list.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is not a valid array.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['routes'])) {
            throw new ToolException('route_list: execution produced a non-array route list.');
        }

        return ['result' => null];
    }

    /**
     * Build the success envelope from the verified route list.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $routes = $execution['routes'];
        $total = $execution['total'];
        $filter = $execution['filter'];
        $count = count($routes);
        $truncated = $count < $total;

        $data = ['routes' => $routes];

        $meta = [
            'mode' => 'query',
            'count' => $count,
            'total' => $total,
            'truncated' => $truncated,
        ];

        $warnings = [];

        if ($truncated) {
            $dropped = $total - $count;
            $warnings[] = [
                'code' => 'OUTPUT_TRUNCATED',
                'message' => sprintf(
                    '%d route(s) omitted because the output exceeded the 8 KB cap.',
                    $dropped,
                ),
            ];
        }

        if ($total === 0 && $filter !== '') {
            $warnings[] = [
                'code' => 'NOT_FOUND',
                'message' => sprintf("No routes matched filter '%s'.", $filter),
            ];
        }

        return $this->success($data, $meta, $warnings);
    }
}
