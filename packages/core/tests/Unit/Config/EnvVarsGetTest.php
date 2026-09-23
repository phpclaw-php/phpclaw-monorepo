<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Config;

use PhpClaw\Config\EnvVars;
use PHPUnit\Framework\TestCase;

final class EnvVarsGetTest extends TestCase
{
    private const VAR = 'PHPCLAW_TEST_ENVVAR_ZZ';

    protected function setUp(): void
    {
        unset($_ENV[self::VAR], $_SERVER[self::VAR]);
        putenv(self::VAR);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::VAR], $_SERVER[self::VAR]);
        putenv(self::VAR);
    }

    public function test_returns_default_when_unset(): void
    {
        $this->assertSame('', EnvVars::get(self::VAR));
        $this->assertSame('fallback', EnvVars::get(self::VAR, 'fallback'));
    }

    public function test_reads_from_env_superglobal(): void
    {
        $_ENV[self::VAR] = 'from-env';
        $this->assertSame('from-env', EnvVars::get(self::VAR));
    }

    public function test_env_takes_precedence_over_server(): void
    {
        $_ENV[self::VAR] = 'from-env';
        $_SERVER[self::VAR] = 'from-server';
        $this->assertSame('from-env', EnvVars::get(self::VAR));
    }

    public function test_empty_env_value_falls_through_to_server(): void
    {
        $_ENV[self::VAR] = '';
        $_SERVER[self::VAR] = 'from-server';
        $this->assertSame('from-server', EnvVars::get(self::VAR));
    }

    public function test_falls_through_to_getenv_when_superglobals_empty(): void
    {
        $_ENV[self::VAR] = '';
        $_SERVER[self::VAR] = '';
        putenv(self::VAR.'=from-getenv');
        $this->assertSame('from-getenv', EnvVars::get(self::VAR));
    }

    public function test_empty_everywhere_returns_default(): void
    {
        $_ENV[self::VAR] = '';
        $_SERVER[self::VAR] = '';
        $this->assertSame('DEF', EnvVars::get(self::VAR, 'DEF'));
    }
}
