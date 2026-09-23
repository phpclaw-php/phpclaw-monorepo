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
 * Read-only tool that lists Views and their displays; available only when the Views module is enabled.
 */
final class DrupalViewsTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'use phpclaw chat';

    private const REQUIRED_MODULE = 'views';

    private const DEFAULT_LIMIT = 100;

    private const MAX_OFFSET = 10000;

    private const AVAILABLE_COLUMNS = ['id', 'label', 'status', 'displays'];

    private const ALLOWED_KEYS = ['search', 'status', 'schema', 'limit', 'offset'];

    private const STATUSES = ['all', 'enabled', 'disabled'];

    public const EXAMPLES = [
        [
            'prompt' => 'what views are configured on this site',
            'arguments' => [],
        ],
        [
            'prompt' => 'what can the views tool filter on',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'which views are disabled',
            'arguments' => ['status' => 'disabled'],
        ],
    ];

    private const MAX_LIMIT = 500;

    /**
     * Bind the module handler and entity type manager this tool reads Views through.
     *
     * @param  ModuleHandlerInterface  $moduleHandler  Used to refuse cleanly when views is uninstalled.
     * @param  EntityTypeManagerInterface  $entityTypeManager  Entity type manager.
     * @param  LoggerChannelInterface|null  $logger  Optional channel for query failures.
     * @return void
     */
    public function __construct(
        private readonly ModuleHandlerInterface $moduleHandler,
        private readonly EntityTypeManagerInterface $entityTypeManager,
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
        return 'drupal_views';
    }

    /**
     * Get the human-readable tool description.
     *
     * @return string
     */
    public function description(): string
    {
        return 'List Drupal Views with their displays (page, block, REST), paths, and status. '
             .'Use to audit Views configuration.';
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
                'search' => [
                    'type' => 'string',
                    'description' => 'Search by View name or machine name.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter: all, enabled, disabled. Default: all.',
                    'default' => 'all',
                    'enum' => self::STATUSES,
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return the fields, filters, limits and worked examples this tool accepts. No query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max views to return (1-500). Default: 100.',
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
            domains: ['views', 'content'],
            tags: ['view', 'views', 'display', 'displays', 'listing', 'listings', 'query', 'filter', 'filters', 'page', 'block'],
            intents: ['list views', 'show view displays', 'what listings exist'],
            examples: ['list the views on this site'],
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
        $forbidden = $this->guardCapability('read Drupal views');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        if (! $this->moduleHandler->moduleExists(self::REQUIRED_MODULE)) {
            return [
                'input' => $input,
                'result' => $this->error(
                    'MODULE_NOT_INSTALLED',
                    'The Drupal "views" module is not installed, so there are no views to read.',
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['views'] ?? null)) {
            throw new ToolException('DrupalViewsTool returned an incomplete view result.');
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
            ['views' => $payload['views']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['views']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => self::AVAILABLE_COLUMNS,
                'status_filter' => $payload['status'],
            ],
        );
    }

    /**
     * Read one page of views, with a real total over the matched set.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the view query fails.
     */
    private function queryData(array $input): array
    {
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $status = (string) ($input['status'] ?? 'all');
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int) ($input['offset'] ?? 0));

        try {
            $viewStorage = $this->entityTypeManager->getStorage('view');
            $ids = $viewStorage->getQuery()->accessCheck(true)->range(0, self::MAX_LIMIT)->execute();
            $allViews = $viewStorage->loadMultiple($ids);
        } catch (\Throwable $e) {
            $this->logger?->error('drupal_views query failed: @message', ['@message' => $e->getMessage()]);

            throw new ToolException('Views list query failed.', 0, $e);
        }

        $matched = [];

        foreach ($allViews as $view) {
            $isEnabled = $view->status();

            if ($status === 'enabled' && ! $isEnabled) {
                continue;
            }

            if ($status === 'disabled' && $isEnabled) {
                continue;
            }

            $label = $view->label() ?: $view->id();

            if ($search !== '' && ! str_contains(strtolower($label), $search) && ! str_contains(strtolower((string) $view->id()), $search)) {
                continue;
            }

            $displays = [];

            foreach ($view->get('display') as $displayId => $display) {
                $displays[] = [
                    'id' => $displayId,
                    'title' => (string) ($display['display_title'] ?? $displayId),
                    'type' => (string) ($display['display_plugin'] ?? '-'),
                    'path' => (string) ($display['display_options']['path'] ?? '-'),
                ];
            }

            $matched[$view->id()] = [
                'id' => $view->id(),
                'label' => $label,
                'status' => $isEnabled ? 'enabled' : 'disabled',
                'displays' => $displays,
            ];
        }

        ksort($matched);
        $total = count($matched);
        $page = array_slice(array_values($matched), $offset, $limit);
        $hasMore = ($offset + count($page)) < $total;

        return [
            'views' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($page) : null,
            'status' => $status,
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
            'filters' => ['search', 'status'],
            'statuses' => self::STATUSES,
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

        if (array_key_exists('status', $input) && ! in_array((string) $input['status'], self::STATUSES, true)) {
            return $this->error(
                'INVALID_ARGUMENT',
                '"status" must be one of: '.implode(', ', self::STATUSES).'.',
            );
        }

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
    }
}
