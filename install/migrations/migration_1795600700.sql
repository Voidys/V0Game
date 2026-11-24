-- engagement audit 2: indexes for player deletion and the catch-up identity check
ALTER TABLE `%PREFIX%eng_season_scores` ADD KEY `user` (`user_id`);
ALTER TABLE `%PREFIX%eng_season_pairs` ADD KEY `user` (`user_id`);
ALTER TABLE `%PREFIX%eng_mothership_damage` ADD KEY `user` (`user_id`);
ALTER TABLE `%PREFIX%eng_bounty_progress` ADD KEY `hunter` (`hunter_id`);
ALTER TABLE `%PREFIX%users` ADD KEY `universe_ip_reg` (`universe`, `ip_at_reg`), ADD KEY `universe_ip_last` (`universe`, `user_lastip`);
