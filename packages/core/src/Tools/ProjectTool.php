<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\AutoDiscovery\Attributes\Tool;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Concerns\HasCoreToolBinding;
use PhpClaw\Tools\Contracts\AuthorizableToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;

/**
 * Read-only project awareness: framework/CMS detection, Composer packages, and a shallow file tree.
 */
#[Tool(
    name: 'project_info',
    description: 'Detect framework/CMS, list Composer packages, and show the project file tree.',
    since: '1.0.0',
    default: true,
    needsConfig: ['projectRoot' => 'string'],
)]
final class ProjectTool implements AuthorizableToolInterface, ToolInterface, ToolRoutingInterface
{
    use HasCoreToolBinding;

    private const MARKERS = [
        'wordpress' => ['wp-config.php', 'wp-load.php'],
        'joomla' => ['configuration.php', 'libraries/src'],
        'opencart' => ['system/startup.php'],
        'prestashop' => ['config/defines.inc.php'],
        'laravel' => ['artisan'],
        'symfony' => ['bin/console'],
        'magento' => ['bin/magento'],
        'drupal' => ['core/lib/Drupal.php'],
    ];

    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.idea', 'storage', 'cache'];

    private readonly string $projectRoot;

    /**
     * Create a new ProjectTool instance.
     *
     * @param  string|null  $projectRoot  Project root to inspect. Defaults to the current working directory.
     * @return void
     */
    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? (string) getcwd();
    }

    /**
     * Tool name advertised to the LLM.
     *
     * @return string
     */
    public function name(): string
    {
        return 'project_info';
    }

    /**
     * Tool description advertised to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Understand the project before changing it: detected framework, Composer packages, and file tree. '
             .'Call this first on any new task. Read-only.';
    }

    /**
     * JSON Schema describing the tool's depth input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'depth' => ['type' => 'integer', 'description' => 'File tree depth 1-4. Default 2.'],
            ],
        ];
    }

    /**
     * Authorize the caller and clamp the requested tree depth.
     *
     * @param  array<string, mixed>  $input  Optional 'depth' (1-4).
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the project root does not exist.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('inspect this project');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if (! is_dir($this->projectRoot)) {
            throw new ToolException("project_info: root not found: {$this->projectRoot}");
        }

        return [
            'input' => ['depth' => max(1, min(4, (int) ($input['depth'] ?? 2)))],
            'result' => null,
        ];
    }

    /**
     * Collect the detected framework, Composer packages, and file tree.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     */
    protected function perform(array $input): array
    {
        return [
            'framework' => $this->detectFramework(),
            'root' => $this->projectRoot,
            'packages' => $this->composerPackages(),
            'file_tree' => $this->tree($this->projectRoot, (int) $input['depth']),
        ];
    }

    /**
     * Accept the collected project snapshot unchanged.
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
     * Convert the project snapshot into the public response envelope.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        return $this->success($execution, [
            'mode' => 'project_info',
            'depth' => (int) $input['depth'],
        ]);
    }

    /**
     * Detect the framework/CMS by well-known marker files.
     *
     * @return string Framework slug, or 'unknown'.
     */
    private function detectFramework(): string
    {
        foreach (self::MARKERS as $name => $markers) {
            foreach ($markers as $marker) {
                if (file_exists($this->projectRoot.'/'.$marker)) {
                    return $name;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Read installed Composer packages from composer.lock.
     *
     * @return list<array{name: string, version: string}>
     */
    private function composerPackages(): array
    {
        $lock = $this->projectRoot.'/composer.lock';
        if (! is_file($lock)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($lock), true);
        $out = [];
        foreach ((array) (($data['packages'] ?? []) ?: []) as $pkg) {
            $out[] = ['name' => (string) ($pkg['name'] ?? ''), 'version' => (string) ($pkg['version'] ?? '')];
        }

        return $out;
    }

    /**
     * Build a shallow file tree up to the requested depth.
     *
     * @param  string  $dir  Directory to walk.
     * @param  int  $depth  Maximum depth.
     * @param  int  $level  Current recursion level.
     * @return array<int|string, mixed>
     */
    private function tree(string $dir, int $depth, int $level = 0): array
    {
        if ($level >= $depth) {
            return [];
        }

        $items = [];
        try {
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($entry->isDot() || in_array($entry->getFilename(), self::SKIP_DIRS, true)) {
                    continue;
                }
                if ($entry->isDir()) {
                    $items[$entry->getFilename().'/'] = $this->tree($entry->getPathname(), $depth, $level + 1);
                } else {
                    $items[] = $entry->getFilename();
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $items;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['project'],
            tags: ['project', 'framework', 'packages', 'composer', 'structure', 'tree'],
            intents: ['inspect project', 'detect framework'],
            examples: ['what framework is this project'],
        );
    }
}
