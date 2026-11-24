-- time since a planet continuously produced with an energy deficit (achievement "Happens to everyone accidentally"), 0 = no deficit
ALTER TABLE `%PREFIX%planets` ADD `low_energy_since` int(11) unsigned NOT NULL DEFAULT 0 AFTER `energy`;
