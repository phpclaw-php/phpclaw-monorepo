<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Skills;

use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\Guards\CodeInjectionGuard;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Tools\Security\BlockedPaths;
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

    public function test_content_notes_file_write_must_be_enabled(): void
    {
        $content = $this->skill->content();

        self::assertStringContainsString('file_write is not enabled by default', $content);
        self::assertStringContainsString('phpclaw_extra_tools', $content);
        self::assertStringContainsString('WP-CLI', $content);
    }

    public function test_content_asks_for_no_file_name_that_file_write_refuses(): void
    {
        preg_match_all('/[\w{}.\/-]+\.(?:php|json|xml|lock|yml|yaml|dist)\b/', $this->skill->content(), $matches);

        $requested = array_unique(array_map(static fn (string $path): string => strtolower(basename($path)), $matches[0]));
        $refused = [...BlockedPaths::FILENAMES, ...BlockedPaths::EDIT_FILENAMES];

        self::assertNotSame([], $requested);
        self::assertSame([], array_values(array_intersect($requested, $refused)));
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
