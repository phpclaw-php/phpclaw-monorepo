<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Admin;

/**
 * phpClaw Analytics admin page.
 */
final class AnalyticsPage
{
    /**
     * Register the Analytics submenu page under the phpClaw admin menu.
     *
     * @return void
     */
    public static function register(): void
    {
        add_submenu_page(
            parent_slug: 'phpclaw',
            page_title: 'phpClaw Analytics',
            menu_title: 'Analytics',
            capability: 'phpclaw_use_chat',
            menu_slug: 'phpclaw-analytics',
            callback: [self::class, 'render'],
        );
    }

    /**
     * Render the Analytics page with 3 stat cards, refusing anyone without phpclaw_use_chat.
     *
     * @return void
     */
    public static function render(): void
    {
        if (! current_user_can('phpclaw_use_chat')) {
            wp_die(esc_html__('Insufficient permissions.', 'phpclaw'));
        }

        global $wpdb;

        $convTable = $wpdb->prefix.'phpclaw_conversations';
        $msgTable = $wpdb->prefix.'phpclaw_messages';

        $manageAll = current_user_can('phpclaw_manage_all_conversations');
        $actingUserId = get_current_user_id();
        $scope = $manageAll ? 'all' : (string) $actingUserId;

        $totalConversations = wp_cache_get('analytics_total_conversations_'.$scope, 'phpclaw');
        if ($totalConversations === false) {
            if ($manageAll) {
                $totalConversations = (int) $wpdb->get_var(
                    $wpdb->prepare('SELECT COUNT(*) FROM %i', $convTable),
                );
            } else {
                $totalConversations = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i WHERE user_id = %d',
                        $convTable,
                        $actingUserId,
                    ),
                );
            }

            wp_cache_set('analytics_total_conversations_'.$scope, $totalConversations, 'phpclaw', 300);
        }

        $totalMessages = wp_cache_get('analytics_total_messages_'.$scope, 'phpclaw');
        if ($totalMessages === false) {
            if ($manageAll) {
                $totalMessages = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i m
                         INNER JOIN %i c ON c.id = m.conversation_id',
                        $msgTable,
                        $convTable,
                    ),
                );
            } else {
                $totalMessages = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i m
                         INNER JOIN %i c ON c.id = m.conversation_id
                         WHERE c.user_id = %d',
                        $msgTable,
                        $convTable,
                        $actingUserId,
                    ),
                );
            }

            wp_cache_set('analytics_total_messages_'.$scope, $totalMessages, 'phpclaw', 300);
        }

        $activeConversations = wp_cache_get('analytics_active_conversations_'.$scope, 'phpclaw');
        if ($activeConversations === false) {
            if ($manageAll) {
                $activeConversations = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i WHERE updated_at >= NOW() - INTERVAL 1 DAY',
                        $convTable,
                    ),
                );
            } else {
                $activeConversations = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i
                         WHERE updated_at >= NOW() - INTERVAL 1 DAY AND user_id = %d',
                        $convTable,
                        $actingUserId,
                    ),
                );
            }

            wp_cache_set('analytics_active_conversations_'.$scope, $activeConversations, 'phpclaw', 300);
        }

        $cards = [
            [
                'label' => 'Total Conversations',
                'value' => number_format($totalConversations),
                'icon' => '💬',
                'colorClass' => 'pc-analytics-value--blue',
            ],
            [
                'label' => 'Total Messages',
                'value' => number_format($totalMessages),
                'icon' => '📝',
                'colorClass' => 'pc-analytics-value--green',
            ],
            [
                'label' => 'Active (Last 24 h)',
                'value' => number_format($activeConversations),
                'icon' => '🟢',
                'colorClass' => 'pc-analytics-value--orange',
            ],
        ];
        ?>
        <div class="wrap">
            <h1 class="pc-page-header">🤖 <?= esc_html__('phpClaw Analytics', 'phpclaw') ?></h1>

            <div class="pc-analytics-grid">
                <?php foreach ($cards as $card) { ?>
                <div class="pc-card pc-analytics-card">
                    <div class="pc-analytics-icon"><?= esc_html($card['icon']) ?></div>
                    <div class="pc-analytics-value <?= esc_attr($card['colorClass']) ?>">
                        <?= esc_html($card['value']) ?>
                    </div>
                    <div class="pc-analytics-label">
                        <?= esc_html($card['label']) ?>
                    </div>
                </div>
                <?php } ?>
            </div>

            <div class="pc-card pc-mt-24">
                <p><strong><?= esc_html__('Note:', 'phpclaw') ?></strong>
                <?= $manageAll
                    ? esc_html__('Analytics reflect all conversations and messages recorded on this site.', 'phpclaw')
                    : esc_html__('Analytics reflect only your own conversations and messages.', 'phpclaw') ?></p>
            </div>

        </div>

        <?php AdminFooter::renderCommunityCard(); ?>
        <?php
    }
}
