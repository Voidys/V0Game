-- engagement: bounties, alliance wars (battle feed needs no table)
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_bounties` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `placer_id` int(11) unsigned NOT NULL,
  `target_id` int(11) unsigned NOT NULL,
  `metal` bigint(20) unsigned NOT NULL DEFAULT 0,
  `crystal` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deuterium` bigint(20) unsigned NOT NULL DEFAULT 0,
  `fee` bigint(20) unsigned NOT NULL DEFAULT 0,
  `required_value` double NOT NULL DEFAULT 0,
  `created_at` int(11) unsigned NOT NULL,
  `expires_at` int(11) unsigned NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'open',
  `hunter_id` int(11) unsigned DEFAULT NULL,
  `closed_at` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `open_list` (`universe`, `status`, `expires_at`),
  KEY `target_status` (`target_id`, `status`),
  KEY `placer_status` (`placer_id`, `status`),
  KEY `pair` (`placer_id`, `target_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_bounty_progress` (
  `bounty_id` int(11) unsigned NOT NULL,
  `hunter_id` int(11) unsigned NOT NULL,
  `credit` double NOT NULL DEFAULT 0,
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`bounty_id`, `hunter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_wars` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `ally_a` int(11) unsigned NOT NULL,
  `ally_b` int(11) unsigned NOT NULL,
  `name_a` varchar(96) NOT NULL DEFAULT '',
  `name_b` varchar(96) NOT NULL DEFAULT '',
  `started_at` int(11) unsigned NOT NULL,
  `ends_at` int(11) unsigned DEFAULT NULL,
  `score_a` double NOT NULL DEFAULT 0,
  `score_b` double NOT NULL DEFAULT 0,
  `loot_a` double NOT NULL DEFAULT 0,
  `loot_b` double NOT NULL DEFAULT 0,
  `fights` int(11) unsigned NOT NULL DEFAULT 0,
  `ended_at` int(11) unsigned DEFAULT NULL,
  `result` varchar(8) DEFAULT NULL,
  `open_pair` varchar(24) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `open_pair` (`open_pair`),
  KEY `universe_ended` (`universe`, `ended_at`),
  KEY `ally_a` (`ally_a`),
  KEY `ally_b` (`ally_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
