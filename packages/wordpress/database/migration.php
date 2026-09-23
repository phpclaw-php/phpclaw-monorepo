<?php

declare(strict_types=1);

/**
 * phpClaw WordPress database migration.
 */
if (! defined('PHPCLAW_DB_VERSION')) {
    define('PHPCLAW_DB_VERSION', '1.0.0');
}

if (! function_exists('phpclaw_run_migration')) {
    /**
     * Create the three phpClaw tables with dbDelta() and record the schema version.
     *
     * @return void
     */
    function phpclaw_run_migration(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        if (! function_exists('dbDelta')) {
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
        }

        $memoryTable = $wpdb->prefix.'phpclaw_memory';

        $sql = "CREATE TABLE {$memoryTable} (
            id CHAR(26) NOT NULL,
            namespace VARCHAR(100) NOT NULL DEFAULT 'default',
            lookup_key VARCHAR(255) NOT NULL,
            value LONGTEXT NOT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY namespace_key (namespace,lookup_key),
            KEY namespace_expires_at (namespace,expires_at)
        ) {$charsetCollate};";

        dbDelta($sql);

        $convsTable = $wpdb->prefix.'phpclaw_conversations';

        $sql = "CREATE TABLE {$convsTable} (
            id CHAR(26) NOT NULL,
            namespace VARCHAR(100) NOT NULL DEFAULT 'default',
            title VARCHAR(255) NULL,
            metadata JSON NULL,
            user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY namespace_created_at (namespace,created_at),
            KEY user_id_namespace_updated (user_id,namespace,updated_at)
        ) {$charsetCollate};";

        dbDelta($sql);

        $msgsTable = $wpdb->prefix.'phpclaw_messages';

        $sql = "CREATE TABLE {$msgsTable} (
            id CHAR(26) NOT NULL,
            conversation_id CHAR(26) NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NULL,
            tool_name VARCHAR(255) NULL,
            tool_input TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY conversation_id_created_at (conversation_id,created_at)
        ) {$charsetCollate};";

        dbDelta($sql);

        update_option('phpclaw_db_version', PHPCLAW_DB_VERSION, false);
    }
}
