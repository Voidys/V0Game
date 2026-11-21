<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Theme;
use App\Routing\GameRoute;
use App\Security\PasswordSupport;
use App\Security\Social\SocialAccounts;
use App\Security\Social\SocialProvider;
use App\Security\Social\SocialSettings;
use Symfony\Contracts\Service\Attribute\Required;

/** Account settings: language, timezone, email and password, account deletion (modes: show, send, toggleDelete). */
class AccountSettingsController extends AbstractAccountController
{
    /** result codes of the social login steps (query `social`) -> [message key, notice kind] */
    private const SOCIAL_NOTICES = [
        'linked' => ['socialLinked', 'success'],
        'unlinked' => ['socialUnlinked', 'success'],
        'taken' => ['socialErrorTaken', 'danger'],
        'last' => ['socialLastMethod', 'danger'],
    ];

    private SocialSettings $socialSettings;
    private SocialAccounts $social;

    /** Container (setter) injection of the social login settings and links. */
    #[Required]
    public function setSocialServices(SocialSettings $socialSettings, SocialAccounts $social): void
    {
        $this->socialSettings = $socialSettings;
        $this->social = $social;
    }

    /**
     * Settings form of the account (language, theme, timezone, e-mail, password, deletion flag).
     */
    #[GameRoute('account', 'account/settings', 'accountSettings')]
    public function show(): Response
    {
        $this->state->LNG->includeData(['PUBLIC', 'INGAME', 'ACCOUNT']);

        return $this->render('account/page.accountSettings.default.html.twig', $this->settingsPageVariables());
    }

    /**
     * Template variables of the settings page (selectors, the account's current language, theme and timezone, back link).
     *
     * @return array<string, mixed>
     */
    private function settingsPageVariables(): array
    {
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;

        return [
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getLanguageOptions(),
            ],
            'id'            => $ACCOUNT['id'],
            'delete'        => $ACCOUNT['db_deaktjava'],
            'accountname'   => $ACCOUNT['accountname'],
            'userLang'      => $ACCOUNT['lang'],
            'theme'         => $ACCOUNT['dpath'],
            'timezone'      => $ACCOUNT['timezone'],
            'toplink'       => $this->router->generate('account_account_show'),
            'topname'       => $LNG->format('Back'),
            'social'        => $this->socialConnections((int) $ACCOUNT['id']),
            'socialNotice'  => self::SOCIAL_NOTICES[$this->input()->string('social')] ?? null,
        ];
    }

    /**
     * "Connected services": a row per provider that is switched on or still linked to the account (so a switched-off provider
     * can be disconnected): link data, the URL that connects it (intent=link) and whether disconnecting is allowed. The last
     * login method cannot be removed from an account without a usable password.
     *
     * @return list<array<string, mixed>>
     */
    private function socialConnections(int $accountId): array
    {
        $links = $this->social->linksOf($accountId);
        $canRemove = $this->social->hasUsablePassword($accountId) || count($links) > 1;
        $rows = [];
        foreach (SocialProvider::catalog() as $provider) {
            $enabled = $this->socialSettings->isUsable($provider->id);
            if (!$enabled && !isset($links[$provider->id])) {
                continue;
            }
            $rows[] = [
                'id' => $provider->id,
                'label' => $provider->label,
                'icon' => $provider->icon,
                'connected' => isset($links[$provider->id]),
                'email' => $links[$provider->id]['email'] ?? null,
                'connectUrl' => $enabled ? $this->router->generate('login_social_start', ['provider' => $provider->id, 'intent' => 'link']) : null,
                'disconnectUrl' => $this->router->generate('account_social_disconnect', ['provider' => $provider->id]),
                'canDisconnect' => $canRemove,
            ];
        }

        return $rows;
    }

    /**
     * Saves the account settings (mode=send) in one transaction. Request: password (current), newpassword and newpassword2,
     * email, language, theme, timezone (language/theme must be among the offered ones, otherwise the current is kept).
     * A new password needs the current one and matching repetitions; it revokes all sessions of the account and logs it out.
     * A changed e-mail needs the current password, a valid address that is not used by another account or pending
     * registration; it is stored with `setmail` = now + 7 days (change period) and a notification mail is sent to the old
     * address (email_2). Language, theme and timezone are always written. Ends with a message page.
     */
    #[GameRoute('account', 'account/settings', 'accountSettings', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['PUBLIC', 'INGAME', 'ACCOUNT']);
        $THEME = $this->state->THEME;
        // passwords are used exactly as typed (no HTML encoding, trimming or "?" for non-ASCII)
        $password           = $this->input()->rawString('password');
        $newpassword        = $this->input()->rawString('newpassword');
        $newpassword2       = $this->input()->rawString('newpassword2');
        $email              = $this->input()->string('email', $this->state->ACCOUNT['email']);
        $language           = $this->input()->string('language');
        $theme              = $this->input()->string('theme', $THEME->getThemeName());
        $timezone           = $this->input()->string('timezone');
        $timezone = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : (string) $this->state->ACCOUNT['timezone'];

        $language = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();
        $theme = array_key_exists($theme, Theme::getAvailableCssThemes()) ? $theme : $THEME->getThemeName();

        $db = $this->db;
        $LNG->includeData(['PUBLIC']);
        $db->startTransaction();
        $sql = "SELECT * FROM %%ACCOUNTS%% a WHERE a.id = :id FOR UPDATE;";
        $db->select($sql, [
            ':id' => $this->state->ACCOUNT['id'],
        ]);
        $this->assign($this->settingsPageVariables());
        if ($failure = $this->changePassword($password, $newpassword, $newpassword2)) {
            return $failure;
        }

        if ($failure = $this->changeEmail($email, $password)) {
            return $failure;
        }

        $sql =  "UPDATE %%ACCOUNTS%% SET
		lang				        = :lang,
		dpath				        = :theme,
        css_dpath                   = :cssTheme,
		timezone				    = :timezone
		WHERE id = :userID;";
        $db->update($sql, [
            ':lang'              => $language,
            ':theme'             => $theme,
            ':cssTheme'          => $theme,
            ':timezone'          => $timezone,
            ':userID'            => $this->state->ACCOUNT['id'],
        ]);
        $this->state->ACCOUNT['dpath'] = $theme;
        $this->state->ACCOUNT['css_dpath'] = $theme;
        $this->assign($this->settingsPageVariables());
        $db->commit();

        return $this->messageResponse($LNG['op_options_changed'], [
            [
                'label' => $LNG['sys_forward'],
                'url'   => $this->router->generate('account_account_settings_show'),
            ],
        ]);
    }

    /**
     * Checks the typed current password (limited per account: a hijacked session cannot guess it); a spent limit gives
     * the "too many attempts" message page instead of the verdict.
     */
    private function currentPasswordMatches(string $password): bool|Response
    {
        if (!$this->rateLimits->allowAccountSettingsPassword((int) $this->state->ACCOUNT['id'])) {
            return $this->messageResponse($this->state->LNG['login_error_4'], [[
                'label' => $this->state->LNG['sys_back'],
                'url'   => $this->router->generate('account_account_settings_show'),
            ]]);
        }

        return PasswordSupport::verifyAny($password, $this->state->ACCOUNT['password']);
    }

    /**
     * Sets a new password when the current one is given and correct and both repetitions match: re-hashes it, revokes
     * every session of the account and logs this one out. Otherwise nothing happens.
     *
     * @return Response|null the message page when the change is refused (too many attempts, password too short)
     */
    private function changePassword(mixed $password, mixed $newpassword, mixed $newpassword2): ?Response
    {
        $db = $this->db;
        if ($newpassword === '' || $password === '') {
            return null;
        }
        $matches = $this->currentPasswordMatches($password);
        if ($matches instanceof Response) {
            return $matches;
        }
        if ($matches && $newpassword === $newpassword2) {
            if (strlen($newpassword) < PasswordSupport::MIN_LENGTH) {
                return $this->messageResponse($this->state->LNG->format('registerErrorPasswordLength', PasswordSupport::MIN_LENGTH), [[
                    'label' => $this->state->LNG['sys_back'],
                    'url'   => $this->router->generate('account_account_settings_show'),
                ]]);
            }

            $newpass     = $this->accountUtil()->cryptPassword($newpassword);
            $sql = "UPDATE %%ACCOUNTS%% SET password = :newpass WHERE id = :accountID;";
            $db->update($sql, [
                ':newpass'  => $newpass,
                ':accountID'   => $this->state->ACCOUNT['id'],
            ]);
            // open reset links of the account do not survive a password change
            $db->update('UPDATE %%LOSTPASSWORD%% SET hasChanged = 1 WHERE accountID = :accountID AND hasChanged = 0;', [':accountID' => $this->state->ACCOUNT['id']]);
            // the new password ends every session of the account, this one included
            $this->sessions()->revokeAccountSessions((int) $this->state->ACCOUNT['id']);
            $this->state->auth->logout();
        }

        return null;
    }

    /**
     * Changes the e-mail address when it differs from the current one: needs the current password, a valid address that
     * no other account or pending registration uses (each failure ends with a message page), stores it with
     * `setmail` = now + 7 days and notifies the old address (email_2).
     *
     * @return Response|null the message page of a refused change
     */
    private function changeEmail(mixed $email, mixed $password): ?Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

        if (!empty($email) && $email != $this->state->ACCOUNT['email']) {
            $matches = $this->currentPasswordMatches((string) $password);
            if ($matches instanceof Response) {
                return $matches;
            }
            if (!$matches) {
                return $this->messageResponse($LNG['op_need_pass_mail'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => $this->router->generate('game_settings_show'),
                    ],
                ]);
            } elseif (!ValidateAddress($email)) {
                return $this->messageResponse($LNG['op_not_vaild_mail'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => $this->router->generate('account_account_settings_show'),
                    ],
                ]);
            } else {
                $sql = "SELECT"
                    . " (SELECT COUNT(*) FROM %%ACCOUNTS%% WHERE id != :accountID AND (email ="
                    . " :email OR email_2 = :email)) + (SELECT COUNT(*) FROM %%ACCOUNTS_VALID%% WHERE email = :email) as count";
                $Count = $db->selectSingle($sql, [
                    ':accountID'   => $this->state->ACCOUNT['id'],
                    ':email'    => $email,
                ], 'count');

                if (!empty($Count)) {
                    return $this->messageResponse($LNG->format('op_change_mail_exist', $email), [
                        [
                            'label' => $LNG['sys_back'],
                            'url'   => $this->router->generate('account_account_settings_show'),
                        ],
                    ]);
                } else {
                    $sql = "UPDATE %%ACCOUNTS%% SET email = :email, setmail = :time WHERE id = :userID;";
                    $db->update($sql, [
                        ':email'    => $email,
                        ':time'     => ($this->state->runtime->timestamp + 604800),
                        ':userID'   => $this->state->ACCOUNT['id'],
                    ]);

                    $config = $this->configs->get();

                    $MailRAW = $LNG->getTemplate('email_mail_changed');
                    $MailContent = str_replace([
                        '{USERNAME}',
                        '{GAMENAME}',
                        '{DISCORD}',
                    ], [
                        $this->state->ACCOUNT['accountname'],
                        $config->game_name,
                        $this->configs->get()->discord_url,
                    ], $MailRAW);

                    $subject = $LNG->formatIn(['mail', 'subject', 'mail_changed'], $config->game_name);
                    $this->mailer()->send($this->state->ACCOUNT['email_2'], $this->state->ACCOUNT['accountname'], $subject, $MailContent);
                }
            }
        }

        return null;
    }

    /**
     * Toggles the account deletion flag (`db_deaktjava` = timestamp of the request, or 0 to cancel). The flag is acted on
     * later by the maintenance cronjob. Ends with a message page.
     */
    #[GameRoute('account', 'account/settings/delete', 'accountSettings', 'toggleDelete', ['POST'])]
    public function toggleDelete(): Response
    {
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;

        $db = $this->db;
        $db->startTransaction();

        $sql = "SELECT id FROM %%ACCOUNTS%% WHERE id = :accountID FOR UPDATE;";
        $db->selectSingle($sql, [
            ':accountID'   => $ACCOUNT['id'],
        ]);
        $this->assign([
            'toplink'       => $this->router->generate('account_account_show'),
            'topname'       => $LNG->format('Back'),
        ]);
        if ($ACCOUNT['db_deaktjava'] === 0) {
            $sql = "UPDATE %%ACCOUNTS%% SET db_deaktjava = :timestamp WHERE id = :accountID;";
            $db->update($sql, [
                ':accountID'       => $ACCOUNT['id'],
                ':timestamp'    => $this->state->runtime->timestamp,
            ]);
            $db->commit();

            $text = new \Twig\Markup(
                htmlspecialchars($LNG['op_options_deletion_activated'], ENT_QUOTES, 'UTF-8', false) . '<br>' . htmlspecialchars($LNG['op_options_no_other_settings_changed'], ENT_QUOTES, 'UTF-8', false),
                'UTF-8',
            );
            return $this->messageResponse($text, [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => $this->router->generate('account_account_settings_show'),
                ],
            ]);
        } else {
            $sql = "UPDATE %%ACCOUNTS%% SET db_deaktjava = 0 WHERE id = :accountID;";
            $db->update($sql, [':accountID'   => $ACCOUNT['id']]);
            $db->commit();

            $text = new \Twig\Markup(
                htmlspecialchars($LNG['op_options_deletion_deactivated'], ENT_QUOTES, 'UTF-8', false) . '<br>' . htmlspecialchars($LNG['op_options_no_other_settings_changed'], ENT_QUOTES, 'UTF-8', false),
                'UTF-8',
            );
            return $this->messageResponse($text, [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => $this->router->generate('account_account_settings_show'),
                ],
            ]);
        }
    }
}
