<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Skills;

use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PHPUnit\Framework\TestCase;

final class PluginSkillsTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    public function test_empty_skills_config_registers_nothing(): void
    {
        $skills = SkillResolver::resolve([]);
        self::assertSame([], $skills);
    }

    public function test_inline_array_skill_resolved(): void
    {
        $skills = SkillResolver::resolve([[
            'name' => 'opencart-expert',
            'description' => 'OpenCart best practices',
            'tags' => ['opencart', 'ecommerce'],
            'content' => 'Use OpenCart model classes for database access.',
        ]]);

        self::assertCount(1, $skills);
        self::assertSame('opencart-expert', $skills[0]->name());
        self::assertSame('Use OpenCart model classes for database access.', $skills[0]->content());
    }

    public function test_missing_file_skill_skipped_silently(): void
    {
        $skills = SkillResolver::resolve([['file' => '/nonexistent/opencart-skill.md']]);
        self::assertSame([], $skills);
    }

    public function test_nonexistent_class_skill_skipped_silently(): void
    {
        $skills = SkillResolver::resolve([['class' => 'App\\Skills\\OpenCartDoesNotExist']]);
        self::assertSame([], $skills);
    }

    public function test_multiple_inline_skills_all_resolved(): void
    {
        $skills = SkillResolver::resolve([
            [
                'name' => 'oc-products',
                'description' => 'Product management tips',
                'tags' => ['products', 'catalog'],
                'content' => 'Use oc_product table for product data.',
            ],
            [
                'name' => 'oc-orders',
                'description' => 'Order management tips',
                'tags' => ['orders'],
                'content' => 'Use oc_order table for order data.',
            ],
        ]);

        self::assertCount(2, $skills);
    }

    public function test_tags_optional_via_canonical_lenient_rule(): void
    {
        $skills = SkillResolver::resolve([[
            'name' => 'no-tags-skill',
            'description' => 'No tags provided',
            'content' => 'Content here.',
        ]]);

        self::assertCount(1, $skills);
        self::assertInstanceOf(SkillInterface::class, $skills[0]);
        self::assertSame('no-tags-skill', $skills[0]->name());
    }
}
