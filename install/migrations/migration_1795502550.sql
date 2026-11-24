-- battle hall lookups by user (achievement 42)
ALTER TABLE `%PREFIX%users_to_topkb` ADD KEY `uid` (`uid`, `role`);
