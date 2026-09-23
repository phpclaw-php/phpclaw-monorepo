<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\ToolCatalogue;
use PHPUnit\Framework\TestCase;

final class ToolCatalogueBootTest extends TestCase
{
    protected function setUp(): void
    {
        ToolCatalogue::reset();
        ComposerExtras::reset();
    }

    protected function tearDown(): void
    {
        ToolCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_boot_registers_valid_tool(): void
    {
        ComposerExtras::withTestPayload([
            'alice/phpclaw-tool-stripe' => [
                'tools' => [
                    ['class' => HttpTool::class],
                ],
            ],
        ]);

        ToolCatalogue::boot();

        $this->assertTrue(ToolCatalogue::has(HttpTool::class));
    }

    public function test_boot_skips_nonexistent_class(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['tools' => [['class' => 'Doesnt\\Exist\\Tool']]],
        ]);

        ToolCatalogue::boot();

        $this->assertFalse(ToolCatalogue::has('Doesnt\\Exist\\Tool'));
    }

    public function test_boot_skips_class_not_implementing_tool_interface(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['tools' => [['class' => \stdClass::class]]],
        ]);

        ToolCatalogue::boot();

        $this->assertFalse(ToolCatalogue::has(\stdClass::class));
    }

    public function test_boot_skips_entry_without_class_key(): void
    {
        ComposerExtras::withTestPayload([
            'bad/pkg' => ['tools' => [['label' => 'Anonymous']]],
        ]);

        ToolCatalogue::boot();

        $registered = ToolCatalogue::all();
        $this->assertContains(HttpTool::class, $registered);
        $this->assertNotContains(\stdClass::class, $registered);
    }

    public function test_boot_idempotent_does_not_double_register(): void
    {
        ComposerExtras::withTestPayload([
            'alice/pkg' => ['tools' => [['class' => HttpTool::class]]],
        ]);

        ToolCatalogue::boot();
        ToolCatalogue::boot();
        ToolCatalogue::boot();

        $occurrences = array_count_values(ToolCatalogue::all())[HttpTool::class] ?? 0;
        $this->assertSame(1, $occurrences, 'register() must dedupe HttpTool even across multiple boot calls');
    }

    public function test_activate_enabled_returns_validated_class_list(): void
    {
        $result = ToolCatalogue::activateEnabled([HttpTool::class, 'Does\\Not\\Exist', \stdClass::class]);

        $this->assertSame([HttpTool::class], $result);
    }

    public function test_activate_enabled_null_returns_all_catalogue_entries(): void
    {
        $result = ToolCatalogue::activateEnabled(null);

        $this->assertContains(HttpTool::class, $result);
    }

    public function test_activate_enabled_empty_array_returns_empty(): void
    {
        $result = ToolCatalogue::activateEnabled([]);

        $this->assertSame([], $result);
    }
}
