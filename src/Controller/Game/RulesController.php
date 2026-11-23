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

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Game rules page.
 */
class RulesController extends AbstractGameController
{
    private \App\Content\ContentPages $contentPages;
    private \App\Content\HtmlContent $htmlContent;
    private \App\Game\Core\UniverseRules $universeRules;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setContentPages(\App\Content\ContentPages $contentPages, \App\Content\HtmlContent $htmlContent, \App\Game\Core\UniverseRules $universeRules): void
    {
        $this->universeRules = $universeRules;
        $this->contentPages = $contentPages;
        $this->htmlContent = $htmlContent;
    }

    /**
     * Rules page: the same CMS page as the public /rules (edited row, else the default text unless blank rules were
     * chosen) in the player's language; empty rules show a notice. Above it the universe settings (UniverseRules).
     */
    #[GameRoute('game', 'game/rules', 'rules')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['PUBLIC']);
        $page = $this->contentPages->find('rules', $LNG->getLanguage());
        $empty = $this->htmlContent->isEmpty($page['content']);
        return $this->render('game/page.rules.default.html.twig', [
            // sanitized CMS HTML (ContentPages) or the trusted default template
            'rules' => $empty ? '' : new \Twig\Markup($page['content'], 'UTF-8'),
            'rulesEmpty' => $empty,
            'universeRules' => $this->universeRules->groups(),
        ]);
    }
}
