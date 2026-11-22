-- inactivity reminder mails now honour the "sendmail_inactive" switch (it was ignored, mails went out whenever mail sending
-- was on): existing installs keep their behaviour by taking the switch over from mail_active
INSERT INTO `%PREFIX%config` (`key`, `value`) SELECT 'sendmail_inactive', `value` FROM `%PREFIX%config` WHERE `key` = 'mail_active' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
