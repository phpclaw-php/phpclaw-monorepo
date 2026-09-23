<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Laravel\Console\Concerns\RendersBanner;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;

/**
 * Artisan command `php artisan phpclaw:guide [--section=<name>] [--live]`, prints adapter documentation, optionally scoped to one section.
 */
final class GuideCommand extends Command
{
    use RendersBanner;

    private const SECTIONS = [
        'quickstart', 'tools', 'providers', 'memory', 'guards',
        'hooks', 'skills', 'rest', 'cli', 'privacy', 'config',
    ];

    protected $signature = 'phpclaw:guide '
        .'{--section= : Print only a specific section (quickstart|tools|providers|memory|guards|hooks|skills|rest|cli|privacy|config)} '
        .'{--live : Fetch configured remote skill URLs and list what actually registers (network I/O; off by default)}';

    protected $description = 'Display phpClaw adapter documentation';

    /**
     * Print the full guide, or a single section when --section is given.
     *
     * @return int Artisan exit code.
     */
    public function handle(): int
    {
        $section = $this->option('section');
        $live = (bool) $this->option('live');

        if ($section !== null && ! in_array($section, self::SECTIONS, strict: true)) {
            $this->error("Unknown section '{$section}'. Available: ".implode(', ', self::SECTIONS));

            return self::FAILURE;
        }

        if ($section !== null) {
            $this->printSection($section);
        } else {
            $this->banner();

            foreach (self::SECTIONS as $s) {
                $this->printSection($s);
                $this->newLine();
            }
        }

        if ($live) {
            $this->printRemoteSkills();
        }

        return self::SUCCESS;
    }

    /**
     * Fetch every configured remote skill URL and print what registers; runs only under --live because it does network I/O.
     *
     * @return void
     */
    private function printRemoteSkills(): void
    {

        $urls = (array) config('phpclaw.remote_skill_urls', []);

        $this->line(str_repeat('━', 50));
        $this->line('  REMOTE SKILLS (live)');
        $this->line(str_repeat('━', 50));

        if ($urls === []) {
            $this->line('No remote skill URLs configured (PHPCLAW_REMOTE_SKILL_URLS is empty).');

            return;
        }

        SkillCatalogue::activateDefaults();
        $before = array_map(fn ($s) => $s->name(), SkillRegistry::all());

        foreach ($urls as $url) {
            try {
                RemoteSkillLoader::load((string) $url);
            } catch (\Throwable) {
                $this->line("  FAILED  {$url}, the skill could not be fetched or parsed.");
            }
        }

        $loaded = array_values(array_diff(
            array_map(fn ($s) => $s->name(), SkillRegistry::all()),
            $before,
        ));

        if ($loaded === []) {
            $this->line('None of the configured URLs registered a skill.');

            return;
        }

        $this->line(count($loaded).' skill(s) registered from '.count($urls).' configured URL(s):');
        foreach ($loaded as $name) {
            $this->line("  - {$name}");
        }
    }

    /**
     * Print a single documentation section by name.
     *
     * @param  string  $section  The section slug to print.
     * @return void
     */
    private function printSection(string $section): void
    {
        $this->line(str_repeat('━', 50));
        $this->line('  '.strtoupper($section));
        $this->line(str_repeat('━', 50));

        match ($section) {
            'quickstart' => $this->sectionQuickstart(),
            'tools' => $this->sectionTools(),
            'providers' => $this->sectionProviders(),
            'memory' => $this->sectionMemory(),
            'guards' => $this->sectionGuards(),
            'hooks' => $this->sectionHooks(),
            'skills' => $this->sectionSkills(),
            'rest' => $this->sectionRest(),
            'cli' => $this->sectionCli(),
            'privacy' => $this->sectionPrivacy(),
            'config' => $this->sectionConfig(),
            default => null,
        };
    }

    /**
     * Print the quickstart steps.
     *
     * @return void
     */
    private function sectionQuickstart(): void
    {
        $this->line('1. Publish config:  php artisan vendor:publish --tag=phpclaw-config');
        $this->line('2. Set ANTHROPIC_API_KEY (or OPENAI_API_KEY, GROQ_API_KEY, etc.) in .env');
        $this->line('3. Run:             php artisan phpclaw "Tell me something useful"');
        $this->line('4. See status:      php artisan phpclaw:about');
    }

    /**
     * Print the available tools and how to enable them.
     *
     * @return void
     */
    private function sectionTools(): void
    {
        $this->line('Tools are enabled in config/phpclaw.php under the tools array.');
        $this->line('Available tools (add the class name to the tools array to enable):');
        $this->line('  db_query     : read-only SELECT queries on the application database');
        $this->line('  read_log     : tail storage/logs/laravel.log');
        $this->line('  route_list   : list registered routes');
        $this->line('  config_get   : read a config value (secrets redacted)');
        $this->line('  cache_inspect: inspect cache key presence');
        $this->line('  queue_status : report queue connection and job counts');
        $this->line('  http_request : fetch external URLs (SSRF-protected)');
        $this->line('  file_read    : sandboxed file reads inside workspace_root');
        $this->line('  file_write   : sandboxed file writes inside workspace_root');
        $this->line('  shell_exec   : allowlist-guarded shell commands');
        $this->newLine();
        $this->line('Deny specific tools or groups via PHPCLAW_TOOL_DENY in .env.');
        $this->line('Example: PHPCLAW_TOOL_DENY="read_log,group:system"');
    }

    /**
     * Print the supported providers and their env keys.
     *
     * @return void
     */
    private function sectionProviders(): void
    {
        $this->line('Set PHPCLAW_PROVIDER in .env. Supported values:');

        foreach (ProviderCatalogue::all() as $slug => $entry) {
            $label = (string) ($entry['label'] ?? $slug);
            $this->line(sprintf('  %-11s, %s (%s)', $slug, $label, $this->providerEnvKey($slug)));
        }

        $this->newLine();
        $this->line('For custom: set PHPCLAW_BASE_URL to the full http(s) endpoint.');
        $this->line('Override model: PHPCLAW_MODEL=<model-id>');
    }

    /**
     * Map a provider slug to its primary env key for display.
     *
     * @param  string  $slug  The provider slug from the catalogue.
     * @return string
     */
    private function providerEnvKey(string $slug): string
    {
        return match ($slug) {
            'ollama' => 'OLLAMA_HOST',
            'custom' => 'PHPCLAW_BASE_URL',
            default => strtoupper($slug).'_API_KEY',
        };
    }

    /**
     * Print the available memory drivers.
     *
     * @return void
     */
    private function sectionMemory(): void
    {
        $this->line('Set PHPCLAW_MEMORY_DRIVER in .env. Available drivers:');
        $this->line('  database            : default; database-backed (namespace-routed)');
        $this->line('  database_kv         : key-value store only');
        $this->line('  database_conversation : conversation store only');
        $this->line('  file                : JSON files in storage/phpclaw/memory/ (no DB)');
        $this->line('  cache               : Laravel Cache facade (any configured store)');
        $this->line('  redis               : core RedisMemory, auto-discovered (requires a Redis connection)');
        $this->line('  array               : in-process only; resets on each request');
        $this->newLine();
        $this->line('The eloquent, eloquent_kv and eloquent_conversation names remain registered aliases.');
        $this->newLine();
        $this->line('Run migrations first for the database drivers:');
        $this->line('  php artisan migrate');
    }

    /**
     * Print guard configuration guidance.
     *
     * @return void
     */
    private function sectionGuards(): void
    {
        $this->line('Guards run on every agent iteration to detect prompt injection.');
        $this->line('Register custom guards in config/phpclaw.php under guards:');
        $this->line("  ['class' => \\App\\Guards\\MyGuard::class, 'priority' => 5]");
        $this->newLine();
        $this->line('Lower priority numbers run first.');
        $this->line('See php artisan phpclaw:about for active guard count.');
    }

    /**
     * Print registered hooks and how to add more.
     *
     * @return void
     */
    private function sectionHooks(): void
    {
        $configHooks = (array) config('phpclaw.hooks', []);
        $count = count($configHooks);

        if ($count === 0) {
            $this->line('No hooks registered.');
        } else {
            $this->line("Registered listeners ({$count}):");
            foreach ($configHooks as $hook) {
                if (is_array($hook) && isset($hook['event'])) {
                    $this->line("  {$hook['event']}");
                }
            }
        }

        $this->newLine();
        $this->line('Register hooks in config/phpclaw.php under hooks, or via the phpclaw.booting event:');
        $this->line("  Event::listen('phpclaw.booting', function (\\PhpClaw\\Laravel\\Extension\\PhpClawExtensions \$ext): void {");
        $this->line('      $ext->hooks[] = [\'event\' => \'agent.after\', \'handler\' => [MyListener::class, \'handle\']];');
        $this->line('  });');
    }

    /**
     * Print skill configuration guidance.
     *
     * @return void
     */
    private function sectionSkills(): void
    {
        SkillCatalogue::activateDefaults();
        $names = array_map(static fn ($skill) => $skill->name(), SkillRegistry::all());
        $count = count($names);

        if ($count === 0) {
            $this->line('No skills discovered.');
        } else {
            $this->line("Discovered skills ({$count}):");
            foreach ($names as $name) {
                $this->line("  {$name}");
            }
        }

        $remoteUrls = array_filter(
            (array) config('phpclaw.remote_skill_urls', []),
            static fn ($url): bool => is_string($url) && trim($url) !== '',
        );

        if ($remoteUrls !== []) {
            $this->newLine();
            $this->line(sprintf(
                'Remote skill URLs configured: %d. These register at engine build, so they are NOT',
                count($remoteUrls),
            ));
            $this->line('in the count above. phpclaw:about reports the combined total.');
            $this->line('Run phpclaw:guide --section=skills --live to fetch and list them.');
        }

        $this->newLine();
        $this->line('Skills inject relevant content into every agent prompt via keyword matching.');
        $this->line('Define skills in config/phpclaw.php under skills:');
        $this->line("  Inline:  ['name'=>'...', 'description'=>'...', 'tags'=>[...], 'content'=>'...']");
        $this->line("  File:    ['file' => storage_path('phpclaw/skills/my-skill.md')]");
        $this->line("  Class:   ['class' => \\App\\Skills\\MySkill::class]");
        $this->newLine();
        $this->line('All discovered skills are always active and inject on keyword match, no per-skill toggle.');
        $this->line('Add remote HTTPS skills via PHPCLAW_REMOTE_SKILL_URLS (comma-separated).');
    }

    /**
     * Print the REST endpoints and authentication.
     *
     * @return void
     */
    private function sectionRest(): void
    {
        $this->line('REST endpoints registered by phpClaw (prefix: PHPCLAW_API_PREFIX, default: phpclaw):');
        $this->line('  POST /phpclaw/send              : send a message, receive {text,tool_calls,provider,model,iterations,tokens}');
        $this->line('  POST /phpclaw/chat/stream       : SSE stream: events tool_before / tool_after / chunk / done');
        $this->newLine();
        $this->line('Authentication: your application\'s own auth. A request must resolve to a');
        $this->line('logged-in user (session, Sanctum, Passport, whatever your app uses), or the');
        $this->line('request is refused with 401 Unauthenticated. phpClaw issues no token of its own.');
        $this->newLine();
        $this->line('Middleware applied, in order:');
        $this->line('  phpclaw.json            : forces a JSON response, before auth can redirect');
        $this->line('  phpclaw.api.middleware  : your stack, default [api, auth:sanctum]');
        $this->line('  throttle                : phpclaw.api.throttle, default 60,1');
        $this->line('  phpclaw.api             : refuses an unauthenticated caller with 401');
        $this->line('  phpclaw.owns            : refuses another user\'s conversation_id with 403');
        $this->newLine();
        $this->line('Disable all REST routes: PHPCLAW_API_ENABLED=false');
        $this->line('Change prefix:           PHPCLAW_API_PREFIX=my-prefix');
        $this->line('Rate limit (per IP):     PHPCLAW_API_THROTTLE=60,1  (maxAttempts,decayMinutes)');
        $this->line('Routes auto-register unless disabled, no manual registration needed.');
    }

    /**
     * Print the available Artisan commands.
     *
     * @return void
     */
    private function sectionCli(): void
    {
        $this->line('Available Artisan commands:');
        $this->line('  phpclaw {message}               : send a message to the agent');
        $this->line('  phpclaw:about [--test]          : show adapter info + test connection');
        $this->line('  phpclaw:guide [--section=<name>]: show this documentation');
        $this->line('  phpclaw:stats                   : show conversation/message counts');
        $this->line('  phpclaw:mcp-server              : start the MCP server over stdio');
        $this->line('  phpclaw:jobs:list               : list stored queue job results');
        $this->line('  phpclaw:jobs:status {jobId}     : show a queued job\'s status and result');
    }

    /**
     * Print the privacy notice.
     *
     * @return void
     */
    private function sectionPrivacy(): void
    {
        $this->line('PHPCLAW_STORE_MESSAGES controls what phpClaw persists. All data stays');
        $this->line('in your database, nothing leaves the app unless cloud forwarding is on.');
        $this->newLine();
        $this->line('  Conversation metadata (ID, title, timestamps)');
        $this->line('    ON: saved to '.DatabaseConversationMemory::CONVERSATIONS_TABLE);
        $this->line('    OFF, never saved');
        $this->line('  Message text (user prompts and AI replies)');
        $this->line('    ON: saved to '.DatabaseConversationMemory::MESSAGES_TABLE);
        $this->line('    OFF, never saved');
        $this->line('  Tool call inputs/outputs (data returned by tools)');
        $this->line('    ON: saved to '.DatabaseConversationMemory::MESSAGES_TABLE);
        $this->line('    OFF, never saved');
        $this->newLine();
        $this->line('When OFF, multi-turn chats still work within a single request (context');
        $this->line('held in memory), but nothing is persisted. Each new request starts fresh.');
        $this->newLine();
        $this->line('phpClaw Cloud: request data is forwarded only when a Cloud Key is set');
        $this->line('and Store Messages is ON. When OFF, nothing is sent to the cloud.');
        $this->newLine();
        $this->line('Responses are AI-generated. Verify before acting.');
    }

    /**
     * Print the .env field reference.
     *
     * @return void
     */
    private function sectionConfig(): void
    {
        $this->line('.env field reference for the Laravel adapter:');
        $this->newLine();
        foreach (ProviderCatalogue::all() as $slug => $entry) {
            if ($slug === 'custom') {
                continue;
            }
            $desc = $slug === 'ollama'
                ? 'Ollama host URL'
                : (string) ($entry['label'] ?? $slug).' API key';
            $this->line(sprintf('  %-23s, %s', $this->providerEnvKey($slug), $desc));
        }
        $this->line('  PHPCLAW_PROVIDER      : active provider slug (default: auto-detect)');
        $this->line('  PHPCLAW_MODEL         : model override (default: provider default)');
        $this->line('  PHPCLAW_BASE_URL      : custom OpenAI-compatible endpoint (provider=custom)');
        $this->line('  PHPCLAW_STORE_MESSAGES: persist chat history (default: true)');
        $this->line('  PHPCLAW_MAX_ITERATIONS: agent loop cap (default: 20)');
        $this->line('  PHPCLAW_MEMORY_DRIVER : memory driver (default: database)');
        $this->line('  PHPCLAW_SYSTEM_PROMPT : prepended system message');
        $this->line('  PHPCLAW_MAX_TOKENS    : output token cap (0 = provider default)');
        $this->line('  PHPCLAW_TOOL_DENY     : comma-separated tool names/groups to block');
        $this->line('  PHPCLAW_CLOUD_KEY     : cloud key (leave empty for local mode)');
        $this->line('  PHPCLAW_CLOUD_SIGNING_SECRET, verify signed cloud scan responses (empty = skip)');
        $this->line('  PHPCLAW_CLOUD_DISABLE : comma-separated cloud features to skip');
        $this->line('  PHPCLAW_EVENTS_BRIDGE : enable Laravel event bridge (default: true)');
        $this->line('  PHPCLAW_TELESCOPE     : enable Telescope watcher (default: true)');
        $this->line('  PHPCLAW_REMOTE_SKILL_URLS, comma-separated HTTPS skill URLs (always-on, keyword-matched)');
        $this->line('  PHPCLAW_API_THROTTLE  : REST rate limit maxAttempts,decayMinutes (default: 60,1)');
    }
}
