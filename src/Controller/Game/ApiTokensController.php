<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Api\ApiTokens;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal tokens of the read-only player API (/api/v1, see App\Controller\Api\ApiController): list, create (the plaintext token
 * is shown once, on the answer of the create action) and revoke, plus the short API documentation.
 */
class ApiTokensController extends AbstractGameController
{
    protected $disableEcoSystem = true;

    private ApiTokens $tokens;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setApiTokens(ApiTokens $tokens): void
    {
        $this->tokens = $tokens;
    }

    /** The token page: active tokens, the create form and the API documentation. */
    #[GameRoute('game', 'game/api', 'api')]
    public function show(): Response
    {
        $flash = $this->state->request->getSession()->getFlashBag()->get('api_token');

        return $this->page($flash[0] ?? null, null);
    }

    /**
     * POST name, scopes[] (reports, empire): creates a token and redirects to the page, which shows the plaintext token once (session flash).
     * At the limit of active tokens or without a name the page shows a notice instead.
     */
    #[GameRoute('game', 'game/api/create', 'api', 'create', ['POST'])]
    public function create(): Response
    {
        $LNG = $this->state->LNG;
        $name = $this->input()->string('name');
        $scopes = array_values(array_intersect(ApiTokens::SCOPES, $this->input()->stringList('scopes')));
        if ($name === '' || $scopes === []) {
            return $this->page(null, $LNG['api_notice_invalid']);
        }

        $token = $this->tokens->create((int) $this->state->USER['id'], (int) $this->state->USER['universe'], $name, $scopes, $this->state->runtime->timestamp);
        if ($token === null) {
            return $this->page(null, $LNG->format('api_notice_limit', ApiTokens::MAX_ACTIVE));
        }

        // post-redirect-get: the plaintext waits in the session flash for exactly one display
        $this->state->request->getSession()->getFlashBag()->add('api_token', $token);

        return $this->redirectToRoute('game_api_show');
    }

    /** POST id: revokes one of the player's tokens. */
    #[GameRoute('game', 'game/api/revoke', 'api', 'revoke', ['POST'])]
    public function revoke(): Response
    {
        $this->tokens->revoke((int) $this->state->USER['id'], $this->input()->int('id'));

        return $this->redirectToRoute('game_api_show');
    }

    /** @param string|null $newToken plaintext of the token just created */
    private function page(?string $newToken, ?string $notice): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $tokens = $this->tokens->listFor((int) $USER['id']);
        foreach ($tokens as &$token) {
            $token['created'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $token['created_at'], $USER['timezone'], $LNG);
            $token['used'] = (int) $token['last_used_at'] > 0
                ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $token['last_used_at'], $USER['timezone'], $LNG)
                : '';
        }

        return $this->render('game/page.api.default.html.twig', [
            'apiTokens' => $tokens,
            'apiNewToken' => $newToken,
            'apiNotice' => $notice,
            'apiMax' => ApiTokens::MAX_ACTIVE,
            'apiScopes' => ApiTokens::SCOPES,
            'apiBase' => substr($this->routeUrl('api_v1_me'), 0, -3),
        ]);
    }
}
