<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tools;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Magento\Model\IdentityResolver;
use PhpClaw\Magento\Tools\Concerns\HasToolExecutionContract;
use PhpClaw\Tools\ToolRoutingMetadata;

/**
 * Tool that returns the store hierarchy: websites, store groups, and store views.
 */
// non-final: Magento interceptor required
class MagentoStoreTool extends AbstractMagentoResourceTool
{
    use HasToolExecutionContract;

    private const REQUIRED_CAPABILITY = 'PhpClaw_Magento::phpclaw_chat';

    /**
     * Bind the resource connection, identity resolver, and ACL service.
     *
     * @param  ResourceConnection  $resourceConnection  Magento DB connection provider.
     * @param  IdentityResolver  $identityResolver  Resolver reporting the area and the acting admin identity.
     * @param  AuthorizationInterface  $acl  Magento authorization service.
     * @return void
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        private readonly IdentityResolver $identityResolver,
        private readonly AuthorizationInterface $acl,
    ) {
        parent::__construct($resourceConnection);
    }

    /**
     * Return the ACL resource a caller must hold to fetch store data.
     *
     * @return string
     */
    public function requiredCapability(): string
    {
        return self::REQUIRED_CAPABILITY;
    }

    /**
     * Return the routing signals the router ranks this tool by.
     *
     * @return ToolRoutingMetadata Domains, tags, intents and examples for this tool.
     */
    public function routingMetadata(): ToolRoutingMetadata
    {
        return new ToolRoutingMetadata(
            domains: ['configuration', 'stores'],
            tags: ['store', 'stores', 'website', 'websites', 'view', 'views', 'scope', 'scopes', 'storeview', 'locale', 'currency', 'group'],
            intents: ['list stores', 'show website scopes', 'what store views exist'],
            examples: ['list the store views'],
        );
    }

    /**
     * Return the identity resolver the contract trait reads the area from.
     *
     * @return IdentityResolver
     */
    protected function identity(): IdentityResolver
    {
        return $this->identityResolver;
    }

    /**
     * Return the ACL service the contract trait checks capabilities against.
     *
     * @return AuthorizationInterface
     */
    protected function authorization(): AuthorizationInterface
    {
        return $this->acl;
    }

    /**
     * Returns the tool identifier used in agent tool dispatch.
     *
     * @return string
     */
    public function name(): string
    {
        return 'magento_stores';
    }

    /**
     * Returns the natural-language description shown to the LLM.
     *
     * @return string
     */
    public function description(): string
    {
        return 'LIST all Magento websites, store groups, and store views with their codes, names, and active status. Returns a nested hierarchy. Invoke it, never guess store config.';
    }

    /**
     * Returns the JSON Schema object describing accepted inputs (empty, no inputs required).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
        ];
    }

    /**
     * Guard the caller and reject any unknown arguments before fetching store data.
     *
     * @param  array<string, mixed>  $input  Runtime input.
     * @return array{input: array<string, mixed>, result: string|null}
     */
    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('list Magento websites, store groups, and store views');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        $unknown = $this->rejectUnknownArguments($input, []);

        if ($unknown !== null) {
            return ['input' => $input, 'result' => $unknown];
        }

        return ['input' => $input, 'result' => null];
    }

    /**
     * Fetch the full website, store group, and store view hierarchy from the database.
     *
     * @param  array<string, mixed>  $input  Validated runtime input (unused, this tool takes no arguments).
     * @return array<string, mixed> Internal execution result containing the nested website list.
     */
    protected function perform(array $input): array
    {
        $conn = $this->resourceConnection->getConnection();
        $tWebsite = $this->resourceConnection->getTableName('store_website');
        $tStoreGroup = $this->resourceConnection->getTableName('store_group');
        $tStore = $this->resourceConnection->getTableName('store');

        $rows = $conn->fetchAll(
            "SELECT
                sw.website_id,   sw.code  AS website_code,  sw.name AS website_name,
                sw.is_default    AS website_is_default,
                sg.group_id,     sg.code  AS group_code,    sg.name AS group_name,
                sg.root_category_id,      sg.default_store_id,
                sv.store_id,     sv.code  AS store_code,    sv.name AS store_name,
                sv.is_active
             FROM {$tWebsite} sw
             LEFT JOIN {$tStoreGroup} sg ON sg.website_id  = sw.website_id
             LEFT JOIN {$tStore}       sv ON sv.group_id    = sg.group_id
             WHERE sw.website_id > 0
             ORDER BY sw.website_id, sg.group_id, sv.store_id",
            [],
        );

        return [
            'mode' => 'list',
            'websites' => array_values($this->buildHierarchy($rows)),
        ];
    }

    /**
     * Confirm the execution result contains the required websites list before packaging.
     *
     * @param  array<string, mixed>  $execution  Internal execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return array{result: string|null}
     *
     * @throws ToolException When the execution result is missing the websites list.
     */
    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['websites'] ?? null)) {
            throw new ToolException('magento_stores: incomplete result.');
        }

        return ['result' => null];
    }

    /**
     * Convert the verified execution into the public response envelope, applying the byte-budget cap.
     *
     * @param  array<string, mixed>  $execution  Verified execution result.
     * @param  array<string, mixed>  $input  Validated input.
     * @return string JSON-encoded response envelope.
     */
    protected function complete(array $execution, array $input): string
    {
        $capped = $this->capRows($execution['websites']);

        return $this->success(
            ['websites' => $capped['rows']],
            [
                'total' => $capped['total'],
                'shown' => $capped['shown'],
                'truncated' => $capped['truncated'],
            ],
        );
    }

    /**
     * Transforms flat JOIN rows into a nested website -> store_group -> store_view structure.
     *
     * @param  array<int, array<string, mixed>>  $rows  Flat rows from the three-table JOIN query.
     * @return array<int, array<string, mixed>> Associative array keyed by website_id before re-indexing.
     */
    private function buildHierarchy(array $rows): array
    {
        $websites = [];

        foreach ($rows as $row) {
            $wid = (int) $row['website_id'];
            $gid = isset($row['group_id']) ? (int) $row['group_id'] : null;
            $sid = isset($row['store_id']) ? (int) $row['store_id'] : null;

            if (! isset($websites[$wid])) {
                $websites[$wid] = [
                    'website_id' => $wid,
                    'website_code' => $row['website_code'],
                    'website_name' => $row['website_name'],
                    'website_is_default' => (bool) $row['website_is_default'],
                    'store_groups' => [],
                ];
            }

            if ($gid === null) {
                continue;
            }

            if (! isset($websites[$wid]['store_groups'][$gid])) {
                $websites[$wid]['store_groups'][$gid] = [
                    'group_id' => $gid,
                    'group_code' => $row['group_code'],
                    'group_name' => $row['group_name'],
                    'root_category_id' => $row['root_category_id'],
                    'default_store_id' => $row['default_store_id'],
                    'store_views' => [],
                ];
            }

            if ($sid === null) {
                continue;
            }

            $websites[$wid]['store_groups'][$gid]['store_views'][] = [
                'store_id' => $sid,
                'store_code' => $row['store_code'],
                'store_name' => $row['store_name'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        foreach ($websites as &$website) {
            $website['store_groups'] = array_values($website['store_groups']);
        }

        return $websites;
    }
}
