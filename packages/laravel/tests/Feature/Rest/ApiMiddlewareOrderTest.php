<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature\Rest;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiMiddlewareOrderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function shippedRoutes(): array
    {
        return [['phpclaw/send'], ['phpclaw/chat/stream']];
    }

    #[DataProvider('shippedRoutes')]
    public function test_force_json_runs_before_the_auth_middleware_on_the_shipped_default(string $uri): void
    {
        $route = collect($this->app['router']->getRoutes()->getRoutes())
            ->first(static fn ($r): bool => $r->uri() === $uri);

        self::assertNotNull($route, "route {$uri} must be registered");

        $middleware = $route->gatherMiddleware();
        $jsonIndex = array_search('phpclaw.json', $middleware, true);
        $authIndex = array_search('auth:sanctum', $middleware, true);

        self::assertNotFalse($jsonIndex, 'phpclaw.json must be registered on the route');
        self::assertNotFalse($authIndex, 'auth:sanctum must be part of the shipped default api.middleware');
        self::assertLessThan(
            $authIndex,
            $jsonIndex,
            'phpclaw.json must run before auth:sanctum, otherwise a bare client with no Accept header '.
            'hits auth:sanctum\'s own unauthenticated() handler first, which redirects to a login route '.
            'that does not exist on an API-only app and renders a 500 HTML page instead of a JSON 401.',
        );
    }
}
