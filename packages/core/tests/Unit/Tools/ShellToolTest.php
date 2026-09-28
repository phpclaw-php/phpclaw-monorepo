<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ShellDeniedException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Tools\ShellTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellToolTest extends TestCase
{
    private const SENSITIVE_DENIAL_REASONS = [
        'sensitive_file', 'sensitive_extension', 'sensitive_directory', 'sensitive_path',
    ];

    private ShellTool $tool;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->tool = new ShellTool;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function captureDenialReason(ShellTool $tool, string $command): ?string
    {
        $reason = null;

        HookRegistry::on('shell.denied', function (array $ctx) use (&$reason): void {
            $reason = $ctx['reason'];
        });

        try {
            $tool->execute(['command' => $command]);
        } catch (ShellDeniedException) {
        }

        return $reason;
    }

    public function test_name_returns_shell_exec(): void
    {
        $this->assertSame('shell_exec', $this->tool->name());
    }

    public function test_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }

    public function test_input_schema_has_required_command_property(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertContains('command', $schema['required']);
    }

    public function test_execute_runs_allowed_command(): void
    {
        $result = $this->tool->execute(['command' => 'pwd']);
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_execute_returns_string_output(): void
    {
        $result = $this->tool->execute(['command' => 'hostname']);
        $this->assertIsString($result);
        $this->assertGreaterThan(0, strlen($result));
    }

    public function test_execute_throws_when_command_is_empty(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['command' => '']);
    }

    public function test_execute_throws_when_command_key_missing(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute([]);
    }

    public function test_execute_throws_shell_denied_for_unlisted_command(): void
    {
        $this->expectException(ShellDeniedException::class);
        $this->tool->execute(['command' => 'git status']);
    }

    public function test_execute_throws_shell_denied_for_another_unlisted_command(): void
    {
        $this->expectException(ShellDeniedException::class);
        $this->tool->execute(['command' => 'node --version']);
    }

    public function test_custom_allowlist_permits_listed_command(): void
    {
        $tool = new ShellTool(allowlist: ['echo', 'pwd']);
        $result = $tool->execute(['command' => 'pwd']);
        $this->assertIsString($result);
    }

    public function test_custom_allowlist_blocks_default_commands_not_in_list(): void
    {
        $tool = new ShellTool(allowlist: ['echo']);

        $this->expectException(ShellDeniedException::class);
        $tool->execute(['command' => 'ls']);
    }

    #[DataProvider('hardBlockedCommandProvider')]
    public function test_hard_blocked_commands_are_always_denied(string $command): void
    {
        $tool = new ShellTool(allowlist: ['rm', 'sudo', 'chmod', 'kill', 'wget', 'curl']);

        $this->expectException(ShellDeniedException::class);
        $tool->execute(['command' => $command]);
    }

    public static function hardBlockedCommandProvider(): array
    {
        return [
            ['rm -rf /tmp/test'],
            ['sudo apt-get update'],
            ['chmod 777 /etc/passwd'],
            ['chown root:root /tmp/x'],
            ['curl https://example.com'],
            ['wget https://example.com'],
            ['kill -9 1'],
            ['dd if=/dev/zero of=/dev/sda'],
            ['reboot'],
            ['shutdown -h now'],
            ['su root'],
            ['bash -c "id"'],
            ['sh -c "whoami"'],
            ['zsh --version'],
            ['nc -l 4444'],
            ['ncat 127.0.0.1 443'],
            ['ssh user@host'],
            ['scp file user@host:/tmp'],
            ['mv /etc/passwd /tmp/stolen'],
            ['shred /var/log/auth.log'],
            ['nohup ./evil.sh'],
            ['npx some-package'],
            ['npm install'],
            ['pip install requests'],
            ['pip3 install requests'],
        ];
    }

    public function test_semicolon_is_rejected(): void
    {
        $tool = new ShellTool(allowlist: ['pwd']);

        $this->expectException(ShellDeniedException::class);
        $tool->execute(['command' => 'pwd; echo injected']);
    }

    public function test_pipe_is_rejected(): void
    {
        $tool = new ShellTool(allowlist: ['pwd']);

        $this->expectException(ShellDeniedException::class);
        $tool->execute(['command' => 'pwd | cat /etc/passwd']);
    }

    public function test_backtick_is_rejected(): void
    {
        $tool = new ShellTool(allowlist: ['pwd']);

        $this->expectException(ShellDeniedException::class);
        $tool->execute(['command' => 'pwd`id`']);
    }

    public function test_shell_denied_exception_is_tool_exception(): void
    {
        try {
            $this->tool->execute(['command' => 'rm -rf /']);
        } catch (ShellDeniedException $e) {
            $this->assertInstanceOf(ToolException::class, $e);

            return;
        }

        $this->fail('Expected ShellDeniedException was not thrown');
    }

    public function test_shell_denied_hook_fires_on_hard_block(): void
    {
        $fired = false;

        HookRegistry::on('shell.denied', function (array $ctx) use (&$fired): void {
            $fired = true;
            $this->assertSame('hard_blocked', $ctx['reason']);
            $this->assertSame('rm', $ctx['cmd_name']);
        });

        try {
            $this->tool->execute(['command' => 'rm -rf /']);
        } catch (ShellDeniedException) {
        }

        $this->assertTrue($fired, 'shell.denied hook should have fired for hard-blocked command');
    }

    public function test_shell_denied_hook_fires_on_allowlist_miss(): void
    {
        $fired = false;

        HookRegistry::on('shell.denied', function (array $ctx) use (&$fired): void {
            $fired = true;
            $this->assertSame('not_in_allowlist', $ctx['reason']);
        });

        try {
            $this->tool->execute(['command' => 'git status']);
        } catch (ShellDeniedException) {
        }

        $this->assertTrue($fired, 'shell.denied hook should have fired for allowlist miss');
    }

    public function test_shell_exec_hook_fires_on_success(): void
    {
        $fired = false;

        HookRegistry::on('shell.exec', function (array $ctx) use (&$fired): void {
            $fired = true;
            $this->assertSame('pwd', $ctx['cmd_name']);
            $this->assertArrayHasKey('command', $ctx);
        });

        $this->tool->execute(['command' => 'pwd']);

        $this->assertTrue($fired, 'shell.exec hook should have fired after successful execution');
    }

    public function test_shell_exec_hook_does_not_fire_on_denied_command(): void
    {
        $execFired = false;

        HookRegistry::on('shell.exec', function () use (&$execFired): void {
            $execFired = true;
        });

        try {
            $this->tool->execute(['command' => 'rm -rf /']);
        } catch (ShellDeniedException) {
        }

        $this->assertFalse($execFired, 'shell.exec hook must not fire for blocked commands');
    }

    #[DataProvider('sensitiveFileProvider')]
    public function test_file_reading_commands_block_sensitive_files(string $command): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);

        $reason = $this->captureDenialReason($tool, $command);

        $this->assertContains($reason, self::SENSITIVE_DENIAL_REASONS);
    }

    public static function sensitiveFileProvider(): array
    {
        return [
            ['cat wp-config.php'],
            ['head -20 wp-config.php'],
            ['tail wp-config.php'],
            ['grep PASSWORD wp-config.php'],
            ['cat .env'],
            ['cat .env.local'],
            ['cat .env.production'],
            ['grep API_KEY .env'],
            ['head .env'],
            ['cat configuration.php'],
            ['cat database.php'],
            ['grep password database.php'],
            ['cat settings.php'],
            ['cat server.key'],
            ['cat certificate.pem'],
            ['cat id_rsa'],
            ['cat credentials.json'],
            ['cat wp-config.php.bak'],
            ['cat wp-config.php~'],
            ['cat id_rsa.bak'],
            ['cat id_rsa~'],
            ['cat credentials.json.save'],
            ['cat server.key.bak'],
            ['cat configuration.php.orig'],
            ['cat /etc/passwd'],
            ['cat /etc/shadow'],
            ['grep root /etc/passwd'],
            ['head /etc/sudoers'],
            ['cat .git/config'],
            ['cat .ssh/id_rsa'],
            ['grep key .aws/credentials'],
            ['cat database.sqlite'],
            ['cat app.db'],
            ['cat docker-compose.yml'],
        ];
    }

    #[DataProvider('sharedBlockedPathsFilenameProvider')]
    public function test_it_blocks_filenames_only_present_in_the_shared_blocked_paths_list(string $command): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);

        $reason = $this->captureDenialReason($tool, $command);

        $this->assertContains($reason, self::SENSITIVE_DENIAL_REASONS);
    }

    public static function sharedBlockedPathsFilenameProvider(): array
    {
        return [
            ['cat settings.inc.php'],
            ['cat .envrc'],
        ];
    }

    public function test_cat_safe_file_is_allowed(): void
    {
        $tool = new ShellTool(allowlist: ['cat']);
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw_');

        try {
            $result = $tool->execute(['command' => 'cat '.$file]);
            $this->assertIsString($result);
        } finally {
            @unlink($file);
        }
    }

    public function test_grep_on_safe_path_is_allowed(): void
    {
        $tool = new ShellTool(allowlist: ['grep']);
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw_');

        try {
            $result = $tool->execute(['command' => 'grep -c x '.$file]);
            $this->assertIsString($result);
        } finally {
            @unlink($file);
        }
    }

    public function test_sensitive_file_hook_fires(): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);
        $fired = false;

        HookRegistry::on('shell.denied', function (array $ctx) use (&$fired): void {
            $fired = true;
            $this->assertSame('sensitive_file', $ctx['reason']);
            $this->assertArrayHasKey('file', $ctx);
        });

        try {
            $tool->execute(['command' => 'cat wp-config.php']);
        } catch (ShellDeniedException) {
        }

        $this->assertTrue($fired, 'shell.denied hook should fire with sensitive_file reason');
    }

    public function test_flags_are_not_treated_as_filenames(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw_');

        try {
            $result = $this->tool->execute(['command' => 'wc -l '.$file]);
            $this->assertIsString($result);
        } finally {
            @unlink($file);
        }
    }

    public function test_dev_path_is_blocked(): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);

        $reason = $this->captureDenialReason($tool, 'cat /dev/mem');

        $this->assertContains($reason, self::SENSITIVE_DENIAL_REASONS);
    }

    public function test_php_command_is_denied(): void
    {
        $this->expectException(ShellDeniedException::class);
        $this->tool->execute(['command' => 'php --version']);
    }

    public function test_composer_command_is_denied(): void
    {
        $this->expectException(ShellDeniedException::class);
        $this->tool->execute(['command' => 'composer --version']);
    }

    public function test_relative_traversal_to_proc_is_denied(): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);

        $reason = $this->captureDenialReason($tool, 'cat ../../proc/self/environ');

        $this->assertContains($reason, self::SENSITIVE_DENIAL_REASONS);
    }

    public function test_symlink_to_sensitive_file_is_denied(): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);
        $link = sys_get_temp_dir().'/phpclaw_shell_link_'.getmypid();
        @unlink($link);

        if (! @symlink('/etc/passwd', $link)) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        try {
            $reason = $this->captureDenialReason($tool, 'cat '.$link);
        } finally {
            @unlink($link);
        }

        $this->assertContains($reason, self::SENSITIVE_DENIAL_REASONS);
    }

    public function test_array_form_executes_multiarg_command(): void
    {
        $tool = new ShellTool(allowlist: ['echo']);
        $result = $tool->execute(['command' => 'echo hello world']);
        $this->assertStringContainsString('hello', $result);
    }

    public function test_flag_embedded_sensitive_path_is_blocked(): void
    {
        $this->expectException(ShellDeniedException::class);
        $this->tool->execute(['command' => 'wc --files0-from=/etc/passwd']);
    }

    public function test_flag_embedded_env_file_is_blocked(): void
    {
        $tool = new ShellTool(allowlist: ['cat', 'head', 'tail', 'grep']);

        $reason = $this->captureDenialReason($tool, 'grep --file=.env.local');

        $this->assertContains($reason, self::SENSITIVE_DENIAL_REASONS);
    }

    public function test_flag_with_glob_value_is_not_blocked(): void
    {
        $tool = new ShellTool(allowlist: ['grep']);
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw_');
        try {
            $result = $tool->execute(['command' => 'grep --color=auto x '.$file]);
            $this->assertIsString($result);
        } finally {
            @unlink($file);
        }
    }

    public function test_pure_flag_with_no_equals_is_still_skipped(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw_');
        try {
            $result = $this->tool->execute(['command' => 'wc -l '.$file]);
            $this->assertIsString($result);
        } finally {
            @unlink($file);
        }
    }

    public function test_assert_path_not_sensitive_parity_across_rule_categories(): void
    {
        $ref = new \ReflectionMethod(ShellTool::class, 'assertPathNotSensitive');
        $ref->setAccessible(true);

        $blocked = [
            'wp-config.php' => "Access to 'wp-config.php' is blocked: contains sensitive configuration.",
            'secret.pem' => "Access to '.pem' files is blocked: may contain secrets.",
            '.env.local' => "Access to '.env.local' is blocked: environment files contain secrets.",
            '.git/config' => "Access to files in '.git/' is blocked.",
            '/etc/passwd' => "Access to '/etc/passwd' is blocked: system file.",
            '/proc/self/environ' => "Access to '/proc/self/environ' is blocked: system file.",
        ];

        foreach ($blocked as $token => $expectedMessage) {
            try {
                $ref->invoke($this->tool, 'cat '.$token, 'cat', $token);
                $this->fail("Expected ShellDeniedException for token '{$token}'");
            } catch (ShellDeniedException $e) {
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }

        foreach (['README.md', '/tmp/ordinary-file.txt'] as $clean) {
            $ref->invoke($this->tool, 'cat '.$clean, 'cat', $clean);
        }
        $this->assertTrue(true, 'clean tokens must not throw');
    }

    public function test_bare_ls_still_runs_on_default_allowlist(): void
    {
        $result = $this->tool->execute(['command' => 'ls']);
        $this->assertIsString($result);
    }

    public function test_path_traversal_program_token_is_denied(): void
    {
        $binary = is_executable('/bin/echo') ? '/bin/echo' : '/bin/pwd';

        $tempDir = sys_get_temp_dir().'/phpclaw_shell_traversal_'.getmypid();
        @mkdir($tempDir.'/ls', 0755, true);

        $expectedTarget = realpath($binary);
        $token = null;

        for ($climb = 0; $climb <= 20; $climb++) {
            $candidate = 'ls/../'.str_repeat('../', $climb).ltrim($binary, '/');

            if (realpath($tempDir.'/'.$candidate) === $expectedTarget) {
                $token = $candidate;

                break;
            }
        }

        if ($token === null) {
            @rmdir($tempDir.'/ls');
            @rmdir($tempDir);
            self::markTestSkipped('Could not construct a resolvable traversal token on this platform.');
        }

        $originalCwd = (string) getcwd();
        chdir($tempDir);

        $reason = null;
        HookRegistry::on('shell.denied', function (array $ctx) use (&$reason): void {
            $reason = $ctx['reason'];
        });

        try {
            $this->tool->execute(['command' => (string) $token.' BYPASS']);
            $this->fail('Expected ShellDeniedException for a traversal program token');
        } catch (ShellDeniedException) {
        } finally {
            chdir($originalCwd);
            @rmdir($tempDir.'/ls');
            @rmdir($tempDir);
        }

        $this->assertSame('not_in_allowlist', $reason);
    }

    public function test_uppercase_program_token_is_denied(): void
    {
        $tool = new ShellTool(allowlist: ['ls']);

        $reason = null;
        HookRegistry::on('shell.denied', function (array $ctx) use (&$reason): void {
            $reason = $ctx['reason'];
        });

        try {
            $tool->execute(['command' => 'LS']);
            $this->fail('Expected ShellDeniedException for an uppercase program token');
        } catch (ShellDeniedException) {
        }

        $this->assertSame('not_in_allowlist', $reason);
    }

    public static function hardBlockedVariantProvider(): array
    {
        return [
            'upper case' => ['RM'],
            'mixed case' => ['Rm'],
            'absolute path' => ['/bin/rm'],
        ];
    }

    #[DataProvider('hardBlockedVariantProvider')]
    public function test_hard_blocked_command_is_refused_even_when_a_variant_is_allowlisted(string $program): void
    {
        $tool = new ShellTool(allowlist: [$program]);

        $reason = null;
        HookRegistry::on('shell.denied', function (array $ctx) use (&$reason): void {
            $reason = $ctx['reason'];
        });

        try {
            $tool->execute(['command' => $program.' phpclaw-missing-file']);
            $this->fail('Expected ShellDeniedException for a hard-blocked program variant');
        } catch (ShellDeniedException) {
        }

        $this->assertSame('hard_blocked', $reason);
    }

    #[DataProvider('defaultDeniedFileReadingCommandProvider')]
    public function test_default_allowlist_refuses_file_reading_commands(string $command): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw_');

        $reason = null;
        HookRegistry::on('shell.denied', function (array $ctx) use (&$reason): void {
            $reason = $ctx['reason'];
        });

        try {
            $this->tool->execute(['command' => $command.' '.$file]);
            $this->fail('Expected ShellDeniedException for: '.$command);
        } catch (ShellDeniedException) {
        } finally {
            @unlink($file);
        }

        $this->assertSame('not_in_allowlist', $reason);
    }

    public static function defaultDeniedFileReadingCommandProvider(): array
    {
        return [
            ['cat'],
            ['head'],
            ['tail'],
            ['grep'],
        ];
    }

    public function test_readonly_commands_still_include_file_readers(): void
    {
        $tool = new ShellTool(allowlist: ['grep']);

        $this->assertFalse($tool->isMutating(['command' => 'grep x f']));
    }
}
