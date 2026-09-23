<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Claw as PhpClawEngine;
use PhpClaw\ClawConfig;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Laravel\Console\Concerns\RendersBanner;
use PhpClaw\Skills\SkillRegistry;

/**
 * Artisan command `php artisan phpclaw:about [--test]`, prints adapter info from the live engine; `--test` probes provider connectivity.
 */
final class AboutCommand extends Command
{
    use RendersBanner;

    protected $signature = 'phpclaw:about {--test : Send a test prompt to verify provider connectivity}';

    protected $description = 'Display phpClaw adapter info (provider, tools, guards, memory, skills, cloud state)';

    /**
     * Print the adapter info card and optionally probe provider connectivity.
     *
     * @param  PhpClawInterface  $phpclaw  The resolved phpClaw engine.
     * @return int Artisan exit code.
     */
    public function handle(PhpClawInterface $phpclaw): int
    {
        $this->banner();

        $config = $phpclaw instanceof PhpClawEngine
            ? $phpclaw->config()
            : null;

        $provider = $config ? $config->providerName : (string) config('phpclaw.provider', 'unknown');
        $model = $config ? $config->model : (string) config('phpclaw.model', '');
        $maxIter = $config ? $config->maxIterations : (int) config('phpclaw.max_iterations', ClawConfig::DEFAULT_MAX_ITERATIONS);
        $storeMsgs = (bool) config('phpclaw.store_messages', true);
        $memoryDriver = (string) config('phpclaw.memory_driver', 'database');

        $toolNames = [];
        if ($config) {
            foreach ($config->tools as $tool) {
                $toolNames[] = $tool->name();
            }
        }
        $toolCount = count($toolNames);
        $toolList = $toolCount > 0 ? implode(', ', $toolNames) : 'none';

        $guardCount = GuardRegistry::count();
        $skillCount = SkillRegistry::count();
        $skillNames = SkillRegistry::names();
        $skillList = $skillCount > 0 ? implode(', ', $skillNames) : 'none';

        $cloudKey = (string) config('phpclaw.cloud_key', '');
        $cloudLine = $this->formatCloudKey($cloudKey);

        $displayModel = $model !== '' ? " ($model)" : '';

        $this->line('phpClaw, Laravel adapter');
        $this->line(str_repeat('━', 40));
        $this->line("Provider:     {$provider}{$displayModel}");
        $this->line("Tools:        {$toolCount} active ({$toolList})");
        $this->line("Guards:       {$guardCount} active");
        $this->line("Memory:       {$memoryDriver} driver");
        $this->line("Skills:       {$skillCount} active ({$skillList})");
        $this->line("Cloud:        {$cloudLine}");
        $this->line('store_msgs:   '.($storeMsgs ? 'ON' : 'OFF'));
        $this->line("Max iter:     {$maxIter}");
        $this->line(str_repeat('━', 40));
        $this->line("Run 'phpclaw:guide' for full documentation");

        if ($this->option('test')) {
            $this->newLine();
            $this->line('Test Connection...');

            try {
                $response = $phpclaw->send('Reply with exactly: OK');
                $text = trim($response->text);

                if (stripos($text, 'OK') !== false) {
                    $this->info('Provider reachable. Response: '.$text);
                } else {
                    $this->warn('Provider responded but reply was unexpected: '.$text);
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error('Provider unreachable, check the provider and API key.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Mask the cloud key for display, never printing it raw.
     *
     * @param  string  $key  The configured cloud key.
     * @return string
     */
    private function formatCloudKey(string $key): string
    {
        if ($key === '') {
            return 'not connected';
        }

        $last4 = substr($key, -4);

        return 'connected (key: ****'.$last4.')';
    }
}
