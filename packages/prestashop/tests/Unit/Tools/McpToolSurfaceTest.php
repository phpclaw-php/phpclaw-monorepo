<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(Plugin::class)]
final class McpToolSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetPluginSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetPluginSingleton();

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_every_tool_the_mcp_surface_advertises_also_executes(): void
    {
        define('PHPCLAW_PS_CONSOLE', true);

        $tools = Plugin::getInstance(null, 'ps_', 0, false, true)->guideTools();
        $advertised = count($tools);

        self::assertGreaterThan(0, $advertised, 'The MCP surface advertised no tools at all.');

        $refused = [];

        foreach ($tools as $tool) {
            if ($this->refusesOnConsole($tool)) {
                $refused[] = $tool->name();
            }
        }

        $executable = $advertised - count($refused);

        self::assertSame(
            [],
            $refused,
            'The MCP server advertises tools it refuses to run on the console: '
            .implode(', ', $refused),
        );
        self::assertSame(
            $advertised,
            $executable,
            "The MCP surface advertised {$advertised} tools but only {$executable} execute.",
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_console_marker_reaches_the_mcp_tool_builder(): void
    {
        define('PHPCLAW_PS_CONSOLE', true);

        $names = array_map(
            static fn (ToolInterface $tool): string => $tool->name(),
            Plugin::getInstance(null, 'ps_', 0, false, true)->guideTools(),
        );

        self::assertContains('database', $names);
        self::assertContains('ps_order', $names);
    }

    private function refusesOnConsole(ToolInterface $tool): bool
    {
        try {
            $result = $tool->execute([]);
        } catch (ToolException) {
            return false;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return false;
        }

        return ($decoded['error']['code'] ?? null) === 'FORBIDDEN';
    }

    private function resetPluginSingleton(): void
    {
        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }
}
