<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Skills\SkillCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\Command\Concerns\RendersBanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints adapter documentation, optionally scoped to one named section.
 */
#[AsCommand(
    name: 'phpclaw:guide',
    description: 'Display phpClaw adapter documentation.',
)]
final class GuideCommand extends Command
{
    use RendersBanner;

    private const SECTIONS = [
        'quickstart', 'tools', 'providers', 'memory', 'guards',
        'hooks', 'skills', 'rest', 'cli', 'privacy', 'config',
    ];

    /**
     * Constructs the command with the configured remote skill URLs and registered hooks.
     *
     * @param  string  $remoteSkillUrls  Comma-separated HTTPS skill URLs from phpclaw.remote_skill_urls.
     * @param  array<int, array{event: string, handler: mixed, priority?: int}>  $hooks  Configured hooks from phpclaw.hooks.
     */
    public function __construct(private readonly string $remoteSkillUrls = '', private readonly array $hooks = [])
    {
        parent::__construct();
    }

    /**
     * Declares the optional --section filter and the opt-in --live flag.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption(
            name: 'section',
            shortcut: null,
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Print only a specific section ('.implode('|', self::SECTIONS).').',
        );

        $this->addOption(
            name: 'live',
            shortcut: null,
            mode: InputOption::VALUE_NONE,
            description: 'Fetch configured remote skill URLs and list what actually registers (network I/O; off by default).',
        );
    }

    /**
     * Prints all documentation sections or a single one when --section is given.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->banner($io);

        $section = $input->getOption('section');

        if ($section !== null && ! in_array($section, self::SECTIONS, strict: true)) {
            $io->error("Unknown section '{$section}'. Available: ".implode(', ', self::SECTIONS));

            return Command::FAILURE;
        }

        if ($section !== null) {
            $this->printSection($io, (string) $section);
        } else {
            $io->writeln('phpClaw Symfony adapter guide');

            foreach (self::SECTIONS as $s) {
                $this->printSection($io, $s);
                $io->newLine();
            }
        }

        if ((bool) $input->getOption('live')) {
            $this->printRemoteSkills($io);
        }

        return Command::SUCCESS;
    }

    /**
     * Fetch every configured remote skill URL and print what registers; runs only under --live because it does network I/O.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function printRemoteSkills(SymfonyStyle $io): void
    {

        $urls = array_values(array_filter(array_map('trim', explode(',', $this->remoteSkillUrls))));

        $io->writeln(str_repeat('━', 50));
        $io->writeln('  REMOTE SKILLS (live)');
        $io->writeln(str_repeat('━', 50));

        if ($urls === []) {
            $io->writeln('No remote skill URLs configured (PHPCLAW_REMOTE_SKILL_URLS is empty).');

            return;
        }

        SkillCatalogue::activateDefaults();
        $before = array_map(fn ($s) => $s->name(), SkillRegistry::all());

        foreach ($urls as $url) {
            try {
                RemoteSkillLoader::load($url);
            } catch (\Throwable $e) {
                $io->writeln("  FAILED  {$url}: ".$e->getMessage());
            }
        }

        $loaded = array_values(array_diff(
            array_map(fn ($s) => $s->name(), SkillRegistry::all()),
            $before,
        ));

        if ($loaded === []) {
            $io->writeln('None of the configured URLs registered a skill.');

            return;
        }

        $io->writeln(count($loaded).' skill(s) registered from '.count($urls).' configured URL(s):');
        foreach ($loaded as $name) {
            $io->writeln("  - {$name}");
        }
    }

    /**
     * Print a single documentation section by name.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @param  string  $section  The section slug.
     * @return void
     */
    private function printSection(SymfonyStyle $io, string $section): void
    {
        $io->writeln(str_repeat('━', 50));
        $io->writeln('  '.strtoupper($section));
        $io->writeln(str_repeat('━', 50));

        match ($section) {
            'quickstart' => $this->sectionQuickstart($io),
            'tools' => $this->sectionTools($io),
            'providers' => $this->sectionProviders($io),
            'memory' => $this->sectionMemory($io),
            'guards' => $this->sectionGuards($io),
            'hooks' => $this->sectionHooks($io),
            'skills' => $this->sectionSkills($io),
            'rest' => $this->sectionRest($io),
            'cli' => $this->sectionCli($io),
            'privacy' => $this->sectionPrivacy($io),
            'config' => $this->sectionConfig($io),
            default => null,
        };
    }

    /**
     * Print the quickstart steps.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionQuickstart(SymfonyStyle $io): void
    {
        $io->writeln('1. Install:   composer require phpclaw/phpclaw-symfony');
        $io->writeln('2. Set ANTHROPIC_API_KEY (or OPENAI_API_KEY, GROQ_API_KEY, etc.) in .env');
        $io->writeln('3. Run:       bin/console phpclaw "Tell me something useful"');
        $io->writeln('4. See status: bin/console phpclaw:about');
    }

    /**
     * Print available tools and how to enable them.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionTools(SymfonyStyle $io): void
    {
        $io->writeln('Registered automatically, no configuration needed:');
        $io->writeln('  shell_exec     : allowlist-guarded shell commands');
        $io->writeln('  http_request   : fetch external URLs (SSRF-protected)');
        $io->writeln('  file_read      : sandboxed file reads inside workspace_root');
        $io->writeln('  file_write     : sandboxed file writes inside workspace_root');
        $io->writeln('  file_edit      : sandboxed in-place edits inside workspace_root');
        $io->writeln('  code_search    : search the project tree for a pattern');
        $io->writeln('  project_info   : report framework, versions and project layout');
        $io->newLine();
        $io->writeln('Symfony-native, opt-in. Add the FQCN under tools: in config/packages/phpclaw.yaml:');
        $io->writeln('  db_query       : read-only SELECT queries via DatabaseTool');
        $io->writeln('  read_log       : tail var/log/*.log via LogTool');
        $io->newLine();
        $io->writeln('Deny tools with the tool_deny key in config/packages/phpclaw.yaml.');
        $io->writeln('Symfony cannot bind an env var to a list, so this one has no PHPCLAW_* equivalent:');
        $io->writeln("  tool_deny: ['read_log', 'shell_exec']");
    }

    /**
     * Print supported providers and their env keys (dynamic from ProviderCatalogue).
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionProviders(SymfonyStyle $io): void
    {
        $io->writeln('Set PHPCLAW_PROVIDER in .env. Supported values:');

        foreach (ProviderCatalogue::all() as $slug => $entry) {
            $label = (string) ($entry['label'] ?? $slug);
            $io->writeln(sprintf('  %-11s : %s (%s)', $slug, $label, $this->providerEnvKey($slug)));
        }

        $io->newLine();
        $io->writeln('For custom: set PHPCLAW_BASE_URL to the full http(s) endpoint.');
        $io->writeln('Override model: PHPCLAW_MODEL=<model-id>');
    }

    /**
     * Print the available memory drivers.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionMemory(SymfonyStyle $io): void
    {
        $io->writeln('Set PHPCLAW_MEMORY_DRIVER in .env. Available drivers:');
        $io->writeln('  doctrine              : default; Doctrine DBAL (namespace-routed)');
        $io->writeln('  doctrine_kv           : key-value store only');
        $io->writeln('  doctrine_conversation : conversation store only');
        $io->writeln('  cache                 : Symfony Cache component (any pool)');
        $io->writeln('  file                  : JSON files in var/phpclaw/ (no DB)');
        $io->writeln('  array                 : in-process only; resets on each request');
        $io->newLine();
        $io->writeln('Run Doctrine migrations for doctrine drivers:');
        $io->writeln('  bin/console doctrine:migrations:migrate');
    }

    /**
     * Print guard configuration guidance.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionGuards(SymfonyStyle $io): void
    {
        $io->writeln('Guards inspect every agent iteration. The defaults cover prompt injection,');
        $io->writeln('destructive SQL, PII, rate limits, message length, homoglyphs and role switching.');
        $io->writeln('Register custom guards in config/packages/phpclaw.yaml under guards:');
        $io->writeln('  - { class: App\\Guard\\MyGuard, priority: 5 }');
        $io->newLine();
        $io->writeln('Lower priority numbers run first.');
        $io->writeln('See bin/console phpclaw:about for active guard count.');
    }

    /**
     * Print registered hooks and how to add more.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionHooks(SymfonyStyle $io): void
    {
        $count = count($this->hooks);

        if ($count === 0) {
            $io->writeln('No hooks registered.');
        } else {
            $io->writeln("Registered listeners ({$count}):");
            foreach ($this->hooks as $hook) {
                if (isset($hook['event'])) {
                    $io->writeln("  {$hook['event']}");
                }
            }
        }

        $io->newLine();
        $io->writeln('Register hooks in config/packages/phpclaw.yaml under hooks:');
        $io->writeln('  - { event: agent.after, handler: [App\\Listener\\MyListener, handle], priority: 10 }');
        $io->newLine();
        $io->writeln('Events fire through phpClaw HookRegistry, then bridge to Symfony EventDispatcher.');
        $io->writeln('Event names follow the phpclaw.* namespace in Symfony (phpclaw.agent.after, etc.).');
    }

    /**
     * Print skill configuration guidance.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionSkills(SymfonyStyle $io): void
    {
        $io->writeln('Skills inject relevant content into every agent prompt via keyword matching.');
        $io->writeln('Define skills in config/packages/phpclaw.yaml under skills:');
        $io->writeln("  Inline:  { name: '...', description: '...', tags: [...], content: '...' }");
        $io->writeln("  File:    { file: '%kernel.project_dir%/skills/my-skill.md' }");
        $io->writeln('  Class:   { class: App\\Skill\\MySkill }');
        $io->newLine();
        $io->writeln('All discovered skills are always active and inject on keyword match, with no per-skill toggle.');
        $io->writeln('Add remote HTTPS skills via remote_skill_urls in phpclaw.yaml (PHPCLAW_REMOTE_SKILL_URLS).');
    }

    /**
     * Print the REST endpoints and authentication.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionRest(SymfonyStyle $io): void
    {
        $io->writeln('Enable the routes: Symfony does not auto-load bundle routes, so import them once:');
        $io->writeln('  # config/routes/phpclaw.yaml');
        $io->writeln('  phpclaw:');
        $io->writeln("      resource: '@PhpClawBundle/config/routes.yaml'");
        $io->newLine();
        $io->writeln('REST endpoints registered by phpClaw:');
        $io->writeln('  POST /phpclaw/send                : send a message, receive');
        $io->writeln('                                      {text,tool_calls,provider,model,iterations,tokens,conversation_id}');
        $io->writeln('  POST /phpclaw/chat/stream         : SSE stream, events');
        $io->writeln('                                      tool_before / tool_after / chunk / done / error');
        $io->newLine();
        $io->writeln('Authentication: your application\'s own security firewall. A request must resolve');
        $io->writeln('to a logged-in user, or it is refused with 401 Unauthenticated. phpClaw issues no');
        $io->writeln('token of its own.');
        $io->newLine();
        $io->writeln('TokenAuthListener guards both routes, in order:');
        $io->writeln('  api.enabled false      : 403 on every REST route');
        $io->writeln('  rate limit             : 429 past 60 requests per 60s window (fixed, per client IP)');
        $io->writeln('  unauthenticated caller : 401');
        $io->writeln('  another user\'s conversation_id : 403');
        $io->newLine();
        $io->writeln('Disable all REST routes: PHPCLAW_API_ENABLED=false');
        $io->writeln('Change the path prefix:  set prefix on the route import, phpClaw has no prefix setting:');
        $io->writeln('  phpclaw:');
        $io->writeln("      resource: '@PhpClawBundle/config/routes.yaml'");
        $io->writeln('      prefix:   /my-prefix');
    }

    /**
     * Print the available console commands.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionCli(SymfonyStyle $io): void
    {
        $io->writeln('Available console commands:');
        $io->writeln('  phpclaw {message}                 : send a message to the agent');
        $io->writeln('  phpclaw:about [--test]            : show adapter info + test connection');
        $io->writeln('  phpclaw:guide [--section=<name>]  : show this documentation');
        $io->writeln('  phpclaw:stats                     : show conversation/message counts');
        $io->writeln('  phpclaw:mcp-server [--transport]  : start the MCP server, stdio (default) or http');
        $io->writeln('  phpclaw:jobs:list                 : list stored queue job results');
        $io->writeln('  phpclaw:jobs:status {jobId}       : show a queued job\'s status and result');
    }

    /**
     * Print the privacy notice.
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionPrivacy(SymfonyStyle $io): void
    {
        $io->writeln('Responses are AI-generated. Check anything that matters before acting on it.');
        $io->newLine();
        $io->writeln('What is stored: with store_messages true (the default) every prompt and reply is');
        $io->writeln('written to phpclaw_messages, so chat history survives a request. Set it false to');
        $io->writeln('keep only conversation rows. Stored conversations may contain personal data.');
        $io->newLine();
        $io->writeln('What leaves the server: prompts go to the provider you configured, and nowhere');
        $io->writeln('else. Cloud forwarding is off until you set cloud_key, and even then it sends');
        $io->writeln('nothing while store_messages is false.');
    }

    /**
     * Print the .env field reference (dynamic from ProviderCatalogue).
     *
     * @param  SymfonyStyle  $io  Output helper.
     * @return void
     */
    private function sectionConfig(SymfonyStyle $io): void
    {
        $io->writeln('.env field reference for the Symfony adapter:');
        $io->newLine();
        $io->writeln('Provider keys are read straight from the environment. Every PHPCLAW_* field below');
        $io->writeln('reaches the bundle through config/packages/phpclaw.yaml, so copy the shipped file');
        $io->writeln('first: cp vendor/phpclaw/phpclaw-symfony/config/phpclaw.yaml config/packages/');
        $io->newLine();

        foreach (ProviderCatalogue::all() as $slug => $entry) {
            if ($slug === 'custom') {
                continue;
            }
            $desc = $slug === 'ollama'
                ? 'Ollama host URL'
                : (string) ($entry['label'] ?? $slug).' API key';
            $io->writeln(sprintf('  %-23s : %s', $this->providerEnvKey($slug), $desc));
        }

        $io->writeln('  PHPCLAW_PROVIDER        : active provider slug (default: auto-detect)');
        $io->writeln('  PHPCLAW_MODEL           : model override (default: provider default)');
        $io->writeln('  PHPCLAW_BASE_URL        : custom OpenAI-compatible endpoint (provider=custom)');
        $io->writeln('  PHPCLAW_STORE_MESSAGES  : persist chat history (default: true)');
        $io->writeln('  PHPCLAW_MAX_ITERATIONS  : agent loop cap (default: 20)');
        $io->writeln('  PHPCLAW_MEMORY_DRIVER   : memory driver (default: doctrine)');
        $io->writeln('  PHPCLAW_SYSTEM_PROMPT   : prepended system message');
        $io->writeln('  PHPCLAW_MAX_TOKENS      : output token cap (0 = provider default)');
        $io->writeln('  PHPCLAW_CLOUD_KEY       : cloud key (leave empty for local mode)');
        $io->writeln('  PHPCLAW_CLOUD_SIGNING_SECRET : verify signed cloud scan responses (empty = skip)');
        $io->writeln('  PHPCLAW_API_ENABLED     : false disables every REST route');
        $io->writeln('  PHPCLAW_REMOTE_SKILL_URLS : comma-separated HTTPS skill URLs (always-on, keyword-matched)');
        $io->newLine();
        $io->writeln('tool_deny has no env var: Symfony cannot feed one into a list. Set it in phpclaw.yaml.');
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
}
