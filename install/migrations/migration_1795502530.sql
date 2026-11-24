-- Achievements: state for "90 days in motion" (2), "Market maker" (66) and the 24 h fuel record (18)
ALTER TABLE `%PREFIX%users` ADD `fleets_moving_since` int(11) unsigned NOT NULL DEFAULT 0;
ALTER TABLE `%PREFIX%users` ADD `market_sales` int(11) unsigned NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `%PREFIX%fuel_log` (
  `planet_id` int(11) unsigned NOT NULL,
  `hour` int(11) unsigned NOT NULL,
  `amount` double(50,0) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`planet_id`, `hour`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
