<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop;

use PhpClaw\Contracts\ClawInterface;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;

/**
 * phpClaw module bootstrap singleton: owns lifecycle, settings I/O, and the lazy engine accessor.
 */
final class Plugin
{
    private const PERSISTED_FIELDS = [
        'provider', 'model', 'api_key', 'base_url', 'max_iterations',
        'store_messages', 'system_prompt', 'cloud_key', 'cloud_signing_secret', 'cloud_disable',
        'remote_skill_urls',
    ];

    private static ?self $instance = null;

    private ?ClawInterface $engine = null;

    private ?\Throwable $engineError = null;

    private array $config;

    private array $saved;

    private EngineFactory $engineFactory;

    /**
     * Load config + saved settings and boot the engine factory's registries.
     *
     * @param  ?PsDbInterface  $db
     * @param  string  $tablePrefix
     * @param  int  $actingEmployeeId  Acting employee id, or the `0` unverified-identity sentinel.
     * @param  bool  $manageAll  Whether the acting identity holds the manage-all-conversations grant.
     * @param  bool  $isConsole  True only when an interactive console entrypoint says so.
     */
    private function __construct(
        private readonly ?PsDbInterface $db = null,
        private readonly string $tablePrefix = 'ps_',
        private readonly int $actingEmployeeId = 0,
        private readonly bool $manageAll = false,
        private readonly bool $isConsole = false,
    ) {
        $this->config = require __DIR__.'/../config/phpclaw.php';
        $this->saved = $this->loadSavedSettings();
        $this->engineFactory = new EngineFactory(
            $this->saved,
            $this->config,
            $this->db,
            $this->tablePrefix,
            $this->actingEmployeeId,
            $this->manageAll,
            $this->isConsole,
        );

        $this->engineFactory->bootRegistries();
    }

    /**
     * Singleton accessor. The first call builds the instance and every later call returns it unchanged,
     * so arguments passed after the first call are ignored rather than rebinding the acting identity.
     *
     * @param  ?PsDbInterface  $db
     * @param  string  $tablePrefix
     * @param  int  $actingEmployeeId
     * @param  bool  $manageAll
     * @param  bool  $isConsole  True only when an interactive console entrypoint says so.
     * @return self
     */
    public static function getInstance(
        ?PsDbInterface $db = null,
        string $tablePrefix = 'ps_',
        int $actingEmployeeId = 0,
        bool $manageAll = false,
        bool $isConsole = false,
    ): self {
        if (self::$instance === null) {
            self::$instance = new self($db, $tablePrefix, $actingEmployeeId, $manageAll, $isConsole);
        }

        return self::$instance;
    }

    /**
     * Lazy engine: built on first access via EngineFactory.
     *
     * @return ClawInterface
     *
     * @throws \Throwable if engine could not be built.
     */
    public function engine(): ClawInterface
    {
        if ($this->engine === null && $this->engineError === null) {
            try {
                $this->engine = $this->engineFactory->build();
            } catch (\Throwable $e) {
                $this->engineError = $e;
            }
        }

        if ($this->engineError !== null) {
            throw $this->engineError;
        }

        return $this->engine;
    }

    /**
     * Return the tool set for the Guide page and the MCP server: no provider profile applied,
     * but the configured deny list and tool groups still removed.
     *
     * @return array<int, ToolInterface>
     */
    public function guideTools(): array
    {
        $tools = $this->engineFactory->buildTools(applyProfile: false);

        ['deny' => $deny, 'groups' => $groups] = $this->engineFactory->denyConfig();

        $registry = new ToolRegistry;
        $registry->register($tools, $deny, $groups);

        return $registry->all();
    }

    /**
     * Build a one-off engine with saved settings overridden, without persisting via {@see saveSettings()}.
     *
     * @param  array<string, mixed>  $overrides  Settings to overlay onto the saved settings for this build only.
     * @return ClawInterface
     */
    public function buildEngineWithOverrides(array $overrides): ClawInterface
    {
        $factory = new EngineFactory(
            array_merge($this->saved, $overrides),
            $this->config,
            $this->db,
            $this->tablePrefix,
            $this->actingEmployeeId,
            $this->manageAll,
            $this->isConsole,
        );

        return $factory->build();
    }

    /**
     * The module runtime configuration array loaded from config/phpclaw.php.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * The admin-saved settings loaded from the native ps_configuration store.
     *
     * @return array<string, mixed>
     */
    public function saved(): array
    {
        return $this->saved;
    }

    /**
     * Return true if a provider and model are configured.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        $provider = (string) ($this->saved['provider'] ?? '');
        $model = (string) ($this->saved['model'] ?? '');

        return $provider !== '' && $model !== '';
    }

    /**
     * Persist admin-panel settings to the native ps_configuration store and reset the engine cache.
     *
     * @param  array<string, mixed>  $settings
     * @return void
     */
    public function saveSettings(array $settings): void
    {
        $this->saved = $settings;
        $this->engine = null;
        $this->engineError = null;

        $this->engineFactory = new EngineFactory(
            $this->saved,
            $this->config,
            $this->db,
            $this->tablePrefix,
            $this->actingEmployeeId,
            $this->manageAll,
            $this->isConsole,
        );

        if (! class_exists(\Configuration::class)) {
            return;
        }

        foreach ($settings as $field => $value) {
            if (! in_array((string) $field, self::PERSISTED_FIELDS, true)) {
                continue;
            }

            $key = 'PHPCLAW_'.strtoupper((string) $field);
            $stored = is_array($value) ? (string) json_encode($value) : (string) $value;
            \Configuration::deleteByName($key);
            \Configuration::updateValue($key, $stored);
        }
    }

    /**
     * Load admin-saved settings from the native ps_configuration store.
     *
     * @return array<string, mixed>
     */
    private function loadSavedSettings(): array
    {
        if (! class_exists(\Configuration::class)) {
            return [];
        }

        $settings = [];
        foreach (self::PERSISTED_FIELDS as $field) {
            $key = 'PHPCLAW_'.strtoupper($field);
            $raw = \Configuration::get($key);
            if ($raw === false || $raw === null) {
                continue;
            }
            if ($field === 'cloud_disable' || $field === 'remote_skill_urls') {
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded)) {
                    $settings[$field] = array_values(array_map('strval', $decoded));
                } elseif ((string) $raw !== '') {
                    $settings[$field] = array_values(array_filter(
                        array_map('trim', explode(',', (string) $raw)),
                        static fn (string $s): bool => $s !== ''
                    ));
                } else {
                    $settings[$field] = [];
                }
            } else {
                $settings[$field] = (string) $raw;
            }
        }

        return $settings;
    }
}
