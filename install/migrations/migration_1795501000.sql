ALTER TABLE `%PREFIX%alliance_ranks` ADD COLUMN `TARGETS` tinyint(3) unsigned NOT NULL DEFAULT '0', ADD COLUMN `ACTIVITY` tinyint(3) unsigned NOT NULL DEFAULT '0', ADD COLUMN `WIKI` tinyint(3) unsigned NOT NULL DEFAULT '0';
ALTER TABLE `%PREFIX%diplo` ADD COLUMN `expires_at` int(11) unsigned DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `%PREFIX%alliance_targets` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) unsigned NOT NULL,
  `galaxy` tinyint(3) unsigned NOT NULL,
  `system` smallint(5) unsigned NOT NULL,
  `planet` tinyint(3) unsigned NOT NULL,
  `planet_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `note` varchar(255) NOT NULL DEFAULT '',
  `added_by` int(11) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `claimed_by` int(11) unsigned DEFAULT NULL,
  `claimed_until` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coords` (`alliance_id`,`galaxy`,`system`,`planet`,`planet_type`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `%PREFIX%alliance_pages` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) unsigned NOT NULL,
  `title` varchar(100) NOT NULL,
  `body` text,
  `updated_by` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `alliance_id` (`alliance_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
