<?php

declare(strict_types=1);

namespace PhpClaw\WordPress;

function add_menu_page(
    string $page_title,
    string $menu_title,
    string $capability,
    string $menu_slug,
    mixed $callback = null,
    string $icon_url = '',
    mixed $position = null,
): string {
    Tests\Unit\MenuSpy::$menu = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'icon_url', 'position');

    return 'toplevel_page_'.$menu_slug;
}

function add_submenu_page(
    string $parent_slug,
    string $page_title,
    string $menu_title,
    string $capability,
    string $menu_slug,
    mixed $callback = null,
): string {
    Tests\Unit\MenuSpy::$subs[] = compact('parent_slug', 'menu_title', 'capability', 'menu_slug');

    return 'phpclaw_page_'.$menu_slug;
}

namespace PhpClaw\WordPress\Admin;

use PhpClaw\WordPress\Tests\Unit\MenuSpy;

function add_submenu_page(
    string $parent_slug,
    string $page_title,
    string $menu_title,
    string $capability,
    string $menu_slug,
    mixed $callback = null,
): string {
    MenuSpy::$subs[] = compact('parent_slug', 'menu_title', 'capability', 'menu_slug');

    return 'phpclaw_page_'.$menu_slug;
}

namespace PhpClaw\WordPress\Tests\Unit;

use Brain\Monkey;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

final class MenuSpy
{
    public static array $menu = [];

    public static array $subs = [];

    public static array $removed = [];

    public static function reset(): void
    {
        self::$menu = [];
        self::$subs = [];
        self::$removed = [];
    }
}

#[CoversClass(Plugin::class)]
final class AdminMenuRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        MenuSpy::reset();

        Monkey\Functions\stubs([
            '__' => static fn (string $s, string $d = ''): string => $s,
        ]);
        Monkey\Functions\when('current_user_can')->justReturn(true);
        Monkey\Functions\when('remove_submenu_page')->alias(
            static function (string $parent, string $slug): bool {
                MenuSpy::$removed[] = [$parent, $slug];

                return true;
            },
        );
    }

    protected function tearDown(): void
    {
        MenuSpy::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_the_top_level_menu_opens_on_the_chat_capability(): void
    {
        Plugin::registerAdminMenu();

        self::assertSame('phpclaw', MenuSpy::$menu['menu_slug']);
        self::assertSame('phpclaw_use_chat', MenuSpy::$menu['capability']);
        self::assertNotSame('manage_options', MenuSpy::$menu['capability']);
    }

    public function test_the_top_level_menu_carries_an_inline_svg_icon_and_a_fixed_position(): void
    {
        Plugin::registerAdminMenu();

        self::assertStringStartsWith('data:image/svg+xml;base64,', MenuSpy::$menu['icon_url']);
        self::assertStringContainsString('<svg', (string) base64_decode(
            substr(MenuSpy::$menu['icon_url'], strlen('data:image/svg+xml;base64,')),
            true,
        ));
        self::assertSame(80, MenuSpy::$menu['position']);
    }

    public function test_five_subpages_register_under_the_phpclaw_parent(): void
    {
        Plugin::registerAdminMenu();

        self::assertCount(5, MenuSpy::$subs);

        foreach (MenuSpy::$subs as $sub) {
            self::assertSame('phpclaw', $sub['parent_slug']);
        }

        self::assertSame(
            ['Settings', 'Chat', 'Analytics', 'Guide', 'About'],
            array_column(MenuSpy::$subs, 'menu_title'),
        );
    }

    public function test_settings_stays_administrator_only_while_the_other_four_use_the_chat_capability(): void
    {
        Plugin::registerAdminMenu();

        $caps = array_combine(
            array_column(MenuSpy::$subs, 'menu_title'),
            array_column(MenuSpy::$subs, 'capability'),
        );

        self::assertSame('phpclaw_use_admin_chat', $caps['Settings']);

        foreach (['Chat', 'Analytics', 'Guide', 'About'] as $page) {
            self::assertSame('phpclaw_use_chat', $caps[$page]);
        }
    }

    public function test_an_administrator_keeps_the_settings_entry_in_the_menu(): void
    {
        Monkey\Functions\when('current_user_can')->alias(
            static fn (string $cap): bool => $cap === 'phpclaw_use_admin_chat',
        );

        Plugin::registerAdminMenu();

        self::assertSame([], MenuSpy::$removed);
    }

    public function test_a_chat_tier_user_loses_the_parent_entry_that_points_at_settings(): void
    {
        Monkey\Functions\when('current_user_can')->alias(
            static fn (string $cap): bool => $cap === 'phpclaw_use_chat',
        );

        Plugin::registerAdminMenu();

        self::assertSame([['phpclaw', 'phpclaw']], MenuSpy::$removed);
    }
}
