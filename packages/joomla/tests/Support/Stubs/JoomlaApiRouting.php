<?php

declare(strict_types=1);

namespace Joomla\Router {
    class Route
    {
        public function __construct(
            private array $methods,
            private string $pattern,
            private mixed $controller,
            private array $rules = [],
            private array $defaults = [],
        ) {}

        public function getMethods(): array
        {
            return $this->methods;
        }

        public function getPattern(): string
        {
            return $this->pattern;
        }

        public function getController(): mixed
        {
            return $this->controller;
        }

        public function getRules(): array
        {
            return $this->rules;
        }

        public function getDefaults(): array
        {
            return $this->defaults;
        }
    }
}

namespace Joomla\CMS\Router {

    class ApiRouter
    {
        public array $routes = [];

        public function addRoutes(array $routes): void
        {
            foreach ($routes as $route) {
                $this->routes[] = $route;
            }
        }

        public function createCRUDRoutes($baseName, $controller, $defaults = [], $publicGets = false): void
        {
            throw new \LogicException('createCRUDRoutes must not be used for the phpClaw chat routes.');
        }
    }
}

namespace Joomla\CMS\Event\Application {
    use Joomla\CMS\Router\ApiRouter;
    use Joomla\Event\Event;

    class BeforeApiRouteEvent extends Event
    {
        public function __construct(private ApiRouter $router)
        {
            parent::__construct('onBeforeApiRoute', ['subject' => $router]);
        }

        public function getRouter(): ApiRouter
        {
            return $this->router;
        }
    }
}

namespace Joomla\CMS\Plugin {
    class CMSPlugin
    {
        protected ?object $application = null;

        public function __construct(array $config = []) {}

        public function setApplication(?object $application): void
        {
            $this->application = $application;
        }
    }
}
