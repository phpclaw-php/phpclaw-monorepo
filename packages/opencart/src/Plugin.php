<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart;

use PhpClaw\AutoDiscovery\Bootstrap;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\RateLimitGuard;
use PhpClaw\Hooks\HookEventBridge;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\OpenCart\Contracts\OcDbInterface;
use PhpClaw\OpenCart\Engine\OcEngineFactory;
use PhpClaw\OpenCart\Memory\OcDbConversationMemory;
use PhpClaw\OpenCart\Memory\OcDbMemory;
use PhpClaw\OpenCart\Memory\OcRouterMemory;
use PhpClaw\OpenCart\Memory\OcSettingMemory;
use PhpClaw\Providers\OpenAIPresets;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Skills\SkillResolver;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;

/**
 * Main extension bootstrap singleton.
 */
final class Plugin
{
    private static ?self $instance = null;

    private ?ClawInterface $engine = null;

    private ?bool $engineMayUseModule = null;

    private ?\Throwable $engineError = null;

    private array $config;

    private array $saved;

    private readonly string $tablePrefix;

    private readonly OcEventFirer $eventFirer;

    private readonly OcEngineFactory $engineFactory;

    /**
     * Resolve the table prefix, bind the Registry and DB handle, then boot the registries.
     *
     * @param  string|null  $tablePrefix  OC table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  object|null  $registry  OC Registry for extension events, or null (CLI).
     * @param  OcDbInterface|null  $db  OC DB adapter, or null (tests/CLI).
     */
    private function __construct(
        ?string $tablePrefix = null,
        private readonly ?object $registry = null,
        private readonly ?OcDbInterface $db = null,
    ) {
        $this->tablePrefix = OcTablePrefix::resolve($tablePrefix);
        $this->eventFirer = new OcEventFirer($registry);
        $this->engineFactory = new OcEngineFactory;
        $this->config = require __DIR__.'/../config/phpclaw.php';
        $this->saved = $this->loadSavedSettings();

        $this->bootRegistries();
        $this->maybeRunMigration();
        $this->maybeUpgradeSchema();
    }

    /**
     * Singleton accessor.
     *
     * @param  string|null  $tablePrefix  OC table prefix; defaults to DB_PREFIX, or 'oc_' when undefined.
     * @param  object|null  $registry  OC Registry for extension hook events.
     * @param  OcDbInterface|null  $db  OC native DB handle.
     * @return self
     */
    public static function getInstance(
        ?string $tablePrefix = null,
        ?object $registry = null,
        ?OcDbInterface $db = null,
    ): self {
        if (self::$instance === null) {
            self::$instance = new self($tablePrefix, $registry, $db);
        }

        return self::$instance;
    }

    /**
     * Expose the Claw engine, rebuilding the cached instance whenever the caller's module grant
     * differs, so one caller's authorisation is never served from another's cache.
     *
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @return ClawInterface
     *
     * @throws \Throwable if the engine could not be built.
     */
    public function engine(bool $callerMayUseModule): ClawInterface
    {
        if ($this->engineMayUseModule !== $callerMayUseModule) {
            $this->engine = null;
            $this->engineError = null;
            $this->engineMayUseModule = $callerMayUseModule;
        }

        if ($this->engine === null && $this->engineError === null) {
            try {
                $this->engine = $this->buildEngine($callerMayUseModule);
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
     * Return the resolved config array (package defaults merged with saved settings).
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Return admin-saved settings (written by the admin Settings page).
     *
     * @return array<string, mixed>
     */
    public function saved(): array
    {
        return $this->saved;
    }

    /**
     * Live tool instances for the Guide, profile-unfiltered.
     *
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @return array<ToolInterface>
     */
    public function guideTools(bool $callerMayUseModule): array
    {
        $tools = $this->engineFactory->buildTools(
            $this->config,
            $this->db,
            $this->tablePrefix,
            $this->eventFirer,
            callerMayUseModule: $callerMayUseModule,
            mayQueryRaw: false,
            applyProfile: false,
        );

        ['deny' => $deny, 'groups' => $groups] = OcEngineFactory::denyConfig($this->config);

        $registry = new ToolRegistry;
        $registry->register($tools, $deny, $groups);

        return $registry->all();
    }

    /**
     * The OpenCart event firer (for Guide listing of module-contributed capabilities).
     *
     * @return OcEventFirer
     */
    public function eventFirer(): OcEventFirer
    {
        return $this->eventFirer;
    }

    /**
     * Build an engine with per-invocation provider/model overrides applied in-memory.
     *
     * @param  array<string, string>  $overrides  Keys: 'provider', 'model'.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @return ClawInterface
     */
    public function buildEngineWithOverrides(array $overrides, bool $callerMayUseModule): ClawInterface
    {
        $saved = $this->saved;
        $saved['provider'] = $overrides['provider'] ?? $saved['provider'] ?? '';
        $saved['model'] = $overrides['model'] ?? $saved['model'] ?? '';

        $previous = $this->saved;
        $this->saved = $saved;
        $this->engine = null;

        try {
            return $this->buildEngine($callerMayUseModule);
        } finally {
            $this->saved = $previous;
            $this->engine = null;
        }
    }

    /**
     * Build and return the memory driver from saved settings.
     *
     * @return MemoryInterface
     */
    public function memory(): MemoryInterface
    {
        return MemoryRegistry::build('oc_router');
    }

    /**
     * Build a conversation memory driver scoped to the given acting user.
     *
     * @param  int  $actingUserId  Authenticated user's ID; 0 = CLI/unowned sentinel.
     * @param  bool  $manageAll  Whether the caller holds the manage-all conversations grant.
     * @return OcDbConversationMemory
     */
    public function scopedConversationMemory(int $actingUserId, bool $manageAll): OcDbConversationMemory
    {
        $storeMessages = filter_var($this->saved['store_messages'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return new OcDbConversationMemory($this->db, $this->tablePrefix, $storeMessages, $actingUserId, $manageAll);
    }

    /**
     * Build a Claw engine whose conversation memory is scoped to the given acting user.
     * Unlike engine(), the instance is NOT cached: each call builds a fresh engine.
     *
     * @param  int  $actingUserId  Authenticated user's ID; 0 = CLI/unowned sentinel.
     * @param  bool  $manageAll  Whether the caller holds the manage-all conversations grant.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @param  bool  $mayQueryRaw  Whether the caller holds the grant that permits raw SQL.
     * @return ClawInterface
     */
    public function buildScopedEngine(int $actingUserId, bool $manageAll, bool $callerMayUseModule, bool $mayQueryRaw): ClawInterface
    {
        $storeMessages = filter_var($this->saved['store_messages'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $db = $this->db;
        $prefix = $this->tablePrefix;

        MemoryRegistry::register('oc_router', static fn () => new OcRouterMemory(
            new OcDbConversationMemory($db, $prefix, $storeMessages, $actingUserId, $manageAll),
            new OcDbMemory($db, $prefix),
        ));

        try {
            return $this->engineFactory->build(
                $this->config,
                $this->saved,
                $this->db,
                $this->tablePrefix,
                $this->eventFirer,
                $callerMayUseModule,
                $mayQueryRaw,
            );
        } finally {
            MemoryRegistry::register('oc_router', fn () => new OcRouterMemory(
                new OcDbConversationMemory($this->db, $this->tablePrefix, $storeMessages),
                new OcDbMemory($this->db, $this->tablePrefix),
            ));
        }
    }

    /**
     * Build a Claw engine with provider/model overrides AND per-user conversation scoping.
     * Combines buildEngineWithOverrides() with buildScopedEngine(); the result is not cached.
     *
     * @param  int  $actingUserId  Authenticated user's ID; 0 = CLI/unowned sentinel.
     * @param  bool  $manageAll  Whether the caller holds the manage-all conversations grant.
     * @param  array<string, string>  $overrides  Keys: 'provider', 'model'.
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @param  bool  $mayQueryRaw  Whether the caller holds the grant that permits raw SQL.
     * @return ClawInterface
     */
    public function buildScopedEngineWithOverrides(int $actingUserId, bool $manageAll, array $overrides, bool $callerMayUseModule, bool $mayQueryRaw): ClawInterface
    {
        $previous = $this->saved;
        $this->saved['provider'] = $overrides['provider'] ?? $this->saved['provider'] ?? '';
        $this->saved['model'] = $overrides['model'] ?? $this->saved['model'] ?? '';

        try {
            return $this->buildScopedEngine($actingUserId, $manageAll, $callerMayUseModule, $mayQueryRaw);
        } finally {
            $this->saved = $previous;
        }
    }

    /**
     * Persist settings written by the admin panel to the native {DB_PREFIX}setting table.
     *
     * @param  array<string, mixed>  $settings
     * @return void
     */
    public function saveSettings(array $settings): void
    {
        $this->saved = $settings;

        $this->engine = null;
        $this->engineError = null;

        if ($this->db === null) {
            return;
        }

        $table = $this->tablePrefix.'setting';

        foreach ($settings as $field => $value) {
            $ocKey = 'module_phpclaw_'.$field;
            $serialized = is_array($value) ? 1 : 0;
            $stored = $serialized ? serialize($value) : (string) $value;

            $this->db->query(
                "DELETE FROM `{$table}` WHERE store_id = 0 AND code = 'module_phpclaw' AND `key` = ?",
                [$ocKey],
            );
            $this->db->query(
                "INSERT INTO `{$table}` (store_id, code, `key`, value, serialized)"
                ." VALUES (0, 'module_phpclaw', ?, ?, ?)",
                [$ocKey, $stored, $serialized],
            );
        }
    }

    /**
     * Execute the install SQL, substituting the real table prefix.
     *
     * @return void
     */
    public function runMigration(): void
    {
        if ($this->db === null) {
            return;
        }

        $sqlFile = __DIR__.'/../sql/install.sql';

        if (! file_exists($sqlFile)) {
            return;
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace('OC_', $this->tablePrefix, $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->query($statement);
        }
    }

    /**
     * Read the module's saved settings row from the OpenCart setting table.
     *
     * @return array<string, mixed>
     */
    private function loadSavedSettings(): array
    {
        if ($this->db === null) {
            return [];
        }

        $table = $this->tablePrefix.'setting';

        try {
            $result = $this->db->query(
                "SELECT `key`, value, serialized FROM `{$table}`"
                ." WHERE store_id = 0 AND code = 'module_phpclaw'",
            );
        } catch (\Throwable) {
            return [];
        }

        $settings = [];

        foreach ($result->rows as $row) {
            $rawKey = (string) ($row['key'] ?? '');
            $field = str_starts_with($rawKey, 'module_phpclaw_')
                ? substr($rawKey, strlen('module_phpclaw_'))
                : $rawKey;

            if ($field === '') {
                continue;
            }

            $serialized = (int) ($row['serialized'] ?? 0);
            $raw = (string) ($row['value'] ?? '');

            $settings[$field] = $serialized === 1 ? unserialize($raw, ['allowed_classes' => false]) : $raw;
        }

        return $settings;
    }

    /**
     * Wire all six phpClaw registries from saved settings + extension events.
     *
     * @return void
     */
    private function bootRegistries(): void
    {
        GuardRegistry::registerDefaults();

        $rateLimit = (int) ($this->config['rate_limit_per_minute'] ?? 60);
        if ($rateLimit > 0) {
            GuardRegistry::register(new RateLimitGuard($rateLimit, 60), replace: true);
        }

        MemoryRegistry::register('oc_setting', fn () => new OcSettingMemory(
            $this->db,
            $this->tablePrefix,
        ));

        MemoryRegistry::register('oc_db', fn () => new OcDbMemory(
            $this->db,
            $this->tablePrefix,
        ));

        MemoryRegistry::register('oc_router', fn () => new OcRouterMemory(
            new OcDbConversationMemory($this->db, $this->tablePrefix, filter_var($this->saved['store_messages'] ?? true, FILTER_VALIDATE_BOOLEAN)),
            new OcDbMemory($this->db, $this->tablePrefix),
        ));

        Bootstrap::boot();
        SkillCatalogue::activateDefaults();

        $this->registerConfigGuards();
        $this->registerConfigHooks();
        $this->registerConfigSkills();

        $this->registerExtraGuards();
        $this->registerExtraHooks();
        $this->registerExtraSkills();
        $this->registerExtraMemory();
        $this->registerExtraProviders();

        if ((bool) ($this->config['events_bridge'] ?? true)) {
            $registry = $this->registry;
            (new HookEventBridge(
                static function (string $event, array $ctx) use ($registry): void {
                    if ($registry !== null && $registry->has('event')) {
                        try {
                            $registry->get('event')->trigger('phpclaw/'.$event, [$ctx]);
                        } catch (\Throwable) {
                        }
                    }
                },
            ))->register();
        }
    }

    /**
     * Trigger the `phpclaw/extra/guards` event and register the contributed guards.
     *
     * @return void
     */
    private function registerExtraGuards(): void
    {
        foreach ($this->eventFirer->fire('phpclaw/extra/guards', []) as $guard) {
            if ($guard instanceof GuardInterface) {
                GuardRegistry::register($guard, replace: true);
            }
        }
    }

    /**
     * Trigger the `phpclaw/extra/hooks` event and subscribe the contributed hooks.
     *
     * @return void
     */
    private function registerExtraHooks(): void
    {
        foreach ($this->eventFirer->fire('phpclaw/extra/hooks', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }
            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }
            HookRegistry::on($entry['event'], $entry['handler'], (int) ($entry['priority'] ?? 10));
        }
    }

    /**
     * Trigger the `phpclaw/extra/skills` event and register the contributed skills.
     *
     * @return void
     */
    private function registerExtraSkills(): void
    {
        foreach ($this->eventFirer->fire('phpclaw/extra/skills', []) as $skill) {
            if ($skill instanceof SkillInterface) {
                SkillRegistry::register($skill);
            }
        }
    }

    /**
     * Trigger the `phpclaw/extra/memory` event and register the contributed drivers.
     *
     * @return void
     */
    private function registerExtraMemory(): void
    {
        foreach ($this->eventFirer->fire('phpclaw/extra/memory', []) as $slug => $factory) {
            if (is_string($slug) && $slug !== '' && is_callable($factory)) {
                MemoryRegistry::register($slug, $factory);
            }
        }
    }

    /**
     * Trigger the `phpclaw/extra/providers` event and register the contributed providers.
     *
     * @return void
     */
    private function registerExtraProviders(): void
    {
        $hasPresets = class_exists(OpenAIPresets::class);
        $natives = Bootstrap::providers();

        foreach ($this->eventFirer->fire('phpclaw/extra/providers', []) as $slug => $entry) {
            if (! is_string($slug) || $slug === '' || ! is_array($entry)) {
                continue;
            }
            if ($hasPresets && OpenAIPresets::has($slug)) {
                continue;
            }
            if (isset($natives[$slug])) {
                continue;
            }
            $class = $entry['class'] ?? '';
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            if (! ProviderRegistry::has($slug)) {
                try {
                    ProviderRegistry::register($slug, $class);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Instantiate and register guards declared in `config/phpclaw.php`.
     *
     * @return void
     */
    private function registerConfigGuards(): void
    {
        foreach ((array) ($this->config['guards'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['class']) || ! is_string($entry['class'])) {
                continue;
            }

            if (! class_exists($entry['class'])) {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            GuardRegistry::register(new $entry['class'], $priority, replace: true);
        }
    }

    /**
     * Subscribe hook handlers declared in `config/phpclaw.php`.
     *
     * @return void
     */
    private function registerConfigHooks(): void
    {
        foreach ((array) ($this->config['hooks'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['event'], $entry['handler'])) {
                continue;
            }

            if (! is_string($entry['event']) || $entry['event'] === '') {
                continue;
            }

            $priority = (int) ($entry['priority'] ?? 10);
            HookRegistry::on($entry['event'], $entry['handler'], $priority);
        }
    }

    /**
     * Build SkillInterface instances from config['skills'].
     *
     * @return SkillInterface[]
     */
    private function resolveConfigSkills(): array
    {
        return SkillResolver::resolve((array) ($this->config['skills'] ?? []));
    }

    /**
     * Instantiate and register skills declared in `config/phpclaw.php`.
     *
     * @return void
     */
    private function registerConfigSkills(): void
    {
        foreach ($this->resolveConfigSkills() as $skill) {
            SkillRegistry::register($skill);
        }
    }

    /**
     * Build a configured Claw engine from `$this->saved` + `$this->config`.
     *
     * @param  bool  $callerMayUseModule  Whether the acting caller holds the phpClaw module grant.
     * @return Claw
     */
    private function buildEngine(bool $callerMayUseModule): Claw
    {
        return $this->engineFactory->build(
            $this->config,
            $this->saved,
            $this->db,
            $this->tablePrefix,
            $this->eventFirer,
            callerMayUseModule: $callerMayUseModule,
            mayQueryRaw: false,
        );
    }

    /**
     * Run the DB migration if tables are missing.
     *
     * @return void
     */
    private function maybeRunMigration(): void
    {
        if ($this->db === null) {
            return;
        }

        $table = $this->tablePrefix.'phpclaw_memory';

        try {
            $exists = $this->db->query("SHOW TABLES LIKE '".$this->db->escape($table)."'")->num_rows > 0;
        } catch (\Throwable) {
            return;
        }

        if ($exists) {
            return;
        }

        $this->runMigration();
    }

    /**
     * Idempotently add the owner_id column to phpclaw_conversations if missing.
     * Called on every request; no-ops immediately once the column exists.
     *
     * @return void
     */
    private function maybeUpgradeSchema(): void
    {
        if ($this->db === null) {
            return;
        }

        $table = $this->tablePrefix.'phpclaw_conversations';

        try {
            $has = $this->db->query(
                "SHOW COLUMNS FROM `{$table}` LIKE 'owner_id'"
            )->num_rows > 0;
        } catch (\Throwable) {
            return;
        }

        if ($has) {
            return;
        }

        try {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD COLUMN `owner_id` INT UNSIGNED NULL DEFAULT NULL AFTER `namespace`"
            );
            $this->db->query(
                "ALTER TABLE `{$table}` ADD INDEX `idx_owner` (`owner_id`)"
            );
        } catch (\Throwable) {
        }
    }
}
