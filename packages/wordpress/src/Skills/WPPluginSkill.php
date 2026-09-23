<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Skills;

use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Injects WordPress plugin-generation rules when the user asks to build a plugin.
 */
#[Skill(
    name: 'wp_plugin_creator',
    label: 'WP Plugin Creator',
    keywords: ['plugin', 'create', 'build', 'generate', 'shortcode', 'settings',
        'admin', 'widget', 'slider', 'form', 'gallery', 'woocommerce', 'extension'],
    since: '0.1.0',
)]
final class WPPluginSkill implements SkillInterface
{
    /**
     * The unique skill identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wp_plugin_creator';
    }

    /**
     * One-line description of what this skill provides.
     *
     * @return string
     */
    public function description(): string
    {
        return 'WordPress plugin generation rules';
    }

    /**
     * Keyword tags the matcher scores a message against.
     *
     * @return string[]
     */
    public function tags(): array
    {
        return ['plugin', 'create', 'build', 'generate', 'shortcode', 'settings',
            'admin', 'widget', 'slider', 'form', 'gallery', 'woocommerce', 'extension'];
    }

    /**
     * The skill content injected into the prompt when this skill matches.
     *
     * @return string
     */
    public function content(): string
    {
        return <<<'MD'
## WordPress Plugin Generation Rules (MANDATORY - follow exactly)

### Workflow
1. Plan the file list first and state it.
2. Write each file with file_write (one file per call); parent folders are created automatically.
3. After every PHP file: verify it parses (re-read it; check braces/quotes).
4. Package ONLY with wp_zip_plugin (never zip_package directly for plugins).

### File structure - exactly this shape (slug = lowercase-hyphen):
{slug}/
  {slug}.php        <- main file: header + wiring only, no HTML
  admin/settings.php
  public/render.php
  uninstall.php     <- deletes the plugin option
  assets/           <- css/js only when needed

### Plugin header (main file, first thing, exact keys):
/**
 * Plugin Name: {Human Name}
 * Description: {one sentence}
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: {author, default to the WordPress site name}
 * License: GPL-2.0-or-later
 * Text Domain: {slug}
 */
Every PHP file's first statement after the header/docblock:
defined('ABSPATH') || exit;
Never add a PHP closing tag to pure-PHP files; omit it entirely.

### Settings API (admin/settings.php):
- ONE option, an array: get_option('{slug}_options', [])
- register_setting('{slug}_group', '{slug}_options', ['sanitize_callback' => ...])
- Sanitize on save: esc_url_raw() for URL fields, sanitize_text_field() for text,
  absint() for numbers. The callback must sanitize EVERY key it stores.
- Escape on output: esc_url() / esc_attr() / esc_html(). Always.
- Settings form uses settings_fields() + do_settings_sections() (nonce handled by WP).
- Menu: add_options_page(), capability 'manage_options'.

### Shortcodes (public/render.php):
- add_shortcode('{slug}', callback): callback RETURNS a string, never echoes.
- Enqueue assets inside the callback via wp_enqueue_style/script with a
  filemtime-based version, only when the shortcode actually renders.

### Forbidden (generation fails review if present):
- Reading request superglobals without a capability check + nonce verification
- Dynamic code execution or OS commands: the eval / exec / system / passthru family, or base64-decoding user input
- Direct SQL without prepared statements
- Writing outside the plugin's own folder
- Inline script or style blobs in admin pages
MD;
    }
}
