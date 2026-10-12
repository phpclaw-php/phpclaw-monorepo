<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\CLI\Fixtures;

use Brain\Monkey\Functions;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Plugin;

trait BuildsCliEngine
{
    private array $options = [];

    private function useEngine(?ScriptedProvider $provider = null, array $saved = [], ?MemoryInterface $memory = null): ClawInterface
    {
        $scripted = $provider ?? new ScriptedProvider;
        ProviderRegistry::register('scripted', static fn () => $scripted);
        $memory ??= new ArrayMemory;
        MemoryRegistry::register('wpdb_router', static fn () => $memory);

        $saved += ['provider' => 'scripted', 'model' => 'scripted-model', 'store_messages' => '1'];
        $this->options['phpclaw_settings'] = $saved;
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });

        $engine = EngineFactory::build(config: [], saved: $saved);
        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andReturn($engine);
        $plugin->allows('config')->andReturn([]);
        (new \ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, $plugin);

        return $engine;
    }
}
