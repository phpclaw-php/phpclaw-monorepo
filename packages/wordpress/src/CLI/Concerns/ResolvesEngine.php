<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI\Concerns;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Plugin;

/**
 * Builds the WP-CLI engine: the plugin's own, or one with a provider or model for this run only.
 */
trait ResolvesEngine
{
    /**
     * The plugin's engine, or a new one with the provider and model overrides; the saved settings are never written.
     *
     * @param  string  $provider  Provider override; '' keeps the saved one.
     * @param  string  $model  Model override; '' keeps the saved one.
     * @return PhpClawInterface
     */
    private function resolveEngine(string $provider = '', string $model = ''): PhpClawInterface
    {
        if ($provider === '' && $model === '') {
            return Plugin::getInstance()->engine();
        }

        $saved = (array) get_option('phpclaw_settings', []);

        if ($provider !== '') {
            $saved['provider'] = $provider;
        }

        if ($model !== '') {
            $saved['model'] = $model;
        }

        return EngineFactory::build(
            config: Plugin::getInstance()->config(),
            saved: $saved,
            skills: [],
            extraToolClasses: Plugin::extraToolClasses(),
        );
    }
}
