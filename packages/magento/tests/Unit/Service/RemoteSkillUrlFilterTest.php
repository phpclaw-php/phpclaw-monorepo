<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\RemoteSkillUrlFilter;
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

    public function test_filter_drops_internal_link_local_and_local_hosts(): void
    {
        $result = RemoteSkillUrlFilter::filter([
            'https://a.dev/skills.md',
            'https://169.254.169.254/meta.json',
            'https://127.0.0.1/x.md',
            'https://localhost/y.json',
            'https://svc.internal/z.md',
        ]);

        self::assertSame('https://a.dev/skills.md', $result);
    }

    public function test_filter_drops_non_https_url(): void
    {
        $result = RemoteSkillUrlFilter::filter(['http://a.dev/skills.md']);

        self::assertSame('', $result);
    }

    public function test_filter_drops_url_with_no_recognised_extension(): void
    {
        $result = RemoteSkillUrlFilter::filter(['https://a.dev/skills']);

        self::assertSame('', $result);
    }

    public function test_filter_keeps_uppercase_extension_case_insensitively(): void
    {
        $result = RemoteSkillUrlFilter::filter(['https://a.dev/skills.JSON']);

        self::assertSame('https://a.dev/skills.JSON', $result);
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
