<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/**
 * Packages a workspace folder into an installable ZIP under the system temp directory (requires ext-zip).
 */
#[Tool(
    name: 'zip_package',
    description: 'Zip a workspace folder into a temp-dir {output_name}.zip for CMS installation.',
    since: '1.0.0',
    default: false,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class ZipPackagerTool implements AuthorizableToolInterface, MutatingToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    public const DEFAULT_WORKSPACE_SUBPATH = 'storage/phpclaw';

    private readonly string $workspaceRoot;

    /**
     * Create a new ZipPackagerTool instance.
     *
     * @param  string|null  $workspaceRoot  Absolute path to workspace root. Defaults to <CWD>/storage/phpclaw/.
     * @return void
     */
    public function __construct(?string $workspaceRoot = null)
    {
        $raw = rtrim(
            $workspaceRoot ?? (getcwd().DIRECTORY_SEPARATOR.self::DEFAULT_WORKSPACE_SUBPATH),
            DIRECTORY_SEPARATOR
        );
        $real = realpath($raw);
        $this->workspaceRoot = $real !== false ? $real : $raw;
    }

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return 'zip_package';
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Create an installable ZIP from a workspace folder. Output goes to the system temp dir. '
             .'Use after all plugin/module files are created and verified.';
    }

    /**
     * JSON Schema describing the tool's source_dir and output_name inputs.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_dir' => ['type' => 'string', 'description' => 'Workspace-relative folder to package.'],
                'output_name' => ['type' => 'string', 'description' => 'ZIP name without extension (alphanumeric, dash, underscore).'],
            ],
            'required' => ['source_dir', 'output_name'],
        ];
    }

    /**
     * Authorize the caller, check ext-zip, and resolve the source folder inside the workspace.
     *
     * @param  array<string, mixed>  $input  Must contain 'source_dir' and 'output_name'.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When ext-zip is missing, inputs are invalid, or the source escapes the workspace.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('package a workspace folder');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if (! class_exists(\ZipArchive::class)) {
            throw new ToolException('zip_package: PHP ext-zip is not installed.');
        }

        $sourceDir = (string) ($input['source_dir'] ?? '');
        $outputName = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($input['output_name'] ?? ''));

        if ($sourceDir === '' || $outputName === '') {
            throw new ToolException('zip_package: source_dir and a valid output_name are required.');
        }

        $abs = realpath($this->workspaceRoot.DIRECTORY_SEPARATOR.ltrim($sourceDir, '/\\'));
        $ws = realpath($this->workspaceRoot) ?: $this->workspaceRoot;

        if ($abs === false || ! is_dir($abs)) {
            throw new ToolException("zip_package: source_dir not found: {$sourceDir}");
        }

        if (! str_starts_with($abs.DIRECTORY_SEPARATOR, rtrim($ws, '/\\').DIRECTORY_SEPARATOR)) {
            throw new ToolException('zip_package: source_dir escapes workspace root - blocked.');
        }

        return [
            'input' => ['source_dir' => $abs, 'output_name' => $outputName],
            'result' => null,
        ];
    }

    /**
     * Write the ZIP archive for the resolved source folder.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException When the archive cannot be created.
     */
    protected function perform(array $input): array
    {
        $abs = (string) $input['source_dir'];
        $outputName = (string) $input['output_name'];

        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$outputName.'.zip';
        @unlink($zipPath);

        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ToolException("zip_package: cannot create {$zipPath}");
        }

        $count = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iter as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($abs) + 1);
            $zip->addFile($file->getPathname(), $outputName.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $rel));
            $count++;
        }

        $zip->close();

        return [
            'status' => 'created',
            'zip_path' => $zipPath,
            'files' => $count,
            'size_bytes' => (int) filesize($zipPath),
        ];
    }

    /**
     * Accept the written archive unchanged.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     */
    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    /**
     * Convert the written archive into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, [
            'mode' => 'package',
            'output_name' => (string) $input['output_name'],
        ]);
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['filesystem', 'packaging'],
            tags: ['zip', 'archive', 'package', 'bundle', 'compress'],
            intents: ['package directory', 'create zip'],
            examples: ['zip up the build folder'],
        );
    }
}
