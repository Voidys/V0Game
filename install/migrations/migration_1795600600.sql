-- engagement: relics (optional equipment found on expeditions, equipped per planet)
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_relics` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `set_code` varchar(24) NOT NULL,
  `slot` tinyint(3) unsigned NOT NULL,
  `rarity` tinyint(3) unsigned NOT NULL,
  `main_stat` varchar(24) NOT NULL,
  `main_value` decimal(6,2) NOT NULL,
  `subs` text NOT NULL,
  `planet_id` int(11) unsigned DEFAULT NULL,
  `locked` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `moved_at` int(11) unsigned NOT NULL DEFAULT 0,
  `found_at` int(11) unsigned NOT NULL DEFAULT 0,
  `source` varchar(24) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `planet_slot` (`planet_id`, `slot`),
  KEY `user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_relic_wallet` (
  `user_id` int(11) unsigned NOT NULL,
  `dust` int(11) unsigned NOT NULL DEFAULT 0,
  `since_rare` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_relic_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `relic_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `action` varchar(16) NOT NULL,
  `detail` varchar(255) NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_time` (`user_id`, `created_at`),
  KEY `uni_time` (`universe`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_relic_config` (
  `universe` tinyint(3) unsigned NOT NULL,
  `json` longtext NOT NULL,
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_relic_fleet` (
  `fleet_id` bigint(20) unsigned NOT NULL,
  `factor` double NOT NULL DEFAULT 1,
  PRIMARY KEY (`fleet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
