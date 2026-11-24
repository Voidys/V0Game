<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Engagement\Cosmetics\CosmeticCatalog;
use App\Game\Engagement\Cosmetics\Cosmetics;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Badges, titles and name colours of the player (optional engagement feature, universe setting cosmetics_enabled): the player
 * sees everything he owns and picks the active title and colour.
 */
class CosmeticsController extends AbstractGameController
{
    /** The collection with the choice forms. While the feature is off the page answers like an inactive module. */
    #[GameRoute('game', 'game/cosmetics', 'cosmetics')]
    public function show(): Response
    {
        $this->assertEnabled();
        $catalog = new CosmeticCatalog($this->state->LNG);
        $owned = (new Cosmetics($this->db))->owned((int) $this->state->USER['id']);

        $badges = [];
        $titles = [];
        $colors = [];
        foreach ($owned as $item) {
            $entry = $catalog->describe($item['kind'], $item['code'], $item['source']);
            if ($item['kind'] === Cosmetics::KIND_BADGE) {
                $badges[] = $entry;
            } elseif ($item['kind'] === Cosmetics::KIND_TITLE) {
                $titles[] = $entry + ['active' => $item['active']];
            } elseif ($item['kind'] === Cosmetics::KIND_COLOR && $entry['colorClass'] !== null) {
                $colors[] = $entry + ['active' => $item['active']];
            }
        }

        $notice = $this->input()->string('notice');

        return $this->render('game/page.cosmetics.default.html.twig', [
            'cosmBadges' => $badges,
            'cosmTitles' => $titles,
            'cosmColors' => $colors,
            'cosmNotice' => in_array($notice, ['saved', 'invalid'], true) ? $this->state->LNG['cosm_notice_' . $notice] : null,
            'cosmNoticeOk' => $notice === 'saved',
        ]);
    }

    /**
     * POST kind (title|color) and code (empty clears the choice): makes one owned cosmetic active, at most one per kind. A code the
     * player does not own is refused. The CSRF token is checked by the global layer.
     */
    #[GameRoute('game', 'game/cosmetics/choose', 'cosmetics', 'choose', ['POST'])]
    public function choose(): Response
    {
        $this->assertEnabled();
        $kind = $this->input()->string('kind');
        $code = $this->input()->string('code');
        $ok = in_array($kind, [Cosmetics::KIND_TITLE, Cosmetics::KIND_COLOR], true)
            && ($code === '' || preg_match('/^[a-z0-9_]{1,48}$/', $code) === 1)
            && (new Cosmetics($this->db))->choose((int) $this->state->USER['id'], $kind, $code === '' ? null : $code);

        return $this->redirectToRoute('game_cosmetics_show', ['notice' => $ok ? 'saved' : 'invalid']);
    }

    private function assertEnabled(): void
    {
        if (!(bool) ($this->configs->get((int) $this->state->USER['universe'])->cosmetics_enabled ?? false)) {
            $this->printPageError($this->state->LNG['sys_module_inactive']);
        }
    }
}
