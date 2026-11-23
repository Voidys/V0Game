ALTER TABLE `%PREFIX%users_settings`
  ADD COLUMN `notify_attack` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN `notify_spy` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `notify_fleet_return` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `notify_queue_build` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `notify_queue_research` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `notify_queue_shipyard` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `notify_discord_webhook` varchar(255) NOT NULL DEFAULT '',
  ADD COLUMN `number_format` tinyint(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `%PREFIX%push_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `endpoint` varchar(512) NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT 0,
  `last_used_at` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint` (`endpoint`),
  KEY `user_id` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%notification_events` (
  `event_key` varchar(80) NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_key`),
  KEY `created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `%PREFIX%notification_watch` (
  `fleet_id` bigint(11) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `return_time` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`fleet_id`),
  KEY `user_id` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`)
SELECT NULL, 'notifications', 1, '*', '*', '*', '*', '*', 'NotificationCronjob', 0, NULL FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `%PREFIX%cronjobs` WHERE `class` = 'NotificationCronjob');
