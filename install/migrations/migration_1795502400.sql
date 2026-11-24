-- chat slow mode of an alliance (seconds between two messages of a member, 0 = off; set by the alliance leader) and the index for the last-message lookup of the slow mode
ALTER TABLE `%PREFIX%alliance` ADD `chat_slowmode` smallint(5) unsigned NOT NULL DEFAULT 0;
ALTER TABLE `%PREFIX%chat_messages` ADD INDEX `channel_user` (`universe`, `channel`, `user_id`, `created_at`);
