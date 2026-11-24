-- engagement: weekly contracts, alliance goals, returning-player welcome
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_contracts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `week` int(11) unsigned NOT NULL,
  `slot` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `kind` varchar(32) NOT NULL,
  `target` bigint(20) unsigned NOT NULL,
  `progress` bigint(20) unsigned NOT NULL DEFAULT 0,
  `rerolled` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` int(11) unsigned NOT NULL,
  `completed_at` int(11) unsigned DEFAULT NULL,
  `claimed_at` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_week_slot` (`user_id`, `week`, `slot`),
  UNIQUE KEY `user_week_kind` (`user_id`, `week`, `kind`),
  KEY `week` (`week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_alliance_goals` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `week` int(11) unsigned NOT NULL,
  `kind` varchar(32) NOT NULL,
  `target` bigint(20) unsigned NOT NULL,
  `progress` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_by` int(11) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `completed_at` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alliance_week` (`alliance_id`, `week`),
  KEY `alliance_done` (`alliance_id`, `completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_returns` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `away_since` int(11) unsigned NOT NULL,
  `returned_at` int(11) unsigned NOT NULL,
  `protected_until` int(11) unsigned NOT NULL DEFAULT 0,
  `seen` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_away` (`user_id`, `away_since`),
  KEY `user_returned` (`user_id`, `returned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
