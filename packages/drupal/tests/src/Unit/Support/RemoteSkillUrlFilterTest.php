<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Support;

use PhpClaw\Drupal\Support\RemoteSkillUrlFilter;
use PHPUnit\Framework\TestCase;

final class RemoteSkillUrlFilterTest extends TestCase
{
    public function test_filter_keeps_valid_md_and_json_urls(): void
    {
        $result = RemoteSkillUrlFilter::filter([
            'https://a.dev/skills.md',
            'https://b.dev/skills.json',
        ]);

        self::assertSame('https://a.dev/skills.md,https://b.dev/skills.json', $result);
    }

    public function test_filter_drops_non_https_url(): void
    {
        self::assertSame('', RemoteSkillUrlFilter::filter(['http://a.dev/skills.md']));
    }

    public function test_filter_drops_url_with_no_recognised_extension(): void
    {
        self::assertSame('', RemoteSkillUrlFilter::filter(['https://a.dev/skills']));
    }

    public function test_filter_keeps_uppercase_extension_case_insensitively(): void
    {
        self::assertSame('https://a.dev/skills.JSON', RemoteSkillUrlFilter::filter(['https://a.dev/skills.JSON']));
    }

    public function test_filter_drops_invalid_and_keeps_valid_from_mixed_list(): void
    {
        $result = RemoteSkillUrlFilter::filter([
            'https://a.dev/skills.md',
            'http://b.dev/skills.md',
            'https://c.dev/skills',
            'https://d.dev/skills.json',
            'https://e.dev/skills.JSON',
        ]);

        self::assertSame(
            'https://a.dev/skills.md,https://d.dev/skills.json,https://e.dev/skills.JSON',
            $result,
        );
    }

    public function test_filter_accepts_comma_separated_string_input(): void
    {
        $result = RemoteSkillUrlFilter::filter('https://a.dev/skills.md, http://b.dev/skills.md, https://c.dev/skills.json');

        self::assertSame('https://a.dev/skills.md,https://c.dev/skills.json', $result);
    }

    public function test_filter_accepts_newline_separated_string_input(): void
    {
        $result = RemoteSkillUrlFilter::filter("https://a.dev/skills.md\nhttps://b.dev/skills\nhttps://c.dev/skills.json");

        self::assertSame('https://a.dev/skills.md,https://c.dev/skills.json', $result);
    }

    public function test_filter_empty_input_returns_empty_string(): void
    {
        self::assertSame('', RemoteSkillUrlFilter::filter([]));
        self::assertSame('', RemoteSkillUrlFilter::filter(''));
    }

    public function test_filter_drops_empty_and_whitespace_entries(): void
    {
        $result = RemoteSkillUrlFilter::filter(['', '   ', 'https://a.dev/skills.md']);

        self::assertSame('https://a.dev/skills.md', $result);
    }
}
