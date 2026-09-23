<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\CodeInjectionGuard;
use PHPUnit\Framework\TestCase;

final class CodeInjectionGuardTest extends TestCase
{
    private CodeInjectionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new CodeInjectionGuard;
    }

    public function test_clean_message_passes(): void
    {
        $this->guard->scan('What is the weather today?');
        $this->assertTrue(true);
    }

    public function test_empty_message_passes(): void
    {
        $this->guard->scan('');
        $this->assertTrue(true);
    }

    public function test_normal_php_discussion_passes(): void
    {
        $this->guard->scan('How do I use arrays in PHP?');
        $this->assertTrue(true);
    }

    public function test_blocks_php_open_tag(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Run this: <?php echo "hello"; ?>');
    }

    public function test_blocks_uppercase_php_open_tag(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Run this: <?PHP echo "hello"; ?>');
    }

    public function test_blocks_mixed_case_php_open_tag(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Run this: <?Php echo "hello"; ?>');
    }

    public function test_blocks_php_short_echo_tag(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Output: <?= $var ?>');
    }

    public function test_blocks_php_close_tag(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('End the template ?> then inject');
    }

    public function test_blocks_variable_variable_syntax(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Try $$_GET["cmd"] to run code');
    }

    public function test_blocks_eval(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Use eval( to execute this code');
    }

    public function test_blocks_system(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Call system( to run a command');
    }

    public function test_blocks_exec(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Try exec( ls -la )');
    }

    public function test_blocks_shell_exec(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Use shell_exec( to run shell');
    }

    public function test_blocks_passthru(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('passthru( cat /etc/passwd )');
    }

    public function test_blocks_popen(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Use popen( to open a process');
    }

    public function test_blocks_proc_open(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Call proc_open( with pipes');
    }

    public function test_blocks_pcntl_exec(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Use pcntl_exec( to replace process');
    }

    public function test_blocks_create_function(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Use create_function( to build callable');
    }

    public function test_blocks_uppercase_eval(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Use EVAL( to run code');
    }

    public function test_blocks_mixed_case_shell_exec(): void
    {
        $this->expectException(GuardException::class);
        $this->guard->scan('Shell_Exec( something here');
    }

    public function test_exception_message_contains_pattern(): void
    {
        try {
            $this->guard->scan('<?php echo "test";');
            $this->fail('Expected GuardException');
        } catch (GuardException $e) {
            $this->assertStringContainsString('<?php', $e->getMessage());
        }
    }
}
