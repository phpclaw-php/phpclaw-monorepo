<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Skills\ArraySkill;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
    }

    public function test_registered_skill_appears_in_all(): void
    {
        $skill = new ArraySkill('deploy', 'deployment guide', ['docker'], 'Deploy with docker.');
        SkillRegistry::register($skill);

        $this->assertCount(1, SkillRegistry::all());
        $this->assertSame('deploy', SkillRegistry::all()[0]->name());
    }

    public function test_registering_same_name_overwrites(): void
    {
        SkillRegistry::register(new ArraySkill('s', 'first', [], 'content a'));
        SkillRegistry::register(new ArraySkill('s', 'second', [], 'content b'));

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('content b', $all[0]->content());
    }

    public function test_match_returns_empty_when_registry_empty(): void
    {
        $this->assertSame([], SkillRegistry::match('deploy the server'));
    }

    public function test_match_returns_empty_when_no_word_overlap(): void
    {
        SkillRegistry::register(new ArraySkill('deploy', 'docker deployment', ['docker'], 'Deploy.'));
        $this->assertSame([], SkillRegistry::match('hello world'));
    }

    public function test_match_ignores_stopword_only_overlap(): void
    {
        SkillRegistry::register(new ArraySkill(
            'domain_modeling',
            'domain modelling guide',
            ['during the session', 'challenge against the glossary', 'cross-reference with code'],
            'Modelling.',
        ));

        $this->assertSame([], SkillRegistry::match('List the products with their name and regular price.'));
    }

    public function test_match_still_selects_on_a_single_real_keyword(): void
    {
        SkillRegistry::register(new ArraySkill('docker_basics', 'docker basics', ['docker'], 'Docker.'));
        $results = SkillRegistry::match('how do I set up docker for this');

        $this->assertCount(1, $results);
        $this->assertSame('docker_basics', $results[0]->name());
    }

    public function test_match_returns_skill_with_overlapping_words(): void
    {
        SkillRegistry::register(new ArraySkill('deploy', 'deployment guide', ['docker'], 'Deploy with docker.'));
        $results = SkillRegistry::match('how do I deploy using docker');

        $this->assertCount(1, $results);
        $this->assertSame('deploy', $results[0]->name());
    }

    public function test_match_scores_by_word_overlap_and_returns_top_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            SkillRegistry::register(new ArraySkill(
                "skill{$i}",
                "deploy docker server guide number {$i}",
                ['deploy'],
                "content {$i}",
            ));
        }

        $results = SkillRegistry::match('deploy docker server guide', limit: 3);
        $this->assertCount(3, $results);
    }

    public function test_match_highest_scoring_skill_returned_first(): void
    {
        SkillRegistry::register(new ArraySkill('low', 'deploy', [], 'deploy once'));
        SkillRegistry::register(new ArraySkill('high', 'deploy docker server', ['deploy', 'docker'], 'deploy docker server content'));

        $results = SkillRegistry::match('deploy docker server');
        $this->assertSame('high', $results[0]->name());
    }

    public function test_match_works_with_three_char_keywords_like_php(): void
    {
        SkillRegistry::register(new ArraySkill('php-best-practices', 'PHP coding standards', ['php', 'code'], 'Follow PSR standards.'));
        $results = SkillRegistry::match('fix my php issue');

        $this->assertCount(1, $results);
        $this->assertSame('php-best-practices', $results[0]->name());
    }

    public function test_match_works_with_three_char_keywords_like_sql(): void
    {
        SkillRegistry::register(new ArraySkill('sql-guide', 'SQL query guide', ['sql', 'database'], 'Write efficient queries.'));
        $results = SkillRegistry::match('optimise my sql query');

        $this->assertCount(1, $results);
        $this->assertSame('sql-guide', $results[0]->name());
    }

    public function test_duplicate_words_in_message_do_not_inflate_score(): void
    {
        SkillRegistry::register(new ArraySkill('deploy', 'docker deployment guide', ['docker', 'deploy'], 'Instructions.'));
        SkillRegistry::register(new ArraySkill('other', 'unrelated topic', ['unrelated'], 'Other instructions.'));

        $results = SkillRegistry::match('deploy deploy deploy');

        $this->assertCount(1, $results);
        $this->assertSame('deploy', $results[0]->name());
    }

    public function test_stopwords_in_description_do_not_cause_false_positive(): void
    {
        SkillRegistry::register(new ArraySkill(
            'php-practices',
            'PHP coding standards and best practices for code review',
            ['php', 'code'],
            'Follow PSR standards.',
        ));

        $results = SkillRegistry::match('how and for what reason');

        $this->assertSame([], $results, 'Stopwords in description must not trigger a match');
    }

    public function test_reset_clears_all_skills(): void
    {
        SkillRegistry::register(new ArraySkill('s', 'desc', [], 'content'));
        SkillRegistry::reset();

        $this->assertSame([], SkillRegistry::all());
        $this->assertSame([], SkillRegistry::match('desc'));
    }

    public function test_register_fires_skill_loaded_event(): void
    {
        $captured = [];
        HookRegistry::on(LifecycleEvent::SkillLoaded->value, function (array $ctx) use (&$captured): void {
            $captured[] = $ctx;
        });

        $skill = new ArraySkill('deploy', 'deployment guide', ['docker'], 'Deploy with docker.');
        SkillRegistry::register($skill);

        $this->assertCount(1, $captured);
        $this->assertSame('deploy', $captured[0]['skill_name']);
        $this->assertSame(ArraySkill::class, $captured[0]['skill_class']);
    }

    public function test_match_breaks_score_ties_alphabetically(): void
    {
        foreach (['zebra', 'apple', 'mango'] as $name) {
            SkillRegistry::register(new ArraySkill($name, 'shared beta keyword', ['beta'], strtoupper($name)));
        }

        $names = array_map(static fn ($s): string => $s->name(), SkillRegistry::match('beta', 2));

        $this->assertSame(['apple', 'mango'], $names);
    }

    public function test_match_splits_multiword_tag_into_individual_words(): void
    {
        SkillRegistry::register(new ArraySkill(
            'html-converter',
            'converts documents',
            ['Step 4: Generate HTML'],
            'Turns markdown into HTML.',
        ));

        $this->assertSame(['html-converter'], array_map(
            static fn ($s) => $s->name(),
            SkillRegistry::match('please generate this'),
        ));
    }

    public function test_match_splits_hyphenated_tag_into_individual_words(): void
    {
        SkillRegistry::register(new ArraySkill(
            'skill_md',
            'skill description',
            ['html-everything'],
            'Recipe content.',
        ));

        $this->assertSame(['skill_md'], array_map(
            static fn ($s) => $s->name(),
            SkillRegistry::match('convert this to html'),
        ));
    }

    public function test_match_splits_hyphenated_description_into_individual_words(): void
    {
        SkillRegistry::register(new ArraySkill('skill_md', '/html-everything', [], 'Recipe content.'));

        $this->assertSame(['skill_md'], array_map(
            static fn ($s) => $s->name(),
            SkillRegistry::match('Convert this into HTML please'),
        ));
    }

    public function test_match_still_works_with_curated_short_tags_after_tag_tokenisation(): void
    {
        SkillRegistry::register(new ArraySkill('php-guide', 'coding standards', ['php'], 'content'));

        $this->assertSame(['php-guide'], array_map(
            static fn ($s) => $s->name(),
            SkillRegistry::match('fix my php bug'),
        ));
    }
}
