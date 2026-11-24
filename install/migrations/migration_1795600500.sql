-- map content: derelict fields, pirate mothership (world boss) and expedition story chains.
-- derelicts and the mothership are rows of pirate_bases (owner 0) so the pirate base missions (spy, attack, recycling) work unchanged;
-- kind 0 = personal pirate base (weekly spawner), 1 = derelict field, 2 = mothership. planet = the galaxy slot (1..max_planets) of kinds 1 and 2.
ALTER TABLE `%PREFIX%pirate_bases`
  ADD COLUMN `kind` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `universe`,
  ADD COLUMN `planet` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `system`,
  ADD KEY `kind_pos` (`universe`, `kind`, `galaxy`, `system`);

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_derelicts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `base_id` int(11) unsigned NOT NULL,
  `galaxy` tinyint(3) unsigned NOT NULL,
  `system` smallint(5) unsigned NOT NULL,
  `planet` tinyint(3) unsigned NOT NULL,
  `metal` double NOT NULL DEFAULT 0,
  `crystal` double NOT NULL DEFAULT 0,
  `created_at` int(11) unsigned NOT NULL,
  `expires_at` int(11) unsigned NOT NULL,
  `removed_at` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `universe_created` (`universe`, `created_at`),
  KEY `live` (`removed_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_motherships` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `base_id` int(11) unsigned NOT NULL,
  `galaxy` tinyint(3) unsigned NOT NULL,
  `system` smallint(5) unsigned NOT NULL,
  `planet` tinyint(3) unsigned NOT NULL,
  `start_value` double NOT NULL DEFAULT 0,
  `loot_metal` double NOT NULL DEFAULT 0,
  `loot_crystal` double NOT NULL DEFAULT 0,
  `loot_deuterium` double NOT NULL DEFAULT 0,
  `min_players` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `spawned_at` int(11) unsigned NOT NULL,
  `expires_at` int(11) unsigned NOT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ended_at` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `universe_status` (`universe`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_mothership_damage` (
  `mothership_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `damage` double NOT NULL DEFAULT 0,
  `fights` int(11) unsigned NOT NULL DEFAULT 0,
  `paid_metal` double NOT NULL DEFAULT 0,
  `paid_crystal` double NOT NULL DEFAULT 0,
  `paid_deuterium` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`mothership_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%eng_stories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `step` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `galaxy` tinyint(3) unsigned NOT NULL,
  `system` smallint(5) unsigned NOT NULL,
  `planet` tinyint(3) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `expires_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
