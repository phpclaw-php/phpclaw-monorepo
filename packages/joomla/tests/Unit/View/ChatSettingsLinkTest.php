<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\View;

use PHPUnit\Framework\TestCase;

final class ChatSettingsLinkTest extends TestCase
{
    public function test_the_view_exposes_a_configure_flag_to_the_layout(): void
    {
        self::assertStringContainsString(
            'public bool $canConfigure',
            $this->view(),
            'The layout needs a flag to gate the Settings link on.',
        );
    }

    public function test_the_configure_flag_defaults_to_denied(): void
    {
        self::assertStringContainsString(
            'public bool $canConfigure = false;',
            $this->view(),
            'The flag must fail closed so a hydration failure hides the link.',
        );
    }

    public function test_the_settings_url_is_empty_when_the_identity_cannot_configure(): void
    {
        self::assertStringContainsString(
            '$settingsUrl = $canConfigure',
            $this->layout(),
            'The URL itself must be withheld, not merely the anchor.',
        );
    }

    public function test_every_settings_anchor_sits_behind_the_flag(): void
    {
        $layout = $this->layout();

        preg_match_all('/^.*\$settingsUrl.*$/m', $layout, $matches);

        $anchors = array_values(array_filter(
            $matches[0],
            static fn (string $line): bool => str_contains($line, '<a href='),
        ));

        self::assertNotSame([], $anchors, 'The Settings anchors were not found.');

        foreach ($anchors as $anchor) {
            $offset = strpos($layout, $anchor);
            $preceding = substr($layout, max(0, $offset - 200), 200);

            self::assertStringContainsString(
                'if ($canConfigure)',
                $preceding,
                'A Settings anchor renders without the configure guard: '.trim($anchor),
            );
        }
    }

    public function test_the_toolbar_and_the_layout_share_one_capability_test(): void
    {
        self::assertSame(
            1,
            preg_match_all("/authorise\('core\.admin'/", $this->view()),
            'The capability must be tested in exactly one place, or the two can drift apart.',
        );
    }

    private function layout(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/component/tmpl/chat/default.php',
        );
    }

    private function view(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/component/src/View/Chat/HtmlView.php',
        );
    }
}
