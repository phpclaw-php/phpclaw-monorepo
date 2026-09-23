<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Joomla\Component\Administrator\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Joomla\Component\Administrator\Validation\PluginWiringValidator;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;
use PhpClaw\Tools\ZipPackagerTool;

/**
 * Validates a generated Joomla extension folder, then packages it as an installable ZIP.
 */
#[Tool(
    name: 'joomla_zip_extension',
    description: 'Validate a generated Joomla extension folder (manifest, _JEXEC guard, PHP syntax) and package it as an installable ZIP.',
    since: '0.1.0',
    default: false,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class JoomlaZipBuilderTool implements MutatingToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    public const EXAMPLES = [
        [
            'prompt' => 'validate and package the extension I just generated',
            'arguments' => ['extension_dir' => 'demo'],
        ],
    ];

    private const FORBIDDEN_CALLS = ['eval(', 'system(', 'exec(', 'passthru(', 'shell_exec('];

    private const MANIFEST_HEAD_BYTES = 8192;

    private const TOOL_NAME = 'joomla_zip_extension';

    private const REQUIRED_ACTION = 'phpclaw.chat.use';

    private const REQUIRED_ASSET = 'com_phpclaw';

    private const RISK_LEVEL = 'write';

    private const ALLOWED_KEYS = ['extension_dir'];

    private readonly string $workspaceRoot;

    /**
     * Bind the workspace root the tool packages extensions within.
     *
     * @param  string|null  $workspaceRoot  Absolute path to the workspace root. Defaults to the current working directory.
     * @return void
     */
    public function __construct(?string $workspaceRoot = null)
    {
        $this->workspaceRoot = $workspaceRoot ?? (string) getcwd();
    }

    /**
     * Worked examples for this tool, surfaced through schema discovery.
     *
     * @return array<int, array{prompt: string, arguments: array<string, mixed>}>
     */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * The tool's unique identifier used by the engine and model.
     *
     * @return string
     */
    public function name(): string
    {
        return self::TOOL_NAME;
    }

    /**
     * Human-readable description surfaced to the model.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Package an extension folder from the workspace into an installable Joomla ZIP. '
             .'Validates the manifest (plugin/component/module), _JEXEC guards, and PHP syntax first. Fails with the exact file/problem if invalid.';
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
            'properties' => [
                'extension_dir' => ['type' => 'string', 'description' => 'Workspace-relative extension folder (the slug).'],
            ],
            'required' => ['extension_dir'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Joomla action the caller must hold to use this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_ACTION;
    }

    /**
     * Joomla asset the required action is checked against.
     *
     * @return string
     */
    protected function requiredAsset(): string
    {
        return self::REQUIRED_ASSET;
    }

    /**
     * Return the risk classification for this tool. It writes a ZIP archive to disk, so it
     * is not a read tool and repeated calls overwrite the previous archive.
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
     * Enforce authorization and validate the slug before anything touches disk.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('package a Joomla extension');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        $slug = trim((string) ($input['extension_dir'] ?? ''), '/');

        if ($slug === '' || preg_match('/[^a-z0-9\-_]/', $slug) === 1) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'INVALID_EXTENSION_DIR',
                    '"extension_dir" must be a simple slug using a-z, 0-9, hyphen or underscore.',
                ),
            ];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Validate the extension folder and package it into an installable ZIP.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        $slug = trim((string) $input['extension_dir'], '/');
        $dir = realpath($this->workspaceRoot.'/'.$slug);
        $workspace = realpath($this->workspaceRoot) ?: $this->workspaceRoot;

        if ($dir === false || ! is_dir($dir) || ! str_starts_with($dir.'/', rtrim($workspace, '/').'/')) {
            return ['type' => 'missing', 'payload' => ['slug' => $slug]];
        }

        $slug = basename($dir);
        $type = $this->manifestType($dir);

        if ($type === null) {
            return [
                'type' => 'invalid',
                'payload' => [
                    'slug' => $slug,
                    'problems' => [sprintf(
                        'No Joomla manifest found at the extension root declaring a supported type. '
                        .'Expected %s.xml or manifest.xml with an <extension type="plugin|component|module"> element.',
                        $slug,
                    )],
                ],
            ];
        }

        $problems = array_merge(
            $this->collectProblems($dir),
            $this->missingManifestFiles($dir, $slug),
        );

        if ($type === 'plugin') {
            $problems = array_merge($problems, (new PluginWiringValidator)->validate($dir, $slug));
        }

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
                'extension_type' => $type,
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
                    'EXTENSION_DIR_NOT_FOUND',
                    sprintf('No extension folder named "%s" exists in the workspace.', $execution['payload']['slug']),
                ),
            ];
        }

        if ($execution['type'] === 'invalid') {
            return [
                'result' => $this->error(
                    'EXTENSION_VALIDATION_FAILED',
                    'The extension folder failed validation. Fix the listed problems with file_edit, then retry.',
                    [
                        'extension_dir' => $execution['payload']['slug'],
                        'problems' => $execution['payload']['problems'],
                    ],
                ),
            ];
        }

        if (! is_array($execution['payload']['package'] ?? null)) {
            throw new ToolException('JoomlaZipBuilderTool returned an incomplete package result.');
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
                'extension_dir' => $execution['payload']['slug'],
                'extension_type' => $execution['payload']['extension_type'],
                'package' => $execution['payload']['package'],
            ],
            [
                'mode' => 'build',
                'wrote_to_disk' => true,
                'idempotent' => false,
                'install' => 'Install via System -> Install -> Extensions -> Upload Package File.',
            ],
        );
    }

    /**
     * Report every file or folder the manifest declares that is not present on disk. Returns empty
     * when the manifest is absent or unreadable.
     *
     * @param  string  $dir  Absolute path to the extension folder.
     * @param  string  $slug  Extension folder name, used to locate the manifest.
     * @return string[] Problem descriptions, empty when every declared entry exists.
     */
    private function missingManifestFiles(string $dir, string $slug): array
    {
        $manifest = $this->manifestPath($dir, $slug);

        if ($manifest === null) {
            return [];
        }

        $xml = @simplexml_load_string((string) file_get_contents($manifest));

        if ($xml === false) {
            return [];
        }

        $problems = [];

        foreach ($xml->xpath('//filename') ?: [] as $node) {
            $rel = trim((string) $node);

            if ($rel !== '' && ! is_file($dir.'/'.$rel)) {
                $problems[] = "{$rel}: declared in the manifest but not present in the extension folder";
            }
        }

        foreach ($xml->xpath('//folder') ?: [] as $node) {
            $rel = trim((string) $node);

            if ($rel !== '' && ! is_dir($dir.'/'.$rel)) {
                $problems[] = "{$rel}: folder declared in the manifest but not present in the extension folder";
            }
        }

        return $problems;
    }

    /**
     * Locate the extension's root manifest file.
     *
     * @param  string  $dir  Absolute path to the extension folder.
     * @param  string  $slug  Extension folder name.
     * @return string|null Absolute manifest path, or null when neither candidate exists.
     */
    private function manifestPath(string $dir, string $slug): ?string
    {
        foreach ([$dir.'/'.$slug.'.xml', $dir.'/manifest.xml'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Return the extension type declared by a root manifest, or null when there is none.
     *
     * @param  string  $dir  Absolute extension directory.
     * @return string|null Declared extension type, or null when no manifest declares one.
     */
    private function manifestType(string $dir): ?string
    {
        foreach (glob($dir.'/*.xml') ?: [] as $manifest) {
            $head = (string) file_get_contents($manifest, false, null, 0, self::MANIFEST_HEAD_BYTES);

            if (preg_match('~<extension[^>]*\btype="(plugin|component|module)"~i', $head, $match) === 1) {
                return strtolower($match[1]);
            }
        }

        return null;
    }

    /**
     * Walk every PHP file in the extension and collect validation problems.
     *
     * @param  string  $dir  Absolute extension directory.
     * @return string[] Human-readable problems, empty when the extension is valid.
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

            $isNamespaced = preg_match('~^\s*namespace\s+~m', $code) === 1;
            if (! $isNamespaced && ! str_contains($code, '_JEXEC')) {
                $problems[] = "{$rel}: missing _JEXEC guard";
            }

            $probe = (string) preg_replace('/\s+\(/', '(', $code);
            foreach (self::FORBIDDEN_CALLS as $bad) {
                if (str_contains($probe, $bad)) {
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
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return 'could not run php -l (proc_open failed); syntax not verified';
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
     * Whether this tool is offered to the model for ranking.
     *
     * @return bool True; the execution guard, not routing, decides what may actually run.
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
            domains: ['packaging', 'extensions'],
            tags: ['zip', 'archive', 'package', 'bundle', 'build', 'installer', 'extension'],
            intents: ['build extension zip', 'package a module'],
            examples: ['package this module as an installable zip'],
        );
    }
}
