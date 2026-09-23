<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Attributes;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\AutoDiscovery\Attributes\Hook;
use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\AutoDiscovery\Attributes\Provider;
use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\AutoDiscovery\Attributes\Tool;
use PHPUnit\Framework\TestCase;

final class AttributesTest extends TestCase
{
    public function test_tool_attribute_carries_all_properties(): void
    {
        $tool = new Tool(
            name: 'my_tool',
            description: 'desc',
            since: '1.0.0',
            default: true,
            needsConfig: ['x' => 'string'],
            deprecated: true,
        );

        $this->assertSame('my_tool', $tool->name);
        $this->assertSame('desc', $tool->description);
        $this->assertSame('1.0.0', $tool->since);
        $this->assertTrue($tool->default);
        $this->assertSame(['x' => 'string'], $tool->needsConfig);
        $this->assertTrue($tool->deprecated);
    }

    public function test_tool_attribute_defaults_to_safe_values(): void
    {
        $tool = new Tool(name: 'minimal');

        $this->assertSame('minimal', $tool->name);
        $this->assertSame('', $tool->description);
        $this->assertSame('', $tool->since);
        $this->assertFalse($tool->default);
        $this->assertSame([], $tool->needsConfig);
        $this->assertFalse($tool->deprecated);
    }

    public function test_provider_attribute_carries_default_model(): void
    {
        $p = new Provider(name: 'anthropic', defaultModel: 'claude-haiku-4-5');

        $this->assertSame('anthropic', $p->name);
        $this->assertSame('claude-haiku-4-5', $p->defaultModel);
        $this->assertFalse($p->deprecated);
    }

    public function test_memory_attribute_carries_driver_key(): void
    {
        $m = new Memory(driver: 'redis');

        $this->assertSame('redis', $m->driver);
    }

    public function test_skill_attribute_carries_keywords(): void
    {
        $s = new Skill(name: 'faq', keywords: ['help', 'support']);

        $this->assertSame('faq', $s->name);
        $this->assertSame(['help', 'support'], $s->keywords);
    }

    public function test_hook_attribute_carries_event_and_priority(): void
    {
        $h = new Hook(event: 'agent.before', priority: 50);

        $this->assertSame('agent.before', $h->event);
        $this->assertSame(50, $h->priority);
    }

    public function test_hook_attribute_supports_wildcard_via_constant(): void
    {
        $h = new Hook(event: Hook::ANY_EVENT);

        $this->assertSame('*', $h->event);
    }

    public function test_hook_attribute_is_repeatable(): void
    {
        $reflection = new \ReflectionClass(Hook::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        $instance = $attributes[0]->newInstance();

        $this->assertSame(
            \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE,
            $instance->flags,
        );
    }

    public function test_guard_attribute_carries_priority(): void
    {
        $g = new Guard(priority: 25);

        $this->assertSame(25, $g->priority);
    }

    public function test_attributes_are_immutable(): void
    {
        $tool = new Tool(name: 'frozen');

        $reflection = new \ReflectionProperty($tool, 'name');

        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_all_six_attribute_classes_target_class_only(): void
    {
        $expected = [
            Tool::class => \Attribute::TARGET_CLASS,
            Provider::class => \Attribute::TARGET_CLASS,
            Memory::class => \Attribute::TARGET_CLASS,
            Skill::class => \Attribute::TARGET_CLASS,
            Guard::class => \Attribute::TARGET_CLASS,
            Hook::class => \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE,
        ];

        foreach ($expected as $class => $expectedFlags) {
            $reflection = new \ReflectionClass($class);
            $attrs = $reflection->getAttributes(\Attribute::class);

            $this->assertNotEmpty($attrs, "{$class} missing #[Attribute] meta-attribute");
            $this->assertSame(
                $expectedFlags,
                $attrs[0]->newInstance()->flags,
                "{$class} has wrong target flags",
            );
        }
    }
}
