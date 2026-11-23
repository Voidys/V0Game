/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */


SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
CREATE TABLE `%PREFIX%accounts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `accountname` varchar(32) NOT NULL DEFAULT '',
  `password` varchar(60) NOT NULL DEFAULT '',
  `email` varchar(64) NOT NULL DEFAULT '',
  `email_2` varchar(64) NOT NULL DEFAULT '',
  `failed_logins` tinyint(1) NOT NULL DEFAULT '0',
  `lang` varchar(2) NOT NULL DEFAULT 'de',
  `authattack` tinyint(1) NOT NULL DEFAULT '0',
  `authlevel` tinyint(1) NOT NULL DEFAULT '0',
  `rights` text,
  `discord_id` varchar(25) NOT NULL DEFAULT '',
  `discord_hook` text,
  `account_lastip` varchar(40) NOT NULL DEFAULT '',
  `ip_at_reg` varchar(40) NOT NULL DEFAULT '',
  `register_time` int(11) NOT NULL DEFAULT '0',
  `onlinetime` int(11) NOT NULL DEFAULT '0',
  `dpath` varchar(20) NOT NULL DEFAULT 'v0game',
  `css_dpath` varchar(20) NOT NULL DEFAULT 'v0game',
  `timezone` varchar(32) NOT NULL DEFAULT 'Europe/London',
  `db_deaktjava` int(11) NOT NULL DEFAULT '0',
  `bana` tinyint(1) NOT NULL DEFAULT '0',
  `banaday` int(11) NOT NULL DEFAULT '0',
  `setmail` int(11) NOT NULL DEFAULT '0',
  `referralID` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_userids ON `%PREFIX%accounts` (`email`);
CREATE UNIQUE INDEX uniq_accounts_email ON `%PREFIX%accounts` (`email`);

CREATE TABLE `%PREFIX%accounts_banned` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) unsigned NOT NULL,
  `who` varchar(64) NOT NULL DEFAULT '',
  `theme` varchar(500) NOT NULL,
  `time` int(11) NOT NULL DEFAULT '0',
  `longer` int(11) NOT NULL DEFAULT '0',
  `author` varchar(64) NOT NULL DEFAULT '',
  `email` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY `ID` (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%accounts_social` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(11) unsigned NOT NULL,
  `provider` varchar(16) NOT NULL,
  `provider_uid` varchar(191) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `created_at` int(11) NOT NULL DEFAULT '0',
  `last_login_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_uid` (`provider`, `provider_uid`),
  KEY `account_id` (`account_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%accounts_to_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `user_id` int NOT NULL,
  `end_time` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `account_id` (`account_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%accounts_valid` (
 `validationID` int(11) unsigned NOT NULL AUTO_INCREMENT,
 `accountname` varchar(64) NOT NULL,
 `validationKey` varchar(32) NOT NULL,
 `password` varchar(60) NOT NULL,
 `email` varchar(64) NOT NULL,
 `date` int(11) NOT NULL,
 `ip` varchar(40) NOT NULL,
 `language` varchar(3) NOT NULL,
 `referralID` int(11) DEFAULT NULL,
 `failed_logins` tinyint(1) NOT NULL DEFAULT '0',
 `timezone` varchar(32) NOT NULL DEFAULT 'Europe/London',
 PRIMARY KEY (`validationID`,`validationKey`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

CREATE TABLE `%PREFIX%advanced_stats` (
  `userId` int(10) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `statkey` varchar(50) NOT NULL,
  `statvalue` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`userId`,`universe`,`statkey`),
  INDEX `user_stat` (`userId`,`statkey`),
  INDEX `universe_stat` (`universe`,`statkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%advanced_stats_history` (
  `timestamp` int(11) NOT NULL DEFAULT '0',
  `userId` int(10) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `statkey` varchar(50) NOT NULL,
  `statvalue` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`timestamp`,`userId`,`universe`,`statkey`),
  INDEX `user_stat` (`userId`,`statkey`),
  INDEX `universe_stat` (`universe`,`statkey`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%advanced_stats_universe` (
  `universe` tinyint(3) unsigned NOT NULL,
  `statkey` varchar(50) NOT NULL,
  `userId` int(10) unsigned NOT NULL,
  `username` varchar(32) NOT NULL DEFAULT '',
  `statvalue` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`universe`,`statkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%aks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(32) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `target` int(11) unsigned NOT NULL,
  `ankunft` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%alliance` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ally_name` varchar(50) DEFAULT '',
  `ally_tag` varchar(20) DEFAULT '',
  `ally_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `ally_register_time` int(11) NOT NULL DEFAULT '0',
  `ally_description` text,
  `ally_web` varchar(255) DEFAULT '',
  `ally_text` text,
  `ally_image` varchar(255) DEFAULT '',
  `ally_request` varchar(1000) DEFAULT NULL,
  `ally_request_notallow` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `ally_request_min_points` bigint(20) unsigned NOT NULL DEFAULT '0',
  `ally_owner_range` varchar(32) DEFAULT '',
  `ally_members` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ally_stats` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `ally_diplo` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `ally_universe` tinyint(3) unsigned NOT NULL,
  `ally_max_members` int(5) unsigned NOT NULL DEFAULT 20,
  `ally_events` varchar(55) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ally_tag` (`ally_tag`),
  KEY `ally_name` (`ally_name`),
  KEY `ally_universe` (`ally_universe`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%alliance_ranks` (
  `rankID` int(11) NOT NULL AUTO_INCREMENT,
  `rankName` varchar(32) NOT NULL,
  `allianceID` int(10) unsigned NOT NULL,
  `MEMBERLIST` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ONLINESTATE` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `TRANSFER` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `SEEAPPLY` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `MANAGEAPPLY` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ROUNDMAIL` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ADMIN` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `KICK` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `DIPLOMATIC` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `RANKS` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `MANAGEUSERS` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `EVENTS` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`rankID`),
  KEY `allianceID` (`allianceID`,`rankID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


CREATE TABLE `%PREFIX%alliance_request` (
  `applyID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `text` text NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `allianceID` int(10) unsigned NOT NULL,
  `time` int(11) NOT NULL,
  PRIMARY KEY (`applyID`),
  KEY `allianceID` (`allianceID`,`userID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%buddy` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sender` int(11) unsigned NOT NULL DEFAULT '0',
  `owner` int(11) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `universe` (`universe`),
  KEY `sender` (`sender`,`owner`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%buddy_request` (
  `id` int(11) unsigned NOT NULL,
  `text` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%config` (
  `key` varchar(255) NOT NULL,
  `value` text,
  PRIMARY KEY (`key`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%config_universe` (
  `uni` int(11) NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` text,
  PRIMARY KEY (`uni`, `key`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%config_universe_modules` (
  `uni` int(11) NOT NULL,
  `module` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `module_name` varchar(100) NOT NULL DEFAULT "",
  `state` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `start` text,
  `end` text,
  PRIMARY KEY (`uni`, `module`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%planet_data` (
  `universe` INT unsigned not NULL,
  `planet_position` INT unsigned not NULL,
  `temp_min` INT,
  `temp_max` INT,
  `fields_min` INT unsigned,
  `fields_max` INT unsigned,
  `metalBonusPercent` FLOAT,
  `crystalBonusPercent` FLOAT,
  `deuteriumBonusPercent` FLOAT,
  PRIMARY KEY (`universe`,`planet_position`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%cronjobs` (
  `cronjobID` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(32) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT '1',
  `min` varchar(32) NOT NULL,
  `hours` varchar(32) NOT NULL,
  `dom` varchar(32) NOT NULL,
  `month` varchar(32) NOT NULL,
  `dow` varchar(32) NOT NULL,
  `class` varchar(32) NOT NULL,
  `nextTime` int(11) DEFAULT NULL,
  `lock` varchar(32) DEFAULT NULL,
  UNIQUE KEY `cronjobID` (`cronjobID`),
  KEY `isActive` (`isActive`,`nextTime`,`lock`,`cronjobID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%cronjobs_log` (
 `cronjobId` int(11) unsigned NOT NULL,
 `executionTime` datetime NOT NULL,
 `lockToken` varchar(32) NOT NULL,
 KEY `cronjobId` (`cronjobId`,`executionTime`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


CREATE TABLE `%PREFIX%diplo` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `owner_1` int(11) unsigned NOT NULL,
  `owner_2` int(11) unsigned NOT NULL,
  `level` tinyint(1) unsigned NOT NULL,
  `accept` tinyint(1) unsigned NOT NULL,
  `accept_text` text CHARACTER SET utf8mb4,
  `universe` tinyint(3) unsigned NOT NULL,
  `request_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `universe` (`universe`),
  KEY `owner_1` (`owner_1`,`owner_2`,`accept`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%expedition_deck_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `universe` INT NOT NULL,
  `deck_data` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uni_deck` (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expedition User Deck Table (per user per universe)
CREATE TABLE `%PREFIX%expedition_user_deck` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `universe` INT NOT NULL,
  `deck_array` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_uni_deck` (`user_id`, `universe`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_universe` (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%expedition_findable_ships` (
  `universe` INT UNSIGNED NOT NULL,
  `ship_id` SMALLINT UNSIGNED NOT NULL,
  `is_findable` TINYINT(1) NOT NULL DEFAULT 0,
  `findable_by` LONGTEXT NOT NULL,
  PRIMARY KEY (`universe`, `ship_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%fleets` (
  `id` bigint(11) unsigned NOT NULL AUTO_INCREMENT,
  `group` int(10) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  `mission` tinyint(3) unsigned NOT NULL DEFAULT '3',
  `owner` int(11) unsigned NOT NULL DEFAULT '0',
  `target_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `ship_amount` bigint(20) unsigned NOT NULL DEFAULT '0',
  `ships` text,
  `start_ships` text,
  `start_time` int(11) NOT NULL DEFAULT '0',
  `arrival_time` int(11) NOT NULL DEFAULT '0',
  `stay_time` int(11) NOT NULL DEFAULT '0',
  `return_time` int(11) NOT NULL DEFAULT '0',
  `start_id` int(11) unsigned NOT NULL,
  `start_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `start_system` smallint(5) unsigned NOT NULL DEFAULT '0',
  `start_planet` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `start_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `end_id` int(11) unsigned NOT NULL,
  `end_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `end_system` smallint(5) unsigned NOT NULL DEFAULT '0',
  `end_planet` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `end_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `missile_target` smallint(3) unsigned NOT NULL DEFAULT '0',
  `fuel_cost` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_deuterium` double(50,0) unsigned NOT NULL DEFAULT '0',
  `wanted_resource` tinyint(3) unsigned NOT NULL DEFAULT '0',
	`wanted_resource_amount` double(50,0) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `cannotCancel` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `hasCanceled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `target_owner` (`target_owner`,`mission`),
  KEY `owner` (`owner`,`mission`),
  KEY `group` (`group`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%trades` (
  `seller_fleet_id`  bigint(11) unsigned NOT NULL DEFAULT 0,
  `buyer_fleet_id`  bigint(11) unsigned DEFAULT NULL,
	`buy_time` datetime,
	`transaction_type` tinyint(1) unsigned NOT NULL DEFAULT 0,
	`filter_visibility` tinyint(1) unsigned NOT NULL DEFAULT 0,
	`filter_flighttime` mediumint unsigned NOT NULL DEFAULT 0,
	`ex_resource_type` tinyint(1) unsigned NOT NULL DEFAULT 0,
	`ex_resource_amount` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_type` tinyint unsigned NOT NULL DEFAULT 0,
  `resource_amount` double(50,0) unsigned NOT NULL DEFAULT '0',
  `marketplace_galaxy` tinyint unsigned NOT NULL DEFAULT 0,
  `marketplace_system` smallint unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY (`seller_fleet_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%fleet_event` (
  `fleetID` int(11) NOT NULL,
  `time` int(11) NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `lock` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`fleetID`),
  KEY `lock` (`lock`,`time`),
  KEY `universe` (`universe`,`lock`,`time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `mode` tinyint(3) unsigned NOT NULL,
  `admin` int(11) unsigned NOT NULL,
  `target` int(11) NOT NULL,
  `time` int(11) unsigned NOT NULL,
  `data` text NOT NULL,
  `universe` tinyint(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mode` (`mode`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%honor_fights` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `attacker` int(11) unsigned NOT NULL,
  `defender` int(11) unsigned NOT NULL,
  `time` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pair` (`attacker`, `defender`, `time`),
  KEY `time` (`time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%user_devices` (
  `user_id` int(11) unsigned NOT NULL,
  `device_hash` char(64) NOT NULL,
  `first_seen` int(11) unsigned NOT NULL,
  `last_seen` int(11) unsigned NOT NULL,
  PRIMARY KEY (`user_id`, `device_hash`),
  KEY `device` (`device_hash`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%log_fleets` (
  `id` bigint(11) unsigned NOT NULL,
  `group` varchar(15) NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  `mission` tinyint(3) unsigned NOT NULL DEFAULT '3',
  `owner` int(11) unsigned NOT NULL DEFAULT '0',
  `owner_name` varchar(32) NOT NULL DEFAULT '',
  `owner_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `target_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `target_owner_name` varchar(32) NOT NULL DEFAULT '',
  `target_owner_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `ship_amount` bigint(20) unsigned NOT NULL DEFAULT '0',
  `ships` text,
  `start_ships` text,
  `start_time` int(11) NOT NULL DEFAULT '0',
  `start_time_formated` datetime,
  `arrival_time` int(11) NOT NULL DEFAULT '0',
  `arrival_time_formated` datetime,
  `stay_time` int(11) NOT NULL DEFAULT '0',
  `stay_time_formated` datetime,
  `return_time` int(11) NOT NULL DEFAULT '0',
  `return_time_formated` datetime,
  `report_id` varchar(32) NOT NULL DEFAULT '',
  `start_id` int(11) unsigned NOT NULL,
  `start_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `start_system` smallint(5) unsigned NOT NULL DEFAULT '0',
  `start_planet` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `start_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `end_id` int(11) unsigned NOT NULL,
  `end_galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `end_system` smallint(5) unsigned NOT NULL DEFAULT '0',
  `end_planet` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `end_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `missile_target` smallint(3) unsigned NOT NULL DEFAULT '0',
  `fuel_cost` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `resource_deuterium` double(50,0) unsigned NOT NULL DEFAULT '0',
  `gained_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `gained_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `gained_deuterium` double(50,0) unsigned NOT NULL DEFAULT '0',
	`wanted_resource` tinyint(3) unsigned NOT NULL DEFAULT '0',
	`wanted_resource_amount` double(50,0) unsigned NOT NULL DEFAULT '0',
	`current_market_rate` text,
	`current_market_value_based_on_DSE` double(50,0) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0',
	`cannotCancel` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `hasCanceled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `BashRule` (`owner`,`end_id`,`start_time`,`mission`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%lostpassword` (
  `accountID` int(10) unsigned NOT NULL,
  `key` varchar(32) NOT NULL,
  `time` int(10) unsigned NOT NULL,
  `hasChanged` tinyint(1) NOT NULL DEFAULT '0',
  `fromIP` varchar(40) NOT NULL,
  PRIMARY KEY (`key`),
  UNIQUE KEY `accountID` (`accountID`,`key`,`time`,`hasChanged`),
  KEY `time` (`time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%messages` (
  `message_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_group` varchar(255) NULL,
  `message_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `message_sender` int(11) unsigned NOT NULL DEFAULT '0',
  `message_time` int(11) NOT NULL DEFAULT '0',
  `message_type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `message_from` varchar(128) DEFAULT NULL,
  `message_subject` varchar(255) DEFAULT NULL,
  `message_text` text CHARACTER SET utf8mb4,
  `message_unread` tinyint(4) NOT NULL DEFAULT '1',
  `message_universe` tinyint(3) unsigned NOT NULL,
  `message_deleted` int(11) unsigned NULL DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `message_sender` (`message_sender`),
  KEY `message_deleted` (`message_deleted`),
  KEY `message_owner` (`message_owner`,`message_type`,`message_unread`,`message_deleted`),
  KEY `message_group` (`message_group`,`message_type`,`message_time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%messages_forbidden` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `text` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%multi` (
  `multiID` int(11) NOT NULL AUTO_INCREMENT,
  `multi_ip` varchar(40) NOT NULL DEFAULT '',
  `universe` tinyint(3) unsigned NOT NULL,
  `lastActivity` int(11) NOT NULL DEFAULT '0',
  `allowed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`multiID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%multi_to_users` (
  `multiID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `lastActivity` int(11) NOT NULL DEFAULT '0',
  `allowed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`multiID`, `userID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%hall_of_fame` (
  `universe` int(11) unsigned NOT NULL,
  `data` mediumtext NOT NULL,
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`universe`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%news` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user` varchar(64) NOT NULL,
  `date` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `summary` varchar(300) NOT NULL DEFAULT '',
  `text` text NOT NULL,
  `status` tinyint NOT NULL DEFAULT 1,
  `pinned` tinyint NOT NULL DEFAULT 0,
  `updated_at` int NOT NULL DEFAULT 0,
  `format` varchar(8) NOT NULL DEFAULT 'html',
  `image` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `status_pinned_date` (`status`, `pinned`, `date`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner` int(11) unsigned DEFAULT NULL,
  `time` int(11) DEFAULT NULL,
  `priority` tinyint(1) unsigned DEFAULT '1',
  `title` varchar(32) DEFAULT NULL,
  `text` text,
  `universe` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `universe` (`universe`),
  KEY `owner` (`owner`,`time`,`priority`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%phalanx_fleets` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `phalanx_log_id` int(11) unsigned NOT NULL,
  `fleet_id` bigint(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%phalanx_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `owner` int(11) unsigned NOT NULL,
  `owner_planet_id` int(11) unsigned NOT NULL,
  `target` int(11) unsigned NOT NULL,
  `target_planet_id` int(11) unsigned NOT NULL,
  `phalanx_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%planet_wreckfield` (
  `planetID` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `created` int(11) NOT NULL DEFAULT '0',
  `ships` text,
  `repair_order` text,
  `repair_order_start` int(11) NOT NULL DEFAULT '0',
  `repair_order_end` int(11) NOT NULL DEFAULT '0',
  `repair_order_rate` double NOT NULL DEFAULT '0',
  PRIMARY KEY (`planetID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%planets` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(20) DEFAULT 'Hauptplanet',
  `id_owner` int(11) unsigned DEFAULT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `galaxy` tinyint(3) NOT NULL DEFAULT '0',
  `system` smallint(5) NOT NULL DEFAULT '0',
  `planet` tinyint(3) NOT NULL DEFAULT '0',
  `last_update` int(11) DEFAULT NULL,
  `planet_type` enum('1','3') NOT NULL DEFAULT '1',
  `destruyed` int(11) NOT NULL DEFAULT '0',
  `b_building` int(11) NOT NULL DEFAULT '0',
  `b_building_id` text,
  `b_hangar` int(11) NOT NULL DEFAULT '0',
  `b_hangar_id` text,
  `b_hangar_plus` int(11) NOT NULL DEFAULT '0',
  `watch_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `image` varchar(32) NOT NULL DEFAULT 'normaltempplanet01',
  `planet_style` tinyint(2) NOT NULL DEFAULT 0,
  `diameter` int(11) unsigned NOT NULL DEFAULT '12800',
  `field_current` smallint(5) unsigned NOT NULL DEFAULT '0',
  `field_max` smallint(5) unsigned NOT NULL DEFAULT '163',
  `temp_min` int(3) NOT NULL DEFAULT '-17',
  `temp_max` int(3) NOT NULL DEFAULT '23',
  `eco_hash` varchar(32) NOT NULL DEFAULT '',
  `metal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `metal_perhour` double(50,6) NOT NULL DEFAULT '0.000000',
  `metal_max` double(50,0) unsigned DEFAULT '100000',
  `metal_bonus_percent` float(5,3) unsigned NOT NULL DEFAULT 0,
  `crystal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `crystal_perhour` double(50,6) NOT NULL DEFAULT '0.000000',
  `crystal_max` double(50,0) unsigned DEFAULT '100000',
  `crystal_bonus_percent` float(5,3) unsigned NOT NULL DEFAULT 0,
  `deuterium` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `deuterium_perhour` double(50,6) NOT NULL DEFAULT '0.000000',
  `deuterium_max` double(50,0) unsigned DEFAULT '100000',
  `deuterium_bonus_percent` float(5,3) unsigned NOT NULL DEFAULT 0,
  `energy_used` double(50,0) NOT NULL DEFAULT '0',
  `energy` double(50,0) unsigned NOT NULL DEFAULT '0',
  `metal_mine` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `crystal_mine` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `deuterium_sintetizer` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `solar_plant` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `fusion_plant` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `robot_factory` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `nano_factory` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `hangar` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `metal_store` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `crystal_store` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `deuterium_store` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `laboratory` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `terraformer` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `university` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ally_deposit` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `silo` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `repair_dock` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `mondbasis` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `phalanx` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `sprungtor` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `small_ship_cargo` bigint(20) unsigned NOT NULL DEFAULT '0',
  `big_ship_cargo` bigint(20) unsigned NOT NULL DEFAULT '0',
  `light_hunter` bigint(20) unsigned NOT NULL DEFAULT '0',
  `heavy_hunter` bigint(20) unsigned NOT NULL DEFAULT '0',
  `crusher` bigint(20) unsigned NOT NULL DEFAULT '0',
  `battle_ship` bigint(20) unsigned NOT NULL DEFAULT '0',
  `colonizer` bigint(20) unsigned NOT NULL DEFAULT '0',
  `recycler` bigint(20) unsigned NOT NULL DEFAULT '0',
  `spy_sonde` bigint(20) unsigned NOT NULL DEFAULT '0',
  `bomber_ship` bigint(20) unsigned NOT NULL DEFAULT '0',
  `solar_satelit` bigint(20) unsigned NOT NULL DEFAULT '0',
  `destructor` bigint(20) unsigned NOT NULL DEFAULT '0',
  `dearth_star` bigint(20) unsigned NOT NULL DEFAULT '0',
  `battleship` bigint(20) unsigned NOT NULL DEFAULT '0',
  `lune_noir` bigint(20) unsigned NOT NULL DEFAULT '0',
  `ev_transporter` bigint(20) unsigned NOT NULL DEFAULT '0',
  `star_crasher` bigint(20) unsigned NOT NULL DEFAULT '0',
  `giga_recykler` bigint(20) unsigned NOT NULL DEFAULT '0',
  `orbital_station` bigint(20) unsigned NOT NULL DEFAULT '0',
  `misil_launcher` bigint(20) unsigned NOT NULL DEFAULT '0',
  `small_laser` bigint(20) unsigned NOT NULL DEFAULT '0',
  `big_laser` bigint(20) unsigned NOT NULL DEFAULT '0',
  `gauss_canyon` bigint(20) unsigned NOT NULL DEFAULT '0',
  `ionic_canyon` bigint(20) unsigned NOT NULL DEFAULT '0',
  `buster_canyon` bigint(20) unsigned NOT NULL DEFAULT '0',
  `small_protection_shield` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `planet_protector` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `big_protection_shield` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `graviton_canyon` bigint(20) unsigned NOT NULL DEFAULT '0',
  `interceptor_misil` bigint(20) unsigned NOT NULL DEFAULT '0',
  `interplanetary_misil` bigint(20) unsigned NOT NULL DEFAULT '0',
  `metal_mine_porcent` enum('0','1','2','3','4','5','6','7','8','9','10') NOT NULL DEFAULT '10',
  `crystal_mine_porcent` enum('0','1','2','3','4','5','6','7','8','9','10') NOT NULL DEFAULT '10',
  `deuterium_sintetizer_porcent` enum('0','1','2','3','4','5','6','7','8','9','10') NOT NULL DEFAULT '10',
  `solar_plant_porcent` enum('0','1','2','3','4','5','6','7','8','9','10') NOT NULL DEFAULT '10',
  `fusion_plant_porcent` enum('0','1','2','3','4','5','6','7','8','9','10') NOT NULL DEFAULT '10',
  `solar_satelit_porcent` enum('0','1','2','3','4','5','6','7','8','9','10') NOT NULL DEFAULT '10',
  `porcent_saved` varchar(32) NOT NULL DEFAULT '',
  `last_jump_time` int(11) NOT NULL DEFAULT '0',
  `der_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `der_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `tf_active` tinyint(1) NOT NULL DEFAULT '0',
  `id_luna` int(11) NOT NULL DEFAULT '0',
  `creation_time` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `id_luna` (`id_luna`),
  KEY `id_owner` (`id_owner`),
  KEY `destruyed` (`destruyed`),
  UNIQUE KEY `universe` (`universe`,`galaxy`,`system`,`planet`,`planet_type`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%pirate_bases` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  `galaxy` tinyint(3) NOT NULL DEFAULT '0',
  `system` smallint(5) NOT NULL DEFAULT '0',
  `planet_type` smallint(5) NOT NULL DEFAULT '4',
  `metal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `crystal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `deuterium` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `ships` text,
  `der_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `der_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%pirate_bases_history` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  `galaxy` tinyint(3) NOT NULL DEFAULT '0',
  `system` smallint(5) NOT NULL DEFAULT '0',
  `planet_type` smallint(5) NOT NULL DEFAULT '4',
  `metal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `crystal` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `deuterium` double(50,6) unsigned NOT NULL DEFAULT '0.000000',
  `ships` text,
  `der_metal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `der_crystal` double(50,0) unsigned NOT NULL DEFAULT '0',
  `timestamp` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%raports` (
  `rid` varchar(32) NOT NULL,
  `raport` longtext NOT NULL,
  `time` int(11) NOT NULL,
  `attacker` varchar(255) NOT NULL DEFAULT '',
  `defender` varchar(255) NOT NULL DEFAULT '',
  `keep` TINYINT(1) unsigned NOT NULL DEFAULT '0',
  `battleengine` VARCHAR(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`rid`),
  KEY `time` (`time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%records` (
  `userID` int(10) unsigned NOT NULL,
  `elementID` smallint(5) unsigned NOT NULL,
  `level` bigint(20) unsigned NOT NULL,
  `universe` tinyint unsigned NOT NULL
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%session` (
  `sessionID` varchar(32) NOT NULL,
  `userID` int(10) unsigned NOT NULL,
  `accountID` int(10) unsigned  NOT NULL,
  `userIP` varchar(40) NOT NULL,
  `lastonline` int(11) NOT NULL,
  `created` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`sessionID`)
) ENGINE=MEMORY DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%fleet_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(40) NOT NULL,
  `ships` text NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%shortcuts` (
  `shortcutID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ownerID` int(10) unsigned NOT NULL,
  `name` varchar(32) NOT NULL,
  `galaxy` tinyint(3) unsigned NOT NULL,
  `system` smallint(5) unsigned NOT NULL,
  `planet` tinyint(3) unsigned NOT NULL,
  `type` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY (`shortcutID`),
  KEY `ownerID` (`ownerID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%statpoints` (
  `id_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `id_ally` int(11) unsigned NOT NULL DEFAULT '0',
  `stat_type` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `tech_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `tech_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `tech_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `tech_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `build_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `build_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `build_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `build_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `defs_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `defs_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `defs_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `defs_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `fleet_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `fleet_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `fleet_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `fleet_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `total_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `total_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `total_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `total_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `honor_points` bigint(20) NOT NULL DEFAULT '0',
  `honor_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `honor_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `honorable_fights` int(11) unsigned NOT NULL DEFAULT '0',
  `dishonorable_fights` int(11) unsigned NOT NULL DEFAULT '0',
  `honor_title` varchar(16) NOT NULL DEFAULT 'neutral',
  `units_points` bigint(20) NOT NULL DEFAULT '0',
  `units_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `units_old_rank` int(11) unsigned NOT NULL DEFAULT '0',
  UNIQUE KEY `unique_values` (`id_owner`,`stat_type`),
  KEY `id_owner` (`id_owner`),
  KEY `universe` (`universe`),
  KEY `stat_type` (`stat_type`),
  KEY `honor_rank_idx` (`universe`,`stat_type`,`honor_rank`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%statpoints_history` (
  `timestamp` int(11) NOT NULL DEFAULT '0',
  `id_owner` int(11) unsigned NOT NULL DEFAULT '0',
  `id_ally` int(11) unsigned NOT NULL DEFAULT '0',
  `stat_type` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `tech_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `tech_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `tech_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `build_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `build_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `build_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `defs_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `defs_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `defs_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `fleet_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `fleet_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `fleet_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `total_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `total_points` double(50,0) unsigned NOT NULL DEFAULT '0',
  `total_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `honor_points` bigint(20) NOT NULL DEFAULT '0',
  `honor_rank` int(11) unsigned NOT NULL DEFAULT '0',
  `honorable_fights` int(11) unsigned NOT NULL DEFAULT '0',
  `dishonorable_fights` int(11) unsigned NOT NULL DEFAULT '0',
  `honor_title` varchar(16) NOT NULL DEFAULT 'neutral',
  PRIMARY KEY `pk` (`id_owner`, `stat_type`, `timestamp`),
  KEY `unique_values` (`id_owner`,`stat_type`),
  KEY `id_owner` (`id_owner`),
  KEY `universe` (`universe`),
  KEY `stat_type` (`stat_type`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%system` (
  `dbVersion` int(10) NOT NULL
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%ticket` (
  `ticketID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `ownerID` int(10) unsigned NOT NULL,
  `categoryID` tinyint(1) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ticketID`),
  KEY `ownerID` (`ownerID`),
  KEY `universe` (`universe`,`status`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%ticket_answer` (
  `answerID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ownerID` int(10) unsigned NOT NULL,
  `ownerName` varchar(32) NOT NULL,
  `ticketID` int(10) unsigned NOT NULL,
  `time` int(10) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` mediumtext NOT NULL,
  PRIMARY KEY (`answerID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%ticket_category` (
  `categoryID` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(32) NOT NULL,
  PRIMARY KEY (`categoryID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%topkb` (
  `rid` varchar(32) NOT NULL,
  `units` double(50,0) unsigned NOT NULL,
  `result` varchar(1) NOT NULL,
  `time` int(11) NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `galaxy` tinyint(3) unsigned NULL DEFAULT NULL,
  `memorial` tinyint(1) unsigned NOT NULL DEFAULT '0',
  KEY `time` (`universe`,`rid`,`time`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(32) NOT NULL DEFAULT '',
  `authattack` tinyint(1) NOT NULL DEFAULT '0',
  `authlevel` tinyint(1) NOT NULL DEFAULT '0',
  `rights` text,
  `discord_id` varchar(25) NOT NULL DEFAULT '',
  `discord_hook` text,
  `id_planet` int(11) unsigned NOT NULL DEFAULT '0',
  `universe` tinyint(3) unsigned NOT NULL,
  `galaxy` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `system` smallint(5) unsigned NOT NULL DEFAULT '0',
  `planet` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `user_lastip` varchar(40) NOT NULL DEFAULT '',
  `ip_at_reg` varchar(40) NOT NULL DEFAULT '',
  `register_time` int(11) NOT NULL DEFAULT '0',
  `onlinetime` int(11) NOT NULL DEFAULT '0',
  `rebuild_protection` int(11) unsigned NOT NULL DEFAULT 0,
  `rebuild_protection_attackers` text,
  `rebuild_protection_locked_until` int(11) unsigned NOT NULL DEFAULT 0,
  `urlaubs_modus` tinyint(1) NOT NULL DEFAULT '0',
  `urlaubs_until` int(11) NOT NULL DEFAULT '0',
  `urlaubs_start` int(11) NOT NULL DEFAULT '0',
  `db_deaktjava` int(11) NOT NULL DEFAULT '0',
  `b_tech_planet` int(11) unsigned NOT NULL DEFAULT '0',
  `b_tech` int(11) unsigned NOT NULL DEFAULT '0',
  `b_tech_id` smallint(2) unsigned NOT NULL DEFAULT '0',
  `b_tech_queue` text,
  `spy_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `computer_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `military_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `defence_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `shield_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `energy_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `hyperspace_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `combustion_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `impulse_motor_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `hyperspace_motor_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `laser_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ionic_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `buster_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `intergalactic_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `expedition_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `metal_proc_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `crystal_proc_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `deuterium_proc_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `fuel_efficiency_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `graviton_tech` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `ally_id` int(11) unsigned NOT NULL DEFAULT '0',
  `ally_register_time` int(11) NOT NULL DEFAULT '0',
  `ally_rank_id` int(11) unsigned NOT NULL DEFAULT '0',
  `bana` tinyint(1) NOT NULL DEFAULT '0',
  `banaday` int(11) NOT NULL DEFAULT '0',
  `uctime` int(11) NOT NULL DEFAULT '0',
  `setmail` int(11) NOT NULL DEFAULT '0',
  `ref_id` int(11) NOT NULL DEFAULT '0',
  `ref_bonus` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `enable_analytics` tinyint NOT NULL DEFAULT 0,
  `outlaw_time` int(11) unsigned NOT NULL DEFAULT 0,
  `wreckfield_cooldown_until` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `authlevel` (`authlevel`),
  KEY `ref_bonus` (`ref_bonus`),
  KEY `universe` (`universe`,`username`,`onlinetime`,`authlevel`),
  UNIQUE KEY `uniq_universe_username` (`universe`,`username`),
  KEY `ally_id` (`ally_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_banned` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `who` varchar(64) NOT NULL DEFAULT '',
  `theme` varchar(500) NOT NULL,
  `time` int(11) NOT NULL DEFAULT '0',
  `longer` int(11) NOT NULL DEFAULT '0',
  `author` varchar(64) NOT NULL DEFAULT '',
  `email` varchar(64) NOT NULL DEFAULT '',
  `universe` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY `ID` (`id`),
  KEY `universe` (`universe`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_comments` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(11) unsigned NOT NULL,
    `comment` text,
    `info` text,
    `created_at` int(11) NOT NULL,
    PRIMARY KEY `ID` (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_settings` (
  `user_id` int NOT NULL,
  `lang` varchar(2) NOT NULL DEFAULT 'de',
  `dpath` varchar(20) NOT NULL DEFAULT 'v0game',
  `css_dpath` varchar(20) NOT NULL DEFAULT 'v0game',
  `timezone` varchar(32) NOT NULL DEFAULT 'Europe/London',
  `planet_sort` tinyint(1) NOT NULL DEFAULT '0',
  `planet_sort_order` tinyint(1) NOT NULL DEFAULT '0',
  `spio_anz` int(10) unsigned NOT NULL DEFAULT '1',
  `settings_fleetactions` tinyint(2) unsigned NOT NULL DEFAULT '3',
  `settings_esp` tinyint(1) NOT NULL DEFAULT '1',
  `settings_wri` tinyint(1) NOT NULL DEFAULT '1',
  `settings_bud` tinyint(1) NOT NULL DEFAULT '1',
  `settings_mis` tinyint(1) NOT NULL DEFAULT '1',
  `settings_rep` tinyint(1) NOT NULL DEFAULT '1',
  `spy_report_fresh_hours` smallint(5) unsigned NOT NULL DEFAULT '24',
  `spy_report_recent_hours` smallint(5) unsigned NOT NULL DEFAULT '168',
  `settings_blockPM` tinyint(1) NOT NULL DEFAULT '0',
  `showMessageCategory` tinyint(1) NOT NULL DEFAULT '0',
  `hof` tinyint(1) NOT NULL DEFAULT '1',
  `spyMessagesMode` tinyint(1) NOT NULL DEFAULT '1',
  `spyReportsCollapsed` tinyint(1) NOT NULL DEFAULT '0',
  `inactive_mail` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `colorMission2friend` varchar(7) NOT NULL DEFAULT '#ff00ff',
  `colorMission1Own` varchar(7) NOT NULL DEFAULT '#66cc33',
  `colorMission2Own` varchar(7) NOT NULL DEFAULT '#339966',
  `colorMission3Own` varchar(7) NOT NULL DEFAULT '#5bf1c2',
  `colorMission4Own` varchar(7) NOT NULL DEFAULT '#cf79de',
  `colorMission5Own` varchar(7) NOT NULL DEFAULT '#80a0c0',
  `colorMission6Own` varchar(7) NOT NULL DEFAULT '#ffcc66',
  `colorMission7Own` varchar(7) NOT NULL DEFAULT '#c1c1c1',
  `colorMission7OwnReturn` varchar(7) NOT NULL DEFAULT '#cf79de',
  `colorMission8Own` varchar(7) NOT NULL DEFAULT '#ceff68',
  `colorMission9Own` varchar(7) NOT NULL DEFAULT '#ffff99',
  `colorMission10Own` varchar(7) NOT NULL DEFAULT '#ffcc66',
  `colorMission15Own` varchar(7) NOT NULL DEFAULT '#5bf1c2',
  `colorMission16Own` varchar(7) NOT NULL DEFAULT '#5bf1c2',
  `colorMission17Own` varchar(7) NOT NULL DEFAULT '#5bf1c2',
  `colorMissionReturnOwn` varchar(7) NOT NULL DEFAULT '#6e8eea',
  `colorMission1Foreign` varchar(7) NOT NULL DEFAULT '#ff0000',
  `colorMission2Foreign` varchar(7) NOT NULL DEFAULT '#aa0000',
  `colorMission3Foreign` varchar(7) NOT NULL DEFAULT '#00ff00',
  `colorMission4Foreign` varchar(7) NOT NULL DEFAULT '#ad57bc',
  `colorMission5Foreign` varchar(7) NOT NULL DEFAULT '#3399cc',
  `colorMission6Foreign` varchar(7) NOT NULL DEFAULT '#ff6600',
  `colorMission7Foreign` varchar(7) NOT NULL DEFAULT '#00ff00',
  `colorMission8Foreign` varchar(7) NOT NULL DEFAULT '#acdd46',
  `colorMission9Foreign` varchar(7) NOT NULL DEFAULT '#dddd77',
  `colorMission10Foreign` varchar(7) NOT NULL DEFAULT '#ff6600',
  `colorMission15Foreign` varchar(7) NOT NULL DEFAULT '#39d0a0',
  `colorMission16Foreign` varchar(7) NOT NULL DEFAULT '#39d0a0',
  `colorMission17Foreign` varchar(7) NOT NULL DEFAULT '#39d0a0',
  `colorMissionReturnForeign` varchar(7) NOT NULL DEFAULT '#6e8eea',
  `colorStaticTimer` varchar(7) NOT NULL DEFAULT '#ffff00',
  `colorPositive` varchar(7) NOT NULL DEFAULT '#00ff00',
  `colorNegative` varchar(7) NOT NULL DEFAULT '#ff0000',
  `colorNeutral` varchar(7) NOT NULL DEFAULT '#ffd600',
  `prioMission1` tinyint(2) NOT NULL DEFAULT 1,
  `prioMission2` tinyint(2) NOT NULL DEFAULT 2,
  `prioMission3` tinyint(2) NOT NULL DEFAULT 0,
  `prioMission4` tinyint(2) NOT NULL DEFAULT 3,
  `prioMission5` tinyint(2) NOT NULL DEFAULT 4,
  `prioMission6` tinyint(2) NOT NULL DEFAULT 5,
  `prioMission7` tinyint(2) NOT NULL DEFAULT 6,
  `prioMission8` tinyint(2) NOT NULL DEFAULT 7,
  `prioMission9` tinyint(2) NOT NULL DEFAULT 8,
  `prioMission17` tinyint(2) NOT NULL DEFAULT 9,
  `stb_small_ress` int NOT NULL DEFAULT 500,
  `stb_med_ress` int NOT NULL DEFAULT 3000,
  `stb_big_ress` int NOT NULL DEFAULT 7000,
  `stb_small_time` tinyint(2) NOT NULL DEFAULT 1,
  `stb_med_time` tinyint(2) NOT NULL DEFAULT 2,
  `stb_big_time` tinyint(2) NOT NULL DEFAULT 3,
  `stb_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `records_optIn` tinyint(1) NOT NULL DEFAULT 0,
  `messages_per_page` tinyint(2) NOT NULL DEFAULT 10,
  `publish_achievement` tinyint(0) NOT NULL DEFAULT 0,
  `show_all_buildable_elements` tinyint unsigned NOT NULL DEFAULT 1,
  `missing_requirements_opacity` tinyint unsigned NOT NULL DEFAULT 1,
  `missing_resources_opacity` tinyint unsigned NOT NULL DEFAULT 0,
  `hide_useless` tinyint(1) NOT NULL DEFAULT 0,
  `hangar_filters` varchar(512) NOT NULL DEFAULT '["civil","military","all"]',
  `hangar_filters_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `enable_planet_styles` tinyint NOT NULL DEFAULT 1,
  `colonyWarn` int(1) unsigned NOT NULL DEFAULT 1,
  `noob_interface` tinyint(1) NOT NULL DEFAULT 1,
  `decimal_separator` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) unsigned NOT NULL,
  `onlinetime` int(11) NOT NULL DEFAULT '0',
  `url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%users_to_achievements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `achievementID` int(11) NOT NULL,
  `date` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_achievement` (`userID`, `achievementID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

CREATE TABLE `%PREFIX%users_to_acs` (
  `userID` int(10) unsigned NOT NULL,
  `acsID` int(10) unsigned NOT NULL,
  KEY `userID` (`userID`),
  KEY `acsID` (`acsID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_blocklist` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `blocking_user` int(11) unsigned NOT NULL,
  `blocked_user` int(11) unsigned NOT NULL,
  `block_dm` int(1) unsigned NOT NULL DEFAULT '0',
  `block_trade` int(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_userids ON `%PREFIX%users_blocklist` (`blocking_user`, `blocked_user`);

CREATE TABLE `%PREFIX%users_to_topkb` (
  `rid` varchar(32) NOT NULL,
  `uid` int(11) NOT NULL,
  `username` varchar(128) NOT NULL,
  `role` tinyint(1) NOT NULL,
  KEY `rid` (`rid`,`role`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%users_to_messages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) UNSIGNED NOT NULL,
  `sender_id` int(11) UNSIGNED NULL,
  `forwarder_id` int(11) UNSIGNED NULL,
  `message_unread` tinyint(1) NOT NULL DEFAULT '1',
  `message_favorite` tinyint(1) NOT NULL DEFAULT '0',
  `message_forwarded` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NULL DEFAULT NULL,
  `deleted_at` int(11) unsigned NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE(`message_id`, `user_id`, `sender_id`, `forwarder_id`),
  KEY `user_unread` (`user_id`, `message_unread`, `deleted_at`),
  FOREIGN KEY (`message_id`) REFERENCES `%PREFIX%messages`(`message_id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  FOREIGN KEY (`user_id`) REFERENCES `%PREFIX%users`(`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  FOREIGN KEY (`sender_id`) REFERENCES `%PREFIX%users`(`id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  FOREIGN KEY (`forwarder_id`) REFERENCES `%PREFIX%users`(`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%vars` (
  `elementID` smallint(5) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `name` varchar(32) NOT NULL,
  `class` int(11) NOT NULL,
  `ship_type` varchar(255) DEFAULT NULL,
  `onPlanetType` set('1','3') NOT NULL,
  `onePerPlanet` tinyint(4) NOT NULL,
  `factor` float(4,2) NOT NULL,
  `maxLevel` int(11) DEFAULT NULL,
  `cost901` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cost902` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cost903` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cost911` bigint(20) unsigned NOT NULL DEFAULT '0',
  `consumption1` int(11) unsigned DEFAULT NULL,
  `consumption2` int(11) unsigned DEFAULT NULL,
  `consumption3` int(11) unsigned DEFAULT NULL,
  `speedTech1` int(11) unsigned DEFAULT NULL,
  `speedTech2` int(11) unsigned DEFAULT NULL,
  `speedTech3` int(11) unsigned DEFAULT NULL,
  `speed1` int(11) unsigned DEFAULT NULL,
  `speed2` int(11) unsigned DEFAULT NULL,
  `speed3` int(11) unsigned DEFAULT NULL,
  `capacity` int(11) unsigned DEFAULT NULL,
  `attack` int(10) unsigned DEFAULT NULL,
  `defend` int(10) unsigned DEFAULT NULL,
  `bigshield` boolean DEFAULT 0 NOT NULL,
  `stun` float DEFAULT 0 NOT NULL,
  `production901` varchar(255) DEFAULT NULL,
  `production902` varchar(255) DEFAULT NULL,
  `production903` varchar(255) DEFAULT NULL,
  `production911` varchar(255) DEFAULT NULL,
  `storage901` varchar(255) DEFAULT NULL,
  `storage902` varchar(255) DEFAULT NULL,
  `storage903` varchar(255) DEFAULT NULL,
  `max_useful_level` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`elementID`, `universe`),
  KEY `class` (`class`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%vars_rapidfire` (
  `elementID` int(11) NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `rapidfireID` int(11) NOT NULL,
  `shoots` int(11) NOT NULL,
  KEY `elementID` (`elementID`),
  KEY `rapidfireID` (`rapidfireID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%vars_requriements` (
  `elementID` int(11) NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `requireID` int(11) NOT NULL,
  `requireLevel` int(11) NOT NULL,
  KEY `elementID` (`elementID`),
  KEY `requireID` (`requireID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%marketplace` (
  `universeId` int(11) NOT NULL AUTO_INCREMENT,
  `metDeutRatio` float(23) NOT NULL DEFAULT 2,
  `crysDeutRatio` float(23) NOT NULL DEFAULT 1,
  PRIMARY KEY (`universeId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- adds tutorial functions
-- define tutorial steps
CREATE TABLE `%PREFIX%tutorials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL, -- human readable name/description
  `translation_key` varchar(255) DEFAULT NULL, -- key for translation files
  `order` int(11) NOT NULL, -- order by number, hint: use 10 steps to add steps in between later
  `support_class` varchar(255) DEFAULT 'DefaultTutorialSupport', -- class for checking goals and rewarding, overwrite for non vars goals/rewards
  `skippable` tinyint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

--  disable a tutorial step per universe, if no enty it can be enabled depending on tutorials table entry
CREATE TABLE `%PREFIX%tutorial_universe` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tutorialID` int(11) NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `tutorialID` (`tutorialID`, `universe`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- add goals to tutorial steps
CREATE TABLE `%PREFIX%tutorial_goals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tutorialID` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `elementID` smallint(5) unsigned NOT NULL, -- does not need to be in table vars, if non standard support_class is used
  `ammount` smallint(5) unsigned NOT NULL,
  `skippable` tinyint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `tutorialID` (`tutorialID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- add rewards to tutorial steps
CREATE TABLE `%PREFIX%tutorial_rewards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tutorialID` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `elementID` smallint(5) unsigned NOT NULL, -- does not need to be in table vars, if non standard support_class is used
  `ammount` smallint(5) unsigned NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `tutorialID` (`tutorialID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- users can skipp or claim rewards of tutorial steps
CREATE TABLE `%PREFIX%users_tutorial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `tutorialID` int(11) NOT NULL,
  `completed` tinyint unsigned NOT NULL DEFAULT 0,
  `claimed` tinyint unsigned NOT NULL DEFAULT 0,
  `skipped` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `userID` (`userID`, `tutorialID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- users can skipp tutorial step goals
CREATE TABLE `%PREFIX%users_tutorial_goal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `tutorialGoalID` int(11) NOT NULL,
  `fullfilled` tinyint unsigned NOT NULL DEFAULT 0,
  `skipped` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `userID` (`userID`, `tutorialGoalID`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%api_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `universe` tinyint(3) unsigned NOT NULL,
  `name` varchar(40) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `scopes` set('reports','empire') NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT 0,
  `last_used_at` int(11) unsigned NOT NULL DEFAULT 0,
  `revoked` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`, `revoked`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%vote_sites` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` int(11) unsigned NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `vote_url` varchar(500) NOT NULL,
  `image_url` varchar(500) NOT NULL DEFAULT '',
  `cooldown_hours` int(11) unsigned NOT NULL DEFAULT 24,
  `reward` json NOT NULL,
  `verification` enum('none','postback') NOT NULL DEFAULT 'none',
  `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 0,
  `secret` varchar(64) NOT NULL,
  `created` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `universe` (`universe`, `active`, `sort`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE `%PREFIX%vote_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `token` char(32) NOT NULL,
  `clicked_at` int(11) unsigned NOT NULL,
  `verified_at` int(11) unsigned DEFAULT NULL,
  `rewarded_at` int(11) unsigned DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `site_user` (`site_id`, `user_id`, `rewarded_at`),
  KEY `clicked_at` (`clicked_at`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `%PREFIX%tutorials` (`name`, `translation_key`, `order`, `support_class`, `skippable`, `enabled`) VALUES
('Tutorial intro', 'step0', 5, 'DefaultTutorialSupport', 0, 1),
('Mines 1', 'step1', 10, 'DefaultTutorialSupport', 0, 1),
('Discord', 'step2', 20, 'LinkTutorialSupport', 1, 1),
('Defence', 'step3', 30, 'DefaultTutorialSupport', 0, 1),
('Mines 2', 'step4', 40, 'DefaultTutorialSupport', 0, 1),
('Ships', 'step5', 50, 'DefaultTutorialSupport', 0, 1),
('Name & friends', 'step6', 60, 'RenameTutorialSupport', 0, 1),
('Spionage', 'step7', 70, 'MissionTutorialSupport', 0, 1),
('Expedition', 'step8', 80, 'MissionTutorialSupport', 0, 1),
('Kolonies', 'step9', 90, 'MissionTutorialSupport', 0, 1),
('Attack', 'step10', 100, 'MissionTutorialSupport', 0, 1),
('Recycle', 'step11', 110, 'MissionTutorialSupport', 0, 1),
('Outro', 'step12', 120, 'DefaultTutorialSupport', 0, 1);

INSERT INTO `%PREFIX%tutorial_goals` (`tutorialID`, `name`, `elementID`, `ammount`, `skippable`, `enabled`) VALUES
(2, 'MetMine', 1, 5, 0, 1),
(2, 'KrisMine', 2, 2, 0, 1),
(2, 'SolPower', 4, 4, 0, 1),
(3, 'Discord', 1, 1, 1, 1),
(4, 'DeutMine', 3, 2, 0, 1),
(4, 'Robo', 14, 2, 0, 1),
(4, 'Shipyard', 21, 1, 0, 1),
(4, 'ML', 401, 1, 0, 1),
(5, 'MetMine', 1, 10, 0, 1),
(5, 'KrisMine', 2, 7, 0, 1 ),
(5, 'DeutMine', 3, 5, 0, 1),
(6, 'ResearchLab', 31, 1, 0, 1),
(6, 'Combustion', 115, 1, 0, 1),
(6, 'KT', 202, 1, 0, 1),
(7, 'Plani Rename', 1, 1, 0, 1),
(7, 'Buddy', 2, 1, 1, 1),
(7, 'Ally', 3, 1, 1, 1),
(8, 'spionieren', 6, 1, 0, 1),
(9, 'expedition', 15, 1, 0, 1),
(10, 'Kolo setzen', 7, 1, 0, 1),
(11, 'att', 1, 1, 1, 1),
(12, 'recycle', 8, 1, 0, 1);

INSERT INTO `%PREFIX%tutorial_rewards` (`tutorialID`, `name`, `elementID`, `ammount`, `enabled`) VALUES
(2, 'Met', 901, 150, 1),
(2, 'Cris', 902, 75, 1),
(4, 'ML', 401, 1, 1),
(5, 'Met', 901, 2000, 1),
(5, 'Cris', 902, 500, 1),
(6, 'Deut', 903, 200, 1),
(8, 'Spio', 210, 2, 1),
(9, 'SJ', 205, 2, 1),
(9, 'KT', 202, 5, 1),
(12, 'Rec', 209, 1, 1);

INSERT INTO `%PREFIX%achievements` (`id`, `name`, `image`) VALUES
(1, 'Sleepless and in a bad mood', 'achievement_1'),
(2, 'Absolute basics', 'achievement_2'),
(3, 'Deserved', 'achievement_3'),
(4, 'What does this do?', 'achievement_4'),
(5, 'Because I can', 'achievement_5'),
(6, 'Low performer', 'achievement_6'),
(7, 'French revolution', 'achievement_7'),
(8, 'Save drunk', 'achievement_8'),
(9, 'Thats no moon', 'achievement_9'),
(10, 'Marble game', 'achievement_10'),
(11, 'TheLegend27', 'achievement_11'),
(12, "Slippy's blessing", 'achievement_12'),
(13, 'That was close', 'achievement_13'),
(14, "Slippy's curse", 'achievement_14'),
(15, 'Do we still have LF?', 'achievement_15'),
(16, "Dude, Where's My Debris?", 'achievement_16'),
(17, 'Overlord Defense!', 'achievement_17'),
(18, 'Frankfurt trainstation', 'achievement_18'),
(19, 'Thick skin 1', 'achievement_19'),
(20, 'Thick skin 2', 'achievement_20'),
(21, 'Thick skin 3', 'achievement_21'),
(22, 'Thick skin 4', 'achievement_22'),
(23, 'Thick skin 5', 'achievement_23'),
(24, "Imma firin' mah laz0r", 'achievement_24'),
(25, 'GDPR breach', 'achievement_25'),
(26, 'Everything for the achievements', 'achievement_26'),
(27, 'Big Brain Time', 'achievement_27'),
(28, 'Alternate gameplay', 'achievement_28'),
(29, 'Communist Manifesto', 'achievement_29'),
(30, 'This is Sparta', 'achievement_30'),
(31, 'Off on vacation', 'achievement_31'),
(32, 'Privateer!', 'achievement_32'),
(33, 'I can stop whenever I want', 'achievement_33'),
(34, 'Noble farm', 'achievement_34'),
(35, "That's not nice!", 'achievement_35'),
(36, 'Miesernachtsmann', 'achievement_36'),
(37, 'I am not one of those', 'achievement_37'),
(38, 'Cornag and Offz', 'achievement_38'),
(39, 'The meaning of life', 'achievement_39'),
(40, 'Columbus', 'achievement_40'),
(41, 'Fleetslot patron', 'achievement_41'),
(42, 'Atleast Battlehall', 'achievement_42'),
(43, 'With my Eyes closed', 'achievement_43'),
(44, 'Happens to everyone accidentally', 'achievement_44'),
(45, 'Locksmith', 'achievement_45'),
(46, 'Pure hatred!', 'achievement_46'),
(47, 'Jack the Ripper', 'achievement_47'),
(48, 'Rip the Jacker', 'achievement_48'),
(49, "We're blasting off again!", 'achievement_49'),
(50, 'Oopsie', 'achievement_50'),
(51, "No clue, it's just there", 'achievement_51'),
(52, 'Megalomania', 'achievement_52'),
(53, 'Einstein', 'achievement_53'),
(54, 'Happy New Year!', 'achievement_54'),
(55, 'Living Legend', 'achievement_55');

INSERT INTO `%PREFIX%planet_data` (`universe`, `planet_position`, `temp_min`, `temp_max`, `fields_min`, `fields_max`, `metalBonusPercent`, `crystalBonusPercent`, `deuteriumBonusPercent`) VALUES
(0, 1,   220,  260,     96,   172,   0,   0,    0),
(0, 2,   170,  210,    104,   176,   0,   0,    0),
(0, 3,   120,  160,    112,   182,   0,   0,    0),
(0, 4,    70,  110,    118,   208,   0,   0,    0),
(0, 5,    60,  100,    133,   232,   0,   0,    0),
(0, 6,    50,   90,    146,   242,   0,   0,    0),
(0, 7,    40,   80,    152,   248,   0,   0,    0),
(0, 8,    30,   70,    156,   252,   0,   0,    0),
(0, 9,    20,   60,    150,   246,   0,   0,    0),
(0, 10,   10,   50,    142,   232,   0,   0,    0),
(0, 11,    0,   40,    136,   210,   0,   0,    0),
(0, 12,  -10,   30,    125,   186,   0,   0,    0),
(0, 13,  -50,  -10,    114,   172,   0,   0,    0),
(0, 14,  -90,  -50,    100,   168,   0,   0,    0),
(0, 15, -130,  -90,     90,   164,   0,   0,    0);

CREATE TABLE `%PREFIX%bot_llm_providers` (
  `provider` VARCHAR(20) NOT NULL,
  `api_key` TEXT NOT NULL,
  `model` VARCHAR(128) NOT NULL DEFAULT '',
  `base_url` VARCHAR(255) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `last_test_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_test_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `last_test_message` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%bot_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_key` VARCHAR(32) NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `settings` TEXT NOT NULL,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile_key` (`profile_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%bot_players` (
  `user_id` INT UNSIGNED NOT NULL,
  `universe` TINYINT UNSIGNED NOT NULL,
  `profile_id` INT UNSIGNED NOT NULL,
  `skill` FLOAT NOT NULL DEFAULT 0,
  `personality` TEXT NOT NULL,
  `next_tick` INT UNSIGNED NOT NULL DEFAULT 0,
  `session_until` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_action_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `actions_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `persona` TEXT NULL,
  `last_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_threat_check` INT UNSIGNED NOT NULL DEFAULT 0,
  `llm_calls_day` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `llm_calls_date` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  KEY `tick` (`universe`, `next_tick`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%bot_relations` (
  `bot_id` INT UNSIGNED NOT NULL,
  `player_id` INT UNSIGNED NOT NULL,
  `universe` TINYINT UNSIGNED NOT NULL,
  `balance` INT NOT NULL DEFAULT 0,
  `interactions` INT UNSIGNED NOT NULL DEFAULT 0,
  `player_contacted` TINYINT(1) NOT NULL DEFAULT 0,
  `last_interaction` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_handled_message_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_bot_message_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`bot_id`, `player_id`),
  KEY `universe` (`universe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%bot_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `universe` TINYINT UNSIGNED NOT NULL,
  `time` INT UNSIGNED NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `uni_time` (`universe`, `time`),
  KEY `user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `%PREFIX%bot_llm_providers` (`provider`, `api_key`, `model`, `base_url`) VALUES
('openai', '', '', 'https://api.openai.com/v1'),
('claude', '', '', 'https://api.anthropic.com/v1'),
('opencode_go', '', '', 'https://opencode.ai/zen/go/v1'),
('deepseek', '', '', 'https://api.deepseek.com'),
('openrouter', '', '', 'https://openrouter.ai/api/v1');

INSERT IGNORE INTO `%PREFIX%bot_profiles` (`profile_key`, `name`, `description`, `is_default`, `settings`, `created_at`) VALUES
('office_worker', 'Office worker', 'Plays before work, at lunch and in the evening.', 1, '{"activity":[0.05,0.05,0.05,0.05,0.05,0.05,0.6,0.6,0.15,0.15,0.15,0.15,0.6,0.15,0.15,0.15,0.15,0.7,0.7,0.7,0.7,0.7,0.7,0.3],"check_interval":[20,60],"session_minutes":[5,15],"actions_per_session":[2,5],"weights":{"mines":42,"energy":15,"storage":6,"facilities":12,"research":17,"ships":5,"defense":3},"expedition_chance":6,"colonize_chance":30,"skill_start":0.75,"skill_max":0.9,"mistake_chance":8,"randomness":20,"reply_chance":50,"message_chance":3,"alliance_chance":10,"buddy_accept_chance":50,"chat_style":"short, polite, businesslike, few words","pattern_shift_hours":0,"pattern_shift_days":0,"skill_gain_per_day":0,"llm_advice":true}', 0),
('jobless', 'Jobless', 'Lots of time, online most of the afternoon and night.', 1, '{"activity":[0.6,0.6,0.6,0.6,0.1,0.1,0.1,0.1,0.1,0.1,0.5,0.5,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8,0.8],"check_interval":[10,30],"session_minutes":[10,40],"actions_per_session":[3,9],"weights":{"mines":38,"energy":14,"storage":6,"facilities":12,"research":16,"ships":10,"defense":4},"expedition_chance":15,"colonize_chance":40,"skill_start":0.8,"skill_max":0.92,"mistake_chance":6,"randomness":25,"reply_chance":95,"message_chance":10,"alliance_chance":25,"buddy_accept_chance":70,"chat_style":"chatty, casual, relaxed, uses slang","pattern_shift_hours":0,"pattern_shift_days":0,"skill_gain_per_day":0,"llm_advice":true}', 0),
('shift_worker', 'Shift worker', 'Rotating shifts: the play pattern moves by 8 hours every week.', 1, '{"activity":[0.05,0.05,0.05,0.05,0.05,0.05,0.05,0.05,0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.4,0.4,0.4,0.4,0.05,0.05,0.05,0.05],"check_interval":[15,45],"session_minutes":[5,25],"actions_per_session":[2,7],"weights":{"mines":40,"energy":15,"storage":7,"facilities":12,"research":16,"ships":6,"defense":4},"expedition_chance":8,"colonize_chance":30,"skill_start":0.75,"skill_max":0.9,"mistake_chance":9,"randomness":25,"reply_chance":75,"message_chance":5,"alliance_chance":20,"buddy_accept_chance":60,"chat_style":"medium length, friendly, a bit tired","pattern_shift_hours":8,"pattern_shift_days":7,"skill_gain_per_day":0,"llm_advice":true}', 0),
('lunatic', 'Lunatic', 'Always online, long sessions, plays almost perfectly.', 1, '{"activity":[0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.7,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9,0.9],"check_interval":[3,10],"session_minutes":[20,60],"actions_per_session":[5,15],"weights":{"mines":36,"energy":13,"storage":5,"facilities":14,"research":20,"ships":9,"defense":3},"expedition_chance":25,"colonize_chance":60,"skill_start":0.92,"skill_max":0.98,"mistake_chance":2,"randomness":10,"reply_chance":95,"message_chance":15,"alliance_chance":30,"buddy_accept_chance":80,"chat_style":"very chatty, enthusiastic, long messages, emojis","pattern_shift_hours":0,"pattern_shift_days":0,"skill_gain_per_day":0,"llm_advice":true}', 0),
('noob', 'Noob', 'Still learning: makes many mistakes, improves slowly.', 1, '{"activity":[0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.3,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.3,0.3],"check_interval":[15,90],"session_minutes":[3,15],"actions_per_session":[1,5],"weights":{"mines":25,"energy":10,"storage":10,"facilities":15,"research":10,"ships":15,"defense":15},"expedition_chance":5,"colonize_chance":15,"skill_start":0.2,"skill_max":0.85,"skill_gain_per_day":0.03,"mistake_chance":45,"randomness":40,"reply_chance":90,"message_chance":6,"alliance_chance":40,"buddy_accept_chance":85,"chat_style":"friendly, curious newbie, asks naive questions, typos","pattern_shift_hours":0,"pattern_shift_days":0,"llm_advice":true}', 0);

INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`) VALUES
(NULL, 'referral', 0, '0,30', '*', '*', '*', '*', 'ReferralCronjob', 0, NULL),
(NULL, 'statistic', 1, '0,30', '*', '*', '*', '*', 'StatisticCronjob', 0, NULL),
(NULL, 'statisticjson', 0, '0', '6', '*', '*', '6', 'StatisticJsonCronjob', 0, NULL),
(NULL, 'daily', 1, '25', '2', '*', '*', '*', 'DailyCronjob', 0, NULL),
(NULL, 'cleaner', 1, '45', '2', '*', '*', '6', 'CleanerCronjob', 0, NULL),
(NULL, 'inactive', 1, '30', '1', '*', '*', '0,3,6', 'InactiveMailCronjob', 0, NULL),
(NULL, 'databasedump', 0, '30', '1', '*', '*', '1', 'DumpCronjob', 0, NULL),
(NULL, 'pirates', 0, '0', '2', '*', '*', '0', 'PirateSpawnerCronjob', 0, NULL),
(NULL, 'disposable_email_domains', 1, '0', '3', '*', '*', '*', 'DisposableEmailDomainsCronjob', 0, NULL),
(NULL, 'bots', 1, '*', '*', '*', '*', '*', 'BotCronjob', 0, NULL),
(NULL, 'fleets', 1, '*', '*', '*', '*', '*', 'FleetEventCronjob', 0, NULL);

INSERT INTO `%PREFIX%ticket_category` (`categoryID`, `name`) VALUES
(1, 'Support'),
(9, 'Debug: Code errors and warnings');

INSERT INTO `%PREFIX%vars` (`elementID`, `universe`, `name`, `class`, `ship_type`, `onPlanetType`, `onePerPlanet`, `factor`, `maxLevel`, `cost901`, `cost902`, `cost903`, `cost911`, `consumption1`, `consumption2`, `consumption3`, `speedTech1`, `speedTech2`, `speedTech3`, `speed1`, `speed2`, `speed3`, `capacity`, `attack`, `defend`, `production901`, `production902`, `production903`, `production911`, `storage901`, `storage902`, `storage903`, `max_useful_level`) VALUES
(1, 0, 'metal_mine', 0, NULL, '1', 0, 1.50, 255, 60, 15, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '(30 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', NULL, NULL, '-(10 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', NULL, NULL, NULL, 0),
(2, 0, 'crystal_mine', 0, NULL, '1', 0, 1.60, 255, 48, 24, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '(20 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', NULL, '-(10 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor);', NULL, NULL, NULL, 0),
(3, 0, 'deuterium_sintetizer', 0, NULL, '1', 0, 1.50, 255, 225, 75, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '(floor(10 * $BuildLevel * pow((1.1), $BuildLevel) * (1.44 -0.004 * $BuildTemp)) * (0.1 * $BuildLevelFactor))', '- (20 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', NULL, NULL, NULL, 0),
(4, 0, 'solar_plant', 0, NULL, '1', 0, 1.50, 255, 75, 30, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '(20 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', NULL, NULL, NULL, 0),
(6, 0, 'university', 0, NULL, '1', 0, 2.00, 255, 100000000, 50000000, 25000000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(12, 0, 'fusion_plant', 0, NULL, '1', 0, 1.80, 255, 900, 360, 180, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '- (10 * $BuildLevel * pow(1.1,$BuildLevel) * (0.1 * $BuildLevelFactor))', '30 * $BuildLevel * pow(1.05 + ($BuildEnergy * 0.01), $BuildLevel) * (0.1 * $BuildLevelFactor)', NULL, NULL, NULL, 0),
(14, 0, 'robot_factory', 0, NULL, '1,3', 0, 2.00, 255, 400, 120, 200, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(15, 0, 'nano_factory', 0, NULL, '1', 0, 2.00, 255, 1000000, 500000, 100000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(21, 0, 'hangar', 0, NULL, '1,3', 0, 2.00, 255, 400, 200, 100, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(22, 0, 'metal_store', 0, NULL, '1,3', 0, 2.00, 255, 1000, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', NULL, NULL, 0),
(23, 0, 'crystal_store', 0, NULL, '1,3', 0, 2.00, 255, 1000, 500, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', NULL, 0),
(24, 0, 'deuterium_store', 0, NULL, '1,3', 0, 2.00, 255, 1000, 1000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', 0),
(31, 0, 'laboratory', 0, NULL, '1', 0, 2.00, 255, 200, 400, 200, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(33, 0, 'terraformer', 0, NULL, '1', 0, 2.00, 255, 0, 50000, 100000, 1000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(34, 0, 'ally_deposit', 0, NULL, '1', 0, 2.00, 255, 20000, 40000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(35, 0, 'repair_dock', 0, NULL, '1', 0, 2.00, 255, 20000, 2000, 3000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(41, 0, 'mondbasis', 0, NULL, '3', 0, 2.00, 255, 20000, 40000, 20000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(42, 0, 'phalanx', 0, NULL, '3', 0, 2.00, 255, 20000, 40000, 20000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(43, 0, 'sprungtor', 0, NULL, '3', 0, 2.00, 255, 2000000, 4000000, 2000000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(44, 0, 'silo', 0, NULL, '1', 0, 2.00, 255, 20000, 20000, 1000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(106, 0, 'spy_tech', 100, NULL, '1,3', 0, 2.00, 255, 200, 1000, 200, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(108, 0, 'computer_tech', 100, NULL, '1,3', 0, 2.00, 255, 0, 400, 600, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(109, 0, 'military_tech', 100, NULL, '1,3', 0, 2.00, 255, 800, 200, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(110, 0, 'shield_tech', 100, NULL, '1,3', 0, 2.00, 255, 200, 600, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(111, 0, 'defence_tech', 100, NULL, '1,3', 0, 2.00, 255, 1000, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(113, 0, 'energy_tech', 100, NULL, '1,3', 0, 2.00, 255, 0, 800, 400, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(114, 0, 'hyperspace_tech', 100, NULL, '1,3', 0, 2.00, 255, 0, 4000, 2000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 8),
(115, 0, 'combustion_tech', 100, NULL, '1,3', 0, 2.00, 255, 400, 0, 600, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(117, 0, 'impulse_motor_tech', 100, NULL, '1,3', 0, 2.00, 255, 2000, 4000, 600, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(118, 0, 'hyperspace_motor_tech', 100, NULL, '1,3', 0, 2.00, 255, 10000, 20000, 6000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(120, 0, 'laser_tech', 100, NULL, '1,3', 0, 2.00, 255, 200, 100, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 12),
(121, 0, 'ionic_tech', 100, NULL, '1,3', 0, 2.00, 255, 1000, 300, 100, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5),
(122, 0, 'buster_tech', 100, NULL, '1,3', 0, 2.00, 255, 2000, 4000, 1000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 7),
(123, 0, 'intergalactic_tech', 100, NULL, '1,3', 0, 2.00, 255, 240000, 400000, 160000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(124, 0, 'expedition_tech', 100, NULL, '1,3', 0, 1.75, 255, 4000, 8000, 4000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(131, 0, 'metal_proc_tech', 100, NULL, '1,3', 0, 2.00, 255, 750, 500, 250, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(132, 0, 'crystal_proc_tech', 100, NULL, '1,3', 0, 2.00, 255, 1000, 750, 500, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(133, 0, 'deuterium_proc_tech', 100, NULL, '1,3', 0, 2.00, 255, 1250, 1000, 750, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(134, 0, 'fuel_efficiency_tech', 101, NULL, '1,3', 0, 1.40, 15, 4000, 4000, 2000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(199, 0, 'graviton_tech', 100, NULL, '1,3', 0, 3.00, 255, 0, 0, 0, 300000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1),
(202, 0, 'small_ship_cargo', 200, 'civil', '1,3', 0, 1.00, NULL, 2000, 2000, 0, 0, 10, 20, 0, 1, 5, 0, 5000, 10000, 0, 5000, 5, 10, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(203, 0, 'big_ship_cargo', 200, 'civil', '1,3', 0, 1.00, NULL, 6000, 6000, 0, 0, 50, 0, 0, 1, 0, 0, 7500, 0, 0, 25000, 5, 25, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(204, 0, 'light_hunter', 200, 'military', '1,3', 0, 1.00, NULL, 3000, 1000, 0, 0, 20, 0, 0, 1, 0, 0, 12500, 0, 0, 50, 50, 10, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(205, 0, 'heavy_hunter', 200, 'military', '1,3', 0, 1.00, NULL, 6000, 4000, 0, 0, 0, 75, 0, 0, 1, 0, 0, 10000, 0, 100, 150, 25, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(206, 0, 'crusher', 200, 'military', '1,3', 0, 1.00, NULL, 20000, 7000, 2000, 0, 0, 300, 0, 0, 1, 0, 0, 15000, 0, 800, 400, 50, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(207, 0, 'battle_ship', 200, 'military', '1,3', 0, 1.00, NULL, 45000, 15000, 0, 0, 0, 0, 500, 0, 0, 1, 0, 0, 10000, 1500, 1000, 200, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(208, 0, 'colonizer', 200, 'civil', '1,3', 0, 1.00, NULL, 10000, 20000, 10000, 0, 0, 1000, 0, 0, 1, 0, 0, 2500, 0, 7500, 50, 100, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(209, 0, 'recycler', 200, 'recycler', '1,3', 0, 1.00, NULL, 10000, 6000, 2000, 0, 300, 300, 300, 1, 17, 15, 2000, 4000, 6000, 20000, 1, 10, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(210, 0, 'spy_sonde', 200, 'civil', '1,3', 0, 1.00, NULL, 0, 1000, 0, 0, 1, 0, 0, 1, 0, 0, 100000000, 0, 0, 5, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(211, 0, 'bomber_ship', 200, 'military', '1,3', 0, 1.00, NULL, 50000, 25000, 15000, 0, 0, 700, 700, 0, 1, 8, 0, 4000, 5000, 500, 1000, 500, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(212, 0, 'solar_satelit', 200, 'civil', '1,3', 0, 1.00, NULL, 0, 2000, 500, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, '((($BuildTemp + 160) / 6) * (0.1 * $BuildLevelFactor) * $BuildLevel)', NULL, NULL, NULL, 0),
(213, 0, 'destructor', 200, 'military', '1,3', 0, 1.00, NULL, 60000, 50000, 15000, 0, 0, 0, 1000, 0, 0, 1, 0, 0, 5000, 2000, 2000, 500, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(214, 0, 'dearth_star', 200, 'military', '1,3', 0, 1.00, NULL, 5000000, 4000000, 1000000, 0, 0, 0, 1, 0, 0, 1, 0, 0, 100, 1000000, 200000, 50000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(215, 0, 'battleship', 200, 'military', '1,3', 0, 1.00, NULL, 30000, 40000, 15000, 0, 0, 0, 250, 0, 0, 1, 0, 0, 10000, 750, 700, 400, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(216, 0, 'lune_noir', 201, 'military', '1,3', 0, 1.00, NULL, 8000000, 2000000, 1500000, 0, 0, 0, 250, 0, 0, 1, 0, 0, 900, 15000000, 150000, 70000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(217, 0, 'ev_transporter', 201, 'civil', '1,3', 0, 1.00, NULL, 35000, 20000, 1500, 0, 0, 0, 90, 0, 0, 1, 0, 0, 6000, 400000000, 50, 120, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(218, 0, 'star_crasher', 201, 'military', '1,3', 0, 1.00, NULL, 275000000, 130000000, 60000000, 0, 0, 0, 10000, 0, 0, 1, 0, 0, 10, 50000000, 35000000, 2000000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(219, 0, 'giga_recykler', 201, 'recycler', '1,3', 0, 1.00, NULL, 1000000, 600000, 200000, 0, 0, 0, 300, 0, 0, 1, 0, 0, 7500, 200000000, 1, 1000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(401, 0, 'misil_launcher', 400, NULL, '1,3', 0, 1.00, NULL, 2000, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 80, 20, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(402, 0, 'small_laser', 400, NULL, '1,3', 0, 1.00, NULL, 1500, 500, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 100, 25, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(403, 0, 'big_laser', 400, NULL, '1,3', 0, 1.00, NULL, 6000, 2000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 250, 100, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(404, 0, 'gauss_canyon', 400, NULL, '1,3', 0, 1.00, NULL, 20000, 15000, 2000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1100, 200, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(405, 0, 'ionic_canyon', 400, NULL, '1,3', 0, 1.00, NULL, 2000, 6000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 150, 500, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(406, 0, 'buster_canyon', 400, NULL, '1,3', 0, 1.00, NULL, 50000, 50000, 30000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 3000, 300, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(407, 0, 'small_protection_shield', 400, NULL, '1,3', 1, 1.00, NULL, 10000, 10000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1, 2000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(408, 0, 'big_protection_shield', 400, NULL, '1,3', 1, 1.00, NULL, 50000, 50000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1, 10000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(409, 0, 'planet_protector', 401, NULL, '1,3', 1, 1.00, NULL, 10000000, 5000000, 2500000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1, 1000000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(410, 0, 'graviton_canyon', 401, NULL, '1,3', 0, 1.00, NULL, 15000000, 15000000, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 500000, 80000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(411, 0, 'orbital_station', 401, NULL, '1,3', 1, 1.00, NULL, 5000000000, 2000000000, 500000000, 1000000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 400000000, 2000000000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(502, 0, 'interceptor_misil', 500, NULL, '1,3', 0, 1.00, NULL, 8000, 0, 2000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(503, 0, 'interplanetary_misil', 500, NULL, '1,3', 0, 1.00, NULL, 12500, 2500, 10000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 12000, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0);

INSERT INTO `%PREFIX%vars_rapidfire` (`elementID`, `universe`, `rapidfireID`, `shoots`) VALUES
(202, 0, 210, 5),
(202, 0, 212, 5),
(203, 0, 210, 5),
(203, 0, 212, 5),
(204, 0, 210, 5),
(204, 0, 212, 5),
(205, 0, 202, 3),
(205, 0, 210, 5),
(205, 0, 212, 5),
(206, 0, 204, 6),
(206, 0, 401, 10),
(206, 0, 210, 5),
(206, 0, 212, 5),
(207, 0, 210, 5),
(207, 0, 212, 5),
(208, 0, 210, 5),
(208, 0, 212, 5),
(209, 0, 210, 5),
(209, 0, 212, 5),
(211, 0, 210, 5),
(211, 0, 212, 5),
(211, 0, 401, 20),
(211, 0, 402, 20),
(211, 0, 403, 10),
(211, 0, 405, 10),
(213, 0, 210, 5),
(213, 0, 212, 5),
(213, 0, 215, 2),
(213, 0, 402, 10),
(214, 0, 210, 1250),
(214, 0, 212, 1250),
(214, 0, 202, 250),
(214, 0, 203, 250),
(214, 0, 208, 250),
(214, 0, 209, 250),
(214, 0, 204, 200),
(214, 0, 205, 100),
(214, 0, 206, 33),
(214, 0, 207, 30),
(214, 0, 211, 25),
(214, 0, 215, 15),
(214, 0, 213, 5),
(214, 0, 401, 200),
(214, 0, 402, 200),
(214, 0, 403, 100),
(214, 0, 404, 50),
(214, 0, 405, 100),
(215, 0, 202, 3),
(215, 0, 203, 3),
(215, 0, 205, 4),
(215, 0, 206, 4),
(215, 0, 207, 7),
(215, 0, 210, 5),
(215, 0, 212, 5),
(216, 0, 210, 1250),
(216, 0, 212, 1250),
(216, 0, 202, 250),
(216, 0, 203, 250),
(216, 0, 204, 200),
(216, 0, 205, 100),
(216, 0, 206, 33),
(216, 0, 207, 30),
(216, 0, 208, 250),
(216, 0, 209, 250),
(216, 0, 211, 25),
(216, 0, 213, 5),
(216, 0, 214, 1),
(216, 0, 215, 15),
(216, 0, 401, 400),
(216, 0, 402, 200),
(216, 0, 403, 100),
(216, 0, 404, 50),
(216, 0, 405, 100),
(217, 0, 210, 5),
(217, 0, 212, 5),
(218, 0, 210, 1250),
(218, 0, 212, 1250),
(218, 0, 202, 250),
(218, 0, 203, 250),
(218, 0, 204, 200),
(218, 0, 205, 100),
(218, 0, 206, 33),
(218, 0, 207, 30),
(218, 0, 208, 250),
(218, 0, 209, 250),
(218, 0, 211, 25),
(218, 0, 213, 5),
(218, 0, 215, 15),
(218, 0, 401, 400),
(218, 0, 402, 200),
(218, 0, 403, 100),
(218, 0, 404, 50),
(218, 0, 405, 100),
(219, 0, 210, 5),
(219, 0, 212, 5);

INSERT INTO `%PREFIX%vars_requriements` (`elementID`, `universe`, `requireID`, `requireLevel`) VALUES
(6, 0, 14, 20),
(6, 0, 31, 22),
(6, 0, 15, 4),
(6, 0, 108, 12),
(6, 0, 123, 3),
(12, 0, 3, 5),
(12, 0, 113, 3),
(15, 0, 14, 10),
(15, 0, 108, 10),
(21, 0, 14, 2),
(33, 0, 15, 1),
(33, 0, 113, 12),
(35, 0, 21, 2),
(42, 0, 41, 1),
(43, 0, 41, 1),
(43, 0, 114, 7),
(44, 0, 21, 1),
(106, 0, 31, 3),
(108, 0, 31, 1),
(109, 0, 31, 4),
(110, 0, 113, 3),
(110, 0, 31, 6),
(111, 0, 31, 2),
(113, 0, 31, 1),
(114, 0, 113, 5),
(114, 0, 110, 5),
(114, 0, 31, 7),
(115, 0, 113, 1),
(115, 0, 31, 1),
(117, 0, 113, 1),
(117, 0, 31, 2),
(118, 0, 114, 3),
(118, 0, 31, 7),
(120, 0, 31, 1),
(120, 0, 113, 2),
(121, 0, 31, 4),
(121, 0, 120, 5),
(121, 0, 113, 4),
(122, 0, 31, 5),
(122, 0, 113, 8),
(122, 0, 120, 10),
(122, 0, 121, 5),
(123, 0, 31, 10),
(123, 0, 108, 8),
(123, 0, 114, 8),
(124, 0, 106, 3),
(124, 0, 117, 3),
(124, 0, 31, 3),
(131, 0, 31, 4),
(131, 0, 113, 3),
(132, 0, 31, 4),
(132, 0, 113, 3),
(133, 0, 31, 4),
(133, 0, 113, 3),
(134, 0, 114, 3),
(134, 0, 113, 6),
(134, 0, 108, 6),
(199, 0, 31, 12),
(202, 0, 21, 2),
(202, 0, 115, 2),
(203, 0, 21, 4),
(203, 0, 115, 6),
(204, 0, 21, 1),
(204, 0, 115, 1),
(205, 0, 21, 3),
(205, 0, 111, 2),
(205, 0, 117, 2),
(206, 0, 21, 5),
(206, 0, 117, 4),
(206, 0, 121, 2),
(207, 0, 21, 7),
(207, 0, 118, 4),
(208, 0, 21, 4),
(208, 0, 117, 3),
(209, 0, 21, 4),
(209, 0, 115, 6),
(209, 0, 110, 2),
(210, 0, 21, 3),
(210, 0, 115, 3),
(210, 0, 106, 2),
(211, 0, 117, 6),
(211, 0, 21, 8),
(211, 0, 122, 5),
(212, 0, 21, 1),
(213, 0, 21, 9),
(213, 0, 118, 6),
(213, 0, 114, 5),
(214, 0, 21, 12),
(214, 0, 118, 7),
(214, 0, 114, 6),
(214, 0, 199, 1),
(215, 0, 114, 5),
(215, 0, 120, 12),
(215, 0, 118, 5),
(215, 0, 21, 8),
(216, 0, 106, 12),
(216, 0, 21, 15),
(216, 0, 109, 14),
(216, 0, 110, 14),
(216, 0, 111, 15),
(216, 0, 114, 10),
(216, 0, 120, 20),
(216, 0, 199, 3),
(217, 0, 111, 10),
(217, 0, 21, 14),
(217, 0, 114, 10),
(217, 0, 110, 14),
(217, 0, 117, 15),
(218, 0, 21, 18),
(218, 0, 109, 20),
(218, 0, 110, 20),
(218, 0, 111, 20),
(218, 0, 114, 15),
(218, 0, 118, 20),
(218, 0, 120, 25),
(218, 0, 199, 8),
(219, 0, 21, 15),
(219, 0, 109, 15),
(219, 0, 110, 15),
(219, 0, 111, 15),
(219, 0, 118, 8),
(401, 0, 21, 1),
(402, 0, 113, 1),
(402, 0, 21, 2),
(402, 0, 120, 3),
(403, 0, 113, 3),
(403, 0, 21, 4),
(403, 0, 120, 6),
(404, 0, 21, 6),
(404, 0, 113, 6),
(404, 0, 109, 3),
(404, 0, 110, 1),
(405, 0, 21, 4),
(405, 0, 121, 4),
(406, 0, 21, 8),
(406, 0, 122, 7),
(407, 0, 110, 2),
(407, 0, 21, 1),
(408, 0, 110, 6),
(408, 0, 21, 6),
(410, 0, 199, 7),
(410, 0, 21, 18),
(410, 0, 109, 20),
(411, 0, 199, 10),
(411, 0, 110, 22),
(411, 0, 122, 20),
(411, 0, 108, 15),
(411, 0, 111, 25),
(411, 0, 113, 20),
(411, 0, 21, 20),
(502, 0, 44, 2),
(502, 0, 21, 1),
(503, 0, 44, 4),
(503, 0, 21, 1),
(503, 0, 117, 1);

INSERT INTO `%PREFIX%marketplace` (`universeId`) VALUES (1);

-- content: CMS pages, team, board (migrations 1794787200, 1794787201, 1794787203)
CREATE TABLE `%PREFIX%pages` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `lang` varchar(5) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `content` mediumtext NOT NULL,
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  `updated_by` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug_lang` (`slug`, `lang`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `%PREFIX%team_members` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `position` varchar(128) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `contact` varchar(128) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT 0,
  `created_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sort` (`sort`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%board_posts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `flair` varchar(32) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL,
  `body` mediumtext NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `comment_count` int NOT NULL DEFAULT 0,
  `pinned` tinyint NOT NULL DEFAULT 0,
  `locked` tinyint NOT NULL DEFAULT 0,
  `deleted` tinyint NOT NULL DEFAULT 0,
  `created_at` int NOT NULL DEFAULT 0,
  `edited_at` int NOT NULL DEFAULT 0,
  `last_activity` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `listing` (`deleted`, `pinned`, `created_at`),
  KEY `score` (`score`),
  KEY `last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%board_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `parent_id` int unsigned NOT NULL DEFAULT 0,
  `account_id` int unsigned NOT NULL,
  `body` text NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `deleted` tinyint NOT NULL DEFAULT 0,
  `created_at` int NOT NULL DEFAULT 0,
  `edited_at` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `post_parent` (`post_id`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%board_votes` (
  `account_id` int unsigned NOT NULL,
  `target_type` tinyint NOT NULL,
  `target_id` int unsigned NOT NULL,
  `value` tinyint NOT NULL,
  PRIMARY KEY (`account_id`, `target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
