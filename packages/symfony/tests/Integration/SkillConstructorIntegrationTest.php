<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;

final class SkillNeedingConstructorArgument implements SkillInterface
{
    public function __construct(private readonly string $required) {}

    public function name(): string
    {
        return 'needs_constructor_argument';
    }

    public function description(): string
    {
        return 'A skill an app registers with a required constructor argument.';
    }

    public function tags(): array
    {
        return ['probe'];
    }

    public function content(): string
    {
        return 'never reached';
    }
}

final class ConstructorFreeSkill implements SkillInterface
{
    public function name(): string
    {
        return 'constructor_free';
    }

    public function description(): string
    {
        return 'A skill an app registers with no constructor argument.';
    }

    public function tags(): array
    {
        return ['probe'];
    }

    public function content(): string
    {
        return 'reachable';
    }
}

final class SkillConstructorIntegrationTest extends IntegrationTestCase
{
    public function test_a_skill_needing_constructor_arguments_is_skipped_instead_of_killing_the_boot(): void
    {
        IntegrationKernel::configure(['skills' => [
            ['class' => SkillNeedingConstructorArgument::class],
            ['class' => ConstructorFreeSkill::class],
        ]]);

        self::bootKernel();

        $names = array_map(
            static fn (SkillInterface $skill): string => $skill->name(),
            SkillRegistry::all(),
        );

        self::assertContains('constructor_free', $names);
        self::assertNotContains('needs_constructor_argument', $names);
    }

    public function test_a_constructor_free_skill_alone_still_registers(): void
    {
        IntegrationKernel::configure(['skills' => [['class' => ConstructorFreeSkill::class]]]);

        self::bootKernel();

        $names = array_map(
            static fn (SkillInterface $skill): string => $skill->name(),
            SkillRegistry::all(),
        );

        self::assertContains('constructor_free', $names);
    }

    public function test_resolving_the_engine_survives_a_skill_needing_constructor_arguments(): void
    {
        IntegrationKernel::configure(['skills' => [
            ['class' => SkillNeedingConstructorArgument::class],
            ['class' => ConstructorFreeSkill::class],
        ]]);

        self::bootKernel();

        $engine = self::getContainer()->get(ClawInterface::class);

        $config = (new \ReflectionObject($engine))->getProperty('config');
        $config->setAccessible(true);

        $names = array_map(
            static fn (SkillInterface $skill): string => $skill->name(),
            $config->getValue($engine)->skills,
        );

        self::assertContains('constructor_free', $names);
        self::assertNotContains('needs_constructor_argument', $names);
    }
}
