<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ShellDeniedException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tests\Unit\Tools\Support\FakeToolAuthorizer;
use PhpClaw\Tools\CodeSearchTool;
use PhpClaw\Tools\Contracts\ToolAuthorizerInterface;
use PhpClaw\Tools\DatabaseQueryTool;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ProjectTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ToolCatalogue;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\Tools\ZipPackagerTool;
use PHPUnit\Framework\TestCase;

final class ToolAuthorizationTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('auth_', true);
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->workspace);
    }

    private function tools(): array
    {
        return [
            'code_search' => [new CodeSearchTool($this->workspace), ['pattern' => '']],
            'db_query' => [new DatabaseQueryTool(new \PDO('sqlite::memory:')), ['sql' => '']],
            'file_edit' => [new FileEditTool($this->workspace), ['path' => '', 'old_str' => '']],
            'file_read' => [new FileReadTool($this->workspace), ['path' => '']],
            'file_write' => [new FileWriteTool($this->workspace), ['path' => '']],
            'http_request' => [new HttpTool, ['url' => '']],
            'project_info' => [new ProjectTool($this->workspace.'/absent'), []],
            'shell_exec' => [new ShellTool, ['command' => '']],
            'zip_package' => [new ZipPackagerTool($this->workspace), ['source_dir' => '', 'output_name' => '']],
        ];
    }

    private function sweep(?ToolAuthorizerInterface $authorizer): array
    {
        $refused = [];

        foreach ($this->tools() as $name => [$tool, $input]) {
            if ($authorizer !== null) {
                $tool->withAuthorizer($authorizer);
            }

            try {
                $out = $tool->execute($input);
            } catch (ToolException|ShellDeniedException) {
                continue;
            }

            $decoded = (array) json_decode($out, true);

            if (($decoded['success'] ?? null) === false && (($decoded['error']['code'] ?? '') === 'FORBIDDEN')) {
                $refused[] = $name;
            }
        }

        return $refused;
    }

    public function test_every_core_tool_is_swept(): void
    {
        self::assertCount(9, $this->tools());
    }

    public function test_authenticated_caller_reaches_all(): void
    {
        self::assertSame([], $this->sweep(new FakeToolAuthorizer(allows: true)));
    }

    public function test_no_identity_reaches_none(): void
    {
        self::assertSame(
            ['code_search', 'db_query', 'file_edit', 'file_read', 'file_write', 'http_request', 'project_info', 'shell_exec', 'zip_package'],
            $this->sweep(new FakeToolAuthorizer(allows: false)),
        );
    }

    public function test_console_reaches_all(): void
    {
        self::assertSame([], $this->sweep(new FakeToolAuthorizer(allows: false, console: true)));
    }

    public function test_no_authorizer_still_allows(): void
    {
        self::assertSame([], $this->sweep(null));
    }

    public function test_every_core_tool_requires_the_same_capability(): void
    {
        foreach ($this->tools() as $name => [$tool, $_input]) {
            self::assertSame(ToolAuthorizerInterface::CAPABILITY, $tool->requiredCapability(), $name);
        }
    }

    public function test_registry_binds_its_authorizer_to_a_hand_constructed_tool(): void
    {
        $registry = new ToolRegistry(new FakeToolAuthorizer(allows: false));
        $registry->register([new ProjectTool($this->workspace)]);

        $decoded = (array) json_decode($registry->get('project_info')->execute([]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
    }

    public function test_registry_without_an_authorizer_leaves_the_tool_allowing(): void
    {
        $registry = new ToolRegistry;
        $registry->register([new ProjectTool($this->workspace)]);

        $decoded = (array) json_decode($registry->get('project_info')->execute([]), true);

        self::assertTrue($decoded['success']);
    }

    public function test_catalogue_binds_its_authorizer_to_default_tools(): void
    {
        $tool = $this->projectToolFromCatalogue([
            'projectRoot' => $this->workspace,
            'authorizer' => new FakeToolAuthorizer(allows: false),
        ]);

        $decoded = (array) json_decode($tool->execute([]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
    }

    public function test_catalogue_without_an_authorizer_leaves_tools_allowing(): void
    {
        $tool = $this->projectToolFromCatalogue(['projectRoot' => $this->workspace]);

        $decoded = (array) json_decode($tool->execute([]), true);

        self::assertTrue($decoded['success']);
    }

    private function projectToolFromCatalogue(array $config): ProjectTool
    {
        foreach (ToolCatalogue::instantiateDefaults($config) as $tool) {
            if ($tool instanceof ProjectTool) {
                return $tool;
            }
        }

        self::fail('project_info not present among default tools');
    }
}
