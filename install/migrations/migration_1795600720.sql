-- engagement: seasons, per-user daily cap on full-weight fights
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_season_daily` (
  `season_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `day` int(11) unsigned NOT NULL,
  `fights` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`season_id`, `user_id`, `day`),
  KEY `user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
