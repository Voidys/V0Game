-- derelict fields: collected metal + crystal per player and day (daily collection cap)
CREATE TABLE IF NOT EXISTS `%PREFIX%eng_derelict_collect` (
  `user_id` int(11) unsigned NOT NULL,
  `day` date NOT NULL,
  `amount` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stored old defaults move to the new, smaller defaults (values an admin changed stay as they are)
UPDATE `%PREFIX%config_universe` SET `value` = '100000' WHERE `key` = 'derelicts_min' AND `value` = '200000';
UPDATE `%PREFIX%config_universe` SET `value` = '600000' WHERE `key` = 'derelicts_max' AND `value` = '2000000';
UPDATE `%PREFIX%config_universe` SET `value` = '1' WHERE `key` = 'derelicts_per_galaxy' AND `value` = '2';
