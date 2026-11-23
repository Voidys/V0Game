-- anti-multi / push-trade review queue: scored player pairs for admins, refreshed hourly by AbuseReviewCronjob
CREATE TABLE IF NOT EXISTS `%PREFIX%abuse_reviews` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `universe` tinyint(3) unsigned NOT NULL,
  `user_a` int(11) unsigned NOT NULL,
  `user_b` int(11) unsigned NOT NULL,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `signals` text NOT NULL,
  `status` enum('open','dismissed','confirmed','actioned') NOT NULL DEFAULT 'open',
  `note` text,
  `reviewed_by` int(11) unsigned DEFAULT NULL,
  `reviewed_at` int(11) unsigned DEFAULT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `updated_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pair` (`universe`,`user_a`,`user_b`),
  KEY `status` (`universe`,`status`,`score`)
) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `%PREFIX%cronjobs` (`cronjobID`, `name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`)
SELECT NULL, 'abusereview', 1, '10', '*', '*', '*', '*', 'AbuseReviewCronjob', 0, NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `%PREFIX%cronjobs` WHERE `class` = 'AbuseReviewCronjob');
