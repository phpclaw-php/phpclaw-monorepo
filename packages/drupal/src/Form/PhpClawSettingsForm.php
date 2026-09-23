<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use PhpClaw\ClawConfig;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Drupal\Support\CloudDisableParser;
use PhpClaw\Drupal\Support\Defaults;
use PhpClaw\Drupal\Support\RemoteSkillUrlFilter;
use PhpClaw\Providers\ProviderCatalogue;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PhpClaw configuration form.
 */
final class PhpClawSettingsForm extends ConfigFormBase
{
    private ?CsrfTokenGenerator $csrfTokenGenerator = null;

    /**
     * Create a new PhpClawSettingsForm instance from the service container.
     *
     * @param  ContainerInterface  $container  The Drupal service container.
     * @return static
     */
    public static function create(ContainerInterface $container): static
    {
        $instance = parent::create($container);
        $instance->csrfTokenGenerator = $container->get('csrf_token');

        return $instance;
    }

    /**
     * Get the unique form identifier.
     *
     * @return string
     */
    public function getFormId(): string
    {
        return 'phpclaw_settings_form';
    }

    /**
     * Build the settings form render array.
     *
     * @param  array<string, mixed>  $form  The incoming form render array to extend.
     * @param  FormStateInterface  $form_state  The current form state.
     * @return array<string, mixed>
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config('phpclaw.settings');
        $provider = (string) ($config->get('provider') ?? '');

        $apiKey = (string) ($config->get('api_key') ?? '');
        $baseUrl = (string) ($config->get('base_url') ?? '');

        $connected = $provider !== '' && ($provider === 'ollama' || $apiKey !== '' || ($provider === 'custom' && $baseUrl !== ''));

        $badgeStatus = $connected ? '● Connected' : '● Not configured: select a provider and enter your API key';

        $model = (string) ($config->get('model') ?? '');
        $model = $model !== '' ? $model : '(not set)';

        $badgeClass = $connected ? 'phpclaw-badge--ok' : 'phpclaw-badge--error';

        $safeProvider = Html::escape($provider);
        $safeModel = Html::escape($model);
        $safeBadgeStatus = Html::escape($badgeStatus);
        $safeBadgeClass = Html::escape($badgeClass);

        $badge = <<<HTML
<div class="phpclaw-settings-badge">
  <div class="phpclaw-settings-badge__left">
    <span class="phpclaw-badge {$safeBadgeClass}">{$safeBadgeStatus}</span>
    <span class="phpclaw-meta">Provider: <strong>{$safeProvider}</strong></span>
    <span class="phpclaw-meta">Model: <strong>{$safeModel}</strong></span>
  </div>
  <a href="https://phpclaw.ai/docs" target="_blank" rel="noopener" class="phpclaw-settings-badge__link">phpclaw.ai/docs →</a>
</div>
HTML;

        $form['badge'] = [
            '#markup' => Markup::create($badge),
            '#weight' => -100,
        ];

        $fieldStyle = ['class' => ['phpclaw-form-field']];

        $providerOptions = ['' => $this->t('Select a provider')];
        foreach (ProviderCatalogue::all() as $slug => $entry) {
            $providerOptions[$slug] = (string) ($entry['label'] ?? $slug);
        }

        $form['provider'] = [
            '#type' => 'select',
            '#title' => $this->t('Provider'),
            '#options' => $providerOptions,
            '#default_value' => $provider,
            '#attributes' => array_merge(['id' => 'phpclaw-provider-select', 'aria-label' => $this->t('Provider')], $fieldStyle),
        ];

        $form['model'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Model'),
            '#default_value' => (string) ($config->get('model') ?? ''),
            '#placeholder' => 'e.g. claude-haiku-4-5-20251001, gpt-4o-mini, llama3.1:8b',
            '#attributes' => $fieldStyle,
        ];

        $form['api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('API Key'),
            '#description' => $this->t('Your API key for the selected provider. Leave blank for Ollama (local models).'),
            '#default_value' => '',
            '#placeholder' => $apiKey !== '' ? $this->t('Saved. Leave blank to keep current value') : '',
            '#id' => 'phpclaw-api-key',
            '#attributes' => array_merge(['autocomplete' => 'new-password'], $fieldStyle),
        ];

        $form['base_url'] = [
            '#type' => 'url',
            '#title' => $this->t('Base URL'),
            '#description' => $this->t('OpenAI-compatible endpoint (https://…/v1/chat/completions). Required when provider is Custom. Use for OpenRouter, Together AI, remote Ollama, or any self-hosted gateway.'),
            '#default_value' => $baseUrl,
            '#placeholder' => 'https://openrouter.ai/api/v1/chat/completions',
            '#attributes' => array_merge(['id' => 'phpclaw-base-url'], $fieldStyle),
        ];

        $form['system_prompt'] = [
            '#type' => 'textarea',
            '#title' => $this->t('System Prompt'),
            '#description' => $this->t('Optional. Customise the AI\'s persona and behaviour for your site.'),
            '#default_value' => (string) ($config->get('system_prompt') ?? ''),
            '#placeholder' => 'e.g. You are a helpful assistant for my Drupal site. Always be concise.',
            '#rows' => 4,
            '#attributes' => $fieldStyle,
        ];

        $form['store_messages'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Store Messages'),
            '#description' => $this->t('When enabled: prompt and response text is persisted, required for multi-turn chat. When disabled: no message content is saved; each prompt is processed independently.'),
            '#default_value' => (bool) ($config->get('store_messages') ?? Defaults::STORE_MESSAGES),
        ];

        $form['max_iterations'] = [
            '#type' => 'number',
            '#title' => $this->t('Max Iterations'),
            '#description' => $this->t('Maximum tool-call iterations per request. Default: @default.', ['@default' => ClawConfig::DEFAULT_MAX_ITERATIONS]),
            '#default_value' => (int) ($config->get('max_iterations') ?? ClawConfig::DEFAULT_MAX_ITERATIONS),
            '#min' => 1,
            '#max' => 50,
            '#attributes' => $fieldStyle,
        ];

        if (class_exists(CloudManager::class)) {
            $visibleWhenStoring = [
                'visible' => [':input[name="store_messages"]' => ['checked' => true]],
            ];

            $form['cloud_off_notice'] = [
                '#type' => 'container',
                '#states' => [
                    'visible' => [':input[name="store_messages"]' => ['checked' => false]],
                ],
                'message' => [
                    '#theme' => 'status_messages',
                    '#message_list' => [
                        'status' => [
                            $this->t('Store Messages is off, so cloud tracing and the cloud security scan are inactive and the cloud fields are hidden. Your saved cloud settings are kept. Turn Store Messages on to see them again.'),
                        ],
                    ],
                ],
            ];

            $form['cloud_key'] = [
                '#type' => 'password',
                '#title' => $this->t('Cloud Key'),
                '#description' => $this->t('phpClaw Cloud API key. Enables cloud guards and webhook features. Optional. Requires a phpClaw Cloud account at phpclaw.ai.'),
                '#default_value' => '',
                '#placeholder' => (string) ($config->get('cloud_key') ?? '') !== '' ? $this->t('Saved. Leave blank to keep current value') : '',
                '#attributes' => array_merge(['autocomplete' => 'new-password'], $fieldStyle),
                '#states' => $visibleWhenStoring,
            ];

            $form['cloud_signing_secret'] = [
                '#type' => 'password',
                '#title' => $this->t('Cloud Signing Secret'),
                '#description' => $this->t('Shared secret used to verify signed cloud scan responses. Copy it from your phpClaw Cloud dashboard when you create the API key. Leave empty to skip signature verification.'),
                '#default_value' => '',
                '#placeholder' => (string) ($config->get('cloud_signing_secret') ?? '') !== '' ? $this->t('Saved. Leave blank to keep current value') : '',
                '#attributes' => array_merge(['autocomplete' => 'new-password'], $fieldStyle),
                '#states' => $visibleWhenStoring,
            ];

            $rawDisable = $config->get('cloud_disable') ?? '';
            $disableStr = is_array($rawDisable) ? implode(', ', $rawDisable) : (string) $rawDisable;

            $form['cloud_disable'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Disable Cloud Features'),
                '#description' => $this->t('Comma-separated cloud feature names to disable. Leave empty to enable all. Only applies when a Cloud Key is set above.'),
                '#default_value' => $disableStr,
                '#placeholder' => 'e.g. guards, webhooks',
                '#attributes' => $fieldStyle,
                '#states' => $visibleWhenStoring,
            ];
        }

        $form['remote_skill_urls'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Remote Skill URLs'),
            '#description' => $this->t('Comma-separated HTTPS URLs pointing to SKILL.md or skill JSON files. Skills are always-on and keyword-matched per message, so no per-skill toggle is needed.'),
            '#default_value' => (string) ($config->get('remote_skill_urls') ?? ''),
            '#placeholder' => 'https://example.com/skills/drupal-tips.md, https://example.com/skills/commerce.json',
            '#rows' => 3,
            '#attributes' => $fieldStyle,
        ];

        $form['#attached']['library'][] = 'phpclaw/admin.settings';
        $form['#attached']['drupalSettings']['phpclaw_settings']['csrf_token'] = $this->csrfTokenGenerator?->get('phpclaw-chat') ?? '';

        $form['test_connection'] = [
            '#markup' => Markup::create(
                '<div class="phpclaw-test-connection">'
                .'<button type="button" id="phpclaw-test-conn" class="button button--primary">'
                .$this->t('Test Connection')
                .'</button>'
                .'<span id="phpclaw-test-result" class="phpclaw-test-result"></span>'
                .'<p class="description">'.$this->t('Save your settings first, then click Test Connection.').'</p>'
                .'</div>'
            ),
            '#weight' => 190,
        ];

        $form['phpclaw_community'] = [
            '#markup' => Markup::create(
                '<div class="phpclaw-community">'
                .'<div class="phpclaw-community-inner">'
                .'<h4 class="phpclaw-community-title">Built for the PHP community</h4>'
                .'<p class="phpclaw-community-text">phpClaw is open source under the MIT license.</p>'
                .'<p class="phpclaw-community-text">One engine for the entire PHP ecosystem.</p>'
                .'<div class="phpclaw-community-buttons">'
                .'<a href="https://github.com/phpclaw-php/phpclaw" target="_blank" rel="noopener" class="phpclaw-btn-github">&#9733; Star on GitHub</a>'
                .'<a href="https://packagist.org/packages/phpclaw/phpclaw" target="_blank" rel="noopener" class="phpclaw-btn-packagist">&#128230; View on Packagist</a>'
                .'</div>'
                .'<div class="phpclaw-community-footer">'
                .'<span>Need custom AI tools for your project?</span>'
                .'<a href="https://phpclaw.ai/enterprise" target="_blank" rel="noopener">&rarr; phpclaw.ai/enterprise</a>'
                .'</div>'
                .'</div>'
                .'</div>'
            ),
            '#weight' => 200,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * Validate the submitted form values.
     *
     * @param  array<string, mixed>  $form  The form render array being validated.
     * @param  FormStateInterface  $form_state  The current form state.
     * @return void
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $maxIter = (int) $form_state->getValue('max_iterations');
        if ($maxIter < 1 || $maxIter > 50) {
            $form_state->setErrorByName('max_iterations', $this->t('Max iterations must be between 1 and 50.'));
        }

        $provider = (string) $form_state->getValue('provider');
        $allowed = array_keys(ProviderCatalogue::all());
        if ($provider !== '' && ! in_array($provider, $allowed, true)) {
            $form_state->setErrorByName('provider', $this->t('Unknown provider.'));
        }

        $baseUrl = trim((string) $form_state->getValue('base_url'));
        if ($baseUrl !== '' && (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || ! preg_match('~^https?://~i', $baseUrl))) {
            $form_state->setErrorByName('base_url', $this->t('Base URL must be a full http(s) OpenAI-compatible endpoint URL.'));
        }
    }

    /**
     * Save the submitted form values to configuration.
     *
     * @param  array<string, mixed>  $form  The form render array being submitted.
     * @param  FormStateInterface  $form_state  The current form state.
     * @return void
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $newApiKey = (string) $form_state->getValue('api_key');
        $newCloudKey = (string) ($form_state->getValue('cloud_key') ?? '');
        $newSigningSecret = (string) ($form_state->getValue('cloud_signing_secret') ?? '');

        $rawStoreMessages = $form_state->getValue('store_messages');
        $storeMessages = ($rawStoreMessages === '1' || $rawStoreMessages === 1 || $rawStoreMessages === true);

        $cloudDisable = CloudDisableParser::parse($form_state->getValue('cloud_disable') ?? '');

        $config = $this->config('phpclaw.settings')
            ->set('provider', (string) $form_state->getValue('provider'))
            ->set('model', (string) $form_state->getValue('model'))
            ->set('base_url', trim((string) $form_state->getValue('base_url')))
            ->set('max_iterations', (int) $form_state->getValue('max_iterations'))
            ->set('store_messages', $storeMessages)
            ->set('system_prompt', (string) $form_state->getValue('system_prompt'))
            ->set('cloud_disable', $cloudDisable)
            ->set('remote_skill_urls', RemoteSkillUrlFilter::filter((string) ($form_state->getValue('remote_skill_urls') ?? '')));

        if ($newApiKey !== '') {
            $config->set('api_key', $newApiKey);
        }

        if ($newCloudKey !== '') {
            $config->set('cloud_key', $newCloudKey);
        }

        if ($newSigningSecret !== '') {
            $config->set('cloud_signing_secret', $newSigningSecret);
        }

        $config->save();
        parent::submitForm($form, $form_state);
    }

    /**
     * Get the list of editable configuration names.
     *
     * @return array<int, string>
     */
    protected function getEditableConfigNames(): array
    {
        return ['phpclaw.settings'];
    }
}
