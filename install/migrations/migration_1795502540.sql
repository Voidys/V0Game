-- achievement 1: attacks of the current night (date as yyyymmdd; count of attacks without a win, -1 = won this night)
ALTER TABLE `%PREFIX%users` ADD `night_attacks_date` int(11) unsigned NOT NULL DEFAULT 0, ADD `night_attacks` smallint(6) NOT NULL DEFAULT 0;
