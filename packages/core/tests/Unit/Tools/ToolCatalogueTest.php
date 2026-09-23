<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ToolCatalogue;
use PHPUnit\Framework\TestCase;

final class ToolCatalogueTest extends TestCase
{
    protected function tearDown(): void
    {
        ToolCatalogue::reset();
    }

    public function test_all_returns_built_in_tools(): void
    {
        $tools = ToolCatalogue::all();

        $this->assertContains(HttpTool::class, $tools);
    }

    public function test_has_returns_true_for_built_in_tool(): void
    {
        $this->assertTrue(ToolCatalogue::has(HttpTool::class));
    }

    public function test_has_returns_false_for_unregistered_class(): void
    {
        $this->assertFalse(ToolCatalogue::has('NonExistent\\FakeTool'));
    }

    public function test_register_adds_custom_tool(): void
    {
        $fakeClass = 'PhpClaw\\Tools\\Extra\\Tests\\Unit\\FakeCustomTool';
        ToolCatalogue::register($fakeClass);

        $this->assertContains($fakeClass, ToolCatalogue::all());
        $this->assertTrue(ToolCatalogue::has($fakeClass));
    }

    public function test_register_does_not_duplicate_same_class(): void
    {
        $fakeClass = 'PhpClaw\\Tools\\Extra\\Tests\\Unit\\FakeCustomTool';
        ToolCatalogue::register($fakeClass);
        ToolCatalogue::register($fakeClass);
        ToolCatalogue::register($fakeClass);

        $registrations = array_filter(
            ToolCatalogue::all(),
            static fn (string $cls): bool => $cls === $fakeClass
        );

        $this->assertCount(1, $registrations);
    }

    public function test_reset_clears_custom_registrations_only(): void
    {
        $fakeClass = 'PhpClaw\\Tools\\Extra\\Tests\\Unit\\FakeCustomTool';
        ToolCatalogue::register($fakeClass);
        ToolCatalogue::reset();

        $this->assertFalse(ToolCatalogue::has($fakeClass));
        $this->assertTrue(ToolCatalogue::has(HttpTool::class));
    }
}
