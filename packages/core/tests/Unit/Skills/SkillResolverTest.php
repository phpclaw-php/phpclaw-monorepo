<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\FileSkill;
use PhpClaw\Skills\SkillResolver;
use PHPUnit\Framework\TestCase;

final class SkillResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/phpclaw-skillresolver-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function writeSkillFile(string $name, string $body, string $description = 'test fixture'): string
    {
        $path = $this->tmpDir.'/'.$name.'.md';
        $contents = "---\nname: {$name}\ndescription: {$description}\ntags: [test]\n---\n\n{$body}\n";
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_empty_entries_returns_empty_array(): void
    {
        $this->assertSame([], SkillResolver::resolve([]));
    }

    public function test_non_array_entry_is_skipped(): void
    {
        $skipped = [];
        $result = SkillResolver::resolve(
            ['just-a-string', 42, null],
            static function (mixed $entry, string $reason) use (&$skipped): void {
                $skipped[] = [$entry, $reason];
            },
        );

        $this->assertSame([], $result);
        $this->assertCount(3, $skipped);
        $this->assertSame(['just-a-string', 'not-an-array'], $skipped[0]);
    }

    public function test_inline_shape_creates_array_skill_with_all_fields(): void
    {
        $skills = SkillResolver::resolve([
            [
                'name' => 'tone',
                'description' => 'Match the brand tone',
                'tags' => ['style', 'voice'],
                'content' => 'Use a warm, concise tone.',
            ],
        ]);

        $this->assertCount(1, $skills);
        $this->assertInstanceOf(ArraySkill::class, $skills[0]);
        $this->assertSame('tone', $skills[0]->name());
        $this->assertSame('Match the brand tone', $skills[0]->description());
        $this->assertSame(['style', 'voice'], $skills[0]->tags());
        $this->assertSame('Use a warm, concise tone.', $skills[0]->content());
    }

    public function test_inline_shape_tags_default_to_empty_array_when_omitted(): void
    {
        $skills = SkillResolver::resolve([
            [
                'name' => 'tone',
                'description' => 'desc',
                'content' => 'instr',
            ],
        ]);

        $this->assertCount(1, $skills);
        $this->assertSame([], $skills[0]->tags());
    }

    public function test_inline_missing_name_is_skipped(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['description' => 'd', 'content' => 'c']],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertSame(['unrecognised-shape'], $skipped);
    }

    public function test_inline_missing_description_is_skipped(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['name' => 'n', 'content' => 'c']],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertSame(['unrecognised-shape'], $skipped);
    }

    public function test_inline_missing_content_is_skipped(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['name' => 'n', 'description' => 'd']],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertSame(['unrecognised-shape'], $skipped);
    }

    public function test_file_shape_creates_file_skill(): void
    {
        $path = $this->writeSkillFile('seo', 'Use keywords.');

        $skills = SkillResolver::resolve([['file' => $path]]);

        $this->assertCount(1, $skills);
        $this->assertInstanceOf(FileSkill::class, $skills[0]);
    }

    public function test_file_shape_missing_path_triggers_on_skip(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['file' => '/tmp/definitely-does-not-exist-'.bin2hex(random_bytes(8)).'.md']],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertCount(1, $skipped);
        $this->assertStringStartsWith('file-error:', $skipped[0]);
    }

    public function test_class_shape_instantiates_valid_skill_class(): void
    {
        $skills = SkillResolver::resolve([['class' => ResolverFixtureSkill::class]]);

        $this->assertCount(1, $skills);
        $this->assertInstanceOf(ResolverFixtureSkill::class, $skills[0]);
        $this->assertSame('resolver_fixture', $skills[0]->name());
    }

    public function test_class_shape_skips_non_existent_class(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['class' => 'Doesnt\\Exist\\AtAll']],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertCount(1, $skipped);
        $this->assertStringStartsWith('class-not-found:', $skipped[0]);
    }

    public function test_class_shape_skips_class_that_does_not_implement_skill_interface(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['class' => \stdClass::class]],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertCount(1, $skipped);
        $this->assertStringStartsWith('class-not-a-skill:', $skipped[0]);
    }

    public function test_mixed_entry_shapes_all_resolve(): void
    {
        $path = $this->writeSkillFile('file-one', 'Body');

        $skills = SkillResolver::resolve([
            ['name' => 'inline', 'description' => 'i', 'content' => 'c'],
            ['file' => $path],
            ['class' => ResolverFixtureSkill::class],
        ]);

        $this->assertCount(3, $skills);
        $this->assertInstanceOf(ArraySkill::class, $skills[0]);
        $this->assertInstanceOf(FileSkill::class, $skills[1]);
        $this->assertInstanceOf(ResolverFixtureSkill::class, $skills[2]);
    }

    public function test_file_shape_wins_over_class_when_both_present(): void
    {
        $path = $this->writeSkillFile('order', 'Body');

        $skills = SkillResolver::resolve([
            ['file' => $path, 'class' => ResolverFixtureSkill::class],
        ]);

        $this->assertCount(1, $skills);
        $this->assertInstanceOf(FileSkill::class, $skills[0]);
    }

    public function test_class_shape_wins_over_inline_when_both_present(): void
    {
        $skills = SkillResolver::resolve([
            [
                'class' => ResolverFixtureSkill::class,
                'name' => 'inline',
                'description' => 'i',
                'content' => 'c',
            ],
        ]);

        $this->assertCount(1, $skills);
        $this->assertInstanceOf(ResolverFixtureSkill::class, $skills[0]);
    }

    public function test_unrecognised_shape_is_skipped_with_reason(): void
    {
        $skipped = [];
        $skills = SkillResolver::resolve(
            [['something-else' => 'value']],
            static function (mixed $e, string $r) use (&$skipped): void {
                $skipped[] = $r;
            },
        );

        $this->assertSame([], $skills);
        $this->assertSame(['unrecognised-shape'], $skipped);
    }

    public function test_on_skip_can_be_null_silent_mode(): void
    {
        $skills = SkillResolver::resolve([
            ['something-else' => 'value'],
            ['class' => 'Bad\\Class\\Path'],
        ]);

        $this->assertSame([], $skills);
    }

    public function test_resolved_skills_implement_skill_interface(): void
    {
        $path = $this->writeSkillFile('iface', 'B');
        $skills = SkillResolver::resolve([
            ['name' => 'n', 'description' => 'd', 'content' => 'c'],
            ['file' => $path],
            ['class' => ResolverFixtureSkill::class],
        ]);

        foreach ($skills as $skill) {
            $this->assertInstanceOf(SkillInterface::class, $skill);
        }
    }
}

final class ResolverFixtureSkill implements SkillInterface
{
    public function name(): string
    {
        return 'resolver_fixture';
    }

    public function description(): string
    {
        return 'Fixture used by SkillResolverTest';
    }

    public function tags(): array
    {
        return ['fixture'];
    }

    public function content(): string
    {
        return 'Test fixture body.';
    }
}
