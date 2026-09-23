<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Field;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;
use PhpClaw\Providers\ProviderCatalogue;

/**
 * Provider dropdown - one option per entry in `ProviderCatalogue::all()`.
 */
final class ProvidersField extends ListField
{
    protected $type = 'Providers';

    /**
     * Build the provider dropdown options from `ProviderCatalogue::all()`.
     *
     * @return array<int, object>
     */
    protected function getOptions(): array
    {
        if (! class_exists(ProviderCatalogue::class)) {
            $autoload = JPATH_ADMINISTRATOR.'/components/com_phpclaw/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        $options = [(object) [
            'value' => '',
            'text' => $this->translate('PLG_SYSTEM_PHPCLAW_PROVIDER_SELECT'),
            'disable' => false,
            'class' => '',
            'onclick' => '',
            'onchange' => '',
        ]];

        foreach (ProviderCatalogue::all() as $key => $entry) {
            $slug = (string) $key;
            if ($slug === '') {
                continue;
            }

            $label = $this->providerLabel($slug, (string) $entry['label']);

            $options[] = (object) [
                'value' => $slug,
                'text' => $label,
                'disable' => false,
                'class' => '',
                'onclick' => '',
                'onchange' => '',
            ];
        }

        return array_merge($options, parent::getOptions());
    }

    /**
     * Translated label for a provider slug, falling back to the catalogue's English label,
     * which ships from core and is never localised.
     *
     * @param  string  $slug  Provider slug, for example anthropic.
     * @param  string  $fallback  The catalogue's own label.
     * @return string
     */
    private function providerLabel(string $slug, string $fallback): string
    {
        $key = 'PLG_SYSTEM_PHPCLAW_PROVIDER_'.strtoupper($slug);
        $translated = $this->translate($key);

        return $translated === $key ? $fallback : $translated;
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
