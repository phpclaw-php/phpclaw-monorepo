<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Console;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use PhpClaw\Joomla\Component\Administrator\Console\McpServerCommand;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class McpServerCommandTest extends TestCase
{
    protected function setUp(): void
    {
        PluginHelper::$plugin = false;
        Factory::$application = null;
        Factory::$applicationError = null;
        Factory::$container = $this->containerWithDatabase();
    }

    protected function tearDown(): void
    {
        PluginHelper::$plugin = false;
        Factory::$application = null;
        Factory::$applicationError = null;
        Factory::$container = null;
    }

    public function test_the_command_is_named_phpclaw_mcp_server(): void
    {
        self::assertSame('phpclaw:mcp-server', (new McpServerCommand)->getName());
    }

    public function test_the_command_describes_the_stdio_transport(): void
    {
        self::assertSame(
            'Start the phpClaw MCP server (stdio transport).',
            (new McpServerCommand)->getDescription(),
        );
    }

    public function test_it_hands_a_tool_registry_to_the_transport_runner(): void
    {
        self::assertInstanceOf(ToolRegistry::class, $this->registryFromRun());
    }

    public function test_it_returns_a_success_exit_code(): void
    {
        $captured = null;
        $command = new McpServerCommand(function (ToolRegistry $registry) use (&$captured): void {
            $captured = $registry;
        });

        $exitCode = $command->execute(new ArrayInput([], $command->getDefinition()), new BufferedOutput);

        self::assertSame(0, $exitCode);
    }

    public function test_it_exposes_every_joomla_native_tool_over_mcp(): void
    {
        $names = $this->registryFromRun()->names();

        foreach ([
            'joomla_articles',
            'joomla_categories',
            'joomla_users',
            'joomla_extensions',
            'joomla_database_query',
        ] as $tool) {
            self::assertContains($tool, $names, 'The MCP server must expose the full Joomla tool set, not a subset.');
        }
    }

    public function test_it_exposes_the_core_utility_tools_over_mcp(): void
    {
        $names = $this->registryFromRun()->names();

        foreach ([
            'file_read',
            'file_write',
            'file_edit',
            'code_search',
            'project_info',
            'shell_exec',
            'http_request',
        ] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function test_it_serves_the_same_tool_count_the_about_page_advertises(): void
    {
        self::assertCount(14, $this->registryFromRun()->names());
    }

    public function test_it_honours_a_configured_tool_deny_list(): void
    {
        PluginHelper::$plugin = (object) ['params' => json_encode(['tool_deny' => 'shell_exec,joomla_users'])];

        $names = $this->registryFromRun()->names();

        self::assertNotContains('shell_exec', $names);
        self::assertNotContains('joomla_users', $names);
        self::assertContains('joomla_articles', $names);
    }

    public function test_it_honours_a_denied_tool_group(): void
    {
        PluginHelper::$plugin = (object) ['params' => json_encode(['tool_deny' => 'group:content'])];

        $names = $this->registryFromRun()->names();

        self::assertNotContains('joomla_articles', $names);
        self::assertNotContains('joomla_categories', $names);
        self::assertContains('joomla_users', $names);
    }

    public function test_it_ignores_the_provider_profile_slice_so_mcp_clients_see_the_full_set(): void
    {
        PluginHelper::$plugin = (object) ['params' => json_encode(['provider' => 'ollama', 'model' => 'qwen2.5:7b'])];

        self::assertCount(14, $this->registryFromRun()->names());
    }

    public function test_it_never_grants_php_write_access_to_mcp_clients(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/component/src/Console/McpServerCommand.php');

        self::assertStringContainsString(
            'allowPhpWrite: false',
            $source,
            'An MCP client is remote: it must never be handed the CLI-only PHP write permission.',
        );
    }

    private function registryFromRun(): ToolRegistry
    {
        $captured = null;

        $command = new McpServerCommand(function (ToolRegistry $registry) use (&$captured): void {
            $captured = $registry;
        });

        $command->execute(new ArrayInput([], $command->getDefinition()), new BufferedOutput);

        self::assertInstanceOf(ToolRegistry::class, $captured, 'The transport runner was never reached.');

        return $captured;
    }

    private function containerWithDatabase(): object
    {
        $db = $this->createMock(DatabaseInterface::class);

        return new class($db)
        {
            public function __construct(private readonly DatabaseInterface $db) {}

            public function get(string $id): DatabaseInterface
            {
                return $this->db;
            }
        };
    }
}
