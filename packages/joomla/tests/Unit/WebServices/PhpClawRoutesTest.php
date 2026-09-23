<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\WebServices;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\Event;
use Joomla\Router\Route;
use PhpClaw\Joomla\WebServices\Extension\PhpClaw;
use PHPUnit\Framework\TestCase;

final class PhpClawRoutesTest extends TestCase
{
    private function registeredRoutes(): array
    {
        $router = new ApiRouter;

        (new PhpClaw([]))->onBeforeApiRoute(new BeforeApiRouteEvent($router));

        return $router->routes;
    }

    private function routeFor(string $pattern): Route
    {
        foreach ($this->registeredRoutes() as $route) {
            if ($route->getPattern() === $pattern) {
                return $route;
            }
        }

        self::fail('No route registered for '.$pattern);
    }

    public function test_it_subscribes_to_the_api_routing_event(): void
    {
        self::assertSame(
            ['onBeforeApiRoute' => 'onBeforeApiRoute'],
            PhpClaw::getSubscribedEvents(),
        );
    }

    public function test_it_publishes_exactly_two_routes(): void
    {
        self::assertCount(2, $this->registeredRoutes());
    }

    public function test_it_publishes_the_send_and_stream_patterns(): void
    {
        $patterns = array_map(
            static fn (Route $route): string => $route->getPattern(),
            $this->registeredRoutes(),
        );

        sort($patterns);

        self::assertSame(['v1/phpclaw/chat', 'v1/phpclaw/chat/stream'], $patterns);
    }

    public function test_both_routes_accept_post_only(): void
    {
        foreach ($this->registeredRoutes() as $route) {
            self::assertSame(['POST'], $route->getMethods(), $route->getPattern());
        }
    }

    public function test_routes_target_the_chat_controller_tasks(): void
    {
        self::assertSame('chat.send', $this->routeFor('v1/phpclaw/chat')->getController());
        self::assertSame('chat.stream', $this->routeFor('v1/phpclaw/chat/stream')->getController());
    }

    public function test_both_routes_are_authenticated(): void
    {
        foreach ($this->registeredRoutes() as $route) {
            $defaults = $route->getDefaults();

            self::assertSame('com_phpclaw', $defaults['component'], $route->getPattern());
            self::assertFalse($defaults['public'], $route->getPattern());
        }
    }

    public function test_only_the_stream_route_negotiates_event_stream(): void
    {
        self::assertSame(
            ['text/event-stream'],
            $this->routeFor('v1/phpclaw/chat/stream')->getDefaults()['format'],
        );

        self::assertArrayNotHasKey('format', $this->routeFor('v1/phpclaw/chat')->getDefaults());
    }

    public function test_the_stream_route_is_registered_before_the_send_route(): void
    {
        $routes = $this->registeredRoutes();

        self::assertSame('v1/phpclaw/chat/stream', $routes[0]->getPattern());
        self::assertSame('v1/phpclaw/chat', $routes[1]->getPattern());
    }

    public function test_it_registers_on_the_joomla_4_event_shape(): void
    {
        $router = new ApiRouter;

        (new PhpClaw([]))->onBeforeApiRoute(new Event('onBeforeApiRoute', [$router, null]));

        self::assertCount(2, $router->routes);
    }

    public function test_it_registers_on_the_joomla_4_named_subject_shape(): void
    {
        $router = new ApiRouter;

        (new PhpClaw([]))->onBeforeApiRoute(new Event('onBeforeApiRoute', ['subject' => $router]));

        self::assertCount(2, $router->routes);
    }

    public function test_an_event_carrying_no_router_registers_nothing_instead_of_throwing(): void
    {
        (new PhpClaw([]))->onBeforeApiRoute(new Event('onBeforeApiRoute', ['subject' => null]));

        $this->expectNotToPerformAssertions();
    }
}
