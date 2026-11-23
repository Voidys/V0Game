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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Public contact/imprint page, rendered from the CMS page "contacts". */
class DisclamerController extends AbstractLoginController
{
    private \App\Content\ContentPages $contentPages;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setContentPages(\App\Content\ContentPages $contentPages): void
    {
        $this->contentPages = $contentPages;
    }

    /**
     * Renders the CMS page "contacts" (imprint) in the public layout.
     */
    #[GameRoute('index', 'disclaimer', 'disclamer')]
    #[\App\Routing\PublicCache(300)]
    public function show(): Response
    {
        $page = $this->contentPages->find('contacts', $this->state->LNG->getLanguage());

        return $this->render('login/page.disclamer.default.html.twig', [
            'cms_title' => htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'),
            'cms_content' => new \Twig\Markup($page['content'], 'UTF-8'), // sanitized by ContentPages
        ]);
    }
}
