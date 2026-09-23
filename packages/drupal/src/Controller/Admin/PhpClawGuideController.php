<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use PhpClaw\AutoDiscovery\DiscoveryCache;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\DrupalIdentityResolver;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guide page: usage documentation for the phpClaw Drupal module.
 */
final class PhpClawGuideController extends ControllerBase
{
    use CsrfValidationTrait;
    use ResolveAgentTrait;

    /**
     * Construct the Guide controller with its optional collaborators.
     *
     * @param  ClawInterface|null  $agent  phpClaw agent (nullable).
     * @param  LoggerInterface|null  $logger  phpClaw logger channel.
     * @param  CsrfTokenGenerator|null  $csrfToken  CSRF token generator.
     * @param  ConfigFactoryInterface|null  $configFactory  Drupal config factory (injected via create()).
     * @param  ?PhpClawRegistrar  $registrar
     * @param  ?DrupalAgentContext  $agentContext  Bundled dependencies for building a deny-filtered tool registry.
     * @return void
     */
    public function __construct(
        private readonly ?ClawInterface $agent = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CsrfTokenGenerator $csrfToken = null,
        ?ConfigFactoryInterface $configFactory = null,
        private readonly ?PhpClawRegistrar $registrar = null,
        private readonly ?DrupalAgentContext $agentContext = null,
    ) {
        if ($configFactory !== null) {
            $this->configFactory = $configFactory;
        }
    }

    /**
     * Create a new controller instance from the service container.
     *
     * @param  ContainerInterface  $container  The Drupal service container.
     * @return static
     */
    public static function create(ContainerInterface $container): static
    {
        return new self(
            self::resolveAgent($container, 'phpclaw.agent.chat'),
            $container->get('logger.channel.phpclaw'),
            $container->get('csrf_token'),
            $container->get('config.factory'),
            $container->has('phpclaw.registrar') ? $container->get('phpclaw.registrar') : null,
            $container->has('phpclaw.agent_context') ? $container->get('phpclaw.agent_context') : null,
        );
    }

    /**
     * Render the guide page listing available tools and example prompts.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return [
            '#theme' => 'phpclaw_admin_guide',
            '#tools' => $this->buildToolRows(),
            '#providers' => $this->buildProviders(),
            '#core_tools' => $this->buildCoreTools(),
            '#capability_records' => $this->buildCapabilityRecords(),
            '#remote_skill_urls' => $this->getRemoteSkillUrls(),
            '#remote_skills' => $this->getRemoteSkills(),
            '#manage_all' => DrupalIdentityResolver::manageAll(),
            '#attached' => [
                'library' => ['phpclaw/admin.system', 'phpclaw/admin.guide'],
                'drupalSettings' => [
                    'phpclaw_guide' => [
                        'csrf_token' => $this->csrfToken?->get('phpclaw-chat'),
                    ],
                ],
            ],
            '#cache' => ['max-age' => 0],
        ];
    }

    /**
     * Drupal-native tool rows for the Guide tools tab, filtered by `tool_deny`.
     *
     * @return list<array{name: string, desc: string, prompts: string}>
     */
    private function buildToolRows(): array
    {
        $meta = [
            'DrupalEntityTool' => ['name' => 'Entity',            'desc' => 'Query nodes, taxonomy terms, users, and any Drupal entity type.', 'prompts' => 'Show published articles | List users with admin role'],
            'DatabaseTool' => ['name' => 'Database',          'desc' => 'Read-only SELECT queries against any Drupal table. Auto-LIMIT 200, maximum 500.', 'prompts' => 'Count rows in node_field_data | Show table sizes'],
            'DrupalBlockTool' => ['name' => 'Block',             'desc' => 'Query block placements, regions, and visibility settings.', 'prompts' => 'List blocks in sidebar | Show disabled blocks'],
            'DrupalCacheTool' => ['name' => 'Cache',             'desc' => 'Inspect Drupal cache bin row counts. Read-only, does not flush or clear caches.', 'prompts' => 'Show render cache row count | Show all cache bin sizes'],
            'DrupalConfigTool' => ['name' => 'Config',            'desc' => 'Read Drupal configuration values (site name, settings, etc.).', 'prompts' => 'Show site name | List active theme'],
            'DrupalContentModerationTool' => ['name' => 'Content Moderation', 'desc' => 'Query content moderation states and transitions.', 'prompts' => 'Content awaiting review | Draft articles'],
            'DrupalCronTool' => ['name' => 'Cron',              'desc' => 'Inspect cron status and last-run timestamps.', 'prompts' => 'When did cron last run? | Cron status'],
            'DrupalMediaTool' => ['name' => 'Media',             'desc' => 'Query media entities (images, documents, videos).', 'prompts' => 'List recent uploads | Images without alt text'],
            'DrupalMenuTool' => ['name' => 'Menu',              'desc' => 'Query menu links and hierarchies.', 'prompts' => 'Show main menu items | Disabled menu links'],
            'DrupalModuleTool' => ['name' => 'Module',            'desc' => 'List enabled/disabled modules and their versions.', 'prompts' => 'List active modules | Any updates available?'],
            'DrupalPathAliasTool' => ['name' => 'Path Alias',        'desc' => 'Query URL aliases and their source paths.', 'prompts' => 'Show aliases for /node/* | Duplicate aliases'],
            'DrupalUserRoleTool' => ['name' => 'User Role',         'desc' => 'Query roles and their permissions.', 'prompts' => 'List all roles | Permissions for editor role'],
            'DrupalViewsTool' => ['name' => 'Views',             'desc' => 'Query Views displays and their configurations.', 'prompts' => 'List all views | Disabled views'],
            'DrupalWebformTool' => ['name' => 'Webform',           'desc' => 'Query webform submissions and field definitions.', 'prompts' => 'Recent contact form submissions | Webform fields'],
            'LogTool' => ['name' => 'Log',               'desc' => 'Read recent Drupal watchdog log entries.', 'prompts' => 'Show recent errors | Last 20 warnings'],
        ];

        try {
            $live = $this->agentContext === null
                ? null
                : PhpClawServiceFactory::buildToolRegistry($this->agentContext)->all();
        } catch (\Throwable) {
            $live = null;
        }

        if ($live === null) {
            $rows = array_values($meta);
            foreach ($this->registrar?->getExternalTools() ?? [] as $tool) {
                if (! is_object($tool)) {
                    continue;
                }
                $rows[] = [
                    'name' => method_exists($tool, 'name') ? (string) $tool->name() : $this->shortName($tool::class),
                    'desc' => method_exists($tool, 'description') ? (string) $tool->description() : '',
                    'prompts' => '',
                ];
            }

            return $rows;
        }

        $rows = [];
        foreach ($live as $tool) {
            $class = $tool::class;
            if (str_starts_with($class, 'PhpClaw\\Tools\\')) {
                continue;
            }

            $short = $this->shortName($class);
            $m = $meta[$short] ?? null;
            $rows[] = [
                'name' => $m['name'] ?? (method_exists($tool, 'name') ? (string) $tool->name() : $short),
                'desc' => $m['desc'] ?? (method_exists($tool, 'description') ? (string) $tool->description() : ''),
                'prompts' => $m['prompts'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Admin-only connectivity check for the currently configured provider.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return JsonResponse
     */
    public function testConnection(Request $request): JsonResponse
    {
        if (! $this->validateCsrfToken($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        if ($this->agent === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Agent service unavailable.'], 503);
        }

        try {
            $response = $this->agent->send('What is 2+2? Answer with the number only.');

            return new JsonResponse([
                'ok' => true,
                'provider' => $response->provider,
                'model' => $response->model,
            ]);
        } catch (GuardException) {
            return new JsonResponse(['ok' => false, 'error' => 'Request blocked by a configured guard.'], 422);
        } catch (ProviderException $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return new JsonResponse(['ok' => false, 'error' => 'Provider error. Check logs for details.'], 502);
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);

            return new JsonResponse(['ok' => false, 'error' => 'Test failed. Check logs for details.'], 500);
        }
    }

    /**
     * Configured `remote_skill_urls`, or [] when none are set.
     *
     * @return list<string>
     */
    public function getRemoteSkillUrls(): array
    {
        $raw = (string) ($this->config('phpclaw.settings')->get('remote_skill_urls') ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Return skills registered via `remote_skill_urls`, diffed against the discovered skill records.
     *
     * @return list<array{name: string, description: string, keywords: list<string>}>
     */
    public function getRemoteSkills(): array
    {
        // AutoDiscovery never sees skills loaded at agent-build time, and the agent is already built and injected by the time the controller runs, so no build trigger is needed here.
        if ($this->getRemoteSkillUrls() === [] || $this->agent === null) {
            return [];
        }

        if (! class_exists(SkillRegistry::class)) {
            return [];
        }

        $known = [];
        foreach ($this->buildCapabilityRecords()['skills'] as $s) {
            $known[$s['name']] = true;
        }

        $records = [];
        foreach (SkillRegistry::all() as $skill) {
            if (isset($known[$skill->name()])) {
                continue;
            }
            $records[] = [
                'name' => $skill->name(),
                'description' => $skill->description(),
                'keywords' => array_map('strval', $skill->tags()),
            ];
        }

        return $records;
    }

    /**
     * Live provider list for the providers tab.
     *
     * @return list<array{name: string, key: string, signup: string, notes: string}>
     */
    private function buildProviders(): array
    {
        try {
            $catalogue = ProviderCatalogue::all();
        } catch (\Throwable) {
            return [];
        }

        $meta = [
            'anthropic' => ['name' => 'Anthropic Claude', 'signup' => 'https://console.anthropic.com/',  'notes' => 'Default for production. Use claude-haiku-* for speed, claude-sonnet-* for quality.'],
            'openai' => ['name' => 'OpenAI GPT',       'signup' => 'https://platform.openai.com/api-keys', 'notes' => 'gpt-4o-mini is the cheapest capable model.'],
            'groq' => ['name' => 'Groq',             'signup' => 'https://console.groq.com/keys',    'notes' => 'Fastest tokens-per-second. Llama and Mixtral models. Free tier available.'],
            'gemini' => ['name' => 'Google Gemini',    'signup' => 'https://aistudio.google.com/',     'notes' => 'gemini-1.5-flash for speed, gemini-1.5-pro for reasoning. Generous free tier.'],
            'mistral' => ['name' => 'Mistral',          'signup' => 'https://console.mistral.ai/',      'notes' => 'European cloud. mistral-large-latest for quality, mistral-small-latest for cost.'],
            'ollama' => ['name' => 'Ollama (local)',   'signup' => 'https://ollama.com/',              'notes' => 'No API key. Runs on http://localhost:11434 by default.'],
            'deepseek' => ['name' => 'DeepSeek',         'signup' => 'https://platform.deepseek.com/',   'notes' => 'Cost-efficient reasoning. Defaults to deepseek-flash; deepseek-chat for general, deepseek-reasoner for complex.'],
            'custom' => ['name' => 'Custom',           'signup' => '',                                  'notes' => 'Any OpenAI-compatible endpoint; set Base URL in Settings.'],
        ];

        $rows = [];
        foreach ($catalogue as $slug => $info) {
            $slug = (string) $slug;
            $m = $meta[$slug] ?? [];
            $rows[] = [
                'name' => (string) ($m['name'] ?? ($info['label'] ?? '') ?: ucfirst($slug)),
                'key' => $slug,
                'signup' => (string) ($m['signup'] ?? ''),
                'notes' => (string) ($m['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Auto-discovered core utility tools (#[Tool(default: true)]).
     *
     * @return list<array{tool: string, desc: string, deprecated: bool}>
     */
    private function buildCoreTools(): array
    {
        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach ($cache['tools'] ?? [] as $class => $attr) {
            if (empty($attr['default'])) {
                continue;
            }
            $rows[] = [
                'tool' => $this->shortName((string) $class),
                'desc' => (string) ($attr['description'] ?? ''),
                'deprecated' => ! empty($attr['deprecated']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['tool'], $b['tool']));

        return $rows;
    }

    /**
     * Class basename for a fully-qualified class string.
     *
     * @param  string  $class  Fully-qualified class name.
     * @return string
     */
    private function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }

    /**
     * Auto-discovered capability records for the Guide tables.
     *
     * @return array{memory: list<array<string, mixed>>, skills: list<array<string, mixed>>, guards: list<array<string, mixed>>, hooks: list<array<string, mixed>>}
     */
    private function buildCapabilityRecords(): array
    {
        $records = ['memory' => [], 'skills' => [], 'guards' => [], 'hooks' => []];

        try {
            $cache = DiscoveryCache::load();
        } catch (\Throwable) {
            return $records;
        }

        foreach ($cache['memory'] ?? [] as $class => $attr) {
            $records['memory'][] = [
                'driver' => (string) ($attr['driver'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'source' => $this->sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['skills'] ?? [] as $class => $attr) {
            $records['skills'][] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'keywords' => array_values(array_map('strval', (array) ($attr['keywords'] ?? []))),
                'source' => $this->sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['guards'] ?? [] as $class => $attr) {
            $records['guards'][] = [
                'name' => (string) ($attr['name'] ?? ''),
                'label' => (string) ($attr['label'] ?? $class),
                'priority' => (int) ($attr['priority'] ?? 0),
                'enabled_by_default' => (bool) ($attr['enabledByDefault'] ?? false),
                'source' => $this->sourceFromClass((string) $class),
                'class' => (string) $class,
            ];
        }

        foreach ($cache['hooks'] ?? [] as $class => $listeners) {
            $source = $this->sourceFromClass((string) $class);
            foreach ((array) $listeners as $listener) {
                $records['hooks'][] = [
                    'event' => (string) ($listener['event'] ?? ''),
                    'name' => (string) ($listener['name'] ?? ''),
                    'priority' => (int) ($listener['priority'] ?? 0),
                    'enabled_by_default' => (bool) ($listener['enabledByDefault'] ?? false),
                    'source' => $source,
                    'class' => (string) $class,
                ];
            }
        }

        foreach ($this->registrar?->getExternalMemoryDrivers() ?? [] as $name => $class) {
            $records['memory'][] = [
                'driver' => (string) $name,
                'label' => (string) $name,
                'source' => 'drupal',
                'class' => (string) $class,
            ];
        }

        foreach ($this->registrar?->getExternalSkills() ?? [] as $skill) {
            if (! is_object($skill)) {
                continue;
            }
            $name = method_exists($skill, 'name') ? (string) $skill->name() : $this->shortName($skill::class);
            $records['skills'][] = [
                'name' => $name,
                'label' => $name,
                'keywords' => method_exists($skill, 'tags') ? array_values(array_map('strval', (array) $skill->tags())) : [],
                'source' => 'drupal',
                'class' => $skill::class,
            ];
        }

        foreach ($this->registrar?->getExternalGuards() ?? [] as $guard) {
            if (! is_object($guard)) {
                continue;
            }
            $records['guards'][] = [
                'name' => $this->shortName($guard::class),
                'label' => $this->shortName($guard::class),
                'priority' => 0,
                'enabled_by_default' => true,
                'source' => 'drupal',
                'class' => $guard::class,
            ];
        }

        foreach ($this->registrar?->getExternalHooks() ?? [] as $hook) {
            $records['hooks'][] = [
                'event' => (string) ($hook['event'] ?? ''),
                'name' => $this->shortName((string) ($hook['class'] ?? '')),
                'priority' => (int) ($hook['priority'] ?? 0),
                'enabled_by_default' => true,
                'source' => 'drupal',
                'class' => (string) ($hook['class'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * Derive a short source label from a class FQCN.
     *
     * @param  string  $class  The capability class FQCN.
     * @return string
     */
    private function sourceFromClass(string $class): string
    {
        return str_starts_with($class, 'PhpClaw\\Drupal\\') ? 'drupal' : 'core';
    }
}
