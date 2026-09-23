<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Api;

use PhpClaw\Magento\Api\Data\SendResponseInterface;
use PhpClaw\Magento\Api\Data\StreamResponseInterface;
use PhpClaw\Magento\Api\SendInterface;
use PhpClaw\Magento\Api\StreamInterface;
use PHPUnit\Framework\TestCase;

final class ApiContractWiringTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_every_web_api_route_binds_to_an_api_interface_method(): void
    {
        preg_match_all(
            '/<service class="([^"]+)" method="([^"]+)"\/>/',
            $this->read('etc/webapi.xml'),
            $m,
        );

        self::assertSame([SendInterface::class, StreamInterface::class], $m[1]);
        self::assertSame(['send', 'stream'], $m[2]);

        foreach ($m[1] as $i => $interface) {
            self::assertTrue(
                method_exists($interface, $m[2][$i]),
                $interface.'::'.$m[2][$i].'() is routed but does not exist',
            );
        }
    }

    public function test_each_api_interface_has_a_di_preference_to_a_real_implementation(): void
    {
        preg_match_all(
            '/<preference for="(PhpClaw\\\\Magento\\\\Api\\\\[^"]+)"\s*type="([^"]+)"/',
            $this->read('etc/di.xml'),
            $m,
        );

        self::assertSame([
            SendInterface::class,
            StreamInterface::class,
            SendResponseInterface::class,
            StreamResponseInterface::class,
        ], $m[1]);

        foreach ($m[1] as $i => $interface) {
            $implementation = $m[2][$i];

            self::assertTrue(class_exists($implementation), $implementation.' is preferred but does not exist');
            self::assertContains(
                $interface,
                class_implements($implementation) ?: [],
                $implementation.' is preferred for '.$interface.' but does not implement it',
            );
        }
    }

    public function test_send_returns_the_send_response_contract(): void
    {
        $method = new \ReflectionMethod(SendInterface::class, 'send');

        self::assertSame(SendResponseInterface::class, (string) $method->getReturnType());
        self::assertSame(['message'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        ));
    }

    public function test_stream_returns_the_stream_response_contract(): void
    {
        $method = new \ReflectionMethod(StreamInterface::class, 'stream');

        self::assertSame(StreamResponseInterface::class, (string) $method->getReturnType());
        self::assertSame(['message', 'conversationId'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        ));
    }
}
