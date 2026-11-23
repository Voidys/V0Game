<?php

/**
 *  2Moons
 *   by Timo_Ka 2024
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package pr0game
 * @copyright 2024 Timo_Ka
 * @licence MIT
 * @version 1.8.0
 * @link https://codeberg.org/pr0game/pr0game
 */

namespace App\Controller\Login;

use App\Routing\GameRoute;
use App\Community\HallOfFame;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/** Public hall of fame: the rankings imported per universe (admin page hallOfFame). */
class HofController extends AbstractLoginController
{
    private HallOfFame $hallOfFame;

    #[Required]
    public function setHallOfFame(HallOfFame $hallOfFame): void
    {
        $this->hallOfFame = $hallOfFame;
    }

    /**
     * Hall of fame of one universe. Request: universe (int; default the highest universe that has data). The dropdown lists
     * only universes with data; without any data the page shows an empty notice.
     */
    #[GameRoute('index', 'hall-of-fame', 'hof')]
    #[\App\Routing\PublicCache(300)]
    public function show(): Response
    {
        $withData = $this->hallOfFame->universesWithData();
        $universeSelect = [];
        foreach ($withData as $uni) {
            $name = $this->configs->get($uni)->uni_name ?? '';
            $universeSelect[$uni] = $name !== '' ? $name : 'Uni ' . $uni;
        }

        $universe = $this->input()->int('universe');
        if (!in_array($universe, $withData, true)) {
            $universe = $withData === [] ? 0 : max($withData);
        }

        return $this->render('login/page.hof.default.html.twig', [
            'universe' => $universe,
            'universeSelect' => $universeSelect,
            'hof' => $universe > 0 ? $this->hallOfFame->find($universe) : null,
        ]);
    }
}
