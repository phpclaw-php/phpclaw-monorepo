<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\WebServices\Extension;

use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

/**
 * Registers the phpClaw chat routes on Joomla's Web Services API.
 */
final class PhpClaw extends CMSPlugin implements SubscriberInterface
{
    private const COMPONENT = 'com_phpclaw';

    private const SEND_ROUTE = 'v1/phpclaw/chat';

    private const STREAM_ROUTE = 'v1/phpclaw/chat/stream';

    private const STREAM_FORMAT = 'text/event-stream';

    private const ERROR_FORMAT = 'jsonapi';

    /**
     * Events this subscriber listens to.
     *
     * @return array<string, string> Event name mapped to handler method.
     */
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    /**
     * Register the two chat routes, and render Joomla's own errors on the stream route as JSON:API, like the send route.
     *
     * @param  EventInterface  $event  Routing event carrying the api router.
     * @return void
     */
    public function onBeforeApiRoute(EventInterface $event): void
    {
        $router = self::routerFrom($event);

        if ($router === null) {
            return;
        }

        $defaults = ['component' => self::COMPONENT, 'public' => false];

        $router->addRoutes([
            new Route(
                ['POST'],
                self::STREAM_ROUTE,
                'chat.stream',
                [],
                $defaults + ['format' => [self::STREAM_FORMAT]],
            ),
            new Route(
                ['POST'],
                self::SEND_ROUTE,
                'chat.send',
                [],
                $defaults,
            ),
        ]);

        $application = $this->getApplication();

        if ($application instanceof ApiApplication) {
            $application->addFormatMap(self::STREAM_FORMAT, self::ERROR_FORMAT);
        }
    }

    /**
     * Read the api router out of the routing event. Joomla 5 and 6 pass a typed event exposing
     * getRouter(); Joomla 4 dispatches a plain event carrying the router as its first argument.
     *
     * @param  EventInterface  $event  Routing event.
     * @return ?ApiRouter Router to register on, or null when the event carries none.
     */
    private static function routerFrom(EventInterface $event): ?ApiRouter
    {
        if (method_exists($event, 'getRouter')) {
            $router = $event->getRouter();

            return $router instanceof ApiRouter ? $router : null;
        }

        foreach (['subject', 0] as $key) {
            $candidate = $event->getArgument($key);

            if ($candidate instanceof ApiRouter) {
                return $candidate;
            }
        }

        return null;
    }
}
