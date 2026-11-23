<?php

namespace App\Controller\Login;

use App\Routing\GameRoute;
use App\Security\PasswordSupport;
use App\Security\Social\OAuthClient;
use App\Security\Social\SocialAccounts;
use App\Security\Social\SocialFlowState;
use App\Security\Social\SocialIdentity;
use App\Security\Social\SocialLoginException;
use App\Security\Social\SocialProvider;
use App\Security\Social\SocialOutcome;
use App\Security\Social\SocialRateLimits;
use App\Security\Social\SocialResolver;
use App\Security\Social\SocialSettings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Social login (OAuth2 authorization code flow with PKCE) with Discord, Facebook, GitHub and Google. Setup: "Social login" in README.md.
 *
 * Steps: start (redirect to the provider) -> callback (state check, code exchange, user lookup) -> resolution:
 * a linked identity logs its account in; an identity that is not linked is never attached to an account by its e-mail
 * address alone: it waits in the session (15 minutes) until the owner of the account with that address proves it with the
 * password (link step), or, without such an account, finishes a short registration (complete step). Logging in uses
 * GameAuth::login(), the same path as the e-mail verification link, so session renewal and account checks (bans are
 * enforced by the game boot after the login) are those of the password login.
 */
class SocialLoginController extends AbstractLoginController
{
    private SocialSettings $settings;
    private SocialAccounts $social;
    private SocialFlowState $flow;
    private OAuthClient $oauth;
    private SocialRateLimits $limits;
    private SocialResolver $resolver;

    /** Container (setter) injection of the social login services. */
    #[Required]
    public function setSocialServices(SocialSettings $settings, SocialAccounts $social, SocialFlowState $flow, OAuthClient $oauth, SocialRateLimits $limits, SocialResolver $resolver): void
    {
        $this->settings = $settings;
        $this->social = $social;
        $this->flow = $flow;
        $this->oauth = $oauth;
        $this->limits = $limits;
        $this->resolver = $resolver;
    }

    /**
     * Sends the browser to the provider (path placeholder provider; only enabled and configured providers of the
     * whitelist, anything else is a 404). Stores state, PKCE verifier and intent in the session. Query `intent=link` (only
     * honoured while an account is logged in) connects the provider to that account instead of logging in.
     */
    #[GameRoute('index', 'login/{provider}', 'social', 'start', requirements: ['provider' => 'discord|facebook|github|google'])]
    public function start(): Response
    {
        $provider = $this->usableProvider();
        $redirectUri = $provider === null ? null : $this->callbackUrl($provider);
        if ($provider === null || $redirectUri === null) {
            return $this->unavailable();
        }
        if (!$this->limits->allowFlow($this->sessions()->getClientIp())) {
            return $this->failure($this->state->LNG['login_error_4']);
        }

        $intent = $this->input()->string('intent') === 'link' && $this->loggedInAccountId() > 0 ? 'link' : 'login';
        $request = $this->flow->begin($this->session(), $provider->id, $intent, $this->state->runtime->timestamp);
        [$clientId] = $this->settings->credentials($provider->id);

        return $this->redirectResponse($this->oauth->authorizeUrl($provider, $clientId, $redirectUri, $request['state'], $request['challenge']), true);
    }

    /**
     * Return address of the provider (the callback URL registered there; always at the site root). Request: state, code,
     * or error (the person cancelled). The stored state is single use and valid for 10 minutes; the code is exchanged
     * server to server (client secret, PKCE verifier, 10 s timeout) and the user looked up. Then see resolve().
     */
    #[GameRoute('index', 'login/{provider}/callback', 'social', 'callback', requirements: ['provider' => 'discord|facebook|github|google'])]
    public function callback(): Response
    {
        $LNG = $this->state->LNG;
        $provider = $this->usableProvider();
        $redirectUri = $provider === null ? null : $this->callbackUrl($provider);
        if ($provider === null || $redirectUri === null) {
            return $this->unavailable();
        }
        if (!$this->limits->allowFlow($this->sessions()->getClientIp())) {
            return $this->failure($LNG['login_error_4']);
        }

        $flow = $this->flow->consume($this->session(), $provider->id, $this->input()->rawString('state'), $this->state->runtime->timestamp);
        if ($flow === null) {
            return $this->failure($LNG['socialErrorState']);
        }
        $code = $this->input()->rawString('code');
        if ($this->input()->rawString('error') !== '' || $code === '') {
            return $this->failure($LNG['socialErrorCancelled']);
        }

        [$clientId, $clientSecret] = $this->settings->credentials($provider->id);
        try {
            $identity = $this->oauth->fetchIdentity($provider, $clientId, $clientSecret, $code, $redirectUri, $flow['verifier']);
        } catch (SocialLoginException $e) {
            error_log($e->getMessage());

            return $this->failure($LNG['socialErrorProvider']);
        }

        return $this->resolve($identity, $flow['intent']);
    }

    /**
     * Turns the decision of SocialResolver into a page: log in, back to the settings (connected / taken), the link page (password
     * of the account with the same verified e-mail), the registration page or a message when there is no verified address.
     */
    private function resolve(SocialIdentity $identity, string $intent): Response
    {
        $now = $this->state->runtime->timestamp;
        [$outcome, $accountId] = $this->resolver->resolve($identity, $intent, $this->loggedInAccountId(), $now);

        switch ($outcome) {
            case SocialOutcome::Login:
                return $this->loginResponse($accountId, $identity);
            case SocialOutcome::Linked:
                return $this->redirectToRoute('account_account_settings_show', ['social' => 'linked']);
            case SocialOutcome::Taken:
                return $this->redirectToRoute('account_account_settings_show', ['social' => 'taken']);
            case SocialOutcome::NoEmail:
                return $this->failure($this->state->LNG['socialErrorNoEmail']);
            default:
                $this->flow->storePending($this->session(), $identity, $now);

                return $this->redirectToRoute($outcome === SocialOutcome::LinkWithPassword ? 'login_social_link_show' : 'login_social_complete_show');
        }
    }

    /** "Link your account": explains the step and asks for the password of the account that has the provider's e-mail address. */
    #[GameRoute('index', 'login/social/link', 'socialLink', 'show')]
    public function linkShow(): Response
    {
        $identity = $this->pendingIdentity();
        $account = $identity === null ? null : $this->accountOf($identity);
        if ($identity === null || $account === null) {
            return $this->expired();
        }

        return $this->render('login/page.socialLink.default.html.twig', [
            'provider' => SocialProvider::find($identity->provider)?->label ?? '',
            'email' => $identity->email,
        ]);
    }

    /**
     * Link step (POST, request: password). Limited per IP and target account; a wrong password counts as a failed login of
     * that account. The right password creates the link (once) and logs the account in.
     */
    #[GameRoute('index', 'login/social/link', 'socialLink', 'send', ['POST'])]
    public function linkSend(): Response
    {
        $LNG = $this->state->LNG;
        $identity = $this->pendingIdentity();
        $account = $identity === null ? null : $this->accountOf($identity);
        if ($identity === null || $account === null) {
            return $this->expired();
        }
        if (!$this->limits->allowLinkPassword($this->sessions()->getClientIp(), $account['id'])) {
            return $this->failure($LNG['login_error_4']);
        }
        $outcome = $this->resolver->verifyAndLink($account, $identity, $this->input()->rawString('password'), $this->state->runtime->timestamp);
        if ($outcome === SocialOutcome::WrongPassword) {
            $this->db->update('UPDATE %%ACCOUNTS%% SET `failed_logins` = LEAST(`failed_logins` + 1, 100) WHERE `id` = :id;', [':id' => $account['id']]);

            return $this->failure($LNG['socialLinkWrong'], $this->router->generate('login_social_link_show'));
        }
        if ($outcome === SocialOutcome::Taken) {
            $this->flow->clearPending($this->session());

            return $this->failure($LNG['socialErrorTaken']);
        }
        $this->flow->clearPending($this->session());

        return $this->loginResponse($account['id'], $identity);
    }

    /** "Complete registration": player name (suggested from the provider's name), rules and privacy for a new account. */
    #[GameRoute('index', 'login/social/complete', 'socialComplete', 'show')]
    public function completeShow(): Response
    {
        $identity = $this->pendingIdentity();
        if ($identity === null || $identity->usableEmail() === null || $this->accountOf($identity) !== null) {
            return $this->expired();
        }
        $lang = $this->state->requestCookies['lang'] ?? 'de';

        return $this->render('login/page.socialComplete.default.html.twig', [
            'provider' => SocialProvider::find($identity->provider)?->label ?? '',
            'email' => $identity->email,
            'accountName' => $identity->suggestedName(),
            'rulesUrl' => $this->router->generate('login_rules', ['lang' => $lang]),
            'privacyUrl' => $this->router->generate('login_data_privacy_show', ['lang' => $lang]),
        ]);
    }

    /**
     * Registration step (POST, request: username, rules, privacy). Limited per IP. Same rules as the registration form
     * (valid and free name, rules and privacy accepted, e-mail not used by an account or pending registration), checked and
     * written under the registration lock. The provider counts as the human check (no proof of work) and as the verification
     * of the e-mail address. The account gets a password nobody can match (the player can set one with "lost password"),
     * then the identity is linked and the account logged in (it picks a universe in the account lobby).
     */
    #[GameRoute('index', 'login/social/complete', 'socialComplete', 'send', ['POST'])]
    public function completeSend(): Response
    {
        $LNG = $this->state->LNG;
        $identity = $this->pendingIdentity();
        $email = $identity?->usableEmail();
        if ($identity === null || $email === null) {
            return $this->expired();
        }
        if (!$this->limits->allowComplete($this->sessions()->getClientIp())) {
            return $this->failure($LNG['login_error_4']);
        }

        $userName = $this->input()->string('username', '', UTF8_SUPPORT);
        $rules = $this->input()->int('rules');
        $privacy = $this->input()->int('privacy');
        $accountId = 0;
        $errors = $this->withRegistrationLock(function () use (&$accountId, $identity, $email, $userName, $rules, $privacy): array {
            $errors = $this->registrationErrors($userName, $email, $rules, $privacy);
            if ($errors === []) {
                $accountId = $this->createLinkedAccount($identity, $userName, $email);
            }

            return $errors;
        });
        if ($errors !== []) {
            return $this->messageResponse(new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errors)), 'UTF-8'), [[
                'label' => $LNG['registerBack'],
                'url' => $this->router->generate('login_social_complete_show'),
            ]]);
        }
        $this->flow->clearPending($this->session());

        return $this->loginResponse($accountId, $identity);
    }

    /** Cancels a waiting link or registration step (POST) and goes back to the start page. */
    #[GameRoute('index', 'login/social/cancel', 'socialCancel', 'send', ['POST'])]
    public function cancel(): Response
    {
        $this->flow->clearPending($this->session());

        return $this->redirectToRoute('login_index_show');
    }

    /**
     * Validation errors of the registration step (translated, in display order): name empty, invalid characters or
     * longer than 32, taken; e-mail used by an account or a pending registration or on the disposable list; rules, privacy.
     *
     * @return string[]
     */
    private function registrationErrors(string $userName, string $email, mixed $rules, mixed $privacy): array
    {
        $LNG = $this->state->LNG;
        $errors = [];
        if ($userName === '') {
            $errors[] = $LNG['registerErrorUsernameEmpty'];
        }
        if (!$this->accountUtil()->isNameValid($userName) || mb_strlen($userName) > 32) {
            $errors[] = $LNG['registerErrorUsernameChar'];
        }
        if ($rules != 1) {
            $errors[] = $LNG['registerErrorRules'];
        }
        if ($privacy != 1) {
            $errors[] = $LNG['registerErrorDataPrivacy'];
        }
        if ($userName !== '' && $this->social->nameTaken($userName)) {
            $errors[] = $LNG['registerErrorUsernameExist'];
        }
        if (\App\Game\Core\DisposableEmailDomainBlocklist::isBlocked($email)) {
            $errors[] = $LNG['registerErrorMailInvalid'];
        }
        if ($this->social->emailTaken($email)) {
            $errors[] = $LNG['registerErrorMailExist'];
        }

        return $errors;
    }

    /** Creates the account (language of the visitor, default timezone, unusable password) and links the identity to it. */
    private function createLinkedAccount(SocialIdentity $identity, string $userName, string $email): int
    {
        $LNG = $this->state->LNG;
        $accountId = (int) $this->accountUtil()->createAccount(
            $userName,
            SocialAccounts::unusablePassword(),
            $email,
            $LNG->getLanguage(),
            0,
            null,
            (string) $this->configs->get()->timezone,
        );
        $this->social->link($accountId, $identity, $this->state->runtime->timestamp);

        return $accountId;
    }

    /**
     * Logs the account in like the password login does (session migration and token by GameAuth::login(), failed-login
     * counter reset, "account" cookie) and goes to the account lobby.
     */
    private function loginResponse(int $accountId, SocialIdentity $identity): Response
    {
        $this->state->auth->login($accountId);
        $this->db->update('UPDATE %%ACCOUNTS%% SET `failed_logins` = 0 WHERE `id` = :id;', [':id' => $accountId]);
        $this->social->touchLogin($identity, $this->state->runtime->timestamp);

        $response = $this->redirectResponse('account?enter=1');
        $response->headers->setCookie(Cookie::create('account', 'account', 2147483647, '/', httpOnly: false));

        return $response;
    }

    /** The provider of the path, null unless it is whitelisted, switched on and configured. */
    private function usableProvider(): ?SocialProvider
    {
        $provider = SocialProvider::find($this->input()->rawString('provider'));

        return $provider !== null && $this->settings->isUsable($provider->id) ? $provider : null;
    }

    /** 404 page for a provider that is unknown, switched off or not configured (no hint which of them). */
    private function unavailable(): Response
    {
        return $this->failure($this->state->LNG['socialErrorUnavailable'])->setStatusCode(Response::HTTP_NOT_FOUND);
    }

    /** The exact callback URL (the same string goes into the authorize and the token request), null without a canonical site URL. */
    private function callbackUrl(SocialProvider $provider): ?string
    {
        return $this->canonicalUrl->siteUrl($this->state->runtime, 'login/' . $provider->id . '/callback');
    }

    /** The waiting identity of this session (null when none or older than 15 minutes). */
    private function pendingIdentity(): ?SocialIdentity
    {
        return $this->flow->pending($this->session(), $this->state->runtime->timestamp);
    }

    /**
     * The account the identity's verified e-mail address belongs to (looked up again at every step).
     *
     * @return array{id: int, password: string}|null
     */
    private function accountOf(SocialIdentity $identity): ?array
    {
        return $this->resolver->accountOf($identity);
    }

    /** Id of the account logged in now, 0 for a visitor. */
    private function loggedInAccountId(): int
    {
        return (int) ($this->state->auth?->accountId() ?? 0);
    }

    private function session(): SessionInterface
    {
        return $this->state->request->getSession();
    }

    /** Message page for a pending step that is missing or expired: back to the start page. */
    private function expired(): Response
    {
        return $this->failure($this->state->LNG['socialErrorExpired']);
    }

    /** Message page with a way back (the start page unless $backUrl is given). */
    private function failure(string $message, ?string $backUrl = null): Response
    {
        return $this->messageResponse($message, [[
            'label' => $this->state->LNG['registerBack'],
            'url' => $backUrl ?? $this->router->generate('login_index_show'),
        ]]);
    }
}
