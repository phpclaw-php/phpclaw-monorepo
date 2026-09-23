<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PhpClaw\Drupal\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Read-only tool that lists roles, permissions, and user counts per role.
 */
final class DrupalUserRoleTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'use phpclaw chat';

    private const MAX_LIMIT = 50;

    private const DEFAULT_LIMIT = 50;

    private const MAX_OFFSET = 10000;

    private const PERMISSION_PREVIEW = 10;

    private const AVAILABLE_COLUMNS = ['id', 'label', 'is_admin', 'user_count', 'permission_count', 'permissions'];

    private const ALLOWED_KEYS = ['role', 'schema', 'limit', 'offset'];

    public const EXAMPLES = [
        [
            'prompt' => 'what roles exist on this site and how many people hold each',
            'arguments' => [],
        ],
        [
            'prompt' => 'what can the roles tool tell me',
            'arguments' => ['schema' => true],
        ],
        [
            'prompt' => 'what permissions does the content editor role have',
            'arguments' => ['role' => 'content_editor'],
        ],
    ];

    /**
     * Bind the database connection and entity type manager this tool reads roles through.
     *
     * @param  Connection  $database  Drupal database connection.
     * @param  EntityTypeManagerInterface  $entityTypeManager  Entity type manager.
     * @param  LoggerChannelInterface|null  $logger  Optional channel for query failures.
     * @return void
     */
    public function __construct(
        private readonly Connection $database,
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
        return 'drupal_roles';
    }

    /**
     * Get the human-readable tool description.
     *
     * @return string
     */
    public function description(): string
    {
        return 'List Drupal user roles with permissions and user counts. '
             .'Use to audit roles, check who can do what, or count users per role.';
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
                'role' => [
                    'type' => 'string',
                    'description' => 'Get details for a specific role ID (e.g. editor, administrator). Leave empty for all roles.',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return the fields, limits and worked examples this tool accepts. No database query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max roles to return (1-50). Default: 50.',
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
            domains: ['users', 'accounts'],
            tags: ['user', 'users', 'role', 'roles', 'account', 'accounts', 'member', 'members', 'permission', 'permissions', 'author', 'admin', 'administrator', 'blocked', 'active', 'login', 'log', 'logged', 'sign', 'signin', 'signed', 'access', 'registered'],
            intents: ['list users', 'show roles', 'who has which permissions'],
            examples: ['list the users and their roles'],
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
        $forbidden = $this->guardCapability('read Drupal user roles');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['roles'] ?? null)) {
            throw new ToolException('DrupalUserRoleTool returned an incomplete role result.');
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
            ['roles' => $payload['roles']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['roles']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => self::AVAILABLE_COLUMNS,
            ],
        );
    }

    /**
     * Read one page of roles, with a real total over the same selection.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When a role query fails.
     */
    private function queryData(array $input): array
    {
        $roleId = trim((string) ($input['role'] ?? ''));
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = max(0, (int) ($input['offset'] ?? 0));

        try {
            $roleStorage = $this->entityTypeManager->getStorage('user_role');
            $all = $roleId !== '' ? $roleStorage->loadMultiple([$roleId]) : $roleStorage->loadMultiple();

            $countsQuery = $this->database->select('user__roles', 'ur')
                ->fields('ur', ['roles_target_id'])
                ->groupBy('ur.roles_target_id');
            $countsQuery->addExpression('COUNT(*)', 'cnt');
            $countsStmt = $countsQuery->execute();

            $userCounts = [];

            while ($row = $countsStmt->fetchAssoc()) {
                $userCounts[(string) $row['roles_target_id']] = (int) $row['cnt'];
            }
        } catch (\Throwable $e) {
            $this->logger?->error('drupal_roles user count query failed: @message', ['@message' => $e->getMessage()]);

            throw new ToolException('Role user-count query failed.', 0, $e);
        }

        ksort($all);
        $total = count($all);
        $page = array_slice($all, $offset, $limit, preserve_keys: true);

        $result = [];

        foreach ($page as $role) {
            $permissions = $role->getPermissions();
            sort($permissions);
            $shown = $roleId !== '' ? $permissions : array_slice($permissions, 0, self::PERMISSION_PREVIEW);

            $result[] = [
                'id' => $role->id(),
                'label' => $role->label(),
                'is_admin' => $role->isAdmin(),
                'user_count' => $userCounts[$role->id()] ?? 0,
                'permission_count' => count($permissions),
                'permissions' => $shown,
            ];
        }

        $hasMore = ($offset + count($result)) < $total;

        return [
            'roles' => $result,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($result) : null,
        ];
    }

    /**
     * Schema discovery payload. No database query.
     *
     * @return array<string, mixed> Schema payload.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'untrusted_columns' => [],
            'sensitive_columns' => [],
            'filters' => ['role'],
            'modes' => ['schema', 'query'],
            'pagination' => ['type' => 'offset'],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
                'permission_preview' => self::PERMISSION_PREVIEW,
            ],
            'examples' => self::EXAMPLES,
            'drupal_permission' => self::REQUIRED_CAPABILITY,
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
