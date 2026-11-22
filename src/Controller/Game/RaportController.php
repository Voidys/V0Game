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
use App\Game\Enum\AuthLevel;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\StoredData;
use App\Game\Enum\PlanetType;

/**
 * Battle report page (/report/{raport}, also without login): renders a stored combat report for its participants.
 */
class RaportController extends AbstractGameController
{
    protected $disableEcoSystem = true;

    /**
     * Marks whether a wreck field was created and hides repair data from viewers that are not the defender.
     */
    private function applyViewerPerspective($combatReport, $isDefender)
    {
        $combatReport['wreckfield_created'] = !empty($combatReport['wreckfield']);
        if (!$isDefender) {
            $combatReport['repaired'] = [];
            $combatReport['wreckfield'] = [];
        }

        return $combatReport;
    }

    /**
     * Combat report (request: raport = report id from RW). Participants (attacker or defender id lists),
     * admins and members of the alliances of participants see the full report; everybody else only a reduced one with the participants' names
     * (from TOPKB_USERS). Renders the template for the battle engine stored with the report (`rust` or classic). Unknown ids end in a message.
     */
    #[GameRoute('game', 'report/{raport}', 'raport', requirements: ['raport' => '[A-Za-z0-9]+'])]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $LNG->includeData(['FLEET']);
        $this->setWindow('popup');
        $e = $this->elements();

        $db = $this->db;

        $RID		= $this->input()->string('raport');

        if ($RID == 0) {
            return $this->messageResponse($LNG['sys_raport_amount_error']);
        }

        $sql = "SELECT raport,attacker,defender,battleengine FROM %%RW%% WHERE rid = :reportID;";
        $reportData = $db->selectSingle($sql, [
            ':reportID'	=> $RID,
        ]);

        if (empty($reportData)) {
            return $this->messageResponse($LNG['sys_raport_not_found']);
        }

        [$showDetails, $isDefender] = $this->detailAccess($reportData);
        $isParticipant = $this->isParticipant($reportData);
        $hasDefendingPlayer = $this->hasDefendingPlayer($reportData);

        $Info = null;
        if (!$showDetails) {
            $reportData = $this->reducedReport($RID);
            $Info		= [$reportData["attacker"], $reportData["defender"]];
        }

        if (empty($reportData)) {
            return $this->messageResponse($LNG['sys_raport_not_found']);
        }

        if ($reportData['battleengine'] === 'rust') {
            $combatReport = json_decode(decompressFromBase64($reportData['raport']), true);
        } else {
            $combatReport = StoredData::decode($reportData['raport']);
        }

        // contact is only lost in a fight with a defending player; simulations and expeditions have none (defender column without ids)
        if (!$isDefender && $hasDefendingPlayer && $combatReport['result'] == DEFENDER && count($combatReport['rounds']) <= 2) {
            return $this->messageResponse($LNG['sys_raport_lost_contact']);
        }

        $combatReport['time']	= $this->viewHelpers()->formatDate($LNG['php_tdformat'], $combatReport['time'], $USER['timezone']);
        $combatReport			= $this->applyViewerPerspective($combatReport, $isDefender);

        if ($reportData['battleengine'] === 'rust') {
            $combatReport = $this->sortRustRounds($combatReport);
        }
        $context = [
            'Raport'        => $combatReport,
            'Info'          => $Info,
            'pageTitle'     => $LNG['sys_mess_attack_report'],
            'recCapacity'   => $e->capacity(SHIP_RECYCLER),
            'actions'       => $isParticipant ? $this->participantActions($combatReport, $reportData['battleengine'] === 'rust') : null,
        ];

        return $this->render($reportData['battleengine'] === 'rust' ? 'game/shared.mission.raport.rust.html.twig' : 'game/shared.mission.raport.html.twig', $context);
    }

    /** Whether the defender column of a report row lists at least one player id (not for simulations and expeditions). */
    private function hasDefendingPlayer(array $reportData): bool
    {
        foreach (explode(',', (string) $reportData['defender']) as $id) {
            if (ctype_digit($id)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the viewer is an attacker or defender of the report itself (admins and alliance mates are not). */
    private function isParticipant(array $reportData): bool
    {
        $id = (string) $this->state->USER['id'];

        return in_array($id, explode(',', (string) $reportData['attacker']), true) || $reportData['attacker'] === 'sim:' . $id || in_array($id, explode(',', (string) $reportData['defender']), true);
    }

    /**
     * Data for the action row of a participant: the target coordinates of the report, the debris total, and a simulator
     * prefill query (battleinput of the simulator, one slot per fleet pair) built from the first round.
     *
     * @param array<string, mixed> $report decoded report
     * @return array<string, mixed>|null null when the report carries no target coordinates
     */
    private function participantActions(array $report, bool $rust): ?array
    {
        $target = $report['koords'] ?? null;
        if (!is_array($target) || count($target) < 3) {
            return null;
        }

        return [
            'galaxy'  => (int) $target[0],
            'system'  => (int) $target[1],
            'planet'  => (int) $target[2],
            'type'    => (int) ($target[3] ?? PlanetType::Planet->value),
            'debris'  => array_sum($report['debris'] ?? []),
            'moon'    => ($report['mode'] ?? 0) == 1,
            'sim'     => $this->simulatorPrefill($report, $rust),
        ];
    }

    /**
     * The simulator's battleinput prefill: per slot [0 = attacker, 1 = defender] the three tech levels and the unit counts of the
     * fleets at the start of the fight.
     *
     * @param array<string, mixed> $report decoded report
     * @return array{slots: int, battleinput: array<int, array<int, array<int, int|float>>>}
     */
    private function simulatorPrefill(array $report, bool $rust): array
    {
        $start = $report['rounds'][0] ?? [];
        $sides = [];
        foreach (['attacker', 'defender'] as $side) {
            $sides[] = $rust ? $this->rustFleets($start[$side . 's'] ?? []) : $this->classicFleets($start[$side] ?? [], $report['players'] ?? []);
        }

        $battleinput = [];
        $slots = max(1, count($sides[0]), count($sides[1]));
        for ($slot = 0; $slot < $slots; $slot++) {
            $battleinput[$slot] = [$sides[0][$slot] ?? [], $sides[1][$slot] ?? []];
        }

        return ['slots' => $slots, 'battleinput' => $battleinput];
    }

    /**
     * Rust report fleets of one side as simulator rows (tech levels and unit counts).
     *
     * @param array<int|string, mixed> $fleets
     * @return list<array<int, int|float>>
     */
    private function rustFleets(array $fleets): array
    {
        $rows = [];
        foreach ($fleets as $fleet) {
            $row = [
                MILITARY_TECH => (int) ($fleet['player']['military_tech'] ?? 0),
                SHIELD_TECH   => (int) ($fleet['player']['shield_tech'] ?? 0),
                DEFENCE_TECH  => (int) ($fleet['player']['defence_tech'] ?? 0),
            ];
            foreach ($fleet['unit'] ?? [] as $unitId => $amount) {
                $row[(int) $unitId] = $amount;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Classic report fleets of one side as simulator rows; the report stores the techs as percent (100 + 10 * level).
     *
     * @param array<int|string, mixed> $fleets
     * @param array<int|string, mixed> $players
     * @return list<array<int, int|float>>
     */
    private function classicFleets(array $fleets, array $players): array
    {
        $rows = [];
        foreach ($fleets as $fleet) {
            $tech = $players[$fleet['userID']]['tech'] ?? [100, 100, 100];
            $row = [
                MILITARY_TECH => (int) round(($tech[0] - 100) / 10),
                SHIELD_TECH   => (int) round(($tech[1] - 100) / 10),
                DEFENCE_TECH  => (int) round(($tech[2] - 100) / 10),
            ];
            foreach ($fleet['ships'] ?? [] as $unitId => $data) {
                $row[(int) $unitId] = (int) ($data[0] ?? 0);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Decides what the viewer may see: participants (attacker or defender id lists),
     * admins (also treated as defenders) and members of the alliances of any participant get the full report.
     *
     * @param array<string, mixed> $reportData RW row with the attacker and defender id lists
     * @return array{0: bool, 1: bool} show the full report, view as defender
     */
    private function detailAccess(array $reportData): array
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $attackers = explode(",", $reportData['attacker']);
        $defenders = explode(",", $reportData['defender']);
        $isAttacker = in_array((string) $USER['id'], $attackers, true);
        $isDefender = in_array((string) $USER['id'], $defenders, true);
        // the player's own simulation (marked `sim:<id>` in the attacker column) opens in full, as the defender's view
        if ($reportData['attacker'] === 'sim:' . $USER['id']) {
            $isAttacker = true;
            $isDefender = true;
        }
        if (array_key_exists('authlevel', $USER) && $USER['authlevel'] > AuthLevel::User->value) {
            $isDefender = true;
        }
        $showDetails = $isAttacker || $isDefender;
        $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :pID;";
        $allyID = $db->selectSingle($sql, [
            ':pID'	=> $USER['id'],
        ], 'ally_id');
        if (!$showDetails && $allyID > 0) {
            foreach ($attackers as $atterID) {
                $aID = $db->selectSingle($sql, [
                    ':pID'	=> $atterID,
                ], 'ally_id');
                if ($allyID == $aID) {
                    $showDetails = true;
                    break;
                }
            }
            if (!$showDetails) {
                foreach ($defenders as $defferID) {
                    $aID = $db->selectSingle($sql, [
                        ':pID'	=> $defferID,
                    ], 'ally_id');
                    if ($allyID == $aID) {
                        $showDetails = true;
                        break;
                    }
                }
            }
        }

        return [$showDetails, $isDefender];
    }

    /**
     * The report row for viewers without access: the report itself plus the names of the attackers and defenders
     * (from TOPKB_USERS) joined with " & ".
     *
     * @return array<string, mixed>|false|null row with battleengine, raport, time, attacker (names) and defender (names)
     */
    private function reducedReport(string $RID): array|false|null
    {
        $sql = "SELECT
		battleengine, raport, time,
		(
			SELECT
			GROUP_CONCAT(username SEPARATOR ' & ') as attacker
			FROM %%USERS%%
			WHERE id IN (SELECT uid FROM %%TOPKB_USERS%% WHERE %%TOPKB_USERS%%.rid = %%RW%%.rid AND role = 1)
		) as attacker,
		(
			SELECT
			GROUP_CONCAT(username SEPARATOR ' & ') as defender
			FROM %%USERS%%
			WHERE id IN (SELECT uid FROM %%TOPKB_USERS%% WHERE %%TOPKB_USERS%%.rid = %%RW%%.rid AND role = 2)
		) as defender
		FROM %%RW%%
		WHERE rid = :reportID;";

        return $this->db->selectSingle($sql, [
            ':reportID'	=> $RID,
        ]);
    }

    /**
     * Brings the rounds of a rust engine report into the order the template expects: round stats sorted by id with the
     * defending planet (id 0) last.
     *
     * @param array<string, mixed> $combatReport decoded report
     * @return array<string, mixed>
     */
    private function sortRustRounds(array $combatReport): array
    {
        foreach ($combatReport['rounds'] as $rkey => $round) {
            ksort($round['attacker']['Roundstats']);
            ksort($round['defender']['Roundstats']);
            if (isset($round['defender']['Roundstats'][0])) {
                $planet = $round['defender']['Roundstats'][0];
                unset($round['defender']['Roundstats'][0]);
                $round['defender']['Roundstats'][0] = $planet;
            }
            foreach ($round['attacker']['Roundstats'] as $key => $value) {
                ksort($value);
                $round['attacker']['Roundstats'][$key] = $value;
            }
            foreach ($round['defender']['Roundstats'] as $key => $value) {
                ksort($value);
                $round['defender']['Roundstats'][$key] = $value;
            }
            $combatReport['rounds'][$rkey] = $round;
        }

        return $combatReport;
    }
}
