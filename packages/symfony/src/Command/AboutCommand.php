<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Claw as PhpClawEngine;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Providers\ProviderCatalogue;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\Command\Concerns\RendersBanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the live adapter configuration card; --test probes provider connectivity.
 */
#[AsCommand(
    name: 'phpclaw:about',
    description: 'Display phpClaw adapter info (provider, tools, guards, memory, skills, cloud state).',
)]
final class AboutCommand extends Command
{
    use RendersBanner;

    private const EXPECTED_DRIVER_CLASS = [
        'doctrine' => 'DoctrineRouterMemory',
        'doctrine_kv' => 'DoctrineMemory',
        'doctrine_conversation' => 'DoctrineConversationMemory',
        'cache' => 'CacheMemory',
        'file' => 'FileMemory',
        'array' => 'ArrayMemory',
    ];

    /**
     * Constructs the command with the resolved adapter state shown in the info panel.
     *
     * @param  PhpClawInterface  $phpClaw  Agent engine.
     * @param  string  $provider  Configured provider slug.
     * @param  string  $model  Configured model.
     * @param  bool  $storeMsg  Whether message content is stored.
     * @param  int  $maxIter  Max agent iterations.
     * @param  string  $memDriver  Configured memory driver name.
     * @param  string  $cloudKey  Cloud key (masked in output).
     * @param  MemoryInterface|null  $memory  The driver actually resolved at boot; null when none was wired.
     */
    public function __construct(
        private readonly PhpClawInterface $phpClaw,
        private readonly string $provider,
        private readonly string $model,
        private readonly bool $storeMsg,
        private readonly int $maxIter,
        private readonly string $memDriver,
        private readonly string $cloudKey,
        private readonly ?MemoryInterface $memory = null,
    ) {
        parent::__construct();
    }

    /**
     * Declares the --test option.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption(
            name: 'test',
            shortcut: null,
            mode: InputOption::VALUE_NONE,
            description: 'Send a test prompt to verify provider connectivity.',
        );
    }

    /**
     * Prints the adapter configuration card, optionally running a connectivity test.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->banner($io);

        $toolNames = [];
        if ($this->phpClaw instanceof PhpClawEngine) {
            foreach ($this->phpClaw->config()->tools as $tool) {
                $toolNames[] = $tool->name();
            }
        }
        $toolCount = count($toolNames);
        $toolList = $toolCount > 0 ? implode(', ', $toolNames) : 'none';
        $guardCount = GuardRegistry::count();
        $skillCount = SkillRegistry::count();
        $skillList = $skillCount > 0 ? implode(', ', SkillRegistry::names()) : 'none';
        $cloudLine = $this->formatCloudKey($this->cloudKey);
        $modelLine = $this->model !== '' ? " ({$this->model})" : '';

        $providerList = array_map(
            static fn (string $slug, array $entry): string => $slug,
            array_keys(ProviderCatalogue::all()),
            array_values(ProviderCatalogue::all()),
        );

        $io->writeln('phpClaw Symfony adapter');
        $io->writeln(str_repeat('━', 50));
        $io->writeln("Provider:       {$this->provider}{$modelLine}");
        $io->writeln("Tools:          {$toolCount} active ({$toolList})");
        $io->writeln("Guards:         {$guardCount} active");
        $io->writeln('Memory:         '.$this->formatMemory());
        $io->writeln("Skills:         {$skillCount} active ({$skillList})");
        $io->writeln("Cloud:          {$cloudLine}");
        $io->writeln('store_messages: '.($this->storeMsg ? 'on' : 'off'));
        $io->writeln("max_iterations: {$this->maxIter}");
        $io->writeln(str_repeat('━', 50));
        $io->writeln('Providers available: '.implode(', ', $providerList));
        $io->writeln("Run 'bin/console phpclaw:guide' for full documentation.");

        if ($input->getOption('test')) {
            $io->newLine();
            $io->writeln('Test Connection...');

            try {
                $response = $this->phpClaw->send('Reply with exactly: OK');
                $text = trim($response->text);

                if (stripos($text, 'OK') !== false) {
                    $io->success('Provider reachable. Response: '.$text);
                } else {
                    $io->warning('Provider responded but reply was unexpected: '.$text);
                }
            } catch (\Throwable) {
                $io->error('Provider unreachable: check the provider and API key.');

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Describe the memory driver, naming the effective one whenever it is not the configured one.
     *
     * @return string
     */
    private function formatMemory(): string
    {
        if ($this->memory === null) {
            return $this->memDriver.' requested, none wired';
        }

        $effective = (new \ReflectionClass($this->memory))->getShortName();

        if (self::EXPECTED_DRIVER_CLASS[$this->memDriver] === $effective) {
            return $this->memDriver.' driver';
        }

        return $this->memDriver.' requested, '.$effective.' in use';
    }

    /**
     * Returns a masked representation of the cloud key for display.
     *
     * @param  string  $key
     * @return string
     */
    private function formatCloudKey(string $key): string
    {
        if ($key === '') {
            return 'not connected';
        }

        if (mb_strlen($key) <= 8) {
            return 'connected (key: ****)';
        }

        return 'connected (key: ****'.mb_substr($key, -4).')';
    }
}
