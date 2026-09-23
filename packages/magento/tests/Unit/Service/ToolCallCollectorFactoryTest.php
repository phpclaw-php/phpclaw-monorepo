<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\ToolCallCollector;
use PhpClaw\Magento\Service\ToolCallCollectorFactory;
use PHPUnit\Framework\TestCase;

final class ToolCallCollectorFactoryTest extends TestCase
{
    public function test_create_returns_a_fresh_collector_on_every_call(): void
    {
        $factory = new ToolCallCollectorFactory;

        $first = $factory->create();
        $second = $factory->create();

        self::assertInstanceOf(ToolCallCollector::class, $first);
        self::assertNotSame($first, $second, 'each turn must get its own collector');
    }

    public function test_create_returns_a_fresh_instance_on_each_call(): void
    {
        $factory = new ToolCallCollectorFactory;

        self::assertNotSame($factory->create(), $factory->create());
    }
}
