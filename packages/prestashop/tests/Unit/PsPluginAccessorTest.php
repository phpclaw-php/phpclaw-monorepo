<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit;

use PhpClaw\PrestaShop\Db\PsDbAdapter;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\PrestaShop\PsPluginAccessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsPluginAccessor::class)]
final class PsPluginAccessorTest extends TestCase
{
    private function newSubject(): object
    {
        return new class
        {
            use PsPluginAccessor;

            public function callGetDb(): PsDbAdapter
            {
                return $this->getDb();
            }

            public function callGetPlugin(): Plugin
            {
                return $this->getPlugin();
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        \Db::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        \Db::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    public function test_get_db_wraps_the_native_db_singleton(): void
    {
        $native = \Db::getInstance();

        $adapter = $this->newSubject()->callGetDb();

        $ref = new \ReflectionProperty(PsDbAdapter::class, 'db');
        $ref->setAccessible(true);

        self::assertSame($native, $ref->getValue($adapter));
    }

    public function test_get_db_reuses_the_same_adapter_across_calls_on_the_same_instance(): void
    {
        $subject = $this->newSubject();

        self::assertSame($subject->callGetDb(), $subject->callGetDb());
    }

    public function test_get_db_builds_a_fresh_adapter_per_object_instance(): void
    {
        $first = $this->newSubject()->callGetDb();
        $second = $this->newSubject()->callGetDb();

        self::assertNotSame($first, $second);
    }

    public function test_get_plugin_returns_the_process_wide_singleton(): void
    {
        $viaAccessor = $this->newSubject()->callGetPlugin();
        $viaDirect = Plugin::getInstance();

        self::assertSame($viaDirect, $viaAccessor);
    }

    public function test_get_plugin_shares_the_singleton_across_different_accessor_instances(): void
    {
        $first = $this->newSubject()->callGetPlugin();
        $second = $this->newSubject()->callGetPlugin();

        self::assertSame($first, $second);
    }
}
