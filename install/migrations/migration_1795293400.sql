-- social login (OAuth2): links between accounts and provider identities (discord, facebook, github, google)
CREATE TABLE IF NOT EXISTS `%PREFIX%accounts_social` (
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
