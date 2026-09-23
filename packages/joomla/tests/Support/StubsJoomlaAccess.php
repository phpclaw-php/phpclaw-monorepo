<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Support;

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;

trait StubsJoomlaAccess
{
    protected function grantJoomlaAccess(): void
    {
        Factory::$application = $this->appWithIdentity(true);
    }

    protected function denyJoomlaAccess(): void
    {
        Factory::$application = $this->appWithIdentity(false);
    }

    protected function consoleJoomlaAccess(): void
    {
        $console = (new \ReflectionClass(ConsoleApplication::class))->newInstanceWithoutConstructor();

        Factory::$application = $console;
    }

    protected function clearJoomlaAccess(): void
    {
        Factory::$application = null;
    }

    protected function assertForbiddenEnvelope(string $json): void
    {
        $result = json_decode($json, true);

        self::assertFalse($result['success']);
        self::assertSame('FORBIDDEN', $result['error']['code']);
        self::assertNull($result['data']);
        self::assertSame('error', $result['meta']['mode']);
    }

    private function appWithIdentity(bool $authorised): object
    {
        $identity = new class($authorised)
        {
            public function __construct(private bool $authorised) {}

            public function authorise(string $action, ?string $asset = null): bool
            {
                return $this->authorised;
            }
        };

        return new class($identity)
        {
            public function __construct(private object $identity) {}

            public function getIdentity(): object
            {
                return $this->identity;
            }
        };
    }
}
