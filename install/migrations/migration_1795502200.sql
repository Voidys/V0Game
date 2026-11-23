-- covering index for the per-category message counts of the message center
ALTER TABLE `%PREFIX%users_to_messages` ADD INDEX `user_cover` (`user_id`, `deleted_at`, `message_id`, `message_unread`, `message_favorite`);
