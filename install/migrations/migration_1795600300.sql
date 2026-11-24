-- engagement: seasons (monthly ladder, no economy reset)
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_seasons` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `number` int(11) unsigned NOT NULL,
  `starts_at` int(11) unsigned NOT NULL,
  `ends_at` int(11) unsigned NOT NULL,
  `closed_at` int(11) unsigned DEFAULT NULL,
  `rewarded_at` int(11) unsigned DEFAULT NULL,
  `archive` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `universe_number` (`universe`, `number`),
  KEY `universe_closed` (`universe`, `closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_season_scores` (
  `season_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `category` varchar(16) NOT NULL,
  `score` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`season_id`, `user_id`, `category`),
  KEY `season_category_score` (`season_id`, `category`, `score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_season_pairs` (
  `season_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `opponent_id` int(11) unsigned NOT NULL,
  `fights` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`season_id`, `user_id`, `opponent_id`),
  KEY `opponent` (`opponent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
