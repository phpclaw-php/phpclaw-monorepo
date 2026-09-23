<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Benchmarks;

use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolProfileResolver;
use PhpClaw\Tools\ToolRegistry;

/**
 * @BeforeMethods({"setUp"})
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class ToolBench
{
    private array $tools = [];

    /**
     * Build the adapter's full 14-tool set as name-only stands-in, since the filter under
     * test reads only name() and real tools would each need a live database handle.
     *
     * @return void
     */
    public function setUp(): void
    {
        $names = [
            'joomla_articles', 'joomla_categories', 'joomla_users', 'joomla_extensions',
            'joomla_database_query', 'joomla_zip_extension', 'zip_package',
            'file_read', 'file_write', 'file_edit', 'code_search', 'project_info',
            'shell_exec', 'http_request',
        ];

        $this->tools = array_map(
            static fn (string $name): ToolInterface => new class($name) implements ToolInterface
            {
                public function __construct(private readonly string $toolName) {}

                public function name(): string
                {
                    return $this->toolName;
                }

                public function description(): string
                {
                    return 'benchmark stand-in';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $input): string
                {
                    return '';
                }
            },
            $names,
        );
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_profile_filter_with_no_denies(): void
    {
        ToolProfileResolver::filter($this->tools, [], ToolBuilder::TOOL_GROUPS);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_profile_filter_denying_a_group(): void
    {
        ToolProfileResolver::filter($this->tools, ['group:content'], ToolBuilder::TOOL_GROUPS);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_profile_filter_denying_named_tools(): void
    {
        ToolProfileResolver::filter($this->tools, ['shell_exec', 'joomla_users'], ToolBuilder::TOOL_GROUPS);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_registry_registration(): void
    {
        (new ToolRegistry)->register($this->tools, [], ToolBuilder::TOOL_GROUPS);
    }
}
