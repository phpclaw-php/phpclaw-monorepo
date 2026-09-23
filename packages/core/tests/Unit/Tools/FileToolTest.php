<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileToolTest extends TestCase
{
    private string $workspace;

    private FileReadTool $reader;

    private FileWriteTool $writer;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_test_'.uniqid();
        mkdir($this->workspace, 0755, true);

        $this->reader = new FileReadTool($this->workspace);
        $this->writer = new FileWriteTool($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workspace);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_read_tool_name(): void
    {
        $this->assertSame('file_read', $this->reader->name());
    }

    public function test_read_tool_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->reader->description());
    }

    public function test_read_tool_schema_has_required_path(): void
    {
        $schema = $this->reader->inputSchema();
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertContains('path', $schema['required']);
    }

    public function test_write_tool_name(): void
    {
        $this->assertSame('file_write', $this->writer->name());
    }

    public function test_write_tool_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->writer->description());
    }

    public function test_write_tool_schema_has_required_path_and_content(): void
    {
        $schema = $this->writer->inputSchema();
        $this->assertContains('path', $schema['required']);
        $this->assertContains('content', $schema['required']);
    }

    public function test_write_creates_file_with_content(): void
    {
        $result = $this->writer->execute(['path' => 'hello.txt', 'content' => 'Hello, World!']);

        $this->assertStringContainsString('13', $result);
        $this->assertFileExists($this->workspace.DIRECTORY_SEPARATOR.'hello.txt');
    }

    public function test_write_creates_parent_directories(): void
    {
        $this->writer->execute(['path' => 'subdir/nested/file.txt', 'content' => 'data']);

        $this->assertFileExists($this->workspace.DIRECTORY_SEPARATOR.'subdir'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'file.txt');
    }

    public function test_write_returns_byte_count_message(): void
    {
        $result = $this->writer->execute(['path' => 'count.txt', 'content' => 'abc']);
        $this->assertStringContainsString('3', $result);
        $this->assertStringContainsString('count.txt', $result);
    }

    private static function content(string $json): string
    {
        $decoded = (array) json_decode($json, true);

        return (string) ((array) $decoded['data'])['content'];
    }

    public function test_read_returns_file_content(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'test.txt', 'Hello phpClaw');

        $result = self::content($this->reader->execute(['path' => 'test.txt']));
        $this->assertSame('Hello phpClaw', $result);
    }

    public function test_read_write_roundtrip(): void
    {
        $content = 'Round-trip content '.uniqid();

        $this->writer->execute(['path' => 'roundtrip.txt', 'content' => $content]);
        $result = self::content($this->reader->execute(['path' => 'roundtrip.txt']));

        $this->assertSame($content, $result);
    }

    public function test_read_throws_when_path_is_empty(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '']);
    }

    public function test_read_throws_when_file_does_not_exist(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'does_not_exist.txt']);
    }

    public function test_write_throws_when_path_is_empty(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => '', 'content' => 'data']);
    }

    #[DataProvider('blockedExtensionProvider')]
    public function test_read_throws_for_blocked_extension(string $filename): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.$filename, 'secret');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => $filename]);
    }

    #[DataProvider('blockedExtensionProvider')]
    public function test_write_throws_for_blocked_extension(string $filename): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => $filename, 'content' => 'secret']);
    }

    public static function blockedExtensionProvider(): array
    {
        return [
            ['secret.key'],
            ['cert.pem'],
            ['cert.crt'],
            ['store.p12'],
            ['store.pfx'],
            ['cert.cer'],
            ['cert.der'],
        ];
    }

    public function test_read_blocks_dotenv_file(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'.env', 'DB_PASSWORD=secret');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '.env']);
    }

    public function test_read_blocks_dotenv_local(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'.env.local', 'KEY=val');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '.env.local']);
    }

    public function test_write_blocks_dotenv_file(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => '.env', 'content' => 'DB_PASS=bad']);
    }

    public function test_read_blocks_path_traversal(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '../../../etc/passwd']);
    }

    public function test_read_blocks_nested_traversal(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'subdir/../../outside.txt']);
    }

    public function test_write_blocks_path_traversal(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => '../../../tmp/evil.txt', 'content' => 'evil']);
    }

    public function test_write_blocks_vendor_directory(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => 'vendor/autoload.php', 'content' => '<?php']);
    }

    public function test_write_blocks_node_modules_directory(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => 'node_modules/evil.js', 'content' => 'evil']);
    }

    public function test_read_blocks_vendor_directory(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'vendor/autoload.php']);
    }

    public function test_read_blocks_git_directory(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '.git/config']);
    }

    public function test_read_blocks_ssh_directory(): void
    {
        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '.ssh/id_rsa']);
    }

    public function test_read_blocks_wp_config(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'wp-config.php', '<?php');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'wp-config.php']);
    }

    public function test_read_blocks_htpasswd(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'.htpasswd', 'admin:hash');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => '.htpasswd']);
    }

    public function test_read_blocks_id_rsa(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'id_rsa', 'private key');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'id_rsa']);
    }

    public function test_read_blocks_sqlite_extension(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'data.sqlite', 'SQLite data');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'data.sqlite']);
    }

    public function test_read_blocks_db_extension(): void
    {
        file_put_contents($this->workspace.DIRECTORY_SEPARATOR.'local.db', 'data');

        $this->expectException(ToolException::class);
        $this->reader->execute(['path' => 'local.db']);
    }

    public function test_write_blocks_sqlite_extension(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => 'data.sqlite', 'content' => 'evil']);
    }

    public function test_write_blocks_db_extension(): void
    {
        $this->expectException(ToolException::class);
        $this->writer->execute(['path' => 'data.db', 'content' => 'evil']);
    }
}
