-- phpClaw module uninstall SQL for PrestaShop 8.x / 9.x.
-- Drops tables in reverse dependency order.

DROP TABLE IF EXISTS `PREFIX_phpclaw_api_token`;
DROP TABLE IF EXISTS `PREFIX_phpclaw_memory`;
DROP TABLE IF EXISTS `PREFIX_phpclaw_messages`;
DROP TABLE IF EXISTS `PREFIX_phpclaw_conversations`;
