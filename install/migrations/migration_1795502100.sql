-- performance pass 3: indexes for the abuse aggregates (log_fleets by universe and start time), the notification
-- detector (planets.b_building, users.b_tech), the chat poll (deleted flag per channel); the notification_watch user
-- index is read by no query.
ALTER TABLE `%PREFIX%log_fleets` ADD KEY `universe_start` (`universe`, `start_time`);
ALTER TABLE `%PREFIX%planets` ADD KEY `b_building` (`b_building`);
ALTER TABLE `%PREFIX%users` ADD KEY `b_tech` (`b_tech`);
ALTER TABLE `%PREFIX%chat_messages` ADD KEY `channel_deleted` (`universe`, `channel`, `deleted`, `id`);
ALTER TABLE `%PREFIX%notification_watch` DROP KEY `user_id`;
