-- indexes for the player removal done by the daily/cleaner cronjobs (DELETE ... WHERE owner = ? OR sender = ?, alliance applications by user)
ALTER TABLE `%PREFIX%buddy` ADD INDEX `owner` (`owner`);
ALTER TABLE `%PREFIX%alliance_request` ADD INDEX `userID` (`userID`);
