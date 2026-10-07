<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Bin;

use PhpClaw\Agent\RunState;
use PhpClaw\Agent\RunStatus;
use PhpClaw\Agent\RunStore;
use PhpClaw\Claw;
use PhpClaw\Exceptions\RunSuspendedException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Agent\Durable\CountingTool;
use PhpClaw\Tests\Unit\Agent\Durable\ScriptedProvider;
use PHPUnit\Framework\TestCase;

final class PhpClawResumeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        $this->dir = sys_get_temp_dir().'/phpclaw_resume_bin_'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private function plan(): array
    {
        return [
            ScriptedProvider::batch(ScriptedProvider::call('s1', 'lookup')),
            ScriptedProvider::batch(ScriptedProvider::call('s2', 'lookup')),
            ['type' => 'text', 'text' => 'resumed from cron'],
        ];
    }

    private function runScript(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 3).'/bin/phpclaw-resume'], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    private function writeBootstrap(string $body): string
    {
        $path = $this->dir.'/bootstrap.php';
        file_put_contents($path, "<?php\n".$body);

        return $path;
    }

    public function test_it_finishes_a_saved_run_from_a_bootstrap_and_prints_the_counts(): void
    {
        $memoryDir = $this->dir.'/runs';
        try {
            Claw::builder()->providerOverride(new ScriptedProvider($this->plan()))->memory(new FileMemory($memoryDir))
                ->useDefaultGuards(false)->tools([new CountingTool('lookup')])->durableRuns(stepBudget: 1)->build()->send('go');
            $this->fail('expected the run to suspend');
        } catch (RunSuspendedException $e) {
            $runId = $e->runId;
        }
        $bootstrap = $this->writeBootstrap(sprintf(
            "use PhpClaw\\Claw;\nuse PhpClaw\\Memory\\FileMemory;\nuse PhpClaw\\Tests\\Unit\\Agent\\Durable\\CountingTool;\nuse PhpClaw\\Tests\\Unit\\Agent\\Durable\\ScriptedProvider;\n"
            ."return Claw::builder()->providerOverride(new ScriptedProvider(%s))->memory(new FileMemory(%s))\n"
            ."    ->useDefaultGuards(false)->tools([new CountingTool('lookup')])->durableRuns()->build();\n",
            var_export($this->plan(), true),
            var_export($memoryDir, true),
        ));

        [$exit, $stdout, $stderr] = $this->runScript(['--bootstrap='.$bootstrap, '--limit=3', '--seconds=0']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("completed=1 suspended=0 failed=0 skipped=0\n", $stdout);
        $this->assertSame(RunStatus::Completed, RunState::fromArray((new FileMemory($memoryDir))->get($runId, RunStore::NAMESPACE))->status);
        array_map('unlink', glob($memoryDir.'/*') ?: []);
        rmdir($memoryDir);
    }

    public function test_help_prints_the_usage_and_exits_cleanly(): void
    {
        [$exit, $stdout] = $this->runScript(['--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Usage: phpclaw-resume --bootstrap=<file.php>', $stdout);
    }

    public function test_it_exits_with_an_error_without_a_bootstrap(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript([]);

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('--bootstrap', $stderr);
    }

    public function test_it_exits_with_an_error_when_the_bootstrap_is_missing(): void
    {
        [$exit, , $stderr] = $this->runScript(['--bootstrap='.$this->dir.'/nope.php']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $stderr);
    }

    public function test_it_exits_with_an_error_when_the_bootstrap_returns_no_claw(): void
    {
        [$exit, , $stderr] = $this->runScript(['--bootstrap='.$this->writeBootstrap('return 42;')]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must return', $stderr);
    }
}
