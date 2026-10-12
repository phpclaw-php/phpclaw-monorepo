<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\CLI;

require_once __DIR__.'/Fixtures/wp-cli-stub.php';

use Brain\Monkey;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

final class CommandRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $GLOBALS['phpclaw_test_cli_commands'] = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function registered(): array
    {
        \WP_CLI::add_command('phpclaw_probe', static fn () => null);
        if (! isset($GLOBALS['phpclaw_test_cli_commands'][0]['args'])) {
            self::markTestSkipped('another test file declared a WP_CLI stub that does not record arguments');
        }
        $GLOBALS['phpclaw_test_cli_commands'] = [];

        $plugin = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod($plugin, 'registerCliCommands'))->invoke($plugin);

        $byName = [];
        foreach ($GLOBALS['phpclaw_test_cli_commands'] as $command) {
            $byName[$command['name']] = array_column($command['args']['synopsis'] ?? [], 'name');
        }

        return $byName;
    }

    public function test_send_chat_and_mcp_server_are_registered(): void
    {
        self::assertSame(['phpclaw send', 'phpclaw chat', 'phpclaw mcp-server'], array_keys($this->registered()));
    }

    public function test_send_declares_the_session_options(): void
    {
        $synopsis = $this->registered()['phpclaw send'];

        foreach (['message', 'provider', 'model', 'stream', 'conv-id', 'continue', 'trace'] as $option) {
            self::assertContains($option, $synopsis, $option);
        }
    }

    public function test_chat_declares_its_resume_options(): void
    {
        self::assertSame(['conv-id', 'continue'], $this->registered()['phpclaw chat']);
    }
}
