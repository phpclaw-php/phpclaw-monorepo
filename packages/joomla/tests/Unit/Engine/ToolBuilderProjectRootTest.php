<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use PhpClaw\Tools\ProjectTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ToolBuilderProjectRootTest extends TestCase
{
    private function builderSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/component/src/Engine/ToolBuilder.php',
        );
    }

    public function test_project_root_is_supplied_to_the_core_tool_catalogue(): void
    {
        $this->assertMatchesRegularExpression(
            "/instantiateDefaults\(\[.*?'projectRoot' => \\\$this->projectRoot\(\)/s",
            $this->builderSource(),
            'ToolBuilder must pass projectRoot, or project_info falls back to the launch directory',
        );
    }

    public function test_project_root_resolves_to_the_site_root_not_the_workspace(): void
    {
        $source = $this->builderSource();

        $this->assertMatchesRegularExpression(
            '/private function projectRoot\(\): string\s*\{\s*return JPATH_ROOT;/s',
            $source,
            'projectRoot must be the Joomla site root, where configuration.php lives',
        );

        $this->assertMatchesRegularExpression(
            '/private function workspaceRoot\(\): string\s*\{\s*return JPATH_ADMINISTRATOR/s',
            $source,
            'workspaceRoot must stay the sandboxed storage directory, separate from projectRoot',
        );
    }

    public function test_project_tool_declares_the_config_key_the_builder_supplies(): void
    {
        $attributes = (new ReflectionClass(ProjectTool::class))->getAttributes();

        $needsConfig = [];

        foreach ($attributes as $attribute) {
            $needsConfig = (array) ($attribute->getArguments()['needsConfig'] ?? []);
        }

        $this->assertArrayHasKey(
            'projectRoot',
            $needsConfig,
            'ProjectTool must declare projectRoot, otherwise the builder value is never injected',
        );
    }

    public function test_project_tool_without_a_root_falls_back_to_the_working_directory(): void
    {
        $tool = new ProjectTool;

        $this->assertSame(
            (string) getcwd(),
            (new ReflectionClass($tool))->getProperty('projectRoot')->getValue($tool),
            'the documented fallback must stay, so an unsupplied root is a builder bug not a crash',
        );
    }
}
