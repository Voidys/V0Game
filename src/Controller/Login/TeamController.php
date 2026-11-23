<?php

/**
 *  2Moons
 *   by Timo_Ka 2023
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package pr0game
 * @copyright 2023 Timo_Ka
 * @licence MIT
 * @version 1.8.0
 * @link https://codeberg.org/pr0game/pr0game
 */

namespace App\Controller\Login;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Public team page (members from the admin team section). */
class TeamController extends AbstractLoginController
{
    use \App\Controller\Shared\TeamPage {
        show as protected renderTeam;
    }

    #[GameRoute('index', 'team', 'team')]
    #[\App\Routing\PublicCache(300)]
    public function show(): Response
    {
        return $this->renderTeam();
    }
}
