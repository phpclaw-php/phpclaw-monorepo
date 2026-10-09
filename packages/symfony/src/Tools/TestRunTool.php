<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tools;

use Closure;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that runs `vendor/bin/phpunit`, optionally with a `--filter`, as a fixed command; console only, never in prod.
 */
final class TestRunTool extends AbstractSymfonyTool implements MutatingToolInterface
{
    private const ALLOWED_KEYS = ['filter'];

    private const FILTER_PATTERN = '/^[A-Za-z0-9_\\\\:]{1,255}$/';

    private const PHPUNIT = 'vendor/bin/phpunit';

    private const PRODUCTION_ENVIRONMENTS = ['prod', 'production'];

    private readonly string $projectRoot;

    private readonly string $environment;

    private readonly Closure $runner;

    /**
     * Bind the project folder, the kernel environment and the runner, plus the base tool dependencies.
     *
     * @param  string  $projectRoot  Project folder holding vendor/bin/phpunit; '' uses the current folder.
     * @param  string  $environment  Kernel environment; prod refuses every run.
     * @param  ConsoleContext|null  $console  Marks an interactive console run; null is not console.
     * @param  SymfonyIdentityResolver|null  $identity  Names the acting user; null denies every caller.
     * @param  bool  $requireChatRole  True when the host app requires ROLE_PHPCLAW_CHAT.
     * @param  (Closure(list<string>, string): array{0: int, 1: string})|null  $runner  Runs the command in the folder and returns the exit code and output.
     */
    public function __construct(
        string $projectRoot = '',
        string $environment = '',
        ?ConsoleContext $console = null,
        ?SymfonyIdentityResolver $identity = null,
        bool $requireChatRole = false,
        ?Closure $runner = null,
    ) {
        parent::__construct(console: $console, identity: $identity, requireChatRole: $requireChatRole);
        $this->projectRoot = rtrim($projectRoot !== '' ? $projectRoot : (string) getcwd(), '/');
        $this->environment = $environment;
        $this->runner = $runner ?? self::runProcess(...);
    }

    /**
     * Returns the tool name registered with ToolRegistry.
     *
     * @return string
     */
    public function name(): string
    {
        return 'test_run';
    }

    /**
     * Returns the tool description surfaced to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'RUN the application test suite with vendor/bin/phpunit, optionally limited by a test filter. '
            .'Use to check that a change works. A human approves every run.';
    }

    /**
     * Returns the JSON Schema describing accepted input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Optional test filter: a class, method or Class::method. Letters, digits, _, \\ and : only.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Returns the platform capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return SymfonyIdentityResolver::CHAT_ROLE;
    }

    /**
     * Returns the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['testing'],
            tags: ['test', 'tests', 'phpunit', 'suite', 'failing', 'passing'],
            intents: ['run the tests', 'check the fix works'],
            examples: ['run the test suite'],
        );
    }

    /**
     * Refuse outside an interactive console, in prod, without the capability, with anything but a plain filter, or without phpunit.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $refusal = $this->runtimeRefusal()
            ?? $this->guardCapability('run the tests')
            ?? $this->rejectUnknownArguments($input, self::ALLOWED_KEYS)
            ?? $this->filterRefusal($input)
            ?? $this->installRefusal();

        return ['input' => $input, 'result' => $refusal];
    }

    /**
     * Run the test command and keep its exit code and the end of its output.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{exit_code: int, output: string, command: list<string>}
     *
     * @throws ToolException When the command cannot be started.
     */
    protected function perform(array $input): array
    {
        $command = [PHP_BINARY, $this->projectRoot.'/'.self::PHPUNIT];
        $filter = isset($input['filter']) && is_string($input['filter']) ? $input['filter'] : '';

        if ($filter !== '') {
            $command[] = '--filter='.$filter;
        }

        [$exitCode, $output] = ($this->runner)($command, $this->projectRoot);

        return ['exit_code' => $exitCode, 'output' => substr($output, -self::MAX_OUTPUT_BYTES), 'command' => $command];
    }

    /**
     * Assert that the run produced an exit code.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the exit code is missing.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_int($execution['exit_code'])) {
            throw new ToolException('test_run: the test command returned no exit code.');
        }

        return ['result' => null];
    }

    /**
     * Build the success envelope: whether the tests passed, the exit code and the end of the output.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success(
            ['passed' => $execution['exit_code'] === 0, 'exit_code' => $execution['exit_code'], 'output' => $execution['output']],
            ['mode' => 'command', 'command' => self::PHPUNIT.(count($execution['command']) > 2 ? ' '.$execution['command'][2] : '')],
        );
    }

    /**
     * The reason this process may not run tests at all, or null: not an interactive console, or prod.
     *
     * @return string|null Error envelope, or null when the run may go ahead.
     */
    private function runtimeRefusal(): ?string
    {
        if (! $this->runningInConsole()) {
            return $this->error('CONSOLE_ONLY', 'test_run runs only from an interactive console session.');
        }

        if (in_array($this->environment, self::PRODUCTION_ENVIRONMENTS, true)) {
            return $this->error('PRODUCTION', 'test_run is refused when the kernel environment is prod.');
        }

        return null;
    }

    /**
     * The reason the filter is refused, or null when it is absent or holds only letters, digits, _, \ and :.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return string|null Error envelope, or null when the filter is acceptable.
     */
    private function filterRefusal(array $input): ?string
    {
        if (array_key_exists('filter', $input) && (! is_string($input['filter']) || preg_match(self::FILTER_PATTERN, $input['filter']) !== 1)) {
            return $this->error('INVALID_ARGUMENT', '"filter" may hold only letters, digits, _, \\ and :.');
        }

        return null;
    }

    /**
     * The reason the run cannot start because vendor/bin/phpunit is missing, or null.
     *
     * @return string|null Error envelope, or null when phpunit is installed.
     */
    private function installRefusal(): ?string
    {
        if (! is_file($this->projectRoot.'/'.self::PHPUNIT)) {
            return $this->error('NOT_INSTALLED', self::PHPUNIT.' was not found in the project; install phpunit to run the tests.');
        }

        return null;
    }

    /**
     * Start the command with no shell, collect its output and error output together, and wait for it.
     *
     * @param  list<string>  $command  Program and arguments.
     * @param  string  $cwd  Folder to run in.
     * @return array{0: int, 1: string} Exit code and combined output.
     *
     * @throws ToolException When the process cannot start.
     */
    private static function runProcess(array $command, string $cwd): array
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            throw new ToolException('test_run: the test command could not start.');
        }

        $open = [$pipes[1], $pipes[2]];
        $output = '';

        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while ($open !== []) {
            $read = $open;
            $write = $except = null;

            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $pipe) {
                $output .= (string) fread($pipe, 8192);
            }

            $open = array_values(array_filter($open, static fn ($pipe): bool => ! feof($pipe)));
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
