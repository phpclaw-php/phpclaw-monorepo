<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Skills;

use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\Guards\CodeInjectionGuard;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\WordPress\Skills\WPPluginSkill;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WPPluginSkill::class)]
final class WPPluginSkillTest extends TestCase
{
    private WPPluginSkill $skill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skill = new WPPluginSkill;
    }

    public function test_implements_skill_interface(): void
    {
        self::assertInstanceOf(SkillInterface::class, $this->skill);
    }

    public function test_name_and_description_are_non_empty(): void
    {
        self::assertSame('wp_plugin_creator', $this->skill->name());
        self::assertNotSame('', $this->skill->description());
    }

    public function test_tags_contain_plugin_slider_create(): void
    {
        $tags = $this->skill->tags();

        self::assertContains('plugin', $tags);
        self::assertContains('slider', $tags);
        self::assertContains('create', $tags);
    }

    public function test_content_carries_the_generation_rules(): void
    {
        $content = $this->skill->content();

        self::assertStringContainsString('Plugin Name:', $content);
        self::assertStringContainsString('ABSPATH', $content);
        self::assertStringContainsString('esc_url_raw', $content);
        self::assertStringContainsString('never echoes', $content);
        self::assertStringContainsString('wp_zip_plugin', $content);
    }

    public function test_content_does_not_trip_the_code_injection_guard(): void
    {
        $guard = new CodeInjectionGuard;
        $content = $this->skill->content();

        self::assertNotSame('', $content);

        $guard->scan($content);

        self::assertTrue(true, 'scan() throws GuardException when it rejects; reaching here is the pass');
    }

    public function test_skill_attribute_signature_matches_core(): void
    {
        $attrs = (new \ReflectionClass(WPPluginSkill::class))
            ->getAttributes(Skill::class);

        self::assertCount(1, $attrs);

        $skillAttr = $attrs[0]->newInstance();
        self::assertSame('wp_plugin_creator', $skillAttr->name);
        self::assertSame('WP Plugin Creator', $skillAttr->label);
        self::assertContains('slider', $skillAttr->keywords);
        self::assertSame('0.1.0', $skillAttr->since);
    }
}
