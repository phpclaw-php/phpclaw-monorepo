<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tools;

use Closure;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Tool that runs `php artisan test`, optionally with a `--filter`, as a fixed command; console only, never in production.
 */
final class TestRunTool extends AbstractLaravelTool implements MutatingToolInterface
{
    private const ALLOWED_KEYS = ['filter'];

    private const FILTER_PATTERN = '/^[A-Za-z0-9_\\\\:]{1,255}$/';

    private const TIMEOUT_SECONDS = 600;

    private readonly Closure $runner;

    /**
     * Use the given runner, or one that starts the command as a process with no shell.
     *
     * @param  (Closure(list<string>, string): array{0: int, 1: string})|null  $runner  Runs the command in the folder and returns the exit code and output.
     */
    public function __construct(?Closure $runner = null)
    {
        $this->runner = $runner ?? self::runProcess(...);
    }

    /**
     * Return the tool identifier used by the agent to invoke this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'test_run';
    }

    /**
     * Return the human-readable description shown to the LLM for tool selection.
     *
     * @return string
     */
    public function description(): string
    {
        return 'RUN the application test suite with php artisan test, optionally limited by a test filter. Use to check that a change works. A human approves every run.';
    }

    /**
     * Return the JSON Schema describing the tool's accepted input parameters.
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
        ];
    }

    /**
     * Return the platform capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::CHAT_ABILITY;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['testing'],
            tags: ['test', 'tests', 'phpunit', 'pest', 'suite', 'failing', 'passing'],
            intents: ['run the tests', 'check the fix works'],
            examples: ['run the test suite'],
        );
    }

    /**
     * Refuse outside an interactive console, in production, without the capability, or with anything but a plain filter.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $refusal = $this->runtimeRefusal()
            ?? $this->guardCapability('run the tests')
            ?? $this->rejectUnknownArguments($input, self::ALLOWED_KEYS)
            ?? $this->filterRefusal($input);

        return ['input' => $input, 'result' => $refusal];
    }

    /**
     * Run the test command and keep its exit code and the end of its output.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array{exit_code: int, output: string, command: list<string>}
     *
     * @throws ToolException When the command cannot be started or runs past the time limit.
     */
    protected function perform(array $input): array
    {
        $command = [PHP_BINARY, base_path('artisan'), 'test'];
        $filter = $this->stringInput($input, 'filter');

        if ($filter !== '') {
            $command[] = '--filter='.$filter;
        }

        [$exitCode, $output] = ($this->runner)($command, base_path());

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
            ['mode' => 'command', 'command' => 'php artisan test'.(count($execution['command']) > 3 ? ' '.$execution['command'][3] : '')],
        );
    }

    /**
     * The reason this process may not run tests at all, or null: not an interactive console, or production.
     *
     * @return string|null Error envelope, or null when the run may go ahead.
     */
    private function runtimeRefusal(): ?string
    {
        if (! $this->runningInConsole()) {
            return $this->error('CONSOLE_ONLY', 'test_run runs only from an interactive console session.');
        }

        if (app()->environment('production', 'prod')) {
            return $this->error('PRODUCTION', 'test_run is refused when APP_ENV is production.');
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
     * Start the command as a process with no shell and wait for it.
     *
     * @param  list<string>  $command  Program and arguments.
     * @param  string  $cwd  Folder to run in.
     * @return array{0: int, 1: string} Exit code and combined output.
     *
     * @throws ToolException When the process cannot start or runs past the time limit.
     */
    private static function runProcess(array $command, string $cwd): array
    {
        $process = new Process($command, $cwd, null, null, self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessRuntimeException $e) {
            throw new ToolException('test_run: the test command could not finish.', previous: $e);
        }

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }
}
