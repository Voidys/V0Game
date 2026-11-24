<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;
use App\Game\Core\StoredData;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/** Repair dock: repairs ships from the wreck field of the current planet. */
#[\App\Controller\RequireModule('MODULE_REPAIR_DOCK')]
class RepairdockController extends AbstractGameController
{
    /**
     * Repair dock of the current planet (wrecks live in PLANET_WRECKFIELD; an empty wreck field ends in a message): the wrecks, the running order and
     * its remaining time.
     */
    #[GameRoute('game', 'game/repair-dock', 'repairdock')]
    public function show(): Response
    {
        return $this->repairDock('');
    }

    /**
     * Starts a new repair order from the posted fmenge[<ship id>] = counts (only when none runs, not in vacation mode, limited to the wrecks available);
     * its duration follows the build time, at least the creation time + 30 minutes and at most 12 hours. Redirects back to the dock.
     */
    #[GameRoute('game', 'game/repair-dock', 'repairdock', 'start', ['POST'])]
    public function start(): Response
    {
        return $this->repairDock('start');
    }

    /**
     * Takes a finished repair order (repair_order_end reached): the ships are added to the planet inside a transaction that re-reads the order, so a
     * parallel request cannot deploy it twice.
     */
    #[GameRoute('game', 'game/repair-dock/deploy', 'repairdock', 'deploy', ['GET', 'POST'])]
    public function deploy(): Response
    {
        if ($redirect = $this->redirectUnlessPost('game_repairdock_show')) {
            return $redirect;
        }

        return $this->repairDock('deploy');
    }

    /**
     * The repair dock page for the command of the route ('' = just show, 'start', 'deploy').
     */
    private function repairDock(string $command): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;

        if (($PLANET[$e->column(REPAIR_DOCK)] ?? 0) < 1) {
            // the requirement is enforced here, not only in the template (also covers moons); the page explains it and links to the buildings
            return $this->render('game/page.repairdock.default.html.twig', ['repdock_level' => 0]);
        }

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);

        if (empty($wreckfield) || (empty($wreckfield['ships']) && empty($wreckfield['repair_order']))) {
            return $this->messageResponse($LNG['bd_repairdock_empty']);
        }

        $ships = $this->fleetFunctions()->unserialize($wreckfield['ships']);
        $buildTodo = $command === 'start' ? $this->input()->bigNumberMap('fmenge') : [];

        $ElementQueue = !empty($wreckfield['repair_order']) ? StoredData::decode($wreckfield['repair_order']) : [];
        if (empty($ElementQueue) && !empty($wreckfield['repair_order'])) {
            // an empty order ("[]", written by an older version) is no order: clear it so the dock is free again
            $db->update("UPDATE %%PLANET_WRECKFIELD%% SET `repair_order` = '', `repair_order_start` = 0, `repair_order_end` = 0, `repair_order_rate` = 0 WHERE `planetID` = :planetID;", [':planetID' => $PLANET['id']]);
            $wreckfield['repair_order'] = '';
        }
        $buildList = [];
        if ($command === 'deploy' && $wreckfield['repair_order_end'] <= $this->state->runtime->timestamp) {
            return $this->deployRepairedShips();
        } elseif (empty($ElementQueue) && !empty($buildTodo) && array_sum($buildTodo) > 0 && $USER['urlaubs_modus'] == 0) {
            return $this->startRepairOrder($buildTodo);
        } elseif (!empty($ElementQueue)) {
            $buildList = [
                'ships' => $ElementQueue,
                'time' => $this->state->runtime->timestamp - $wreckfield['repair_order_start'],
                'resttime'  => $USER['urlaubs_modus'] ? ($wreckfield['repair_order_end'] - $USER['urlaubs_start']) : ($wreckfield['repair_order_end'] - $this->state->runtime->timestamp),
                'endtime'   => $this->viewHelpers()->formatDate('U', $wreckfield['repair_order_end'], $USER['timezone']),
                'pretty_end_time' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $wreckfield['repair_order_end'], $USER['timezone']),
            ];

            $ships = $this->remainingWrecks($ships, $ElementQueue, $this->orderRate($wreckfield, $PLANET));
        }

        $elementList = $this->repairableShips($ships);
        $SolarEnergy = round((($PLANET['temp_max'] + 160) / 6) * $this->configs->get()->energy_multiplier * $this->eventModifiers()->factor($this->player()->universe, \App\Game\Engagement\Events\EventModifiers::SOLAR), 1);

        $busy = !empty($ElementQueue);

        $this->addScript('repairdock.js');

        return $this->render('game/page.repairdock.default.html.twig', [
            'repdock_level' => $PLANET[$e->column(REPAIR_DOCK)],
            'umode'         => $USER['urlaubs_modus'],
            'wftime'        => $wreckfield['created'] + TIME_72_HOURS - $this->state->runtime->timestamp,
            'busy'          => $busy,
            'elementList'   => $elementList,
            'List'          => $buildList,
            'SolarEnergy'   => $SolarEnergy,
            'repairRate'    => $this->getRepairRate($PLANET[$e->column(REPAIR_DOCK)]) * 100,
        ]);
    }

    /**
     * The ships of the wreck field as element rows (stock, build time, how many can be repaired at the dock's rate).
     *
     * @param array<int, int|float> $ships wreck counts by ship id
     *
     * @return array<int, array<string, mixed>>
     */
    private function repairableShips(array $ships): array
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $e = $this->elements();

        $elementIDs = $e->ids('fleet');
        $elementList = [];
        foreach ($elementIDs as $elementID) {
            if (!isset($ships[$elementID])) {
                continue;
            }

            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $elementID);
            $maxBuildable = floor($ships[$elementID] * $this->getRepairRate($PLANET[$e->column(REPAIR_DOCK)]));

            $elementList[$elementID] = [
                'id' => $elementID,
                'available' => $PLANET[$e->column($elementID)],
                'elementTime' => $elementTime,
                'maxBuildable' => floatToString($maxBuildable),
                'wrecks' => $ships[$elementID],
            ];
        }

        return $elementList;
    }

    /**
     * cmd=deploy: adds the ships of the finished repair order to the planet and clears the order. Runs in a transaction
     * that re-reads the wreck field under lock, so a parallel request cannot deploy the order twice (it is redirected
     * instead). Returns a confirmation message.
     */
    private function deployRepairedShips(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $db->startTransaction();
        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%%
            WHERE `planetID` = :planetID FOR UPDATE;";
        // Re-read the order under the lock: a parallel deploy request may already have consumed it.
        $locked = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
        if (
            empty($locked) || empty(StoredData::decode((string) $locked['repair_order']))
            || $locked['repair_order_end'] > $this->state->runtime->timestamp
        ) {
            $db->commit();
            return $this->redirectToRoute('game_repairdock_show');
        }
        $ships = $this->fleetFunctions()->unserialize($locked['ships']);
        $ElementQueue = StoredData::decode($locked['repair_order']);
        $queuedShips = $ships;

        $repaired = [];
        foreach ($ElementQueue as $elementID => $amount) {
            $repaired[$e->column($elementID)] = $amount;
            $statsHandler->increment($USER['id'], AdvancedStatsEnum::getRepaired($elementID), $amount);
        }
        $ships = $this->remainingWrecks($queuedShips, $ElementQueue, $this->orderRate($locked, $PLANET));

        if (!empty($repaired)) {
            // the repaired ships return to the planet
            $db->updateRows('PLANETS', [], ['id' => $PLANET['id']], [], $repaired);

            $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `ships` = :ships, `repair_order` = '', `repair_order_start` = 0, `repair_order_end` = 0, `repair_order_rate` = 0
                WHERE `planetID` = :planetID;";
            $db->update($sql, [
                ':ships' => $this->fleetFunctions()->serialize($ships),
                ':planetID' => $PLANET['id'],
            ]);
        }
        $db->commit();
        return $this->messageResponse($LNG['bd_repairdock_deploy'], [[
            'label' => $LNG['bd_continue'],
            'url'   => $this->router->generate('game_repairdock_show'),
        ]]);
    }

    /**
     * Starts a repair order from the posted counts (fmenge[<ship id>]), limited to the repairable share of the wrecks. The
     * duration follows the build time of the ships, at least until 30 minutes after the wreck field was created and at most 12
     * hours. Runs in a transaction on the locked wreck field (a parallel order wins; this request is redirected). Returns a
     * redirect to the repair dock.
     *
     * @param array<int|string, mixed> $buildTodo ship id => requested count
     */
    private function startRepairOrder(array $buildTodo): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $e = $this->elements();
        $db = $this->db;
        $ElementQueue = [];

        $db->startTransaction();
        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%%
            WHERE `planetID` = :planetID FOR UPDATE;";
        $locked = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
        if (empty($locked) || !empty(StoredData::decode((string) $locked['repair_order']))) {
            // A parallel request already queued an order.
            $db->commit();
            return $this->redirectToRoute('game_repairdock_show');
        }
        $ships = $this->fleetFunctions()->unserialize($locked['ships']);
        $wreckfield = $locked;

        $QueueTime = 0;
        foreach ($buildTodo as $element => $amount) {
            if (!empty($ships[$element])) {
                $amount = is_numeric($amount) ? round($amount) : 0;
                if ($amount > 0) {
                    $ElementQueue[$element] = max(0, min($amount, floor($ships[$element] * $this->getRepairRate($PLANET[$e->column(REPAIR_DOCK)]))));
                    $ElementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $element);
                    $QueueTime += $ElementTime * $ElementQueue[$element];
                }
            }
        }
        $ElementQueue = array_filter($ElementQueue);
        if (empty($ElementQueue)) {
            // nothing can be repaired from the requested amounts: no (empty) order that would block the dock
            $db->commit();
            return $this->redirectToRoute('game_repairdock_show');
        }

        $min = max($this->configs->get()->min_build_time, 1800 - ($this->state->runtime->timestamp - $wreckfield['created']));
        $QueueTime = max($min, min($QueueTime, TIME_12_HOURS)); //Repair duration: min Creation + 30m, max 12h

        $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `repair_order` = :repair_order, `repair_order_start` = :start_time, `repair_order_end` = :end_time, `repair_order_rate` = :rate
            WHERE `planetID` = :planetID;";
        $db->update($sql, [
            ':repair_order' => StoredData::encode(array_filter($ElementQueue)),
            ':start_time' => $this->state->runtime->timestamp,
            ':end_time' => $this->state->runtime->timestamp + $QueueTime,
            ':rate' => $this->getRepairRate($PLANET[$e->column(REPAIR_DOCK)]),
            ':planetID' => $PLANET['id'],
        ]);

        $db->commit();
        return $this->redirectToRoute('game_repairdock_show');
    }

    /**
     * Wrecks left after an order. An order of $amount ships uses up ceil($amount / rate) wrecks (rounded up, so
     * splitting an order into many small ones can never repair more than the rate share of the wrecks).
     */
    /**
     * Repair rate the order was started with (stored with the order), the current dock's rate for orders without one:
     * the dock level may change while an order runs, the wrecks it consumed must not.
     *
     * @param array<string, mixed> $wreckfield wreck field row
     * @param array<string, mixed> $planet current planet
     */
    private function orderRate(array $wreckfield, array $planet): float
    {
        $stored = (float) ($wreckfield['repair_order_rate'] ?? 0);

        return $stored > 0 ? $stored : $this->getRepairRate($planet[$this->elements()->column(REPAIR_DOCK)]);
    }

    /**
     * Wrecks left after an order: the ships the order's amounts used up at the given repair rate are taken off.
     *
     * @param array<int|string, int|float> $ships wrecks per ship id
     * @param array<int|string, int|float> $queue repaired amounts per ship id
     * @return array<int|string, int|float>
     */
    private function remainingWrecks(array $ships, array $queue, float $rate): array
    {
        foreach ($queue as $elementID => $amount) {
            if (empty($ships[$elementID])) {
                continue;
            }
            if (floor($ships[$elementID] * $rate) == $amount) {
                $ships[$elementID] = 0;
            } else {
                $ships[$elementID] -= ceil(round($amount / $rate, 6));
            }
            if ($ships[$elementID] <= 0) {
                unset($ships[$elementID]);
            }
        }

        return $ships;
    }

    /**
     * Share of the wrecks that can be repaired: (24 + level^1.5) %, capped at 100% (reached at dock level 18),
     * so no more ships than wrecks can ever be repaired.
     */
    private function getRepairRate($repairDockLvl): float
    {
        return min(1.0, (24 + pow($repairDockLvl, 1.5)) / 100);
    }
}
