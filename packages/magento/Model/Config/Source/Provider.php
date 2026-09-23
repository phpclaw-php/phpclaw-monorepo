<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PhpClaw\Providers\ProviderCatalogue;

/**
 * Source model for the Provider select field on the Settings page.
 */
// non-final: Magento interceptor required
class Provider implements OptionSourceInterface
{
    /**
     * Return the list of provider options for the Provider select field.
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        if ($this->hasCatalogue()) {
            foreach (ProviderCatalogue::all() as $key => $info) {
                $options[] = [
                    'value' => (string) $key,
                    'label' => (string) ($info['label'] ?? $key),
                ];
            }

            return $options;
        }

        $options[] = ['value' => 'anthropic', 'label' => 'Anthropic (Claude)'];
        $options[] = ['value' => 'openai',    'label' => 'OpenAI (GPT)'];
        $options[] = ['value' => 'groq',      'label' => 'Groq'];
        $options[] = ['value' => 'gemini',    'label' => 'Google Gemini'];
        $options[] = ['value' => 'mistral',   'label' => 'Mistral'];
        $options[] = ['value' => 'deepseek',  'label' => 'DeepSeek'];
        $options[] = ['value' => 'ollama',    'label' => 'Ollama (local)'];
        $options[] = ['value' => 'custom',    'label' => 'Custom (OpenAI-compatible)'];

        return $options;
    }

    /**
     * Return whether ProviderCatalogue is available at runtime.
     *
     * @return bool
     */
    protected function hasCatalogue(): bool
    {
        return class_exists(ProviderCatalogue::class);
    }
}
