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

use App\Game\Enum\Mission;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/** Interplanetary missile attack: validates the target and launches the missiles. */
#[\App\Controller\RequireModule('MODULE_MISSILEATTACK')]
class FleetMissileController extends AbstractGameController
{
    /** The target is a stronger player (outlaw rule on): the outlaw status is written once the launch is certain. */
    private bool $outlawPending = false;

    /**
     * Interplanetary missile launch (request: galaxy, system, planet, type = planet type of the target, SendMI = number of missiles, Target
     * = primary target defense id). Validates silo level, missile range, protections and counts, then creates the missile fleet event and
     * deducts the missiles and ends with a message page. Direct GET visits with empty input end in the error message (the galaxy form POSTs the launch).
     */
    #[GameRoute('game', 'game/missiles', 'fleetMissile', 'show', ['GET', 'POST'])]
    public function show(): Response
    {
        // Planet selector keeps the URL and only adds cp=. A GET then
        // re-enters this action with empty coords/SendMI and always hits the
        // error template. Galaxy already POSTs the launch form.
        if (($this->state->requestServer['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->redirectToRoute('game_galaxy_show');
        }

        $LNG = $this->state->LNG;
        $targetGalaxy       = $this->input()->int('galaxy');
        $targetSystem       = $this->input()->int('system');
        $targetPlanet       = $this->input()->int('planet');
        $targetType         = $this->input()->int('type');
        $requested          = $this->input()->int('SendMI');
        $primaryTarget      = $this->input()->int('Target');

        $planet = $this->state->PLANET;
        $sent = null;

        // Serialise parallel launches of the same player (user row, then planet row, like FleetDispatch), so the bash
        // limit and the missile stock are checked against the state the previous launch left behind.
        $error = $this->db->transactional(function () use ($requested, $targetGalaxy, $targetSystem, $targetPlanet, $targetType, $primaryTarget, $planet, &$sent): string {
            $this->state->PLANET = $planet;
            $LNG = $this->state->LNG;
            $this->db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :userId FOR UPDATE;', [':userId' => $this->state->USER['id']]);
            $locked = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;', [':planetId' => $this->state->PLANET['id']]);
            if (!empty($locked)) {
                $this->state->PLANET = array_merge($this->state->PLANET, $locked);
            }

            $this->outlawPending = false;
            $missileCount = $this->state->PLANET['interplanetary_misil'];
            $anz          = min($requested, $missileCount);

            $target = $this->findTarget($targetGalaxy, $targetSystem, $targetPlanet, $targetType);

            $error = $this->launchError($target, $targetGalaxy, $primaryTarget, $missileCount, $anz);

            // without a target there is nothing to check protections against: report the first failing precondition
            if (empty($target) && $error != "") {
                return $error;
            }

            $error = $this->targetRestrictionError($target, $error);

            if ($error == "") {
                // last check: a refused launch must not use up the daily quota (audit F-14)
                $capped = $this->fleetCaps->consume((int) $this->state->USER['id'], Mission::Missile->value);
                if ($capped !== null) {
                    return $LNG->format($capped['key'], $capped['limit'], $capped['hours'], $capped['minutes']);
                }
                if ($this->outlawPending) {
                    $this->db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                        ':outlawTime' => $this->state->runtime->timestamp,
                        ':userId'     => $this->state->USER['id'],
                    ]);
                    $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
                    $statsHandler->increment($this->state->USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
                }
                $this->launch($target, $targetGalaxy, $targetSystem, $targetPlanet, $targetType, $primaryTarget, $anz);
                $sent = (int) $anz;
            }

            return $error;
        });

        if ($error != "") {
            return $this->messageResponse($error);
        }

        return $this->launchMessage($targetGalaxy, $targetSystem, $primaryTarget, (int) $sent);
    }

    /**
     * Checks the (existing) target against admin attack level, vacation mode, own planet, protections and the bash
     * limit. Returns the error text of the first failing check that overrides the earlier $error, "" when launching is
     * allowed.
     *
     * @param array<string, mixed> $target
     */
    private function targetRestrictionError(array $target, string $error): string
    {
        $LNG = $this->state->LNG;

        if ($this->configs->get()->adm_attack == 1 && $target['authattack'] > $this->state->USER['authlevel']) {
            $error = $LNG['fl_admin_attack'];
        } elseif ($target['urlaubs_modus']) {
            $error = $LNG['fl_in_vacation_player'];
        }

        if ($error == "" && $target['id_owner'] == $this->state->USER['id']) {
            $error = $LNG['ma_own_planet'];
        }

        $this->loadOwnPoints();

        if ($error == "") {
            $protectionError = $this->protectionError($target);
            if ($protectionError !== null) {
                $error = $protectionError;
            }
        }

        if ($error == "" && $this->fleetFunctions()->checkBash((int) $target['planetID'])) {
            $error = $LNG['fl_bash_protection'];
        }

        return $error;
    }

    /**
     * The target planet with its owner and points (only planets whose owner has points are found), or a falsy value when
     * there is none at these coordinates.
     *
     * @return array<string, mixed>|false|null
     */
    private function findTarget(mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetType): array|false|null
    {
        $sql = "SELECT p.`id` as planetID, p.`id_owner`, p.`galaxy`, p.`system`, p.`planet`,
                u.`id`, u.`onlinetime`, u.`banaday`, u.`urlaubs_modus`, u.`authattack`, u.`rebuild_protection`, u.`rebuild_protection_attackers`, u.`outlaw_time`,
                s.`total_points`
            FROM %%PLANETS%% p
            JOIN %%USERS%% u ON u.`id` = p.`id_owner`
            JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = 1
            WHERE p.`universe` = :universe AND p.`galaxy` = :targetGalaxy
              AND p.`system` = :targetSystem AND p.`planet` = :targetPlanet AND p.`planet_type` = :targetType;";

        return $this->db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':targetGalaxy' => $targetGalaxy,
            ':targetSystem' => $targetSystem,
            ':targetPlanet' => $targetPlanet,
            ':targetType'   => $targetType,
        ]);
    }

    /**
     * First failing launch precondition as message text, "" when all hold: not in vacation mode, silo level 4, impulse drive
     * researched, same galaxy, target exists and is in missile range, valid primary target, missiles available and requested.
     *
     * @param array<string, mixed>|false|null $target from findTarget()
     */
    private function launchError(array|false|null $target, mixed $targetGalaxy, mixed $primaryTarget, mixed $missileCount, mixed $anz): string
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $e = $this->elements();

        $error = "";

        if (IsVacationMode($this->state->USER)) {
            $error = $LNG['fl_vacation_mode_active'];
        } elseif ($PLANET['silo'] < 4) {
            $error = $LNG['ma_silo_level'];
        } elseif ($this->state->USER['impulse_motor_tech'] == 0) {
            $error = $LNG['ma_impulse_drive_required'];
        } elseif ($targetGalaxy != $PLANET['galaxy']) {
            $error = $LNG['ma_not_send_other_galaxy'];
        } elseif (empty($target)) {
            $error = $LNG['ma_planet_doesnt_exists'];
        } elseif (!$this->fleetFunctions()->inMissileRange($PLANET, $target, $this->state->USER[$e->column(117)])) {
            $error = $LNG['ma_no_range'];
        } elseif (!in_array($primaryTarget, $e->ids('defense')) && $primaryTarget != 0) {
            $error = $LNG['ma_wrong_target'];
        } elseif ($missileCount == 0) {
            $error = $LNG['ma_no_missiles'];
        } elseif ($anz <= 0) {
            $error = $LNG['ma_add_missile_number'];
        }

        return $error;
    }

    /**
     * Adds the player's own total points to the USER state (0 when unranked), needed by the newbie protection check.
     */
    private function loadOwnPoints(): void
    {
        $sql = 'SELECT `total_points`
            FROM %%STATPOINTS%%
            WHERE `id_owner` = :userId AND `stat_type` = 1';

        try {
            $this->state->USER += $this->db->selectSingle($sql, [
                ':userId' => $this->state->USER['id'],
            ]) ?: ['total_points' => 0];
        } catch (Exception $exception) {
            $this->state->USER += ['total_points' => 0];
        }
    }

    /**
     * Newbie and strong-player protection against the target: a newbie target gives the error text; a strong target gives
     * the error text unless outlaw attacks are allowed, in which case the player is marked as outlaw when the missiles
     * are really launched (and the activation is counted in the advanced stats). Null when the protection does not forbid the launch.
     *
     * @param array<string, mixed>|false|null $target from findTarget()
     */
    private function protectionError(array|false|null $target): ?string
    {
        $LNG = $this->state->LNG;
        $IsNoobProtec = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($target));

        if ($IsNoobProtec['NoobPlayer']) {
            return $LNG['fl_week_player'];
        }
        if ($IsNoobProtec['StrongPlayer']) {
            if ($this->configs->get()->noob_protection_outlaw) {
                $this->outlawPending = true;
            } else {
                return $LNG['fl_strong_player'];
            }
        }

        return null;
    }

    /**
     * Sends the missile fleet (mission 10, not recallable) to the target.
     *
     * @param array<string, mixed> $target from findTarget()
     */
    private function launch(array $target, mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetType, mixed $primaryTarget, mixed $anz): void
    {
        $PLANET = $this->state->PLANET;

        $Duration       = $this->fleetFunctions()->getMIPDuration($PLANET['system'], $targetSystem);

        $fleetArray     = [INTERPLANETARY_MISSILE => $anz];

        $fleetStartTime = $this->state->runtime->timestamp + $Duration;
        $fleetStayTime  = $fleetStartTime;
        $fleetEndTime   = $fleetStartTime;

        $fleetResource  = [
            RESOURCE_METAL      => 0,
            RESOURCE_CRYSTAL    => 0,
            RESOURCE_DEUT       => 0,
        ];

        $this->fleetFunctions()->sendFleet(
            $fleetArray,
            10,
            $this->state->USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $target['id_owner'],
            $target['planetID'],
            $targetGalaxy,
            $targetSystem,
            $targetPlanet,
            $targetType,
            $fleetResource,
            $fleetStartTime,
            $fleetStayTime,
            $fleetEndTime,
            0,
            $primaryTarget,
            1, // missiles cannot be recalled
        );
    }

    /**
     * The "missiles sent" message with a redirect to the target system after 3 seconds.
     */
    private function launchMessage(mixed $targetGalaxy, mixed $targetSystem, mixed $primaryTarget, int $anz): Response
    {
        $LNG = $this->state->LNG;
        $DefenseLabel   = ($primaryTarget == 0) ? $LNG['ma_all'] : $LNG['tech'][$primaryTarget];
        $url = $this->router->generate('game_galaxy_show', ['galaxy' => $targetGalaxy, 'system' => $targetSystem]);
        // the amount is numeric, the texts are translations (trusted markup)
        return $this->messageResponse(new \Twig\Markup('<b>' . (int) $anz . '</b>' . $LNG['ma_missiles_sended'] . $DefenseLabel, 'UTF-8'), [[
            'label' => $LNG['sys_back'],
            'url'   => $url,
        ]], [
            $url,
            3,
        ]);
    }
}
