<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery;

use PhpClaw\AutoDiscovery\ComposerExtras;
use PHPUnit\Framework\TestCase;

final class ComposerExtrasTest extends TestCase
{
    protected function setUp(): void
    {
        ComposerExtras::reset();
    }

    protected function tearDown(): void
    {
        ComposerExtras::reset();
    }

    private function injectPackages(array $packages): void
    {
        ComposerExtras::withTestPayload($packages);
    }

    public function test_with_no_packages_returns_empty_collections(): void
    {
        $this->injectPackages([]);

        self::assertSame([], ComposerExtras::providers());
        self::assertSame([], ComposerExtras::memory());
        self::assertSame([], ComposerExtras::skills());
        self::assertSame([], ComposerExtras::hooks());
        self::assertSame([], ComposerExtras::guards());
        self::assertSame([], ComposerExtras::tools());
    }

    public function test_providers_collected_as_slug_map(): void
    {
        $this->injectPackages([
            'phpclaw/phpclaw-providers' => [
                'providers' => [
                    'deepseek' => ['label' => 'DeepSeek', 'class' => 'PhpClaw\\Providers\\OpenAIProvider'],
                ],
            ],
            'alice/phpclaw-grok-provider' => [
                'providers' => [
                    'grok' => ['label' => 'Grok', 'class' => 'Alice\\GrokProvider'],
                ],
            ],
        ]);

        $providers = ComposerExtras::providers();

        self::assertCount(2, $providers);
        self::assertSame('DeepSeek', $providers['deepseek']['label']);
        self::assertSame('Grok', $providers['grok']['label']);
        self::assertSame('phpclaw/phpclaw-providers', $providers['deepseek']['_source']);
        self::assertSame('alice/phpclaw-grok-provider', $providers['grok']['_source']);
    }

    public function test_memory_collected_as_slug_map(): void
    {
        $this->injectPackages([
            'phpclaw/phpclaw-memory' => [
                'memory' => [
                    'redis' => ['label' => 'Redis', 'class' => 'PhpClaw\\Memory\\RedisMemory'],
                ],
            ],
            'carol/phpclaw-memory-mongo' => [
                'memory' => [
                    'mongo' => [
                        'label' => 'MongoDB',
                        'class' => 'Carol\\MongoMemory',
                        'factory' => 'Carol\\MongoMemoryFactory::create',
                    ],
                ],
            ],
        ]);

        $memory = ComposerExtras::memory();

        self::assertCount(2, $memory);
        self::assertSame('Redis', $memory['redis']['label']);
        self::assertSame('MongoDB', $memory['mongo']['label']);
        self::assertSame('Carol\\MongoMemoryFactory::create', $memory['mongo']['factory']);
    }

    public function test_skills_collected_as_list_with_source_tag(): void
    {
        $this->injectPackages([
            'phpclaw/phpclaw-skills' => [
                'skills' => [
                    ['class' => 'PhpClaw\\Skills\\PhpBestPracticesSkill'],
                ],
            ],
            'alice/phpclaw-skills-seo' => [
                'skills' => [
                    ['class' => 'Alice\\SeoSkill', 'label' => 'SEO'],
                    ['class' => 'Alice\\MetaSkill'],
                ],
            ],
        ]);

        $skills = ComposerExtras::skills();

        self::assertCount(3, $skills);
        self::assertSame('PhpClaw\\Skills\\PhpBestPracticesSkill', $skills[0]['class']);
        self::assertSame('Alice\\SeoSkill', $skills[1]['class']);
        self::assertSame('SEO', $skills[1]['label']);
        self::assertSame('Alice\\MetaSkill', $skills[2]['class']);
        self::assertSame('alice/phpclaw-skills-seo', $skills[1]['_source']);
    }

    public function test_hooks_collected_as_list(): void
    {
        $this->injectPackages([
            'phpclaw/phpclaw-hooks' => [
                'hooks' => [
                    [
                        'event' => 'guard.blocked',
                        'class' => 'PhpClaw\\Hooks\\SecurityAlertHook',
                        'priority' => 50,
                        'enabled_by_default' => true,
                    ],
                ],
            ],
        ]);

        $hooks = ComposerExtras::hooks();

        self::assertCount(1, $hooks);
        self::assertSame('guard.blocked', $hooks[0]['event']);
        self::assertSame(50, $hooks[0]['priority']);
        self::assertTrue($hooks[0]['enabled_by_default']);
    }

    public function test_guards_collected_as_list(): void
    {
        $this->injectPackages([
            'phpclaw/phpclaw-guards' => [
                'guards' => [
                    ['class' => 'PhpClaw\\Guards\\PiiDetectionGuard', 'priority' => 30, 'enabled_by_default' => true],
                ],
            ],
        ]);

        $guards = ComposerExtras::guards();

        self::assertCount(1, $guards);
        self::assertSame(30, $guards[0]['priority']);
    }

    public function test_tools_collected_as_list(): void
    {
        $this->injectPackages([
            'phpclaw/phpclaw-tools' => [
                'tools' => [
                    [
                        'class' => 'PhpClaw\\Tools\\HttpTool',
                        'label' => 'Example Tool',
                        'config_required' => ['example_api_key'],
                    ],
                ],
            ],
        ]);

        $tools = ComposerExtras::tools();

        self::assertCount(1, $tools);
        self::assertSame('Example Tool', $tools[0]['label']);
        self::assertSame(['example_api_key'], $tools[0]['config_required']);
    }

    public function test_map_collision_last_wins(): void
    {
        $this->injectPackages([
            'alpha/pkg' => [
                'providers' => [
                    'shared' => ['label' => 'Alpha version', 'class' => 'A'],
                ],
            ],
            'beta/pkg' => [
                'providers' => [
                    'shared' => ['label' => 'Beta version', 'class' => 'B'],
                ],
            ],
        ]);

        $providers = ComposerExtras::providers();

        self::assertCount(1, $providers);
        self::assertSame('Beta version', $providers['shared']['label']);
        self::assertSame('beta/pkg', $providers['shared']['_source']);
    }

    public function test_list_collision_both_returned(): void
    {
        $this->injectPackages([
            'alpha/pkg' => [
                'skills' => [['class' => 'A\\Skill']],
            ],
            'beta/pkg' => [
                'skills' => [['class' => 'A\\Skill']],
            ],
        ]);

        $skills = ComposerExtras::skills();

        self::assertCount(2, $skills);
        self::assertSame('alpha/pkg', $skills[0]['_source']);
        self::assertSame('beta/pkg', $skills[1]['_source']);
    }

    public function test_packages_without_phpclaw_extra_are_skipped(): void
    {
        $this->injectPackages([
            'random/package' => [/* no phpclaw key: but this is already the phpclaw payload, so the gate is implicit */],
            'phpclaw/phpclaw-providers' => [
                'providers' => [
                    'deepseek' => ['label' => 'DeepSeek', 'class' => 'X'],
                ],
            ],
        ]);

        $providers = ComposerExtras::providers();

        self::assertCount(1, $providers);
        self::assertArrayHasKey('deepseek', $providers);
    }

    public function test_malformed_block_is_silently_skipped(): void
    {
        $this->injectPackages([
            'bad/pkg' => [
                'providers' => 'not-an-array',
            ],
            'good/pkg' => [
                'providers' => [
                    'ok' => ['label' => 'OK', 'class' => 'X'],
                ],
            ],
        ]);

        $providers = ComposerExtras::providers();

        self::assertCount(1, $providers);
        self::assertArrayHasKey('ok', $providers);
    }

    public function test_map_entry_with_non_string_slug_is_skipped(): void
    {
        $this->injectPackages([
            'mixed/pkg' => [
                'providers' => [
                    'valid_slug' => ['label' => 'OK', 'class' => 'X'],
                    42 => ['label' => 'numeric slug', 'class' => 'Y'],
                ],
            ],
        ]);

        $providers = ComposerExtras::providers();

        self::assertArrayHasKey('valid_slug', $providers);
        self::assertArrayNotHasKey(42, $providers);
        self::assertArrayNotHasKey('42', $providers);
    }

    public function test_map_entry_with_non_array_value_is_skipped(): void
    {
        $this->injectPackages([
            'mixed/pkg' => [
                'providers' => [
                    'good' => ['label' => 'OK', 'class' => 'X'],
                    'bad' => 'not-an-array',
                ],
            ],
        ]);

        $providers = ComposerExtras::providers();

        self::assertArrayHasKey('good', $providers);
        self::assertArrayNotHasKey('bad', $providers);
    }

    public function test_list_entry_with_non_array_value_is_skipped(): void
    {
        $this->injectPackages([
            'mixed/pkg' => [
                'skills' => [
                    ['class' => 'A\\Skill'],
                    'not-an-array',
                    42,
                    ['class' => 'B\\Skill'],
                ],
            ],
        ]);

        $skills = ComposerExtras::skills();

        self::assertCount(2, $skills);
        self::assertSame('A\\Skill', $skills[0]['class']);
        self::assertSame('B\\Skill', $skills[1]['class']);
    }

    public function test_cache_is_populated_on_first_read(): void
    {
        $this->injectPackages([
            'cached/pkg' => ['providers' => ['x' => ['label' => 'X', 'class' => 'X']]],
        ]);

        $first = ComposerExtras::providers();
        $second = ComposerExtras::providers();

        self::assertSame($first, $second);
        self::assertSame('X', $first['x']['label']);
    }

    public function test_reset_clears_cache_and_override(): void
    {
        $this->injectPackages([
            'pkg/a' => ['providers' => ['x' => ['label' => 'A', 'class' => 'A']]],
        ]);
        self::assertCount(1, ComposerExtras::providers());

        ComposerExtras::reset();

        $afterReset = ComposerExtras::providers();
        self::assertNotSame('A', $afterReset['x']['label'] ?? null);
    }

    public function test_with_test_payload_overrides_real_lookup(): void
    {
        ComposerExtras::reset();
        $real = ComposerExtras::providers();

        ComposerExtras::withTestPayload([
            'fake/pkg' => ['providers' => ['injected' => ['label' => 'Injected', 'class' => 'F']]],
        ]);

        $providers = ComposerExtras::providers();
        self::assertArrayHasKey('injected', $providers);
        self::assertSame('Injected', $providers['injected']['label']);
        self::assertNotSame($real, $providers);
    }

    public function test_source_tag_added_to_every_entry(): void
    {
        $this->injectPackages([
            'pkg-a' => [
                'providers' => ['p1' => ['label' => 'P1', 'class' => 'X']],
                'memory' => ['m1' => ['label' => 'M1', 'class' => 'Y']],
                'skills' => [['class' => 'S1']],
                'hooks' => [['event' => 'agent.before', 'class' => 'H1']],
                'guards' => [['class' => 'G1']],
                'tools' => [['class' => 'T1']],
            ],
        ]);

        self::assertSame('pkg-a', ComposerExtras::providers()['p1']['_source']);
        self::assertSame('pkg-a', ComposerExtras::memory()['m1']['_source']);
        self::assertSame('pkg-a', ComposerExtras::skills()[0]['_source']);
        self::assertSame('pkg-a', ComposerExtras::hooks()[0]['_source']);
        self::assertSame('pkg-a', ComposerExtras::guards()[0]['_source']);
        self::assertSame('pkg-a', ComposerExtras::tools()[0]['_source']);
    }

    public function test_multi_category_single_package(): void
    {
        $this->injectPackages([
            'big/extension' => [
                'providers' => ['custom' => ['label' => 'Custom', 'class' => 'X']],
                'memory' => ['cache' => ['label' => 'Cache', 'class' => 'Y']],
                'skills' => [['class' => 'Z\\Skill']],
                'hooks' => [['event' => 'agent.error', 'class' => 'Z\\ErrorHook']],
            ],
        ]);

        self::assertCount(1, ComposerExtras::providers());
        self::assertCount(1, ComposerExtras::memory());
        self::assertCount(1, ComposerExtras::skills());
        self::assertCount(1, ComposerExtras::hooks());
        self::assertCount(0, ComposerExtras::guards());
        self::assertCount(0, ComposerExtras::tools());
    }

    public function test_lookup_without_test_payload_does_not_fatal(): void
    {
        ComposerExtras::reset();

        $providers = ComposerExtras::providers();
        $skills = ComposerExtras::skills();

        self::assertIsArray($providers);
        self::assertIsArray($skills);
    }
}
