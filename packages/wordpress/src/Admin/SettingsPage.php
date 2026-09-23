<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

use PhpClaw\Cloud\CloudManager;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Tools\Contracts\ConfigurableToolInterface;
use PhpClaw\WordPress\Plugin;

/**
 * WordPress admin settings page - phpClaw → Settings.
 */
final class SettingsPage
{
    private const PAGE = 'phpclaw';

    private const SECTION = 'phpclaw_main';

    private const OPTION = 'phpclaw_settings';

    /**
     * Register the Settings submenu page under the phpClaw top-level menu.
     *
     * @return void
     */
    public static function register(): void
    {
        add_submenu_page(
            parent_slug: 'phpclaw',
            page_title: __('phpClaw Settings', 'phpclaw'),
            menu_title: __('Settings', 'phpclaw'),
            capability: 'phpclaw_use_admin_chat',
            menu_slug: self::PAGE,
            callback: [self::class, 'render'],
        );
    }

    /**
     * Register WordPress settings, sections, and fields for the phpClaw options page.
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        register_setting(
            self::PAGE,
            self::OPTION,
            [
                'sanitize_callback' => [self::class, 'sanitize'],
                'default' => [],
                'autoload' => 'no',
            ],
        );

        add_settings_section(
            self::SECTION,
            '',
            '__return_false',
            self::PAGE,
        );

        $fields = [
            'provider' => __('Provider', 'phpclaw'),
            'model' => __('Model', 'phpclaw'),
            'api_key' => __('API Key', 'phpclaw'),
            'base_url' => __('Base URL', 'phpclaw'),
            'system_prompt' => __('System Prompt', 'phpclaw'),
            'store_messages' => __('Store Messages', 'phpclaw'),
            'max_iterations' => __('Max Iterations', 'phpclaw'),
            'remote_skill_urls' => __('Remote Skill URLs', 'phpclaw'),
        ];

        if (class_exists(CloudManager::class)) {
            $fields['cloud_key'] = __('Cloud Key', 'phpclaw');
            $fields['cloud_signing_secret'] = __('Cloud Signing Secret', 'phpclaw');
            $fields['cloud_disable'] = __('Disable Cloud Features', 'phpclaw');
        }

        foreach (Plugin::extraToolClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }
            if (is_a($class, ConfigurableToolInterface::class, true)) {
                foreach ($class::settingsFields() as $key => $def) {
                    $fields[$key] = $def['label'];
                }
            }
        }

        foreach ($fields as $id => $label) {
            add_settings_field(
                'phpclaw_'.$id,
                $label,
                [self::class, 'renderField'],
                self::PAGE,
                self::SECTION,
                ['field' => $id, 'label_for' => 'phpclaw_'.$id],
            );
        }
    }

    /**
     * Render the main settings page with tabbed navigation, returning early for anyone
     * without phpclaw_use_admin_chat.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can('phpclaw_use_admin_chat')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1 class="pc-page-header">
                🤖 <?= esc_html__('phpClaw AI Agent Engine', 'phpclaw') ?>
            </h1>

            <?php self::renderConnectionBadge(); ?>
            <?php self::renderCloudHiddenNotice(); ?>

            <div class="pc-tab-content">
                <?php self::renderTabSettings(); ?>
            </div>

        </div>
        <?php
    }

    /**
     * Render an informational notice when cloud is installed but message storage is off.
     *
     * @return void
     */
    private static function renderCloudHiddenNotice(): void
    {
        if (! class_exists(CloudManager::class)) {
            return;
        }

        $hidden = Plugin::storeMessagesEnabled() ? ' style="display:none"' : '';

        printf(
            '<div class="notice notice-info" id="phpclaw-cloud-off-notice"%s><p>%s</p></div>',
            $hidden,
            esc_html__(
                'Store Messages is off, so cloud tracing and the cloud security scan are inactive and the cloud fields are hidden. Your saved cloud settings are kept. Turn Store Messages on to see them again.',
                'phpclaw',
            ),
        );
    }

    /**
     * Render a single settings field input based on field name.
     *
     * @param  array<string, mixed>  $args  Field arguments including 'field' key.
     * @return void
     */
    public static function renderField(array $args): void
    {
        $field = $args['field'];
        $settings = (array) get_option(self::OPTION, []);
        $value = $settings[$field] ?? '';
        $name = self::OPTION.'['.esc_attr($field).']';

        match ($field) {
            'api_key' => self::renderSecretField(
                'phpclaw_api_key',
                $name,
                (string) $value,
                esc_html__('Your API key for the selected provider. Leave blank for Ollama (local models), or blank to keep the saved key.', 'phpclaw'),
            ),
            'provider' => self::renderSelect($name, (string) $value, self::providerOptions(), 'phpclaw_provider'),
            'base_url' => printf(
                '<input type="url" id="phpclaw_base_url" name="%s" value="%s" class="regular-text" placeholder="https://api.example.com/v1/chat/completions" />'
                .'<p class="description">%s</p>',
                esc_attr($name),
                esc_attr((string) $value),
                esc_html__('For the Custom provider only. The full OpenAI-compatible /chat/completions endpoint URL. Enter your API key above if the endpoint requires one.', 'phpclaw'),
            ),
            'system_prompt' => printf(
                '<textarea id="phpclaw_system_prompt" name="%s" rows="4" class="large-text" placeholder="%s">%s</textarea>'
                .'<p class="description">%s</p>',
                esc_attr($name),
                esc_attr(__('e.g. You are a helpful assistant for my WordPress blog. Always be concise.', 'phpclaw')),
                esc_textarea((string) $value),
                esc_html__('Optional. Customise the AI\'s persona and behaviour for your site.', 'phpclaw'),
            ),
            'store_messages' => printf(
                '<label><input type="checkbox" id="phpclaw_store_messages" name="%s" value="1"%s /> %s</label>'
                .'<p class="description">%s</p>',
                esc_attr($name),
                checked(! empty($value), true, false),
                esc_html__('Enable chat history (recommended)', 'phpclaw'),
                esc_html__('When enabled: prompt and response text is persisted via your configured memory driver, required for multi-turn chat to remember previous messages. When disabled: no message content is ever saved. Each prompt is processed independently with no memory of previous turns. Cloud tracing and the cloud security scan also stop while this is off. Your saved cloud settings are kept and reappear when you turn it back on.', 'phpclaw'),
            ),
            'max_iterations' => printf(
                '<input type="number" id="phpclaw_max_iterations" name="%s" value="%s" min="1" max="50" class="small-text" />'
                .'<p class="description">%s</p>',
                esc_attr($name),
                esc_attr($value !== '' ? (string) $value : '20'),
                esc_html__('Maximum tool-call iterations per request. Default: 20.', 'phpclaw'),
            ),
            'cloud_key' => self::renderSecretField(
                'phpclaw_cloud_key',
                $name,
                (string) $value,
                esc_html__('phpClaw Cloud API key. Enables cloud guards and webhook features. Leave blank to keep the saved key.', 'phpclaw'),
            ),
            'cloud_signing_secret' => self::renderSecretField(
                'phpclaw_cloud_signing_secret',
                $name,
                (string) $value,
                esc_html__('Shared secret used to verify signed cloud scan responses. Copy it from your phpClaw Cloud dashboard when you create the API key. Leave blank to keep the saved secret.', 'phpclaw'),
            ),
            'cloud_disable' => printf(
                '<input type="text" id="phpclaw_cloud_disable" name="%s" value="%s" class="regular-text" placeholder="%s" />'
                .'<p class="description">%s <em>%s</em></p>',
                esc_attr($name),
                esc_attr(is_array($value) ? implode(', ', $value) : (string) $value),
                esc_attr__('e.g. guards, webhooks', 'phpclaw'),
                esc_html__('Comma-separated cloud feature names to disable. Leave empty to enable all features from your plan.', 'phpclaw'),
                esc_html__('Only applies when a Cloud Key is set above and Store Messages is on.', 'phpclaw'),
            ),
            'remote_skill_urls' => printf(
                '<textarea id="phpclaw_remote_skill_urls" name="%s" rows="3" class="large-text code" placeholder="%s">%s</textarea>'
                .'<p class="description">%s</p>',
                esc_attr($name),
                esc_attr('https://example.com/skills.json'),
                esc_textarea(is_array($value) ? implode("\n", array_filter($value, 'is_string')) : (string) $value),
                esc_html__('One HTTPS URL per line. Each points to a phpClaw JSON skill collection or a SKILL.md file. Remote skills are inert text injected into prompts, never executed. Fetched with SSRF protection and cached for 24 hours.', 'phpclaw'),
            ),
            default => self::renderDynamicField($field, $name, (string) $value),
        };
    }

    /**
     * Sanitize and validate all submitted settings fields.
     *
     * @param  mixed  $input  Raw form input from WordPress settings API.
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $stored = (array) get_option(self::OPTION, []);

        if (! is_array($input)) {
            return $stored;
        }

        $clean = $stored;

        if (isset($input['api_key'])) {
            $clean['api_key'] = self::preserveSecret('api_key', $input['api_key']);
        }

        if (isset($input['provider'])) {
            $allowed = array_merge([''], array_keys(ProviderCatalogue::all()));
            $clean['provider'] = in_array($input['provider'], $allowed, true) ? $input['provider'] : '';
        }

        if (isset($input['model'])) {
            $clean['model'] = sanitize_text_field((string) $input['model']);
        }

        if (isset($input['max_iterations'])) {
            $clean['max_iterations'] = max(1, min(50, (int) $input['max_iterations']));
        }

        if (isset($input['base_url'])) {
            $url = trim((string) $input['base_url']);
            $clean['base_url'] = (
                $url === '' || (
                    filter_var($url, FILTER_VALIDATE_URL) !== false &&
                    (bool) preg_match('~^https?://~i', $url)
                )
            ) ? $url : '';
        }

        foreach (Plugin::extraToolClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }
            if (! is_a($class, ConfigurableToolInterface::class, true)) {
                continue;
            }
            foreach ($class::settingsFields() as $key => $def) {
                if (! isset($input[$key])) {
                    continue;
                }
                $raw = trim((string) $input[$key]);
                $clean[$key] = match ($def['type']) {
                    'url' => filter_var($raw, FILTER_VALIDATE_URL) !== false ? $raw : '',
                    'number' => (string) (int) $raw,
                    default => sanitize_text_field($raw),
                };
            }
        }

        if (isset($input['system_prompt'])) {
            $clean['system_prompt'] = mb_substr(sanitize_textarea_field((string) $input['system_prompt']), 0, 8000);
        }

        if (isset($input['cloud_key'])) {
            $clean['cloud_key'] = self::preserveSecret('cloud_key', $input['cloud_key']);
        }

        if (isset($input['cloud_signing_secret'])) {
            $clean['cloud_signing_secret'] = self::preserveSecret('cloud_signing_secret', $input['cloud_signing_secret']);
        }

        if (isset($input['cloud_disable'])) {
            $raw = $input['cloud_disable'];
            $items = is_array($raw)
                ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw)
                : explode(',', sanitize_text_field((string) $raw));
            $clean['cloud_disable'] = array_values(array_filter(
                array_map(static fn (string $s): string => sanitize_text_field(trim($s)), $items),
                static fn (string $s): bool => $s !== '',
            ));
        }

        if (isset($input['store_messages'])) {
            $sm = $input['store_messages'];
            $on = $sm === '1' || $sm === 1 || $sm === true;
            $clean['store_messages'] = $on ? '1' : '0';
        } else {
            $clean['store_messages'] = '0';
        }

        if (isset($input['remote_skill_urls'])) {
            $clean['remote_skill_urls'] = self::filterRemoteSkillUrls($input['remote_skill_urls']);
        } else {
            $clean['remote_skill_urls'] = [];
        }

        return $clean;
    }

    /**
     * Filter a raw remote-skill-URL list to valid HTTPS entries whose path ends in .md or .json.
     *
     * @param  mixed  $raw  Raw remote-skill-URL value (array or newline-separated string).
     * @return list<string> Sanitised HTTPS .md/.json URLs.
     */
    public static function filterRemoteSkillUrls(mixed $raw): array
    {
        $lines = is_array($raw) ? $raw : (preg_split('/[\r\n]+/', (string) $raw) ?: []);

        $valid = array_values(array_filter(
            array_map(static fn (mixed $u): string => esc_url_raw(trim((string) $u)), $lines),
            static function (string $u): bool {
                if ($u === '' || ! preg_match('~^https://~i', $u)) {
                    return false;
                }

                $path = (string) parse_url($u, PHP_URL_PATH);

                return (bool) preg_match('~\.(md|json)$~i', $path);
            },
        ));

        return array_slice($valid, 0, 50);
    }

    /**
     * Render the provider connection status badge at the top of the page.
     *
     * @return void
     */
    private static function renderConnectionBadge(): void
    {
        $saved = (array) get_option(self::OPTION, []);
        $provider = ($saved['provider'] ?? '') ?: 'Not selected';
        $model = ($saved['model'] ?? '') ?: 'provider default';
        $apiKey = ($saved['api_key'] ?? '');
        $isOllama = $provider === 'ollama';
        $connected = $isOllama || $apiKey !== '';
        $status = $connected ? '● Connected' : '● Not configured. Enter your API key below';
        ?>
        <div class="pc-badge">
            <span class="pc-badge__status <?= $connected ? 'pc-badge__status--ok' : 'pc-badge__status--warn' ?>">
                <?= esc_html($status) ?>
            </span>
            <?php if ($connected) { ?>
                <span class="pc-badge__meta">Provider: <strong><?= esc_html($provider) ?></strong></span>
                <span class="pc-badge__meta">Model: <strong><?= esc_html($model) ?></strong></span>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * Render the Settings tab content with the options form.
     *
     * @return void
     */
    private static function renderTabSettings(): void
    {
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields(self::PAGE);
        do_settings_sections(self::PAGE);
        submit_button(__('Save Settings', 'phpclaw'), 'primary', 'submit', false);
        ?>
            <button type="button" id="phpclaw-test-conn" class="button button-secondary pc-ml-8">
                <?= esc_html__('Test Connection', 'phpclaw') ?>
            </button>
            <span id="phpclaw-test-result" class="pc-test-conn-result"></span>
            <p class="description pc-description-mt"><?= esc_html__('Save your settings first, then click Test Connection.', 'phpclaw') ?></p>
        </form>
        <?php AdminFooter::renderCommunityCard(); ?>

        <?php
    }

    /**
     * Render a write-only secret input - never emits the stored value, only whether one is saved.
     *
     * @param  string  $id  Element id for the password input.
     * @param  string  $name  Fully-qualified option field name attribute.
     * @param  string  $stored  Currently stored secret (used only to indicate saved state).
     * @param  string  $description  Escaped field description HTML.
     * @return void
     */
    private static function renderSecretField(string $id, string $name, string $stored, string $description): void
    {
        $placeholder = $stored !== ''
            ? esc_attr__('Saved. Leave blank to keep current value', 'phpclaw')
            : '';

        printf(
            '<input type="password" id="%s" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s" />'
            .'<p class="description">%s</p>',
            esc_attr($id),
            esc_attr($name),
            $placeholder,
            $description,
        );
    }

    /**
     * Sanitise a secret field, preserving the stored value when the submission is blank.
     *
     * @param  string  $field  Option key of the secret (e.g. api_key).
     * @param  mixed  $submitted  Raw submitted value.
     * @return string The sanitised new value, or the stored value when the submission is blank.
     */
    private static function preserveSecret(string $field, mixed $submitted): string
    {
        $value = sanitize_text_field((string) $submitted);

        if ($value !== '') {
            return $value;
        }

        $stored = (array) get_option(self::OPTION, []);

        return (string) ($stored[$field] ?? '');
    }

    /**
     * Build the provider dropdown options.
     *
     * @return array<string, string>
     */
    private static function providerOptions(): array
    {
        $options = ['' => __('Select a provider', 'phpclaw')];

        foreach (ProviderCatalogue::all() as $slug => $entry) {
            $key = (string) $slug;
            if ($key === '') {
                continue;
            }
            $options[$key] = (string) $entry['label'];
        }

        return $options;
    }

    /**
     * Render a dynamic field defined by a ConfigurableToolInterface tool.
     *
     * @param  string  $field  The field key.
     * @param  string  $name  The HTML input name attribute.
     * @param  string  $value  The current saved value.
     * @return void
     */
    private static function renderDynamicField(string $field, string $name, string $value): void
    {
        foreach (Plugin::extraToolClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }
            if (! is_a($class, ConfigurableToolInterface::class, true)) {
                continue;
            }
            $fields = $class::settingsFields();
            if (! isset($fields[$field])) {
                continue;
            }

            $def = $fields[$field];
            $type = $def['type'];
            $placeholder = $def['placeholder'] ?? '';
            $description = $def['description'] ?? '';

            $inputType = match ($type) {
                'url' => 'url',
                'password' => 'password',
                'number' => 'number',
                default => 'text',
            };

            printf(
                '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />'
                .($description !== '' ? '<p class="description">%s</p>' : '%s'),
                esc_attr($inputType),
                esc_attr('phpclaw_'.$field),
                esc_attr($name),
                esc_attr($value),
                esc_attr($placeholder),
                $description !== '' ? esc_html($description) : '',
            );

            return;
        }

        printf(
            '<input type="text" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />'
            .'<p class="description">%s</p>',
            esc_attr('phpclaw_'.$field),
            esc_attr($name),
            esc_attr($value),
            esc_attr($field === 'model' ? __('e.g. llama3.1:8b, gpt-4o, claude-opus-4-8', 'phpclaw') : ''),
            '',
        );
    }

    /**
     * Render an HTML select dropdown for a settings field.
     *
     * @param  string  $name  The HTML select name attribute.
     * @param  string  $current  The currently selected value.
     * @param  array<string, string>  $options  Key-value pairs of option value => label.
     * @param  string  $id  Optional HTML id attribute for the select.
     * @return void
     */
    private static function renderSelect(string $name, string $current, array $options, string $id = ''): void
    {
        $idAttr = $id !== '' ? ' id="'.esc_attr($id).'"' : '';
        echo '<select'.$idAttr.' name="'.esc_attr($name).'">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                selected($current, (string) $value, false),
                esc_html((string) $label),
            );
        }
        echo '</select>';
    }
}
