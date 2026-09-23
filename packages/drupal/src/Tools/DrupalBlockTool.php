<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\Drupal\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Read-only tool that lists block instances and their regions.
 */
final class DrupalBlockTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'use phpclaw chat';

    private const REQUIRED_MODULE = 'block';

    private const MAX_LIMIT = 500;

    private const DEFAULT_LIMIT = 100;

    private const MAX_OFFSET = 10000;

    private const AVAILABLE_COLUMNS = ['id', 'label', 'region', 'plugin', 'status', 'weight', 'theme'];

    private const ALLOWED_KEYS = ['region', 'search', 'schema', 'limit', 'offset'];

    public const EXAMPLES = [
        [
            'prompt' => 'what blocks are placed on this site',
            'arguments' => [],
        ],
        [
            'prompt' => 'what can the blocks tool filter on',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'which blocks are in the footer',
            'arguments' => ['region' => 'footer_bottom'],
        ],
    ];

    /**
     * Bind the entity type manager and module handler this tool reads blocks through.
     *
     * @param  EntityTypeManagerInterface  $entityTypeManager  Entity type manager.
     * @param  ModuleHandlerInterface|null  $moduleHandler  Used to refuse cleanly when block is uninstalled.
     * @param  LoggerChannelInterface|null  $logger  Optional channel for query failures.
     * @return void
     */
    public function __construct(
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
        return 'drupal_blocks';
    }

    /**
     * Get the human-readable tool description.
     *
     * @return string
     */
    public function description(): string
    {
        return 'List Drupal block instances with their regions, visibility, and status. '
             .'Use to check site layout and block placement.';
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
                'region' => [
                    'type' => 'string',
                    'description' => 'Filter by region name (e.g. sidebar_first, content, header). Leave empty for all.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search by block label or plugin ID.',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return the fields, filters, limits and worked examples this tool accepts. No query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max blocks to return (1-500). Default: 100.',
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
            domains: ['layout', 'blocks'],
            tags: ['block', 'blocks', 'region', 'regions', 'placement', 'layout', 'sidebar', 'footer', 'header', 'theme', 'visible'],
            intents: ['list blocks', 'show block placement', 'what is in the sidebar'],
            examples: ['list the blocks and their regions'],
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
        $forbidden = $this->guardCapability('read Drupal block placements');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if ($this->moduleHandler !== null && ! $this->moduleHandler->moduleExists(self::REQUIRED_MODULE)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'MODULE_NOT_INSTALLED',
                    'The Drupal "block" module is not installed, so there are no block placements to read.',
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

        return ['type' => 'query', 'payload' => $this->queryData($input)];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['blocks'] ?? null)) {
            throw new ToolException('DrupalBlockTool returned an incomplete block result.');
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

        return $this->success(
            ['blocks' => $payload['blocks'], 'region_counts' => $payload['region_counts']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['blocks']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => self::AVAILABLE_COLUMNS,
            ],
        );
    }

    /**
     * Read one page of block placements, with a real total over the matched set.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the block query fails.
     */
    private function queryData(array $input): array
    {
        $region = trim((string) ($input['region'] ?? ''));
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int) ($input['offset'] ?? 0));

        try {
            $blockStorage = $this->entityTypeManager->getStorage('block');
            $ids = $blockStorage->getQuery()->accessCheck(true)->range(0, self::MAX_LIMIT)->execute();
            $allBlocks = $blockStorage->loadMultiple($ids);
        } catch (\Throwable $e) {
            $this->logger?->error('drupal_blocks query failed: @message', ['@message' => $e->getMessage()]);

            throw new ToolException('Block list query failed.', 0, $e);
        }

        $matched = [];
        $regions = [];

        foreach ($allBlocks as $block) {
            $blockRegion = $block->getRegion();
            $label = $block->label() ?: $block->id();
            $pluginId = $block->getPluginId();

            if ($region !== '' && $blockRegion !== $region) {
                continue;
            }

            if ($search !== '' && ! str_contains(strtolower($label), $search) && ! str_contains(strtolower($pluginId), $search)) {
                continue;
            }

            $matched[] = [
                'id' => $block->id(),
                'label' => $label,
                'region' => $blockRegion,
                'plugin' => $pluginId,
                'status' => $block->status() ? 'enabled' : 'disabled',
                'weight' => $block->getWeight(),
                'theme' => $block->getTheme(),
            ];

            $regions[$blockRegion] = ($regions[$blockRegion] ?? 0) + 1;
        }

        usort($matched, static fn (array $a, array $b): int => strcmp($a['region'], $b['region'])
            ?: ($a['weight'] <=> $b['weight'])
            ?: strcmp((string) $a['id'], (string) $b['id']));

        $total = count($matched);
        $page = array_slice($matched, $offset, $limit);
        $hasMore = ($offset + count($page)) < $total;

        return [
            'blocks' => $page,
            'region_counts' => $regions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($page) : null,
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
            'available_columns' => self::AVAILABLE_COLUMNS,
            'untrusted_columns' => [],
            'sensitive_columns' => [],
            'filters' => ['region', 'search'],
            'modes' => ['schema', 'query'],
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
