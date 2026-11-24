<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account editor tab "achievements": lists the achievements of a player and grants or revokes one by hand (for the
 * achievements the game cannot detect itself, e.g. events outside the game). Every change is written to the admin log.
 */
class AchievementsTab extends AccountEditorTab
{
    /** Shows the form with the player's achievements (GET, optional id), or applies a grant/revoke (POST). */
    public function handle(): Response
    {
        if ($this->state->requestPost) {
            $this->save();
        }

        $LNG = $this->state->LNG;
        $id = $this->input()->int('id');
        $catalog = $this->achievement()->getAchievementList();
        $names = [];
        foreach ($catalog as $row) {
            $names[(int) $row['id']] = $LNG['Achievement_names'][(int) $row['id']] ?? $row['name'];
        }

        $held = [];
        if ($id > 0) {
            foreach ($this->achievement()->getUserAchievements($id) as $row) {
                $held[] = [
                    'id'   => (int) $row['achievementID'],
                    'name' => $names[(int) $row['achievementID']] ?? '',
                    'date' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $row['date'], $this->state->USER['timezone']),
                ];
            }
        }

        $this->responder->assign([
            'achievementOptions' => $names,
            'heldAchievements'   => $held,
            'playerId'           => $id,
        ]);

        return $this->responder->render('admin/AccountEditorPageAchievements.html.twig');
    }

    /**
     * Grants (button grant) or revokes (button revoke) the chosen achievement for the player, logs it (log type 1)
     * and ends with a message. Unknown player or achievement ids end with an error message.
     */
    private function save(): never
    {
        $LNG = $this->state->LNG;
        $id = $this->input()->int('id');
        $achievementId = $this->input()->int('achievement');
        $grant = isset($this->state->requestPost['grant']);
        $back = 'admin/accounts/edit/achievements?id=' . $id;

        $exists = $this->db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :id AND `universe` = :uni;', [
            ':id'  => $id,
            ':uni' => $this->universe()->getEmulated(),
        ], 'id');
        $achievement = $this->db->selectSingle('SELECT `id`, `name` FROM %%ACHIEVEMENTS%% WHERE `id` = :id;', [':id' => $achievementId]);
        if (empty($exists) || empty($achievement)) {
            $this->responder->message($LNG['ad_ach_invalid'], $back, 3, true);
        }

        $label = $LNG['Achievement_names'][$achievementId] ?? $achievement['name'];
        $held = $this->achievement()->checkAchievement($id, $achievementId);
        if ($grant && !$held) {
            $this->achievement()->setAchievement($id, $achievementId);
        } elseif (!$grant && $held) {
            $this->achievement()->removeAchievement($id, $achievementId);
        } elseif ($grant) {
            $this->responder->message($LNG['ad_ach_already'], $back, 3, true);
        } else {
            $this->responder->message($LNG['ad_ach_notheld'], $back, 3, true);
        }

        $LOG = new Log($this->db, $this->configs, $this->state, '1');
        $LOG->target = $id;
        $LOG->universe = $this->universe()->getEmulated();
        $LOG->old = [$label => $grant ? 0 : 1];
        $LOG->new = [$label => $grant ? 1 : 0];
        $LOG->save();

        $this->responder->message($LNG->format($grant ? 'ad_ach_granted' : 'ad_ach_revoked', $label), $back);
    }
}
