<?php

declare(strict_types=1);

/**
 * phpClaw Uninstall - runs when the plugin is deleted from WP admin.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tableMessages = $wpdb->prefix.'phpclaw_messages';
$tableConversations = $wpdb->prefix.'phpclaw_conversations';
$tableMemory = $wpdb->prefix.'phpclaw_memory';

$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $tableMessages));
$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $tableConversations));
$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $tableMemory));

$wpdb->query(
    $wpdb->prepare(
        'DELETE FROM %i WHERE option_name LIKE %s',
        $wpdb->options,
        'phpclaw\_%',
    )
);
