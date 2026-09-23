-- phpClaw install SQL for PrestaShop 8.x / 9.x.
-- PREFIX_ is replaced with _DB_PREFIX_ by the module installer at install time.
-- Column `lookup_key` avoids the MySQL reserved word `key`.

CREATE TABLE IF NOT EXISTS `PREFIX_phpclaw_conversations` (
    `id`          CHAR(26)      NOT NULL,
    `namespace`   VARCHAR(100)  NOT NULL DEFAULT 'default',
    `id_employee` INT UNSIGNED  DEFAULT NULL,
    `title`       VARCHAR(255)  DEFAULT NULL,
    `metadata`    TEXT          DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL,
    `updated_at`  DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ns_created` (`namespace`, `created_at`),
    KEY `idx_employee_ns_updated` (`id_employee`, `namespace`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- content is only persisted when store_messages = true (privacy opt-in).
CREATE TABLE IF NOT EXISTS `PREFIX_phpclaw_messages` (
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

CREATE TABLE IF NOT EXISTS `PREFIX_phpclaw_memory` (
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

-- REST API tokens are issued per employee. Only the sha256 hash is stored.
CREATE TABLE IF NOT EXISTS `PREFIX_phpclaw_api_token` (
    `id_api_token` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_employee`  INT UNSIGNED  NOT NULL,
    `label`        VARCHAR(64)   NOT NULL DEFAULT '',
    `token_hash`   CHAR(64)      NOT NULL,
    `created_at`   DATETIME      NOT NULL,
    `last_used_at` DATETIME      DEFAULT NULL,
    PRIMARY KEY (`id_api_token`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_employee` (`id_employee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `PREFIX_access` (`id_profile`, `id_authorization_role`)
SELECT p.`id_profile`, r.`id_authorization_role`
FROM `PREFIX_profile` p
CROSS JOIN `PREFIX_authorization_role` r
WHERE r.`slug` LIKE 'ROLE_MOD_TAB_ADMINPHPCLAW%'
AND r.`slug` NOT LIKE 'ROLE_MOD_TAB_ADMINPHPCLAWSETTINGS%'
AND (r.`slug` LIKE '%_READ' OR r.`slug` LIKE '%_UPDATE');

INSERT IGNORE INTO `PREFIX_module_access` (`id_profile`, `id_authorization_role`)
SELECT p.`id_profile`, r.`id_authorization_role`
FROM `PREFIX_profile` p
CROSS JOIN `PREFIX_authorization_role` r
WHERE r.`slug` IN ('ROLE_MOD_MODULE_PHPCLAW_READ', 'ROLE_MOD_MODULE_PHPCLAW_UPDATE');
