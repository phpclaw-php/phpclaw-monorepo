<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\Contracts\ToolRoutingInterface;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Employee tool: read-only inspection of back-office staff.
 */
final class PsEmployeeTool implements ToolInterface, ToolRoutingInterface
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'AdminPhpClawDebug';

    private const MAX_ROW_BYTES = ToolOutputEncoder::MAX_ROW_BYTES;

    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    private const MAX_OFFSET = 100000;

    private const DEFAULT_OFFSET = 0;

    private const FILTERS = ['search', 'profile_id', 'active'];

    private const ALLOWED_KEYS = [
        'columns', 'schema', 'aggregate', 'search', 'active', 'profile_id',
        'limit', 'offset', 'order_by', 'order_dir',
    ];

    private const AVAILABLE_COLUMNS = [
        'id', 'firstname', 'lastname', 'email', 'profile_name',
        'active', 'last_connection', 'date_add',
    ];

    private const DEFAULT_COLUMNS = [
        'id', 'firstname', 'lastname', 'profile_name', 'active', 'last_connection',
    ];

    private const BLOCKED_COLUMNS = ['passwd', 'reset_password_token'];

    private const SENSITIVE_COLUMNS = ['email'];

    private const COLUMN_MAP = [
        'id' => 'e.id_employee',
        'firstname' => 'e.firstname',
        'lastname' => 'e.lastname',
        'email' => 'e.email',
        'profile_name' => 'pl.name',
        'active' => 'e.active',
        'last_connection' => 'e.last_connection_date',
        'date_add' => 'e.date_add',
    ];

    /**
     * Create a new PsEmployeeTool instance.
     *
     * @param  PsDbInterface|null  $db  Native PrestaShop DB handle.
     * @param  string  $tablePrefix  PrestaShop table prefix.
     */
    public function __construct(
        private readonly ?PsDbInterface $db,
        private readonly string $tablePrefix = 'ps_',
    ) {}

    /**
     * Return the tool's machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'ps_employee';
    }

    /**
     * Return the natural-language description presented to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return <<<'DESC'
Search, filter, and inspect PrestaShop back-office employees.

AVAILABLE COLUMNS:
  id, firstname, lastname, email, profile_name,
  active, last_connection, date_add

BLOCKED COLUMNS (never returned, hardcoded security):
  passwd, reset_password_token

SENSITIVE COLUMNS (masked in output):
  email: first 3 characters shown, rest replaced with ***

CAPABILITIES:
  - Search by name or email (partial match)
  - Filter by active/inactive status
  - Filter by profile ID (1=SuperAdmin, 2=Logistician, etc.)
  - Request specific columns or get all available columns
  - Aggregate mode: total employees, active/inactive, by_profile counts
  - Schema mode: discover available columns before querying

EXAMPLES:
  "List all employees" → (no params)
  "Who are the super admins?" → profile_id: 1
  "Find employee named John" → search: "John"
  "How many employees per profile?" → aggregate: true
  "Show inactive employees" → active: false
  "What columns exist?" → schema: true
  "Show name, email, last login" → columns: ["firstname","lastname","email","last_connection"]
DESC;
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
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Columns to return. Any of: '.
                        implode(', ', self::AVAILABLE_COLUMNS).
                        '. Omit for defaults. Use ["*"] for all.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Partial match search across firstname, lastname, and email (case-insensitive).',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'true = active only, false = inactive only. Omit for all.',
                ],
                'profile_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by profile ID (1=SuperAdmin, 2=Logistician, 3=Translator, 4=Salesman).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return (1-100, default 50).',
                    'default' => self::DEFAULT_LIMIT,
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset.',
                    'default' => 0,
                ],
                'order_by' => [
                    'type' => 'string',
                    'description' => 'Sort column. Default: lastname.',
                    'default' => 'lastname',
                ],
                'order_dir' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'description' => 'Sort direction. Default: ASC.',
                    'default' => 'ASC',
                ],
                'aggregate' => [
                    'type' => 'boolean',
                    'description' => 'true = stats only. Returns: total, active, inactive, by_profile counts.',
                ],
                'schema' => [
                    'type' => 'boolean',
                    'description' => 'true = return available columns. No DB query.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Return the back-office tab grant required to run this tool.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Whether this tool may be offered to the model. PrestaShop evaluates employee permissions when the tool runs, so every tool stays eligible for routing.
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
            domains: ['staff', 'accounts'],
            tags: ['employee', 'employees', 'staff', 'user', 'users', 'admin', 'administrator', 'account', 'accounts', 'profile', 'profiles', 'role', 'roles', 'backoffice'],
            intents: ['list employees', 'show staff accounts', 'who can log into the back office'],
            examples: ['list the back office employees'],
        );
    }

    /**
     * Plan the execution: authorise first, then validate, before any query runs.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     *
     * @throws ToolException When the store database is unreachable.
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read PrestaShop back-office employees');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $invalid = $this->validate($input);

        if ($invalid !== null) {
            return ['input' => $input, 'result' => $invalid];
        }

        if (empty($input['schema']) && $this->db === null) {
            throw new ToolException('ps_employee: no database connection available.');
        }

        return ['input' => $input, 'result' => null];
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
        if (! empty($input['schema'])) {
            return ['type' => 'schema', 'payload' => $this->schemaData()];
        }

        if (! empty($input['aggregate'])) {
            return ['type' => 'aggregate', 'payload' => $this->aggregateData($input)];
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
        if ($execution['type'] === 'query' && ! is_array($execution['payload']['employees'] ?? null)) {
            throw new ToolException('ps_employee: the query returned an incomplete result.');
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

        if ($execution['type'] === 'aggregate') {
            return $this->success($payload, ['mode' => 'aggregate']);
        }

        return $this->success(
            ['employees' => $payload['employees']],
            [
                'mode' => 'query',
                'total' => $payload['total'],
                'count' => count($payload['employees']),
                'limit' => $payload['limit'],
                'offset' => $payload['offset'],
                'has_more' => $payload['has_more'],
                'next_offset' => $payload['next_offset'],
                'columns_returned' => $payload['columns'],
                'truncated' => $payload['truncated'],
            ],
            $payload['warnings'],
        );
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

        return $this->validatePaging($input, self::MAX_LIMIT, self::MAX_OFFSET);
    }

    /**
     * Read one page of employees, with a real total from the same WHERE clause.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Query result data.
     *
     * @throws ToolException When the employee query fails.
     */
    private function queryData(array $input): array
    {
        $p = $this->tablePrefix;
        $limit = min(max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset = min(max(0, (int) ($input['offset'] ?? self::DEFAULT_OFFSET)), self::MAX_OFFSET);
        $orderBy = $this->safeColumn($input['order_by'] ?? 'lastname');
        $orderDir = strtoupper($input['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $columns = $this->resolveColumns($input['columns'] ?? []);
        $select = implode(', ', array_map(fn (string $c) => self::COLUMN_MAP[$c].' AS '.$c, $columns));

        [$where, $bindings] = $this->buildWhere($input);

        $from = "FROM `{$p}employee` e
                LEFT JOIN `{$p}profile_lang` pl
                       ON pl.id_profile = e.id_profile AND pl.id_lang = 1
                {$where}";

        $sql = "SELECT {$select}
                {$from}
                ORDER BY ".self::COLUMN_MAP[$orderBy]." {$orderDir}, e.id_employee ASC
                LIMIT {$limit} OFFSET {$offset}";

        try {
            $total = (int) ($this->db->query("SELECT COUNT(*) AS total {$from}", $bindings)->row['total'] ?? 0);
            $rows = $this->db->query($sql, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsEmployeeTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_employee: database query failed.', previous: $e);
        }

        $rows = array_map(function (array $row): array {
            if (isset($row['active'])) {
                $row['active'] = (bool) $row['active'];
            }

            if (isset($row['email'])) {
                $row['email'] = $this->maskEmail((string) $row['email']);
            }

            return $row;
        }, $rows);

        $capped = ToolOutputEncoder::cap($rows, self::MAX_ROW_BYTES);
        $hasMore = ($offset + $capped['shown']) < $total;

        return [
            'employees' => $capped['rows'],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $capped['shown'] : null,
            'columns' => $columns,
            'truncated' => $capped['truncated'],
            'warnings' => ToolOutputEncoder::warnings($capped),
        ];
    }

    /**
     * Aggregate mode, counts and stats only, zero row data.
     *
     * @param  array<string, mixed>  $input  Validated runtime input.
     * @return array<string, mixed> Aggregate result data.
     *
     * @throws ToolException When the aggregate query fails.
     */
    private function aggregateData(array $input): array
    {
        $p = $this->tablePrefix;
        [$where, $bindings] = $this->buildWhere($input);

        $sqlCounts = "SELECT
                        COUNT(*)              AS total,
                        SUM(e.active = 1)     AS active,
                        SUM(e.active = 0)     AS inactive
                      FROM `{$p}employee` e
                      LEFT JOIN `{$p}profile_lang` pl
                             ON pl.id_profile = e.id_profile AND pl.id_lang = 1
                      {$where}";

        $sqlProfiles = "SELECT
                          pl.name        AS profile_name,
                          COUNT(*)       AS count
                        FROM `{$p}employee` e
                        LEFT JOIN `{$p}profile_lang` pl
                               ON pl.id_profile = e.id_profile AND pl.id_lang = 1
                        {$where}
                        GROUP BY e.id_profile, pl.name
                        ORDER BY count DESC";

        try {
            $stats = $this->db->query($sqlCounts, $bindings)->row;
            $profiles = $this->db->query($sqlProfiles, $bindings)->rows;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('phpClaw PsEmployeeTool: '.$e->getMessage(), 3);
            throw new ToolException('ps_employee: database query failed.', previous: $e);
        }

        return [
            'stats' => array_map('intval', $stats),
            'by_profile' => $profiles,
        ];
    }

    /**
     * Schema discovery data, no database query.
     *
     * @return array<string, mixed> Schema discovery data.
     */
    private function schemaData(): array
    {
        return [
            'available_columns' => self::AVAILABLE_COLUMNS,
            'default_columns' => self::DEFAULT_COLUMNS,
            'blocked_columns' => self::BLOCKED_COLUMNS,
            'sensitive_columns' => self::SENSITIVE_COLUMNS,
            'column_descriptions' => [
                'id' => 'Employee ID (integer)',
                'firstname' => 'Employee first name',
                'lastname' => 'Employee last name',
                'email' => 'Employee email (masked, first 3 chars shown)',
                'profile_name' => 'Role/profile name (e.g. SuperAdmin, Logistician)',
                'active' => 'true = enabled, false = disabled',
                'last_connection' => 'Date/time of last back-office login',
                'date_add' => 'Date the employee was created',
            ],
            'filter_capabilities' => self::FILTERS,
            'modes' => ['schema', 'aggregate', 'query'],
            'pagination' => ['type' => 'offset'],
            'limits' => [
                'default_limit' => self::DEFAULT_LIMIT,
                'maximum_limit' => self::MAX_LIMIT,
            ],
        ];
    }

    /**
     * Build WHERE clause and positional bindings from input filters.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(array $input): array
    {
        $conditions = [];
        $bindings = [];

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $s = '%'.trim((string) $input['search']).'%';
            $conditions[] = '(e.firstname LIKE ? OR e.lastname LIKE ? OR e.email LIKE ?)';
            $bindings[] = $s;
            $bindings[] = $s;
            $bindings[] = $s;
        }

        if (isset($input['active'])) {
            $conditions[] = 'e.active = ?';
            $bindings[] = $input['active'] ? 1 : 0;
        }

        if (isset($input['profile_id'])) {
            $conditions[] = 'e.id_profile = ?';
            $bindings[] = (int) $input['profile_id'];
        }

        $where = $conditions !== [] ? 'WHERE '.implode(' AND ', $conditions) : '';

        return [$where, $bindings];
    }

    /**
     * Mask an email address for output. Shows first 3 chars before @.
     *
     * @param  string  $email
     * @return string
     */
    private function maskEmail(string $email): string
    {
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return '***';
        }

        return substr($email, 0, min(3, $atPos)).'***@'.substr($email, $atPos + 1);
    }

    /**
     * Resolve requested columns to a safe validated list.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function resolveColumns(array $requested): array
    {
        if ($requested === ['*']) {
            return self::AVAILABLE_COLUMNS;
        }
        if ($requested === []) {
            return self::DEFAULT_COLUMNS;
        }

        $valid = array_filter(
            $requested,
            fn (string $c) => in_array($c, self::AVAILABLE_COLUMNS, true)
        );

        if (! in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
        }

        return array_values($valid);
    }

    /**
     * Validate a column name for use in ORDER BY.
     *
     * @param  string  $col
     * @return string
     */
    private function safeColumn(string $col): string
    {
        return in_array($col, self::AVAILABLE_COLUMNS, true) ? $col : 'lastname';
    }
}
