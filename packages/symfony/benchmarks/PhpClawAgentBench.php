<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Benchmarks;

/**
 * Benchmarks the config assembly done on every engine build.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class PhpClawAgentBench
{
    /**
     * Benchmark assembling the builder parameter set from a config array.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_builder_param_assembly(): void
    {
        $config = [
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'max_iterations' => 20,
            'store_messages' => true,
            'memory_driver' => 'doctrine',
            'shell_allowlist' => ['ls', 'pwd', 'cat', 'grep', 'php'],
            'tool_deny' => ['shell_exec'],
        ];

        $params = [];
        foreach ($config as $key => $value) {
            $params[$key] = is_array($value) ? array_values($value) : $value;
        }
    }

    /**
     * Benchmark filtering the tool deny-list against the resolved tool set.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_tool_deny_filter(): void
    {
        $tools = ['file_read', 'file_write', 'http_request', 'shell_exec', 'current_time', 'db_query'];
        $deny = ['shell_exec', 'db_query'];

        $kept = array_values(array_filter($tools, static fn (string $t): bool => ! in_array($t, $deny, true)));
    }
}
