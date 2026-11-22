-- the account area (lobby, account settings, board) uses the account's skin, which was only set at account creation:
-- take over the skin the player chose in the game (settings now keep both in sync)
UPDATE `%PREFIX%accounts` a
  INNER JOIN `%PREFIX%accounts_to_users` atu ON atu.account_id = a.id AND atu.end_time = 0
  INNER JOIN `%PREFIX%users_settings` s ON s.user_id = atu.user_id
SET a.dpath = s.dpath, a.css_dpath = s.css_dpath
WHERE s.dpath <> '' AND s.css_dpath <> '';
