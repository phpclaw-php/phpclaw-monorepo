<?php

declare(strict_types=1);

use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\PsIdentityResolver;

if (! defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/AdminPhpClawBaseController.php';

/**
 * phpClaw Analytics admin controller: displays conversation and message usage statistics.
 */
final class AdminPhpClawAnalyticsController extends AdminPhpClawBaseController
{
    /**
     * Set the page title after the parent bootstrap.
     */
    public function __construct()
    {
        parent::__construct();
        $this->meta_title = $this->trans('phpClaw: Analytics', [], 'Modules.Phpclaw.Admin');
    }

    /**
     * Render the Analytics page template with conversation and message statistics.
     *
     * @return void
     */
    public function initContent(): void
    {
        parent::initContent();

        $plugin = $this->getPlugin();
        $db = $this->getDb();
        $prefix = _DB_PREFIX_;

        $stats = $this->gatherStats($db, $prefix);

        $this->context->smarty->assign([
            'stats' => $stats,
            'phpclaw_is_configured' => $plugin->isConfigured(),
            'url_settings' => $this->context->link->getAdminLink('AdminPhpClawSettings'),
            'url_debug' => $this->context->link->getAdminLink('AdminPhpClawDebug'),
        ]);

        $this->content .= $this->context->smarty->fetch('file:'._PS_MODULE_DIR_.'phpclaw/views/templates/admin/analytics.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Query aggregate statistics from phpClaw tables.
     *
     * @param  PsDbInterface  $db  Native database handle.
     * @param  string  $prefix  Table prefix (e.g. 'ps_').
     * @return array{total_conversations: int, total_messages: int, active_24h: int}
     */
    private function gatherStats(PsDbInterface $db, string $prefix): array
    {
        $manageAll = PsIdentityResolver::manageAll();
        $employeeId = PsIdentityResolver::actingEmployeeId();

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));

            if ($manageAll) {
                $totalConversations = (int) ($db->query(
                    "SELECT COUNT(*) AS c FROM `{$prefix}phpclaw_conversations`"
                )->row['c'] ?? 0);

                $totalMessages = (int) ($db->query(
                    "SELECT COUNT(*) AS c FROM `{$prefix}phpclaw_messages` m"
                    ." INNER JOIN `{$prefix}phpclaw_conversations` c ON c.`id` = m.`conversation_id`"
                )->row['c'] ?? 0);

                $active24h = (int) ($db->query(
                    "SELECT COUNT(DISTINCT `id`) AS c FROM `{$prefix}phpclaw_conversations` WHERE `updated_at` >= ?",
                    [$cutoff],
                )->row['c'] ?? 0);
            } else {
                $totalConversations = (int) ($db->query(
                    "SELECT COUNT(*) AS c FROM `{$prefix}phpclaw_conversations` WHERE `id_employee` = ?",
                    [$employeeId],
                )->row['c'] ?? 0);

                $totalMessages = (int) ($db->query(
                    "SELECT COUNT(*) AS c FROM `{$prefix}phpclaw_messages` m"
                    ." INNER JOIN `{$prefix}phpclaw_conversations` c ON c.`id` = m.`conversation_id`"
                    .' WHERE c.`id_employee` = ?',
                    [$employeeId],
                )->row['c'] ?? 0);

                $active24h = (int) ($db->query(
                    "SELECT COUNT(DISTINCT `id`) AS c FROM `{$prefix}phpclaw_conversations`"
                    .' WHERE `updated_at` >= ? AND `id_employee` = ?',
                    [$cutoff, $employeeId],
                )->row['c'] ?? 0);
            }
        } catch (Throwable $e) {
            if (class_exists(PrestaShopLogger::class)) {
                PrestaShopLogger::addLog('phpClaw Analytics: '.$e->getMessage(), 3);
            }

            return ['total_conversations' => 0, 'total_messages' => 0, 'active_24h' => 0];
        }

        return [
            'total_conversations' => $totalConversations,
            'total_messages' => $totalMessages,
            'active_24h' => $active24h,
        ];
    }
}
