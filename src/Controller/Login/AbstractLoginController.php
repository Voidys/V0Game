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

namespace App\Controller\Login;

use App\Controller\GameControllerInterface;
use App\Controller\GuardsModule;
use App\Controller\ModuleGuard;
use App\Controller\RendersPages;
use App\Game\Core\EndOfRequest;

/**
 * Base for the public pages (start page, registration, news, hall of fame, ...).
 *
 * The window is $defaultWindow ("normal", the start page frame), or "ajax" for XHR requests.
 * Every action has its own route (GameRoute); pages render with RendersPages and get the game name,
 * language and universe data of the login layout. save() runs before every response.
 */
abstract class AbstractLoginController implements GameControllerInterface, ModuleGuard
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use GuardsModule;

    use RendersPages {
        render as private renderPage;
    }

    protected static function templateArea(): string
    {
        return 'login';
    }

    /** Error page of the area for GuardsModule (module inactive); ends the request. */
    protected function printPageError(string $message): never
    {
        $this->printMessage($message);
    }

    /** window of non-ajax requests (layout.<window>.html.twig) */
    public $defaultWindow = 'normal';

    /**
     * Picks the layout window: `ajax` for XHR requests, otherwise $defaultWindow. No login is required for these pages.
     */
    public function initialize(): void
    {
        if (!$this->state->runtime->ajax) {
            $this->setWindow($this->defaultWindow);
        } else {
            $this->setWindow('ajax');
        }
    }

    protected \App\Security\BotProtection $botProtection;

    /** Container (setter) injection of the bot check used by the public forms. */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setBotProtection(\App\Security\BotProtection $botProtection): void
    {
        $this->botProtection = $botProtection;
    }

    /**
     * The message page (with a way back to the form) unless the form carries a valid bot-check solution (field `pow`)
     * and an empty honeypot (field `website`), see BotProtection; null when the check passed.
     */
    protected function botCheckFailure(string $backUrl, string $backLabel): ?\Symfony\Component\HttpFoundation\Response
    {
        $passed = $this->botProtection->passes(
            $this->input()->rawString(\App\Security\BotProtection::FIELD),
            $this->input()->rawString(\App\Security\BotProtection::HONEYPOT),
            $this->sessions()->getClientIp(),
        );

        return $passed ? null : $this->messageResponse($this->state->LNG['powFailed'], [['label' => $backLabel, 'url' => $backUrl]]);
    }

    /**
     * Universe dropdown data: universe id => universe name, for all available universes.
     */
    protected function getUniverseSelector()
    {
        $universeSelect	= [];
        foreach ($this->universe()->availableUniverses() as $uniId) {
            $universeSelect[$uniId]	= $this->configs->get($uniId)->uni_name;
        }
        return $universeSelect;
    }

    /**
     * The message page of the area.
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param list<array{url: string, label: string}>|null $redirectButtons links under the message
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     * @param bool $fullSide false: popup window
     */
    protected function messageResponse($message, $redirectButtons = null, $redirect = null, $fullSide = true): \Symfony\Component\HttpFoundation\Response
    {
        \App\Routing\PublicCache::withdraw($this->state->request);
        $context = [
            'message'			=> $message,
            'redirectButtons'	=> $redirectButtons,
        ];

        if (isset($redirect)) {
            $context += ['goto' => $redirect[0], 'gotoinsec' => $redirect[1]];
        }

        if (!$fullSide) {
            $this->setWindow('popup');
        }

        return $this->render('login/error.default.html.twig', $context);
    }

    /**
     * Ends the request with the message page: for initialize() and helpers that cannot return the Response (an action
     * returns messageResponse()).
     *
     * @param string|\Twig\Markup $message
     * @param list<array{url: string, label: string}>|null $redirectButtons
     * @param array{0: string, 1: int}|null $redirect
     * @param bool $fullSide false: popup window
     */
    protected function printMessage($message, $redirectButtons = null, $redirect = null, $fullSide = true): never
    {
        throw EndOfRequest::withResponse($this->messageResponse($message, $redirectButtons, $redirect, $fullSide));
    }

    /**
     * Runs $work while holding the named MySQL lock `registration`, so that "is the name/e-mail free?" and the insert
     * (or the claim of a verification link and the account creation) of parallel requests cannot interleave. The lock
     * is released afterwards, also when $work ends the request or throws.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    protected function withRegistrationLock(callable $work): mixed
    {
        $locked = (int) $this->db->selectSingle("SELECT GET_LOCK('registration', 10) AS locked;", [], 'locked');
        if ($locked !== 1) {
            throw new \RuntimeException('Could not get the registration lock.');
        }
        try {
            return $work();
        } finally {
            $this->db->selectSingle("SELECT RELEASE_LOCK('registration') AS released;", [], 'released');
        }
    }

    /** hook run before every response (pages that write data on the way out) */
    protected function save() {}

    /**
     * Saves the game state and renders the template inside the public layout.
     *
     * @param array<string, mixed> $context
     */
    protected function render(string $template, array $context = []): \Symfony\Component\HttpFoundation\Response
    {
        $this->save();

        return $this->renderPage($template, $context);
    }

    protected function beforeResponse(): void
    {
        $this->save();
    }

    /**
     * Variables of the public layout: game name, mail/referral switches, language list, version, base URL (with the `uniN.`
     * host part removed when universes are served from wildcard subdomains) and whether several universes exist.
     */
    protected function layoutData(string $template): array
    {
        $LNG = $this->state->LNG;
        $config	= $this->configs->get();

        $data = [];
        if ($this->getWindow() !== 'ajax') {
            $data = [
                'gameName' 				=> $config->game_name,
                'mailEnable'			=> $config->mail_active,
                // "stay logged in" checkbox on the login form, 0 = option off
                'rememberMeDays'		=> (int) ($config->remember_me_days ?? \App\Security\SessionPolicy::DEFAULT_REMEMBER_DAYS),
                'referralEnable'		=> $config->ref_active,
                'lang'					=> $LNG->getLanguage(),
                'UNI'					=> $this->universe()->current(),
                'version'				=> $config->version,
                'REV'					=> substr($config->version, -4),
                'languages'				=> $LNG->getLanguageOptions(),
                // name of the account with a valid login (existing session only), null for guests
                'loginAccountName'		=> $this->state->auth?->account()?->getAccountName(),
            ];
        }

        if (UNIS_WILDCAST) {
            $hostParts = explode('.', $this->state->runtime->httpHost);
            if (preg_match('/uni[0-9]+/', $hostParts[0])) {
                array_shift($hostParts);
            }
            $host = implode('.', $hostParts);
            $basePath = $this->state->runtime->protocol . $host . $this->state->runtime->httpBase;
        } else {
            $basePath = $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase;
        }

        return array_replace($data, [
            'lang'    			=> $LNG->getLanguage(),
            'bodyclass'			=> $this->getWindow(),
            'basepath'			=> $basePath,
            'isMultiUniverse'	=> count($this->universe()->availableUniverses()) > 1,
            'unisWildcast'		=> UNIS_WILDCAST,
        ]);
    }

    /**
     * Ends the request with a JSON answer (helpers; an action returns jsonResponse()).
     */
    protected function sendJSON($data): never
    {
        throw EndOfRequest::withResponse($this->jsonResponse($data));
    }

    /**
     * Ends the request with a redirect to $url (initialize() and helpers; an action returns redirectResponse()).
     */
    protected function redirectTo($url): never
    {
        throw EndOfRequest::withResponse($this->redirectResponse($url));
    }
}
