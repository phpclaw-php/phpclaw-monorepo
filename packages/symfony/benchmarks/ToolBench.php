<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Benchmarks;

use PhpClaw\Symfony\Tools\LogTool;

/**
 * Benchmarks the tool output-cap and JSON-encode path shared by the DB and log tools.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class ToolBench
{
    private ?LogTool $tool = null;

    private ?\Closure $truncate = null;

    /**
     * Bind the shipped truncation helper so the benchmark measures the real tool path.
     *
     * @return void
     *
     * @BeforeMethods("setUpTool")
     */
    public function setUpTool(): void
    {
        $this->tool = new LogTool;
        $this->truncate = \Closure::bind(
            fn (string $text): string => $this->truncate($text),
            $this->tool,
            LogTool::class,
        );
    }

    /**
     * Benchmark truncating oversized tool output to the byte cap.
     *
     * @return void
     *
     * @Subject
     *
     * @BeforeMethods("setUpTool")
     */
    public function bench_output_truncate(): void
    {
        ($this->truncate)(str_repeat('row data ', 4096));
    }

    /**
     * Benchmark JSON-encoding a rowset result with the truncation guard.
     *
     * @return void
     *
     * @Subject
     *
     * @BeforeMethods("setUpTool")
     */
    public function bench_json_encode_rows(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['id' => $i, 'role' => 'user', 'content' => 'message '.$i];
        }

        ($this->truncate)((string) json_encode($rows, JSON_UNESCAPED_SLASHES));
    }
}
