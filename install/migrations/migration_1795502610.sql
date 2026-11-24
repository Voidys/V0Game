-- bot grudges: decaying hostility of a bot against a player (revenge raids)
ALTER TABLE `%PREFIX%bot_relations` ADD COLUMN `grudge` DOUBLE NOT NULL DEFAULT 0 AFTER `balance`, ADD COLUMN `grudge_at` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `grudge`;
