-- OC_ is replaced with your actual DB_PREFIX by Plugin::runMigration() at install time.
-- Column `lookup_key` avoids the MySQL reserved word `key`.

CREATE TABLE IF NOT EXISTS `OC_phpclaw_conversations` (
    `id`         CHAR(26)      NOT NULL,
    `namespace`  VARCHAR(100)  NOT NULL DEFAULT 'default',
    `owner_id`   INT UNSIGNED  NULL DEFAULT NULL,
    `title`      VARCHAR(255)  DEFAULT NULL,
    `metadata`   TEXT          DEFAULT NULL,
    `created_at` DATETIME      NOT NULL,
    `updated_at` DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ns_created` (`namespace`, `created_at`),
    KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OC_phpclaw_messages` (
    `id`              CHAR(26)      NOT NULL,
    `conversation_id` CHAR(26)      NOT NULL,
    `role`            VARCHAR(20)   NOT NULL,
    `content`         LONGTEXT      DEFAULT NULL,
    `tool_name`       VARCHAR(255)  DEFAULT NULL,
    `tool_input`      TEXT          DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_conv_created` (`conversation_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OC_phpclaw_memory` (
    `id`         CHAR(26)      NOT NULL,
    `namespace`  VARCHAR(100)  NOT NULL DEFAULT 'default',
    `lookup_key` VARCHAR(255)  NOT NULL,
    `value`      LONGTEXT      NOT NULL,
    `expires_at` DATETIME      DEFAULT NULL,
    `created_at` DATETIME      NOT NULL,
    `updated_at` DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ns_key` (`namespace`, `lookup_key`),
    KEY `idx_ns_expires` (`namespace`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
