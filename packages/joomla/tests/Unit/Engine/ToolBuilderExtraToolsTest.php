<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use PhpClaw\Exceptions\ShellDeniedException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\DatabaseQueryTool;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ShellTool;
use PHPUnit\Framework\TestCase;

final class ToolBuilderExtraToolsTest extends TestCase
{
    private const WORKSPACE = JPATH_ADMINISTRATOR.'/components/com_phpclaw/storage/workspace';

    protected function setUp(): void
    {
        Factory::$container = $this->containerWithDatabase();
        @mkdir(self::WORKSPACE, 0755, true);
    }

    protected function tearDown(): void
    {
        Factory::$application = null;
        Factory::$container = null;
        FileReadLog::reset();
        $this->wipeWorkspace();
    }

    public function test_a_class_name_shell_tool_from_the_event_gets_the_configured_allowlist(): void
    {
        $this->fireExtraTools([ShellTool::class]);

        $config = new PhpClawConfig(shellAllowlist: ['wc']);

        $shell = $this->findByName((new ToolBuilder)->build($config, applyProfile: false), 'shell_exec');

        $this->expectException(ShellDeniedException::class);
        $shell->execute(['command' => 'ls']);
    }

    public function test_a_class_name_shell_tool_allows_a_command_the_configured_allowlist_permits(): void
    {
        $this->fireExtraTools([ShellTool::class]);

        $config = new PhpClawConfig(shellAllowlist: ['wc']);

        $shell = $this->findByName((new ToolBuilder)->build($config, applyProfile: false), 'shell_exec');

        $result = json_decode($shell->execute(['command' => 'wc']), true);

        self::assertTrue($result['success']);
    }

    public function test_a_class_name_file_write_tool_gets_the_component_workspace_and_refuses_php_when_not_allowed(): void
    {
        $this->fireExtraTools([FileWriteTool::class]);

        $writer = $this->findByName((new ToolBuilder)->build(new PhpClawConfig, applyProfile: false, allowPhpWrite: false), 'file_write');

        $this->expectException(ToolException::class);
        $writer->execute(['path' => 'probe.php', 'content' => '<?php // x']);
    }

    public function test_a_class_name_file_write_tool_gets_the_component_workspace_and_allows_php_when_allowed(): void
    {
        $this->fireExtraTools([FileWriteTool::class]);

        $writer = $this->findByName((new ToolBuilder)->build(new PhpClawConfig, applyProfile: false, allowPhpWrite: true), 'file_write');

        $writer->execute(['path' => 'probe.php', 'content' => '<?php // x']);

        self::assertFileExists(self::WORKSPACE.'/probe.php');
    }

    public function test_a_class_name_file_edit_tool_gets_the_same_component_workspace(): void
    {
        file_put_contents(self::WORKSPACE.'/edit-me.txt', 'hello world');
        (new FileReadTool(workspaceRoot: self::WORKSPACE))->execute(['path' => 'edit-me.txt']);

        $this->fireExtraTools([FileEditTool::class]);

        $editor = $this->findByName((new ToolBuilder)->build(new PhpClawConfig, applyProfile: false), 'file_edit');

        $editor->execute(['path' => 'edit-me.txt', 'old_str' => 'world', 'new_str' => 'joomla']);

        self::assertSame('hello joomla', file_get_contents(self::WORKSPACE.'/edit-me.txt'));
    }

    public function test_a_tool_object_passed_through_the_event_is_used_as_is(): void
    {
        $stub = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'stub_tool';
            }

            public function description(): string
            {
                return 'stub';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return 'stub-result';
            }
        };

        $this->fireExtraTools([$stub]);

        $all = (new ToolBuilder)->build(new PhpClawConfig, applyProfile: false);

        self::assertTrue(in_array($stub, $all, true), 'The tool object handed through the event must be returned unchanged.');
    }

    public function test_an_unrelated_no_arg_tool_class_name_is_built(): void
    {
        $this->fireExtraTools([HttpTool::class]);

        $names = array_map(static fn (object $tool): string => $tool->name(), (new ToolBuilder)->build(new PhpClawConfig, applyProfile: false));

        self::assertContains('http_request', $names);
    }

    public function test_a_tool_class_name_with_required_constructor_arguments_is_skipped(): void
    {
        $this->fireExtraTools([DatabaseQueryTool::class]);

        $names = array_map(static fn (object $tool): string => $tool->name(), (new ToolBuilder)->build(new PhpClawConfig, applyProfile: false));

        self::assertNotContains('database_query', $names);
    }

    public function test_a_non_existent_class_name_is_skipped(): void
    {
        $missing = 'PhpClaw\\Joomla\\Tests\\Fixtures\\DoesNotExist';

        $this->fireExtraTools([$missing]);

        $all = (new ToolBuilder)->build(new PhpClawConfig, applyProfile: false);

        self::assertFalse(in_array($missing, $all, true), 'An unresolvable class name must never reach the tool list as a raw string.');
    }

    public function test_a_class_name_that_is_not_a_tool_is_skipped(): void
    {
        $this->fireExtraTools([\stdClass::class]);

        $names = array_map(static fn (object $tool): string => $tool->name(), (new ToolBuilder)->build(new PhpClawConfig, applyProfile: false));

        self::assertNotContains(\stdClass::class, $names);
    }

    private function fireExtraTools(array $tools): void
    {
        $dispatcher = new Dispatcher;
        $dispatcher->addListener('onPhpClawExtraTools', static function (Event $e) use ($tools): void {
            $e->setArgument('tools', $tools);
        });

        Factory::$application = new class($dispatcher)
        {
            public function __construct(private Dispatcher $dispatcher) {}

            public function getDispatcher(): Dispatcher
            {
                return $this->dispatcher;
            }
        };
    }

    private function findByName(array $tools, string $name): object
    {
        foreach ($tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        self::fail("No tool named {$name} was built.");
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

    private function wipeWorkspace(): void
    {
        if (! is_dir(self::WORKSPACE)) {
            return;
        }

        foreach (glob(self::WORKSPACE.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(self::WORKSPACE);
    }
}
