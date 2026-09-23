<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Symfony\Command\GuideCommand;
use PhpClaw\Symfony\Command\PhpClawCommand;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolCatalogue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GuideAccuracyTest extends TestCase
{
    public function test_the_tools_section_names_every_automatically_registered_tool(): void
    {
        Bootstrap::boot();

        $registered = array_map(
            static fn (ToolInterface $tool): string => $tool->name(),
            ToolCatalogue::instantiateDefaults(['workspaceRoot' => sys_get_temp_dir()]),
        );

        $section = $this->section('tools');

        self::assertNotSame([], $registered);

        foreach ($registered as $name) {
            self::assertStringContainsString(
                $name,
                $section,
                "the guide's tools section never mentions the auto-registered tool {$name}",
            );
        }
    }

    public function test_the_tools_section_does_not_send_readers_to_a_tool_deny_env_var(): void
    {
        $section = $this->section('tools');

        self::assertStringNotContainsString('PHPCLAW_TOOL_DENY', $section);
        self::assertStringContainsString('tool_deny', $section);
    }

    public function test_the_yaml_examples_are_sequence_entries_a_reader_can_paste(): void
    {
        foreach (['guards', 'hooks'] as $name) {
            $section = $this->section($name);

            foreach (explode("\n", $section) as $line) {
                self::assertDoesNotMatchRegularExpression(
                    '/^\s+:\s*\{/',
                    $line,
                    "the {$name} section prints a YAML mapping entry with no key: {$line}",
                );
            }
        }
    }

    public function test_the_rest_section_lists_every_field_the_send_endpoint_returns(): void
    {
        $shape = null;
        foreach (explode("\n", $this->section('rest')) as $line) {
            if (preg_match('/\{([a-z_,]+)\}/', $line, $matches) === 1) {
                $shape = explode(',', $matches[1]);
            }
        }

        self::assertNotNull($shape, 'the rest section prints no response shape at all');

        sort($shape);

        self::assertSame(
            ['conversation_id', 'iterations', 'model', 'provider', 'text', 'tokens', 'tool_calls'],
            $shape,
        );
    }

    public function test_the_rest_section_lists_every_stream_event_the_controller_emits(): void
    {
        $section = $this->section('rest');

        foreach (['tool_before', 'tool_after', 'chunk', 'done', 'error'] as $event) {
            self::assertStringContainsString($event, $section);
        }
    }

    public function test_the_privacy_section_says_what_is_stored_and_what_leaves_the_server(): void
    {
        $section = $this->section('privacy');

        self::assertStringContainsString('store_messages', $section);
        self::assertStringContainsString('phpclaw_messages', $section);
        self::assertStringContainsString('cloud_key', $section);
    }

    public function test_the_cli_section_mentions_the_mcp_transport_option(): void
    {
        self::assertStringContainsString('--transport', $this->section('cli'));
    }

    public function test_the_agent_command_declares_no_option_it_ignores(): void
    {
        $definition = (new PhpClawCommand(
            $this->createMock(ClawInterface::class),
        ))->getDefinition();

        self::assertSame(['stream'], array_keys($definition->getOptions()));
    }

    private function section(string $name): string
    {
        $tester = new CommandTester(new GuideCommand('', []));
        $tester->execute(['--section' => $name]);

        return $tester->getDisplay();
    }
}
