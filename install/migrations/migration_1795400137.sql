-- production percentages the player had before vacation mode / the inactivity reset, restored on the way back
ALTER TABLE `%PREFIX%planets` ADD COLUMN `porcent_saved` varchar(32) NOT NULL DEFAULT '';
