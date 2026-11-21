<?php

namespace App\Controller\Account;

use App\Routing\GameRoute;
use App\Security\Social\SocialAccounts;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/** Disconnects a social login provider from the account (the connect side is the social login flow with intent=link). */
class SocialConnectionsController extends AbstractAccountController
{
    private SocialAccounts $social;

    /** Container (setter) injection of the social login links. */
    #[Required]
    public function setSocialAccounts(SocialAccounts $social): void
    {
        $this->social = $social;
    }

    /**
     * Removes the link of the path's provider from the logged-in account (POST, CSRF protected). Refused when it is the last
     * login method of an account that has no usable password (it would lock the player out). Back to the settings page with the
     * result in the query `social` (unlinked | last).
     */
    #[GameRoute('account', 'account/social/{provider}/disconnect', 'social', 'disconnect', ['POST'], ['provider' => 'discord|facebook|github|google'])]
    public function disconnect(): Response
    {
        $accountId = (int) $this->state->ACCOUNT['id'];
        $provider = $this->input()->rawString('provider');
        $links = $this->social->linksOf($accountId);

        if (isset($links[$provider]) && count($links) <= 1 && !$this->social->hasUsablePassword($accountId)) {
            return $this->redirectToRoute('account_account_settings_show', ['social' => 'last']);
        }
        $result = $this->social->unlink($accountId, $provider) ? 'unlinked' : null;

        return $this->redirectToRoute('account_account_settings_show', $result === null ? [] : ['social' => $result]);
    }
}
