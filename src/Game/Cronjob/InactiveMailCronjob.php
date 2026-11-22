<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Cronjob;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Language;
use App\Game\Core\Mail;
use App\Game\Core\Singleton;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Inactivity reminder (default 01:30 on Sunday, Wednesday, Saturday): mails players who were offline longer than
 * del_user_sendmail days (once; bots excluded) when mail sending is enabled.
 */
class InactiveMailCronjob extends AbstractCronjobTask
{
    /** @param string $defaultUri public base URL of the site (env DEFAULT_URI), used for the link when run from the console */
    public function __construct(
        Database $db,
        ConfigRegistry $configs,
        Singleton $state,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri = '',
    ) {
        parent::__construct($db, $configs, $state);
    }

    /** Link target of the mail: the request's URL, or the configured site URL when run from the console (no host there). */
    private function siteUrl(): string
    {
        $runtime = $this->state->runtime;
        if ($runtime->httpHost === '127.0.0.1' && $this->defaultUri !== '') {
            return rtrim($this->defaultUri, '/') . '/';
        }

        return $runtime->httpPath;
    }

    /**
     * Players who were offline since before $before, were not mailed yet and are no bots; the address is the one of the
     * player's account (the users table has none).
     *
     * @return list<array<string, mixed>> id, username, lang, email, onlinetime, timezone, universe
     */
    public function inactiveUsers(int $before): array
    {
        $sql = 'SELECT u.`id`, u.`username`, us.`lang`, a.`email`, u.`onlinetime`, us.`timezone`, u.`universe`
            FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id`
            INNER JOIN %%ACCOUNTS_TO_USERS%% au ON au.`user_id` = u.`id`
            INNER JOIN %%ACCOUNTS%% a ON a.`id` = au.`account_id`
            WHERE us.`inactive_mail` = 0 AND u.`onlinetime` < :time AND a.`email` != ""
            AND u.`urlaubs_modus` = 0 AND u.`db_deaktjava` = 0
            AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% bp WHERE bp.`user_id` = u.`id`);';

        return $this->db->select($sql, [':time' => $before]);
    }

    /**
     * Sends the localized `email_inactive` text to each player with inactive_mail = 0 and onlinetime older than the
     * limit (only with mail_active and sendmail_inactive), then sets inactive_mail = 1 so the player is not mailed again
     * until he has been online again.
     */
    public function run(): void
    {
        $LNG = $this->state->LNG;

        $config	= $this->configs->get(ROOT_UNI);

        if ($config->mail_active == 1 && $config->sendmail_inactive) {
            $before = $this->state->runtime->timestamp - max(1, (int) $config->del_user_sendmail) * 24 * 60 * 60;
            // players who came back are armed again for their next absence
            $this->db->update('UPDATE %%USERS_SETTINGS%% us INNER JOIN %%USERS%% u ON u.`id` = us.`user_id`
                SET us.`inactive_mail` = 0 WHERE us.`inactive_mail` = 1 AND u.`onlinetime` >= :time;', [':time' => $before]);

            /** @var Language[] $langObjects */
            $langObjects	= [];

            $inactiveUsers	= $this->inactiveUsers($before);

            foreach ($inactiveUsers as $user) {
                if (!isset($langObjects[$user['lang']])) {
                    $langObjects[$user['lang']]	= new Language($this->configs, $user['lang']);
                    $langObjects[$user['lang']]->includeData(['L18N', 'INGAME', 'PUBLIC', 'CUSTOM']);
                }

                $userConfig	= $this->configs->get($user['universe']);

                $LNG			= $langObjects[$user['lang']];
                $this->state->LNG = $LNG; // the last mailed language stays the active one (as before)

                $MailSubject	= $LNG->format('spec_mail_inactive_title', $userConfig->game_name . ' - ' . $userConfig->uni_name);
                $MailRAW		= $LNG->getTemplate('email_inactive');

                $MailContent	= str_replace([
                    '{USERNAME}',
                    '{GAMENAME}',
                    '{LASTDATE}',
                    '{HTTPPATH}',
                ], [
                    $user['username'],
                    $userConfig->game_name . ' - ' . $userConfig->uni_name,
                    $this->viewHelpers()->formatDate($LNG['php_tdformat'], $user['onlinetime'], $user['timezone']),
                    $this->siteUrl(),
                ], $MailRAW);

                $this->mailer()->send($user['email'], $user['username'], $MailSubject, $MailContent);

                $sql	= 'UPDATE %%USERS_SETTINGS%% SET `inactive_mail` = 1 WHERE `user_id` = :userId;';
                $this->db->update($sql, [
                    ':userId'	=> $user['id'],
                ]);
            }
        }
    }
}
