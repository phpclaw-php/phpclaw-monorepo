<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Exceptions\HumanDeniedException;
use PhpClaw\Tests\Unit\Tools\Support\FakeToolAuthorizer;
use PhpClaw\Tools\CodeSearchTool;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ProjectTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ZipPackagerTool;
use PHPUnit\Framework\TestCase;

final class ApprovalGateRegressionTest extends TestCase
{
    private CliApprovalGate $gate;

    private string $workspace;

    protected function setUp(): void
    {
        $this->gate = new CliApprovalGate;
        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('agr_', true);
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace.'/denied.txt');
        @rmdir($this->workspace);
    }

    /**
     * @return array<string, array{0: ToolInterface, 1: array<string, mixed>}>
     */
    private function allowedOnTheWeb(): array
    {
        return [
            'file_read' => [new FileReadTool($this->workspace), ['path' => 'a.txt']],
            'code_search' => [new CodeSearchTool($this->workspace), ['pattern' => 'x']],
            'http_request' => [new HttpTool, ['url' => 'https://example.com']],
            'project_info' => [new ProjectTool($this->workspace), []],
            'shell_exec_readonly' => [new ShellTool, ['command' => 'whoami']],
        ];
    }

    /**
     * @return array<string, array{0: ToolInterface, 1: array<string, mixed>}>
     */
    private function blockedOnTheWeb(): array
    {
        return [
            'file_write' => [new FileWriteTool($this->workspace), ['path' => 'a.txt', 'content' => 'x']],
            'file_edit' => [new FileEditTool($this->workspace), ['path' => 'a.txt', 'old_str' => 'a', 'new_str' => 'b']],
            'zip_package' => [new ZipPackagerTool($this->workspace), ['source_dir' => 'a', 'output_name' => 'a']],
            'shell_exec_mutating' => [new ShellTool, ['command' => 'rm -rf /tmp/phpclaw-nope']],
        ];
    }

    public function test_read_path_stays_open_on_the_web(): void
    {
        foreach ($this->allowedOnTheWeb() as $label => [$tool, $input]) {
            $this->gate->check($tool->name(), $input, $tool);
            $this->addToAssertionCount(1);
        }
    }

    public function test_mutating_path_stays_blocked_on_the_web(): void
    {
        foreach ($this->blockedOnTheWeb() as $label => [$tool, $input]) {
            $denied = false;

            try {
                $this->gate->check($tool->name(), $input, $tool);
            } catch (HumanDeniedException) {
                $denied = true;
            }

            self::assertTrue($denied, $label.' must be denied on a non-interactive path.');
        }
    }

    public function test_capability_guard_never_short_circuits_the_approval_gate(): void
    {
        $tool = new FileWriteTool($this->workspace);
        $tool->withAuthorizer(new FakeToolAuthorizer(allows: true));

        $denied = false;

        try {
            $this->gate->check('file_write', ['path' => 'denied.txt', 'content' => 'x'], $tool);
        } catch (HumanDeniedException) {
            $denied = true;
        }

        self::assertTrue($denied, 'an authorized caller must still face the approval gate');
        self::assertFileDoesNotExist($this->workspace.'/denied.txt');
    }

    public function test_a_denied_capability_refuses_rather_than_writing(): void
    {
        $tool = new FileWriteTool($this->workspace);
        $tool->withAuthorizer(new FakeToolAuthorizer(allows: false));

        $decoded = (array) json_decode($tool->execute(['path' => 'denied.txt', 'content' => 'x']), true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertFileDoesNotExist($this->workspace.'/denied.txt');
    }
}
