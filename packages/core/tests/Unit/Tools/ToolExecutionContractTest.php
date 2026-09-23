<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Tests\Unit\Tools\Support\FakeToolAuthorizer;
use PhpClaw\Tools\CodeSearchTool;
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\DatabaseQueryTool;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ProjectTool;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\ZipPackagerTool;
use PHPUnit\Framework\TestCase;

final class ToolExecutionContractTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        FileReadLog::reset();

        $this->workspace = sys_get_temp_dir().'/phpclaw-test-'.uniqid('tec_', true);
        mkdir($this->workspace.'/bundle', 0755, true);
        file_put_contents($this->workspace.'/notes.txt', "alpha needle beta\n");
        file_put_contents($this->workspace.'/bundle/readme.txt', 'packaged');
    }

    protected function tearDown(): void
    {
        FileReadLog::reset();
        $this->rrm($this->workspace);
    }

    private function rrm(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rrm($path.DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($path);
    }

    private function allowing(AuthorizableToolInterface $tool): AuthorizableToolInterface
    {
        $tool->withAuthorizer(new FakeToolAuthorizer(allows: true));

        return $tool;
    }

    private function assertSuccessEnvelope(string $json): array
    {
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertIsArray($decoded['data']);
        self::assertIsArray($decoded['meta']);
        self::assertIsArray($decoded['warnings']);

        return (array) $decoded['data'];
    }

    private function assertErrorEnvelope(string $json): array
    {
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);
        self::assertIsArray($decoded['error']);
        self::assertNull($decoded['data']);
        self::assertIsArray($decoded['meta']);
        self::assertIsArray($decoded['warnings']);

        return (array) $decoded['error'];
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, label TEXT)');
        $pdo->exec("INSERT INTO widgets (label) VALUES ('first')");

        return $pdo;
    }

    public function test_code_search_returns_the_success_envelope(): void
    {
        $tool = $this->allowing(new CodeSearchTool($this->workspace));

        $data = $this->assertSuccessEnvelope($tool->execute(['pattern' => 'needle']));

        self::assertSame('needle', $data['pattern']);
        self::assertSame(1, $data['total']);
    }

    public function test_file_read_returns_the_success_envelope(): void
    {
        $tool = $this->allowing(new FileReadTool($this->workspace));

        $data = $this->assertSuccessEnvelope($tool->execute(['path' => 'notes.txt']));

        self::assertSame("alpha needle beta\n", $data['content']);
    }

    public function test_file_write_returns_the_success_envelope(): void
    {
        $tool = $this->allowing(new FileWriteTool($this->workspace));

        $data = $this->assertSuccessEnvelope($tool->execute([
            'path' => 'written.txt',
            'content' => 'twelve bytes',
        ]));

        self::assertSame(12, $data['bytes_written']);
        self::assertFileExists($this->workspace.'/written.txt');
    }

    public function test_file_edit_returns_the_success_envelope(): void
    {
        $this->allowing(new FileReadTool($this->workspace))->execute(['path' => 'notes.txt']);

        $tool = $this->allowing(new FileEditTool($this->workspace));

        $data = $this->assertSuccessEnvelope($tool->execute([
            'path' => 'notes.txt',
            'old_str' => 'needle',
            'new_str' => 'thread',
        ]));

        self::assertSame('edited', $data['status']);
        self::assertStringContainsString('thread', (string) file_get_contents($this->workspace.'/notes.txt'));
    }

    public function test_project_info_returns_the_success_envelope(): void
    {
        $tool = $this->allowing(new ProjectTool($this->workspace));

        $data = $this->assertSuccessEnvelope($tool->execute([]));

        self::assertSame($this->workspace, $data['root']);
    }

    public function test_shell_exec_returns_the_success_envelope(): void
    {
        $tool = $this->allowing(new ShellTool);

        $data = $this->assertSuccessEnvelope($tool->execute(['command' => 'whoami']));

        self::assertNotSame('', trim((string) $data['output']));
    }

    public function test_zip_package_returns_the_success_envelope(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not installed.');
        }

        $tool = $this->allowing(new ZipPackagerTool($this->workspace));

        $data = $this->assertSuccessEnvelope($tool->execute([
            'source_dir' => 'bundle',
            'output_name' => 'bundle',
        ]));

        self::assertSame(1, $data['files']);
        @unlink((string) $data['zip_path']);
    }

    public function test_db_query_returns_the_success_envelope(): void
    {
        $tool = $this->allowing(new DatabaseQueryTool($this->pdo()));

        $data = $this->assertSuccessEnvelope($tool->execute(['sql' => 'SELECT label FROM widgets']));

        self::assertSame(1, $data['count']);
        self::assertSame('first', $data['rows'][0]['label']);
    }

    public function test_http_request_returns_the_error_envelope_when_the_caller_is_unknown(): void
    {
        $tool = new HttpTool;
        $tool->withAuthorizer(new FakeToolAuthorizer(allows: false));

        $error = $this->assertErrorEnvelope($tool->execute(['url' => 'https://example.com']));

        self::assertSame('FORBIDDEN', $error['code']);
    }
}
