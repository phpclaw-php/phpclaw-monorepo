<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\Plugin;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    public function test_helper_with_null_returns_a_cached_plugin(): void
    {
        if (! function_exists('phpclaw_agent')) {
            require_once __DIR__.'/../../src/helpers.php';
        }

        self::assertInstanceOf(Plugin::class, phpclaw_agent());
        self::assertSame(
            phpclaw_agent(),
            phpclaw_agent(),
            'the helper holds its own static instance, so repeat calls must return the same object',
        );
    }
}
