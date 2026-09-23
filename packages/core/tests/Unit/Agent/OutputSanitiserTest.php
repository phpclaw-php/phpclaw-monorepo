<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\OutputSanitiser;
use PhpClaw\Hooks\HookRegistry;
use PHPUnit\Framework\TestCase;

final class OutputSanitiserTest extends TestCase
{
    private OutputSanitiser $sanitiser;

    protected function setUp(): void
    {
        $this->sanitiser = new OutputSanitiser;
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_clean_text_returned_unchanged(): void
    {
        $result = $this->sanitiser->sanitise('The answer is 42.');
        $this->assertSame('The answer is 42.', $result);
    }

    public function test_empty_string_returned_unchanged(): void
    {
        $this->assertSame('', $this->sanitiser->sanitise(''));
    }

    public function test_strips_php_open_tag(): void
    {
        $result = $this->sanitiser->sanitise('Here is code: <?php echo "hi"; ?>');
        $this->assertStringNotContainsString('<?php', $result);
        $this->assertStringContainsString('[PHP_REMOVED]', $result);
    }

    public function test_strips_php_short_echo_tag(): void
    {
        $result = $this->sanitiser->sanitise('Output: <?= $var ?>');
        $this->assertStringNotContainsString('<?=', $result);
        $this->assertStringContainsString('[PHP_REMOVED]', $result);
    }

    public function test_strips_php_close_tag(): void
    {
        $result = $this->sanitiser->sanitise('End template ?> inject here');
        $this->assertStringNotContainsString('?>', $result);
        $this->assertStringContainsString('[PHP_REMOVED]', $result);
    }

    public function test_strips_multiple_php_tags(): void
    {
        $result = $this->sanitiser->sanitise('<?php $x = 1; ?> done');
        $this->assertStringNotContainsString('<?php', $result);
        $this->assertStringNotContainsString('?>', $result);
    }

    public function test_redacts_eval(): void
    {
        $result = $this->sanitiser->sanitise('You can use eval(\'malicious code\')');
        $this->assertStringNotContainsStringIgnoringCase('eval(', $result);
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_redacts_system(): void
    {
        $result = $this->sanitiser->sanitise('Call system("ls -la")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_redacts_exec(): void
    {
        $result = $this->sanitiser->sanitise('Use exec("whoami")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_redacts_shell_exec(): void
    {
        $result = $this->sanitiser->sanitise('Try shell_exec("id")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_redacts_passthru(): void
    {
        $result = $this->sanitiser->sanitise('Use passthru("cat /etc/passwd")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_redacts_proc_open(): void
    {
        $result = $this->sanitiser->sanitise('Use proc_open("cmd", [], $pipes)');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_redacts_pcntl_exec(): void
    {
        $result = $this->sanitiser->sanitise('Call pcntl_exec("/bin/sh")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_opening_paren_preserved_after_redaction(): void
    {
        $result = $this->sanitiser->sanitise('eval("bad code")');
        $this->assertStringContainsString('[REDACTED]("bad code")', $result);
    }

    public function test_case_insensitive_redaction(): void
    {
        $result = $this->sanitiser->sanitise('EVAL("code")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_mixed_case_redaction(): void
    {
        $result = $this->sanitiser->sanitise('Shell_Exec("whoami")');
        $this->assertStringContainsString('[REDACTED](', $result);
    }

    public function test_php_tag_hook_fires(): void
    {
        $fired = false;
        HookRegistry::on('guard.output_php_tag_removed', function () use (&$fired): void {
            $fired = true;
        });

        $this->sanitiser->sanitise('<?php echo "hi";');
        $this->assertTrue($fired);
    }

    public function test_php_tag_hook_receives_tag(): void
    {
        $tag = null;
        HookRegistry::on('guard.output_php_tag_removed', function (array $ctx) use (&$tag): void {
            $tag = $ctx['tag'];
        });

        $this->sanitiser->sanitise('<?php echo "hi";');
        $this->assertSame('<?php', $tag);
    }

    public function test_function_redaction_hook_fires(): void
    {
        $fired = false;
        HookRegistry::on('guard.output_function_redacted', function () use (&$fired): void {
            $fired = true;
        });

        $this->sanitiser->sanitise('eval("bad")');
        $this->assertTrue($fired);
    }

    public function test_function_redaction_hook_receives_function_name(): void
    {
        $fn = null;
        HookRegistry::on('guard.output_function_redacted', function (array $ctx) use (&$fn): void {
            $fn = $ctx['function'];
        });

        $this->sanitiser->sanitise('system("ls")');
        $this->assertSame('system(', $fn);
    }

    public function test_no_hook_fires_for_clean_text(): void
    {
        $fired = false;
        HookRegistry::on('guard.output_php_tag_removed', function () use (&$fired): void {
            $fired = true;
        });
        HookRegistry::on('guard.output_function_redacted', function () use (&$fired): void {
            $fired = true;
        });

        $this->sanitiser->sanitise('The server uptime is 3 days.');
        $this->assertFalse($fired);
    }

    public function test_disabled_sanitiser_returns_dangerous_text_unchanged(): void
    {
        $sanitiser = new OutputSanitiser(false);
        $dangerous = 'eval("bad") <?php echo 1; ?> system("ls")';

        $this->assertSame($dangerous, $sanitiser->sanitise($dangerous));
    }

    public function test_disabled_sanitiser_fires_no_hooks(): void
    {
        $fired = false;
        HookRegistry::on('guard.output_php_tag_removed', function () use (&$fired): void {
            $fired = true;
        });
        HookRegistry::on('guard.output_function_redacted', function () use (&$fired): void {
            $fired = true;
        });

        $sanitiser = new OutputSanitiser(false);
        $sanitiser->sanitise('eval("bad") <?php echo 1; ?>');

        $this->assertFalse($fired);
    }

    public function test_enabled_true_is_default_behaviour(): void
    {
        $default = new OutputSanitiser;
        $explicit = new OutputSanitiser(true);

        $text = 'eval("test")';

        $this->assertSame($default->sanitise($text), $explicit->sanitise($text));
        $this->assertStringContainsString('[REDACTED](', $default->sanitise($text));
    }
}
