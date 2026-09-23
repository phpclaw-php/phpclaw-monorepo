<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\Extension\PhpClawExtensions;
use PhpClaw\Symfony\PhpClawFactory;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class NeedsConstructorArgumentTool implements ToolInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function name(): string
    {
        return 'needs_constructor_argument';
    }

    public function description(): string
    {
        return 'A tool an extension registers with a required constructor argument.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): string
    {
        return '';
    }
}

final class ConstructorFreeTool implements ToolInterface
{
    public function name(): string
    {
        return 'constructor_free';
    }

    public function description(): string
    {
        return 'A tool an extension registers with no constructor argument.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): string
    {
        return '';
    }
}

final class ExtensionToolConstructorTest extends TestCase
{
    public function test_a_tool_with_a_required_constructor_argument_is_skipped_not_fatal(): void
    {
        $engine = $this->engineWithTools([NeedsConstructorArgumentTool::class, ConstructorFreeTool::class]);

        $names = $this->toolNames($engine);

        self::assertContains('constructor_free', $names);
        self::assertNotContains('needs_constructor_argument', $names);
    }

    public function test_the_rest_of_the_tool_set_still_loads_alongside_the_skipped_class(): void
    {
        $names = $this->toolNames($this->engineWithTools([NeedsConstructorArgumentTool::class]));

        self::assertNotContains('needs_constructor_argument', $names);
        self::assertContains('shell_exec', $names);
        self::assertContains('file_read', $names);
    }

    private function engineWithTools(array $tools): object
    {
        return (new PhpClawFactory(
            apiKey: 'sk-test',
            provider: 'openai',
            model: '',
            storeMessages: false,
            maxIterations: 3,
            shellAllowlist: ['ls'],
            tools: $tools,
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            skills: [],
            baseUrl: '',
            toolDeny: [],
            remoteSkillUrls: '',
            extensions: new PhpClawExtensions,
        ))->create();
    }

    private function toolNames(object $engine): array
    {
        $config = (new \ReflectionObject($engine))->getProperty('config');
        $config->setAccessible(true);

        return array_map(
            static fn (ToolInterface $tool): string => $tool->name(),
            $config->getValue($engine)->tools,
        );
    }
}
