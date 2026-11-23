-- in-game chat (MODULE_CHAT, off by default: a missing module row counts as off): separate from the player messages
CREATE TABLE IF NOT EXISTS `%PREFIX%chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `channel` varchar(16) NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `username` varchar(32) NOT NULL DEFAULT '',
  `ally_tag` varchar(20) DEFAULT NULL,
  `message` varchar(500) NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `original_message` varchar(500) DEFAULT NULL,
  `deleted` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `deleted_by` int(11) unsigned DEFAULT NULL,
  `deleted_at` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `channel` (`universe`, `channel`, `id`),
  KEY `created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- reports of chat messages by players (reviewed in the admin panel, /admin/chat)
CREATE TABLE IF NOT EXISTS `%PREFIX%chat_reports` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `reporter_id` int(11) unsigned NOT NULL,
  `reason` varchar(16) NOT NULL,
  `comment` varchar(200) NOT NULL DEFAULT '',
  `status` enum('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  `handled_by` int(11) unsigned DEFAULT NULL,
  `handled_at` int(11) unsigned DEFAULT NULL,
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_reporter` (`message_id`, `reporter_id`),
  KEY `status` (`status`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `%PREFIX%users` ADD COLUMN `chat_muted_until` int(11) unsigned NOT NULL DEFAULT 0;
