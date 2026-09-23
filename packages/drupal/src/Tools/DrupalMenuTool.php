<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\Drupal\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Read-only tool that reads navigation menus and their links.
 */
final class DrupalMenuTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'use phpclaw chat';

    private const REQUIRED_MODULE = 'menu_link_content';

    private const MAX_LIMIT = 200;

    private const DEFAULT_LIMIT = 100;

    private const MAX_OFFSET = 10000;

    private const MENU_COLUMNS = ['id', 'label', 'description', 'link_count'];

    private const LINK_COLUMNS = ['id', 'title', 'url', 'enabled', 'weight', 'parent'];

    private const ALLOWED_KEYS = ['menu', 'schema', 'limit', 'offset'];

    public const EXAMPLES = [
        [
            'prompt' => 'what navigation menus does this site have',
            'arguments' => [],
        ],
        [
            'prompt' => 'what can the menus tool tell me',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'what is in the main menu',
            'arguments' => ['menu' => 'main'],
        ],
    ];

    /**
     * Bind the database connection and entity type manager this tool reads menus through.
     *
     * @param  Connection  $database  Drupal database connection.
     * @param  EntityTypeManagerInterface  $entityTypeManager  Entity type manager.
     * @param  ModuleHandlerInterface|null  $moduleHandler  Used to refuse cleanly when menu_link_content is uninstalled.
     * @param  LoggerChannelInterface|null  $logger  Optional channel for query failures.
     * @return void
     */
    public function __construct(
        private readonly Connection $database,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?ModuleHandlerInterface $moduleHandler = null,
        private readonly ?LoggerChannelInterface $logger = null,
    ) {}

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
     * The Drupal permission the caller must hold.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Get the tool name identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'drupal_menus';
    }

    /**
     * Get the human-readable tool description.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Read Drupal navigation menus and their links with hierarchy. '
             .'Use to check site navigation structure.';
    }

    /**
     * Get the JSON Schema for the tool input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'menu' => [
                    'type' => 'string',
                    'description' => 'Menu machine name (e.g. main, footer, admin). Leave empty to list all menus.',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return the fields, limits and worked examples this tool accepts. No query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-200). Default: 100.',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Row offset for paging. Send meta.next_offset from the previous response.',
                    'default' => 0,
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * Whether this tool may be offered to the model. Drupal evaluates the account's permissions when the tool runs, so every tool stays eligible for routing.
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
            domains: ['navigation'],
            tags: ['menu', 'menus', 'navigation', 'nav', 'link', 'links', 'item', 'items', 'tree', 'weight', 'parent'],
            intents: ['list menus', 'show navigation', 'what menu links exist'],
            examples: ['list the navigation menus'],
        );
    }

    /**
     * Guard the caller and validate input before any query runs.
     *
     * @param  array<string, mixed>  $input  Raw runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read Drupal navigation menus');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if ($this->moduleHandler !== null && ! $this->moduleHandler->moduleExists(self::REQUIRED_MODULE)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'MODULE_NOT_INSTALLED',
                    'The Drupal "menu_link_content" module is not installed, so menu links cannot be read.',
                    ['module' => self::REQUIRED_MODULE],
                ),
            ];
        }

        $input = InputNormaliser::flattenArrayValues($input);

        return ['input' => $input, 'result' => $this->validate($input)];
    }

    /**
     * Execute the planned read.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Internal execution result.
     *
     * @throws ToolException On infrastructure failure.
     */
    protected function perform(array $input): array
    {
        if (($input['schema'] ?? false) === true) {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        $menuName = trim((string) ($input['menu'] ?? ''));

        return $menuName === ''
            ? ['type' => 'menus', 'payload' => $this->menuData($input)]
            : ['type' => 'links', 'payload' => $this->linkData($menuName, $input)];
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
        if ($execution['type'] === 'menus' && ! is_array($execution['payload']['menus'] ?? null)) {
            throw new ToolException('DrupalMenuTool returned an incomplete menu result.');
        }

        if ($execution['type'] === 'links' && ! is_array($execution['payload']['links'] ?? null)) {
            throw new ToolException('DrupalMenuTool returned an incomplete link result.');
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
        $payload = $execution['payload'];

        if ($execution['type'] === 'schema') {
            return $this->success($payload, ['mode' => 'schema', 'database_query_performed' => false]);
        }

        $isMenus = $execution['type'] === 'menus';
        $rows = $isMenus ? $payload['menus'] : $payload['links'];

        $meta = [
            'mode' => $isMenus ? 'menus' : 'links',
            'total' => $payload['total'],
            'count' => count($rows),
            'limit' => $payload['limit'],
            'offset' => $payload['offset'],
            'has_more' => $payload['has_more'],
            'next_offset' => $payload['next_offset'],
            'columns_returned' => $isMenus ? self::MENU_COLUMNS : self::LINK_COLUMNS,
        ];

        if (! $isMenus) {
            $meta['menu'] = $payload['menu'];
        }

        return $this->success($isMenus ? ['menus' => $rows] : ['links' => $rows], $meta);
    }

    /**
     * List menus, with a real total and a link count for each.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Menu list payload.
     *
     * @throws ToolException When a menu query fails.
     */
    private function menuData(array $input): array
    {
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int) ($input['offset'] ?? 0));

        try {
            $menus = $this->entityTypeManager->getStorage('menu')->loadMultiple();

            $countsQuery = $this->database->select('menu_link_content_data', 'm')
                ->fields('m', ['menu_name'])
                ->condition('m.enabled', 1)
                ->groupBy('m.menu_name');
            $countsQuery->addExpression('COUNT(*)', 'cnt');
            $countsStmt = $countsQuery->execute();

            $linkCounts = [];

            while ($row = $countsStmt->fetchAssoc()) {
                $linkCounts[(string) $row['menu_name']] = (int) $row['cnt'];
            }
        } catch (\Throwable $e) {
            $this->logger?->error('drupal_menus list failed: @message', ['@message' => $e->getMessage()]);

            throw new ToolException('Menu list query failed.', 0, $e);
        }

        ksort($menus);
        $total = count($menus);
        $page = array_slice($menus, $offset, $limit, preserve_keys: true);

        $result = [];

        foreach ($page as $menu) {
            $result[] = [
                'id' => $menu->id(),
                'label' => $menu->label(),
                'description' => $menu->getDescription(),
                'link_count' => $linkCounts[$menu->id()] ?? 0,
            ];
        }

        $hasMore = ($offset + count($result)) < $total;

        return [
            'menus' => $result,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($result) : null,
        ];
    }

    /**
     * Read one page of links for a menu, with a real COUNT over the same filter.
     *
     * @param  string  $menuName  Machine name of the menu.
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Link payload.
     *
     * @throws ToolException When a link query fails.
     */
    private function linkData(string $menuName, array $input): array
    {
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int) ($input['offset'] ?? 0));

        try {
            $stmt = $this->database->select('menu_link_content_data', 'm')
                ->fields('m', ['id', 'title', 'link__uri', 'link__title', 'menu_name', 'weight', 'enabled', 'parent'])
                ->condition('m.menu_name', $menuName)
                ->orderBy('m.weight', 'ASC')
                ->orderBy('m.id', 'ASC')
                ->range($offset, $limit)
                ->execute();

            $results = [];

            while ($row = $stmt->fetchAssoc()) {
                $results[] = $row;
            }

            $countQuery = $this->database->select('menu_link_content_data', 'm')
                ->condition('m.menu_name', $menuName);
            $total = (int) $countQuery->countQuery()->execute()->fetchField();
        } catch (\Throwable $e) {
            $this->logger?->error('drupal_menus link query failed: @message', ['@message' => $e->getMessage()]);

            throw new ToolException('Menu links query failed.', 0, $e);
        }

        $links = [];

        foreach ($results as $row) {
            $links[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'url' => (string) ($row['link__uri'] ?? ''),
                'enabled' => (bool) $row['enabled'],
                'weight' => (int) $row['weight'],
                'parent' => (string) ($row['parent'] ?? ''),
            ];
        }

        $hasMore = ($offset + count($links)) < $total;

        return [
            'menu' => $menuName,
            'links' => $links,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($links) : null,
        ];
    }

    /**
     * Schema discovery payload. No query.
     *
     * @return array<string, mixed> Schema payload.
     */
    private function schemaData(): array
    {
        return [
            'menu_columns' => self::MENU_COLUMNS,
            'link_columns' => self::LINK_COLUMNS,
            'untrusted_columns' => [],
            'sensitive_columns' => [],
            'filters' => ['menu'],
            'modes' => ['schema', 'menus', 'links'],
            'pagination' => ['type' => 'offset'],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
            ],
            'examples' => self::EXAMPLES,
            'drupal_permission' => self::REQUIRED_CAPABILITY,
            'required_module' => self::REQUIRED_MODULE,
            'idempotent' => true,
        ];
    }

    /**
     * Validate runtime input and return a structured error when it is unusable.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return string|null JSON-encoded error envelope, or null when valid.
     */
    private function validate(array $input): ?string
    {
        $unknown = $this->rejectUnknownArguments($input, self::ALLOWED_KEYS);

        if ($unknown !== null) {
            return $unknown;
        }

        if (array_key_exists('schema', $input) && ! is_bool($input['schema'])) {
            return $this->error('INVALID_ARGUMENT', '"schema" must be a boolean.');
        }

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
    }
}
