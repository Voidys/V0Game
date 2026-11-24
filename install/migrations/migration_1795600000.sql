-- engagement features: one cronjob ticks events, contracts, seasons, bounties, wars, goals, derelicts and the mothership
INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`) VALUES
(NULL, 'engagement', 1, '*/5', '*', '*', '*', '*', 'EngagementCronjob', 0, NULL);

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_cosmetics` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `kind` varchar(8) NOT NULL,
  `code` varchar(48) NOT NULL,
  `source` varchar(64) NOT NULL DEFAULT '',
  `granted_at` int(11) unsigned NOT NULL,
  `active` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_kind_code` (`user_id`, `kind`, `code`),
  KEY `user_active` (`user_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
