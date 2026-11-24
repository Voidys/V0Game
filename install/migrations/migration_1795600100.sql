-- engagement: universe events (scheduler) and catch-up boost for late joiners
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_events` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `preset` varchar(24) NOT NULL DEFAULT 'custom',
  `title` varchar(80) NOT NULL DEFAULT '',
  `modifiers` longtext NOT NULL,
  `starts_at` int(11) unsigned NOT NULL,
  `ends_at` int(11) unsigned NOT NULL,
  `announced_start` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `announced_end` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uni_time` (`universe`, `starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_catchup` (
  `user_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `ended_at` int(11) unsigned NOT NULL DEFAULT 0,
  `blocked` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `check_until` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_catchup_avg` (
  `universe` tinyint(3) unsigned NOT NULL,
  `avg_points` double NOT NULL DEFAULT 0,
  `computed_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
