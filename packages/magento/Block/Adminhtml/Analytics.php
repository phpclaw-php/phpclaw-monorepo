<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use PhpClaw\Magento\Model\IdentityResolver;
use Psr\Log\LoggerInterface;

/**
 * Block for the PhpClaw analytics page (usage stats).
 */
// non-final: Magento interceptor required
class Analytics extends Template
{
    private const CACHE_TAG = 'PHPCLAW';

    private const TTL_SECONDS = 300;

    /**
     * Bind the block context and database connection this block reads counts through.
     *
     * @param  Template\Context  $context  Magento block context.
     * @param  ResourceConnection  $resource  Database connection for counting rows.
     * @param  CacheInterface  $cache  Magento cache pool used to cache analytics counts.
     * @param  IdentityResolver  $identity  Resolves the acting admin and whether counts span every user.
     * @param  LoggerInterface|null  $logger  PSR-3 logger used when a count query fails and zeroes would otherwise look real.
     * @param  array<string, mixed>  $data  Optional block data passed from layout XML.
     * @return void
     */
    public function __construct(
        Template\Context $context,
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly IdentityResolver $identity,
        private readonly ?LoggerInterface $logger = null,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return aggregated usage counts for the analytics template.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $stats = [
            'conversations' => 0,
            'messages' => 0,
            'active_24h' => 0,
        ];

        try {
            $connection = $this->resource->getConnection();
            $tblConv = $this->resource->getTableName('phpclaw_conversations');
            $tblMsg = $this->resource->getTableName('phpclaw_messages');

            $manageAll = $this->identity->manageAll();
            $actingUserId = $this->identity->actingUserId();
            $ownerSql = $manageAll ? '' : ' AND `admin_user_id` = ?';
            $ownerBind = $manageAll ? [] : [$actingUserId];

            if ($connection->isTableExists($tblConv)) {
                $stats['conversations'] = $this->cachedCount(
                    'phpclaw_analytics_total_conversations',
                    static fn () => (int) $connection->fetchOne(
                        'SELECT COUNT(*) FROM '.$tblConv.' WHERE 1'.$ownerSql,
                        $ownerBind,
                    ),
                );

                $cutoff = date('Y-m-d H:i:s', time() - 86400);
                $stats['active_24h'] = $this->cachedCount(
                    'phpclaw_analytics_active_24h',
                    static fn () => (int) $connection->fetchOne(
                        'SELECT COUNT(*) FROM '.$tblConv.' WHERE updated_at >= ?'.$ownerSql,
                        array_merge([$cutoff], $ownerBind),
                    ),
                );
            }

            if ($connection->isTableExists($tblMsg)) {
                $stats['messages'] = $this->cachedCount(
                    'phpclaw_analytics_total_messages',
                    static fn () => (int) ($manageAll
                        ? $connection->fetchOne(
                            'SELECT COUNT(*) FROM '.$tblMsg.' m
                             INNER JOIN '.$tblConv.' c ON c.id = m.conversation_id',
                        )
                        : $connection->fetchOne(
                            'SELECT COUNT(*) FROM '.$tblMsg.' m
                             INNER JOIN '.$tblConv.' c ON c.id = m.conversation_id
                             WHERE c.admin_user_id = ?',
                            [$actingUserId],
                        )),
                );
            }
        } catch (\Exception $e) {
            $this->logger?->warning('phpclaw: analytics counts could not be read, showing zeroes', ['exception' => $e]);
        }

        return $stats;
    }

    /**
     * Whether the viewer sees site-wide counts rather than only their own.
     *
     * @return bool
     */
    public function isManageAll(): bool
    {
        return $this->identity->manageAll();
    }

    /**
     * Load a count from cache, or compute and store it on miss.
     *
     * @param  string  $key  Cache identifier, scoped to the acting identity before use.
     * @param  callable  $query  Zero-argument callable that returns the int count on miss.
     * @return int Cached or freshly computed count.
     */
    private function cachedCount(string $key, callable $query): int
    {
        $scopedKey = $key.'_'.($this->identity->manageAll() ? 'all' : (string) $this->identity->actingUserId());

        $hit = $this->cache->load($scopedKey);
        if ($hit !== false) {
            return (int) $hit;
        }

        $value = $query();
        $this->cache->save((string) $value, $scopedKey, [self::CACHE_TAG], self::TTL_SECONDS);

        return $value;
    }
}
