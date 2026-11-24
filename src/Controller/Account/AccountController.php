<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use App\Game\Enum\AuthLevel;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Account lobby: lists the universes with the account's players and enters one (modes: show, send). */
class AccountController extends AbstractAccountController
{
    /**
     * Account lobby (/account): lists the universes the account has a player in and the ones it can still register in.
     */
    #[GameRoute('account', 'account', 'account')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['INGAME', 'ACCOUNT']);
        $ACCOUNT = $this->state->ACCOUNT;
        $newUniverses = $this->accountUtil()->get_non_player_universes($ACCOUNT['id']);
        $playerUniverses = $this->accountUtil()->get_player_universes($ACCOUNT['id']);

        // right after the login (?enter=1 from the login redirect) a single, open universe is entered directly; the plain lobby URL always shows the lobby
        if ($this->input()->int('enter') === 1 && count($playerUniverses) === 1 && empty($newUniverses)) {
            $only = $playerUniverses[0];
            $open = in_array($only['uni_status'], [STATUS_OPEN, STATUS_LOGIN_ONLY]) || $only['authlevel'] == AuthLevel::Admin->value;
            if ($open) {
                return $this->enterUniverse((int) $only['user_id'], (int) $only['uni']);
            }
        }

        return $this->render('account/page.account.default.html.twig', [
            'id'                => $ACCOUNT['id'],
            'username'          => $ACCOUNT['accountname'],
            'authlevel'         => $ACCOUNT['authlevel'],
            'newUniverses'      => $newUniverses,
            'playerUniverses'   => $playerUniverses,
            'toplink'           => $this->router->generate('account_account_settings_show'),
            'topname'           => $LNG->format('Settings'),
        ]);
    }

    /**
     * Enters a universe (mode=send): POST userID (player id); the universe comes from the player.
     * Checks that the player belongs to the logged in account (ACCOUNTS_TO_USERS), switches the session to that player and universe
     * (admin access is dropped) and redirects to /game. A foreign player id redirects back to the lobby.
     */
    #[GameRoute('account', 'account/enter', 'account', 'send', ['POST'])]
    public function send(): Response
    {
        $ACCOUNT = $this->state->ACCOUNT;
        $userID = $this->input()->int('userID');

        $db = $this->db;
        // the universe is the one the player lives in (the request's `universe` is not trusted)
        $sql = "SELECT u.`universe` FROM %%ACCOUNTS_TO_USERS%% au INNER JOIN %%USERS%% u ON u.`id` = au.`user_id` WHERE au.`account_id` = :accountId AND au.`user_id` = :userId AND au.`end_time` = 0;";
        $universe = $db->selectSingle($sql, [':accountId' => $ACCOUNT['id'], ':userId' => $userID], 'universe');

        if (empty($universe)) {
            return $this->redirectToRoute('account_account_show');
        }

        return $this->enterUniverse($userID, $universe);
    }

    /** Switches the session to the player and universe (admin access is dropped) and redirects to /game. */
    private function enterUniverse(int $userID, mixed $universe): Response
    {
        // the lobby speaks the language of the player the account entered last
        $this->db->update(
            'UPDATE %%ACCOUNTS%% a INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = :userId SET a.`lang` = us.`lang` WHERE a.`id` = :accountId AND us.`lang` != \'\';',
            [':userId' => $userID, ':accountId' => $this->state->ACCOUNT['id']],
        );
        $session	= $this->sessions()->create();
        $session->userId		= $userID;
        $session->universe      = $universe;
        $session->planetId      = 0;
        $session->adminAccess	= 0;
        $session->save();

        return $this->redirectResponse('game');
    }
}
