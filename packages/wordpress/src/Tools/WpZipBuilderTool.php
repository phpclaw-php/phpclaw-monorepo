<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\Tools\ZipPackagerTool;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;

/**
 * Validates a generated WordPress plugin folder, then packages it as an installable ZIP; an invalid plugin (missing header, missing ABSPATH guard, forbidden calls, or a syntax error) fails with the exact file and problem so the agent can repair it.
 */
#[Tool(
    name: 'wp_zip_plugin',
    description: 'Validate a generated WordPress plugin folder (header, ABSPATH guard, PHP syntax) and package it as an installable ZIP.',
    since: '0.1.0',
    default: false,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class WpZipBuilderTool implements MutatingToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const FORBIDDEN_CALLS = ['eval(', 'system(', 'exec(', 'passthru(', 'shell_exec('];

    private const REQUIRED_CAPABILITY = 'phpclaw_use_chat';

    private const PHPCLAW_CAPABILITY = 'wordpress.package.build';

    private const RISK_LEVEL = 'write';

    private const ALLOWED_KEYS = ['plugin_dir'];

    public const EXAMPLES = [
        [
            'prompt' => 'validate and package the plugin I just generated',
            'arguments' => ['plugin_dir' => 'my-generated-plugin'],
        ],
    ];

    private readonly string $workspaceRoot;

    /**
     * Bind the workspace root this tool packages plugins from.
     *
     * @param  string|null  $workspaceRoot  Absolute path to the workspace root. Defaults to the WP uploads workspace.
     * @return void
     */
    public function __construct(?string $workspaceRoot = null)
    {
        $this->workspaceRoot = $workspaceRoot
            ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR.'/uploads/phpclaw' : (string) getcwd());
    }

    /**
     * The tool's unique identifier used by the engine and model.
     *
     * @return string
     */
    public function name(): string
    {
        return 'wp_zip_plugin';
    }

    /**
     * Return worked example prompts for this tool.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * Human-readable description surfaced to the model.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Package a plugin folder from the workspace into an installable WordPress ZIP. '
             .'Validates plugin header, ABSPATH guards, and PHP syntax first. Fails with the exact file/problem if invalid.';
    }

    /**
     * JSON-schema describing the tool's input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'plugin_dir' => [
                    'type' => 'string',
                    'description' => 'Workspace-relative plugin folder (the slug).',
                    'minLength' => 1,
                    'maxLength' => 191,
                ],
            ],
            'required' => ['plugin_dir'],
        ];
    }

    /**
     * Return the phpClaw capability identifier this tool exercises.
     *
     * @return string
     */
    public function capability(): string
    {
        return self::PHPCLAW_CAPABILITY;
    }

    /**
     * Return the WordPress capability required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the risk classification for this tool.
     *
     * @return string
     */
    public function risk(): string
    {
        return self::RISK_LEVEL;
    }

    /**
     * Report whether repeated identical calls produce the same result.
     *
     * @return bool
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /**
     * Plan the execution by enforcing authorization, validating input, and determining the operation that can safely run.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('build a plugin package');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $slug = trim((string) ($input['plugin_dir'] ?? ''), '/');

        if ($slug === '' || preg_match('/[^a-z0-9\-_]/', $slug) === 1) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'INVALID_PLUGIN_DIR',
                    '"plugin_dir" must be a simple slug using a-z, 0-9, hyphen or underscore.',
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Validate the plugin folder and package it into an installable ZIP.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        $slug = trim((string) $input['plugin_dir'], '/');
        $dir = realpath($this->workspaceRoot.'/'.$slug);
        $workspace = realpath($this->workspaceRoot) ?: $this->workspaceRoot;

        if ($dir === false || ! is_dir($dir) || ! str_starts_with($dir.'/', rtrim($workspace, '/').'/')) {
            return ['type' => 'missing', 'payload' => ['slug' => $slug]];
        }

        $slug = basename($dir);
        $headerProblem = $this->mainFileProblem($dir, $slug);

        if ($headerProblem !== null) {
            return ['type' => 'invalid', 'payload' => ['slug' => $slug, 'problems' => [$headerProblem]]];
        }

        $problems = $this->collectProblems($dir);

        if ($problems !== []) {
            return ['type' => 'invalid', 'payload' => ['slug' => $slug, 'problems' => $problems]];
        }

        $packaged = (array) json_decode(
            (new ZipPackagerTool($this->workspaceRoot))->execute([
                'source_dir' => $slug,
                'output_name' => $slug,
            ]),
            true,
        );

        return [
            'type' => 'built',
            'payload' => [
                'slug' => $slug,
                'package' => $packaged['data'] ?? null,
            ],
        ];
    }

    /**
     * Verify the raw execution result before it becomes the final model result.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When an infrastructure result is incomplete.
     */
    protected function verify(array $execution, array $input): array
    {
        if ($execution['type'] === 'missing') {
            return [
                'result' => $this->error(
                    'PLUGIN_DIR_NOT_FOUND',
                    sprintf('No plugin folder named "%s" exists in the workspace.', $execution['payload']['slug']),
                ),
            ];
        }

        if ($execution['type'] === 'invalid') {
            return [
                'result' => $this->error(
                    'PLUGIN_VALIDATION_FAILED',
                    'The plugin folder failed validation. Fix the listed problems with file_edit, then retry.',
                    [
                        'plugin_dir' => $execution['payload']['slug'],
                        'problems' => $execution['payload']['problems'],
                    ],
                ),
            ];
        }

        if (! is_array($execution['payload']['package'] ?? null)) {
            throw new ToolException('WpZipBuilderTool returned an incomplete package result.');
        }

        return ['result' => null];
    }

    /**
     * Complete a verified execution into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success(
            [
                'plugin_dir' => $execution['payload']['slug'],
                'package' => $execution['payload']['package'],
            ],
            [
                'mode' => 'build',
                'install' => 'Install via wp-admin -> Plugins -> Add New -> Upload Plugin.',
            ],
        );
    }

    /**
     * Report the first problem with the plugin's main file, if any.
     *
     * @param  string  $dir  Absolute path to the plugin folder.
     * @param  string  $slug  Plugin folder slug.
     * @return string|null The problem description, or null when the main file is valid.
     */
    private function mainFileProblem(string $dir, string $slug): ?string
    {
        $main = $dir.'/'.$slug.'.php';

        if (! is_file($main)) {
            return "main file missing: {$slug}/{$slug}.php";
        }

        $head = (string) file_get_contents($main, false, null, 0, 8192);

        if (! str_contains($head, 'Plugin Name:')) {
            return "{$slug}.php has no 'Plugin Name:' header. Not a valid plugin.";
        }

        return null;
    }

    /**
     * Walk every PHP file in the plugin and collect validation problems.
     *
     * @param  string  $dir  Absolute plugin directory.
     * @return string[] Human-readable problems, empty when the plugin is valid.
     */
    private function collectProblems(string $dir): array
    {
        $problems = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iter as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $rel = substr($file->getPathname(), strlen($dir) + 1);
            $code = (string) file_get_contents($file->getPathname());

            if (! preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $code)) {
                $problems[] = "{$rel}: missing ABSPATH guard";
            }

            foreach (self::FORBIDDEN_CALLS as $bad) {
                if (str_contains($code, $bad)) {
                    $problems[] = "{$rel}: forbidden call {$bad})";
                }
            }

            $syntaxError = $this->lintSyntax($file->getPathname());
            if ($syntaxError !== null) {
                $problems[] = "{$rel}: syntax error: {$syntaxError}";
            }
        }

        return $problems;
    }

    /**
     * Run `php -l` against a file, returning the first error line or null when valid.
     *
     * @param  string  $path  Absolute file path to lint.
     * @return string|null First error line, or null when the file parses cleanly.
     */
    private function lintSyntax(string $path): ?string
    {
        $process = proc_open(
            ['php', '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return null;
        }

        $out = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return trim(strtok($out, "\n") ?: 'parse failure');
        }

        return null;
    }

    /**
     * Whether this tool may be offered to the model. WordPress evaluates the caller's capability when the tool runs, so every tool stays eligible for routing.
     *
     * @return bool Always true; execution-time checks remain the authority.
     */
    public function isEligibleForRouting(): bool
    {
        return true;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['packaging', 'plugins'],
            tags: ['zip', 'archive', 'package', 'bundle', 'build', 'installer', 'plugin'],
            intents: ['build a plugin zip', 'package this plugin'],
            examples: ['package this plugin as an installable zip'],
        );
    }
}
