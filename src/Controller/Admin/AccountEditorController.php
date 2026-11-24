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

namespace App\Controller\Admin;

use App\Game\Admin\AccountEditor\AchievementsTab;
use App\Game\Admin\AccountEditor\RelicsTab;
use App\Game\Admin\AccountEditor\AlliancesTab;
use App\Game\Admin\AccountEditor\BuildingsTab;
use App\Game\Admin\AccountEditor\DefensesTab;
use App\Game\Admin\AccountEditor\PersonalTab;
use App\Game\Admin\AccountEditor\PlanetsTab;
use App\Game\Admin\AccountEditor\ResearchTab;
use App\Game\Admin\AccountEditor\ResourcesTab;
use App\Game\Admin\AccountEditor\ShipsTab;
use App\Game\Admin\ClosureAdminResponder;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Edits a player: resources, ships, defenses, buildings, research, personal data, planets and alliance. Each tab
 * (`admin/accounts/edit/<tab>`) is handled by its class in App\Game\Admin\AccountEditor; this controller picks the tab.
 */
class AccountEditorController extends AbstractAdminController
{
    /**
     * Menu of the account editor (admin/accounts/edit): renders the tab links. The tabs (resources, ships, buildings,
     * research, personal data, planets, alliances ...) are classes under App\Game\Admin\AccountEditor.
     */
    #[GameRoute('admin', 'admin/accounts/edit', 'accountEditor')]
    public function show(): Response
    {
        return $this->render('admin/AccountEditorPageMenu.html.twig');
    }

    /**
     * One tab of the account editor: checks that the acting admin may edit the target (staff boundary), then the tab shows
     * its form (GET) or applies it (POST, with a message page).
     */
    #[GameRoute('admin', 'admin/accounts/edit/{tab}', 'accountEditor', 'tab', requirements: ['tab' => 'resources|ships|defenses|buildings|researchs|personal|planets|alliances|achievements|relics'])]
    #[GameRoute('admin', 'admin/accounts/edit/{tab}', 'accountEditor', 'save', ['POST'], ['tab' => 'resources|ships|defenses|buildings|researchs|personal|planets|alliances|achievements|relics'])]
    public function tab(): Response
    {
        $name = $this->input()->string('tab');
        $responder = new ClosureAdminResponder(
            fn(string|\Twig\Markup $message, string|false $redirect, int $delay, bool $fatal) => $this->message($message, $redirect, $delay, $fatal),
            fn(array $variables) => $this->assign($variables),
            fn(string $template): Response => $this->render($template),
        );
        $tab = match ($name) {
            'resources' => ResourcesTab::class,
            'ships' => ShipsTab::class,
            'defenses' => DefensesTab::class,
            'buildings' => BuildingsTab::class,
            'researchs' => ResearchTab::class,
            'personal' => PersonalTab::class,
            'planets' => PlanetsTab::class,
            'alliances' => AlliancesTab::class,
            'achievements' => AchievementsTab::class,
            'relics' => RelicsTab::class,
            default => throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(),
        };

        $this->guardTarget($name);

        return (new $tab($this->db, $this->configs, $this->state, $responder))->handle();
    }

    /**
     * Staff boundary (as in QuickEditor): the player behind the posted/requested id must be actable by this admin
     * (never the root user, never an equal or higher authlevel, the admin itself is fine). Planet tabs resolve the owner
     * of the planet id, the alliance tab checks the owner, every member and the ids of leader change / member removal.
     */
    private function guardTarget(string $tab): void
    {
        $id = $this->input()->int('id');
        $userIds = [];
        switch ($tab) {
            case 'researchs':
            case 'personal':
            case 'achievements':
            case 'relics':
                $userIds[] = $id;
                break;
            case 'resources':
            case 'ships':
            case 'defenses':
            case 'buildings':
            case 'planets':
                $owner = $this->db->selectSingle('SELECT `id_owner` FROM %%PLANETS%% WHERE `id` = :id;', [':id' => $id], 'id_owner');
                if ($owner !== null && $owner !== false) {
                    $userIds[] = (int) $owner;
                }
                break;
            case 'alliances':
                $owner = $this->db->selectSingle('SELECT `ally_owner` FROM %%ALLIANCE%% WHERE `id` = :id;', [':id' => $id], 'ally_owner');
                if ($owner !== null && $owner !== false) {
                    $userIds[] = (int) $owner;
                }
                // id 0 = no alliance chosen yet (empty form): ally_id 0 would match every player without an alliance
                foreach ($id > 0 ? $this->db->select('SELECT `id` FROM %%USERS%% WHERE `ally_id` = :id;', [':id' => $id]) : [] as $member) {
                    $userIds[] = (int) $member['id'];
                }
                $userIds[] = $this->input()->int('changeleader');
                $userIds[] = $this->input()->int('delete_u');
                break;
        }
        foreach ($userIds as $userId) {
            if ($userId > 0 && !$this->mayActOn($userId, true)) {
                $this->deny();
            }
        }
    }
}
