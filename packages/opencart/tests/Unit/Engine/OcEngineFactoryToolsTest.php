<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Engine;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Engine\OcEngineFactory;
use PhpClaw\OpenCart\OcEventFirer;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ZipPackagerTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class OcEngineFactoryToolsTest extends TestCase
{
    public function test_shell_tool_class_name_gets_configured_allowlist(): void
    {
        $firer = $this->firerWithExtras([ShellTool::class]);
        $config = ['shell_allowlist' => ['date']];

        $tools = (new OcEngineFactory)->buildTools($config, null, 'oc_', $firer, true, true, false);
        $shell = $this->findTool($tools, ShellTool::class);

        self::assertNotNull($shell);

        try {
            $shell->execute(['command' => 'pwd']);
            self::fail('pwd is not in the configured allowlist and must be denied.');
        } catch (ToolException $exception) {
            self::assertStringContainsString('not in the shell allowlist', $exception->getMessage());
        }

        $allowed = json_decode($shell->execute(['command' => 'date']), true);
        self::assertTrue($allowed['success'], 'date is in the configured allowlist and must run.');
    }

    public function test_file_write_tool_class_name_gets_configured_workspace_and_refuses_php_without_marker(): void
    {
        self::assertFalse(
            defined('PHPCLAW_OC_CONSOLE'),
            'This negative control is only meaningful while the marker is absent.',
        );

        $dir = sys_get_temp_dir().'/oc_fw_'.getmypid();
        mkdir($dir, 0755, true);

        $firer = $this->firerWithExtras([FileWriteTool::class]);
        $config = ['workspace_root' => $dir];

        $tools = (new OcEngineFactory)->buildTools($config, null, 'oc_', $firer, true, true, false);
        $writer = $this->findTool($tools, FileWriteTool::class);

        self::assertNotNull($writer);

        $result = json_decode($writer->execute(['path' => 'note.txt', 'content' => 'hello']), true);
        self::assertTrue($result['success'], 'The write must land under the configured workspace.');
        self::assertFileExists($dir.'/note.txt');

        try {
            $writer->execute(['path' => 'probe.php', 'content' => '<?php echo 1;']);
            self::fail('A .php write without the console marker must be refused.');
        } catch (ToolException $exception) {
            self::assertStringContainsString('blocked', $exception->getMessage());
        } finally {
            unlink($dir.'/note.txt');
            rmdir($dir);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_file_write_tool_class_name_allows_php_with_console_marker(): void
    {
        define('PHPCLAW_OC_CONSOLE', true);

        $dir = sys_get_temp_dir().'/oc_fw_console_'.getmypid();
        mkdir($dir, 0755, true);

        $firer = $this->firerWithExtras([FileWriteTool::class]);
        $config = ['workspace_root' => $dir];

        $tools = (new OcEngineFactory)->buildTools($config, null, 'oc_', $firer, true, true, false);
        $writer = $this->findTool($tools, FileWriteTool::class);

        self::assertNotNull($writer);

        $result = json_decode($writer->execute(['path' => 'probe.php', 'content' => '<?php echo 1;']), true);
        self::assertTrue($result['success'], 'The console marker must allow a .php write.');
        self::assertFileExists($dir.'/probe.php');

        unlink($dir.'/probe.php');
        rmdir($dir);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_file_write_tool_class_name_refuses_php_in_a_non_interactive_build_under_the_console_marker(): void
    {
        define('PHPCLAW_OC_CONSOLE', true);

        $firer = $this->firerWithExtras([FileWriteTool::class]);

        $tools = (new OcEngineFactory)->buildTools(['workspace_root' => sys_get_temp_dir()], null, 'oc_', $firer, true, false, false, interactive: false);
        $writer = $this->findTool($tools, FileWriteTool::class);

        self::assertNotNull($writer);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Writing .php files is blocked by default');

        $writer->execute(['path' => 'probe.php', 'content' => '<?php echo 1;']);
    }

    public function test_file_edit_tool_class_name_gets_configured_workspace(): void
    {
        $dir = sys_get_temp_dir().'/oc_fe_'.getmypid();
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/note.txt', 'hello world');

        $firer = $this->firerWithExtras([FileEditTool::class]);
        $config = ['workspace_root' => $dir];

        $tools = (new OcEngineFactory)->buildTools($config, null, 'oc_', $firer, true, true, false);
        $editor = $this->findTool($tools, FileEditTool::class);

        self::assertNotNull($editor);

        try {
            $editor->execute(['path' => 'note.txt', 'old_str' => 'hello', 'new_str' => 'bye']);
            self::fail('Expected the file_read precondition to fire, proving the file was found under the configured workspace.');
        } catch (ToolException $exception) {
            self::assertStringContainsString(
                'file_read must be called',
                $exception->getMessage(),
                'A "file not found" message here would mean the workspace was not applied.',
            );
        } finally {
            unlink($dir.'/note.txt');
            rmdir($dir);
        }
    }

    public function test_extra_tool_object_passes_through_unchanged(): void
    {
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'fixture_pass_through';
            }

            public function description(): string
            {
                return 'fixture';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass];
            }

            public function execute(array $input): string
            {
                return 'ok';
            }
        };

        $firer = $this->firerWithExtras([$tool]);

        $tools = (new OcEngineFactory)->buildTools([], null, 'oc_', $firer, true, true, false);

        self::assertContains($tool, $tools, 'The exact object instance must pass through unchanged.');
    }

    public function test_unrelated_noarg_class_name_is_built(): void
    {
        $firer = $this->firerWithExtras([ZipPackagerTool::class]);

        $tools = (new OcEngineFactory)->buildTools([], null, 'oc_', $firer, true, true, false);

        self::assertNotNull($this->findTool($tools, ZipPackagerTool::class));
    }

    public function test_non_tool_string_is_skipped(): void
    {
        $baseline = (new OcEngineFactory)->buildTools([], null, 'oc_', new OcEventFirer(null), true, true, false);

        $firer = $this->firerWithExtras(['NotAToolClassAtAll', \stdClass::class]);
        $tools = (new OcEngineFactory)->buildTools([], null, 'oc_', $firer, true, true, false);

        self::assertCount(count($baseline), $tools, 'Neither entry names a ToolInterface, so nothing is added.');

        foreach ($tools as $tool) {
            self::assertNotInstanceOf(\stdClass::class, $tool);
        }
    }

    private function firerWithExtras(array $extra): OcEventFirer
    {
        $listeners = [
            'phpclaw/extra/tools' => static function (array &$bucket) use ($extra): void {
                foreach ($extra as $item) {
                    $bucket[] = $item;
                }
            },
        ];

        $registry = new class($listeners)
        {
            public function __construct(private readonly array $listeners) {}

            public function has(string $name): bool
            {
                return $name === 'event';
            }

            public function get(string $name): object
            {
                return new class($this->listeners)
                {
                    public function __construct(private readonly array $listeners) {}

                    public function trigger(string $event, array $args): void
                    {
                        if (isset($this->listeners[$event])) {
                            ($this->listeners[$event])($args[0]);
                        }
                    }
                };
            }
        };

        return new OcEventFirer($registry);
    }

    private function findTool(array $tools, string $class): ?ToolInterface
    {
        foreach ($tools as $tool) {
            if ($tool instanceof $class) {
                return $tool;
            }
        }

        return null;
    }
}
