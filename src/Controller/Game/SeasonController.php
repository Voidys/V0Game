<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Engagement\Seasons\Seasons;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Season ladder (optional engagement feature, universe setting seasons_enabled): the running season with a tab per category,
 * the top 25, the own rank and the time left, and the archive of past seasons.
 */
class SeasonController extends AbstractGameController
{
    private const LADDER_SIZE = 25;

    /**
     * The ladder page; request `cat` picks the category tab (unknown values show the first active one). While the feature is off
     * the page answers like an inactive module.
     */
    #[GameRoute('game', 'game/season', 'season')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $universe = (int) $this->state->USER['universe'];
        $userId = (int) $this->state->USER['id'];
        $seasons = new Seasons($this->db, $this->configs, $this->state);
        if (!$seasons->enabled($universe)) {
            $this->printPageError($LNG['sys_module_inactive']);
        }

        $categories = $seasons->categories($universe);
        $cat = $this->input()->string('cat');
        $active = in_array($cat, $categories, true) ? $cat : ($categories[0] ?? '');
        $season = $seasons->openSeason($universe);

        $tabs = [];
        foreach ($categories as $category) {
            $tabs[] = ['key' => $category, 'label' => $LNG['season_cat_' . $category], 'active' => $category === $active];
        }

        $rows = [];
        $own = null;
        if ($season !== null && $active !== '') {
            foreach ($seasons->ladder($universe, (int) $season['id'], $active, self::LADDER_SIZE) as $entry) {
                $rows[] = $entry + ['own' => $entry['user_id'] === $userId];
            }
            $own = $seasons->standing($universe, (int) $season['id'], $active, $userId);
        }

        $archive = [];
        foreach ($seasons->archive($universe) as $past) {
            $tops = [];
            foreach ($past['tops'] as $category => $top) {
                $tops[] = ['label' => $LNG['season_cat_' . $category], 'top' => $top];
            }
            $archive[] = ['number' => $past['number'], 'endsAt' => $past['ends_at'], 'tops' => $tops];
        }

        return $this->render('game/page.season.default.html.twig', [
            'seasonTabs' => $tabs,
            'seasonActive' => $active,
            'seasonNumber' => $season === null ? 0 : (int) $season['number'],
            'seasonEndsAt' => $season === null ? 0 : (int) $season['ends_at'],
            'seasonRemaining' => $season === null ? 0 : max(0, (int) $season['ends_at'] - $this->state->runtime->timestamp),
            'seasonRows' => $rows,
            'seasonOwn' => $own,
            'seasonArchive' => $archive,
        ]);
    }
}
