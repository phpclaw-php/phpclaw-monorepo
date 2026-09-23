<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Writes the configured authentication providers onto the phpClaw REST routes.
 */
final class PhpClawApiRouteSubscriber extends RouteSubscriberBase
{
    private const SETTINGS = 'phpclaw.settings';

    private const SETTING_KEY = 'api_auth_providers';

    private const ROUTES = [
        'phpclaw.api.send',
        'phpclaw.api.stream',
    ];

    public const DEFAULT_PROVIDERS = ['basic_auth', 'cookie'];

    /**
     * Create a new PhpClawApiRouteSubscriber instance.
     *
     * @param  ConfigFactoryInterface  $configFactory  Drupal configuration factory.
     * @return void
     */
    public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

    /**
     * Set the `_auth` option on the phpClaw REST routes. Drupal accepts only the providers named
     * there, so a site running its own provider adds it through the setting rather than by patching.
     *
     * @param  RouteCollection  $collection  The route collection being built.
     * @return void
     */
    protected function alterRoutes(RouteCollection $collection): void
    {
        $providers = self::normalise(
            $this->configFactory->get(self::SETTINGS)->get(self::SETTING_KEY)
        );

        foreach (self::ROUTES as $name) {
            $route = $collection->get($name);

            if ($route !== null) {
                $route->setOption('_auth', $providers);
            }
        }
    }

    /**
     * Reduce a stored setting to a clean list of provider ids, falling back to the shipped default.
     *
     * @param  mixed  $stored  Raw configuration value.
     * @return list<string>
     */
    public static function normalise(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = preg_split('/[\s,]+/', $stored) ?: [];
        }

        if (! is_array($stored)) {
            return self::DEFAULT_PROVIDERS;
        }

        $providers = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $stored),
            static fn (string $id): bool => $id !== '',
        )));

        return $providers === [] ? self::DEFAULT_PROVIDERS : $providers;
    }
}
