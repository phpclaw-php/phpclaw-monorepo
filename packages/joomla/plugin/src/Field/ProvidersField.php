<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;
use PhpClaw\ClawConfig;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Tools\ToolRegistry;

/**
 * Provider dropdown - one option per entry in `ProviderCatalogue::all()`, minus any slug in the field's `exclude`
 * attribute; with `follow`, only providers sharing the named primary field's tool format, rebuilt when it changes.
 */
final class ProvidersField extends ListField
{
    private const REBUILD_SCRIPT = <<<'JS'
        document.addEventListener('DOMContentLoaded', function () {
            var d = %s;
            var p = document.getElementById(d.primary), f = document.getElementById(d.fallback);
            if (!p || !f) { return; }
            p.addEventListener('change', function () {
                var format = p.value === '' ? d.autoFormat : d.formats[p.value], current = f.value, keep = false;
                f.options.length = 0;
                f.add(new Option(d.offLabel, ''));
                Object.keys(d.providers).forEach(function (slug) {
                    if (d.formats[slug] === format) {
                        f.add(new Option(d.providers[slug], slug));
                        keep = keep || slug === current;
                    }
                });
                f.value = keep ? current : '';
                f.dispatchEvent(new Event('change'));
            });
        });
        JS;

    protected $type = 'Providers';

    /**
     * Render the dropdown; a following dropdown also adds the script that rebuilds it when the primary changes.
     *
     * @return string
     */
    protected function getInput()
    {
        $html = parent::getInput();
        $follow = $this->follow();

        if ($follow === '') {
            return $html;
        }

        $primary = $this->form->getField($follow, $this->group);
        $data = [
            'primary' => $primary ? (string) $primary->id : '',
            'fallback' => (string) $this->id,
            'offLabel' => $this->translate('PLG_SYSTEM_PHPCLAW_FALLBACK_OFF'),
            'providers' => $this->providerLabels(),
            'formats' => $this->providerFormats(),
            'autoFormat' => ToolRegistry::toolFormat((new ClawConfig)->providerName),
        ];

        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(
            sprintf(self::REBUILD_SCRIPT, (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP)),
        );

        return $html;
    }

    /**
     * Build the dropdown options: an empty choice, then each catalogue provider not excluded and, for a following
     * dropdown, sharing the primary provider's tool format.
     *
     * @return array<int, object>
     */
    protected function getOptions(): array
    {
        $follow = $this->follow();
        $format = $follow === '' ? null : $this->primaryFormat($follow);

        $options = [$this->option('', $this->translate($follow === '' ? 'PLG_SYSTEM_PHPCLAW_PROVIDER_SELECT' : 'PLG_SYSTEM_PHPCLAW_FALLBACK_OFF'))];

        foreach ($this->providerLabels() as $slug => $label) {
            if ($format === null || ToolRegistry::toolFormat($slug) === $format) {
                $options[] = $this->option($slug, $label);
            }
        }

        return array_merge($options, parent::getOptions());
    }

    /**
     * Core catalogue label of every provider not named in `exclude`, keyed by slug, so the names match every adapter.
     *
     * @return array<string, string>
     */
    private function providerLabels(): array
    {
        $this->loadCatalogue();
        $excluded = array_map('trim', explode(',', (string) ($this->element['exclude'] ?? '')));
        $labels = [];

        foreach (ProviderCatalogue::all() as $key => $entry) {
            $slug = (string) $key;
            if ($slug !== '' && ! in_array($slug, $excluded, true)) {
                $labels[$slug] = (string) $entry['label'];
            }
        }

        return $labels;
    }

    /**
     * Tool format of every catalogue provider, Custom included, keyed by slug.
     *
     * @return array<string, string>
     */
    private function providerFormats(): array
    {
        $formats = [];

        foreach (array_keys(ProviderCatalogue::all()) as $key) {
            $formats[(string) $key] = ToolRegistry::toolFormat((string) $key);
        }

        return $formats;
    }

    /**
     * Tool format of the primary provider field's saved value; empty resolves to the provider core auto-detects.
     *
     * @param  string  $follow  Name of the primary provider field in this field's group.
     * @return string
     */
    private function primaryFormat(string $follow): string
    {
        $primary = (string) $this->form->getValue($follow, $this->group, '');

        return ToolRegistry::toolFormat($primary !== '' ? $primary : (new ClawConfig)->providerName);
    }

    /**
     * Name of the primary provider field this dropdown follows, or '' when it follows none.
     *
     * @return string
     */
    private function follow(): string
    {
        return trim((string) ($this->element['follow'] ?? ''));
    }

    /**
     * One dropdown option object in the shape ListField renders.
     *
     * @param  string  $value  Option value.
     * @param  string  $text  Option label.
     * @return object
     */
    private function option(string $value, string $text): object
    {
        return (object) ['value' => $value, 'text' => $text, 'disable' => false, 'class' => '', 'onclick' => '', 'onchange' => ''];
    }

    /**
     * Load the component's autoloader when the provider catalogue is not yet available.
     *
     * @return void
     */
    private function loadCatalogue(): void
    {
        if (class_exists(ProviderCatalogue::class)) {
            return;
        }

        $autoload = JPATH_ADMINISTRATOR.'/components/com_phpclaw/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /**
     * Localize a key, falling back to the key itself when the language subsystem is unavailable.
     *
     * @param  string  $key
     * @return string
     */
    private function translate(string $key): string
    {
        if (class_exists(Text::class)) {
            return Text::_($key);
        }

        return $key;
    }
}
