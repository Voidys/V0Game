CREATE TABLE IF NOT EXISTS `%PREFIX%api_tokens` (
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
