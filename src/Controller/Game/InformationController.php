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
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Player\PlanetQueries;
use App\Game\Core\FormulaEvaluator;

/**
 * Element information popup: description, production/storage tables, jump gate and missile actions (actions: show,
 * sendFleet, destroyMissiles).
 */
#[\App\Controller\RequireModule('MODULE_INFORMATION')]
class InformationController extends AbstractGameController
{
    // protected $disableEcoSystem = true;

    /**
     * Timestamp at which a jump gate can be used again. $lastTime = last jump (timestamp), $jumpLevel = gate level: 60 minutes at level 0,
     * falling with the level along 0.25*x^2 - 7x + 60 (x = level - 1) down to 10 minutes from level 15, divided by `jumpgate_factor`.
     */
    protected function getNextJumpWaitTime($lastTime, $jumpLevel)
    {
        if ($jumpLevel >= 15) {
            $cooldownMinutes = 10;
        } elseif ($jumpLevel >= 1) {
            // floor(0.25*x*x - 7*x + 60) == intdiv(x*x - 28*x + 240, 4)
            $x = (int) $jumpLevel - 1;
            $cooldownMinutes = intdiv($x * $x - 28 * $x + 240, 4);
        } else {
            $cooldownMinutes = 60;
        }

        return $lastTime + ceil(($cooldownMinutes * 60) / $this->configs->get()->jumpgate_factor);
    }

    /**
     * Jump gate jump (XHR). Request: jmpto (target gate planet id), ship[<id>] = counts. Both gates must be ready (cooldown above), counts are
     * limited to what the planet holds (solar satellites excluded); moves the ships to the target planet in two UPDATEs, sets `last_jump_time` on both
     * and answers JSON {message, error}.
     */
    #[GameRoute('game', 'game/info/jump', 'information', 'sendFleet', ['POST'])]
    public function sendFleet(): Response
    {
        // both gate planets are locked (lowest id first) and re-read, so parallel jumps cannot pass the cooldown check
        // on stale values. Every answer is a JSON response, committed with the transaction; nothing is written before the ships move.
        $db = $this->db;
        $db->startTransaction();
        try {
            $this->lockGatePlanets();
            $response = $this->jump();
        } catch (\Throwable $failure) {
            $db->rollback();
            throw $failure;
        }
        $db->commit();

        return $response;
    }

    /** Locks the current planet and the requested target planet (own planets only) and refreshes the current planet copy from the locked row. */
    private function lockGatePlanets(): void
    {
        $current = (int) $this->state->PLANET['id'];
        $target = $this->input()->int('jmpto', $current);
        $rows = $this->db->select(
            'SELECT * FROM %%PLANETS%% WHERE `id` IN (:current, :target) AND `id_owner` = :userId ORDER BY `id` FOR UPDATE;',
            [':current' => $current, ':target' => $target, ':userId' => $this->state->USER['id']],
        );
        foreach ($rows as $row) {
            if ((int) $row['id'] === $current) {
                $this->state->PLANET = array_merge($this->state->PLANET, $row);
            }
        }
    }

    /**
     * The jump itself, run inside the locked transaction of sendFleet(): checks both gates, takes the ships from the
     * planet state, moves them and answers JSON.
     */
    private function jump(): Response
    {
        $LNG = $this->state->LNG;

        $TargetPlanet = $this->checkJumpGates();
        if ($TargetPlanet instanceof Response) {
            return $TargetPlanet;
        }
        $shipsOut = $this->takeShipsForJump();

        if (empty($shipsOut)) {
            return $this->jumpError($LNG['in_jump_gate_error_data']);
        }

        $this->moveShips($shipsOut, $TargetPlanet);

        $this->state->PLANET['last_jump_time']   = $this->state->runtime->timestamp;
        $NextJumpTime   = $this->getNextJumpWaitTime($this->state->PLANET['last_jump_time'], $this->state->PLANET['sprungtor']);
        return $this->jsonResponse([
            'message' => $LNG->format('in_jump_gate_done', $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp)),
            'error' => false,
        ]);
    }

    /** the JSON answer of a refused jump */
    private function jumpError(string $message): Response
    {
        return $this->jsonResponse([
            'message' => $message,
            'error' => true,
        ]);
    }

    /**
     * Checks both ends of a jump: not in vacation mode, the current planet has a jump gate that has cooled down, and the requested
     * target (request jmpto) is another planet of the player with a jump gate that has cooled down.
     *
     * @return int|Response id of the target planet, the JSON error answer when a check fails
     */
    private function checkJumpGates(): int|Response
    {
        $USER = $this->state->USER;
        $e = $this->elements();
        $LNG = $this->state->LNG;
        $db = $this->db;

        if (isVacationMode($USER)) {
            return $this->jumpError($LNG['fa_vacation_mode_current']);
        }

        // The source planet needs a jump gate itself
        if ($this->state->PLANET[$e->column(43)] <= 0) {
            return $this->jumpError($LNG['in_jump_gate_doesnt_have_one']);
        }

        $NextJumpTime = $this->getNextJumpWaitTime($this->state->PLANET['last_jump_time'], $this->state->PLANET['sprungtor']);

        if ($this->state->runtime->timestamp < $NextJumpTime) {
            return $this->jumpError($LNG['in_jump_gate_already_used'] . ' ' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp));
        }

        $TargetPlanet = $this->input()->int('jmpto', (int) $this->state->PLANET['id']);

        $sql = "SELECT id, last_jump_time, sprungtor FROM %%PLANETS%% WHERE id = :targetID AND id_owner = :userID AND"
            . " sprungtor > 0;";
        $TargetGate = $db->selectSingle($sql, [
            ':targetID' => $TargetPlanet,
            ':userID'   => $USER['id'],
        ]);

        if (!isset($TargetGate) || $TargetPlanet == $this->state->PLANET['id']) {
            return $this->jumpError($LNG['in_jump_gate_doesnt_have_one']);
        }

        $NextJumpTime   = $this->getNextJumpWaitTime($TargetGate['last_jump_time'], $TargetGate['sprungtor']);

        if ($this->state->runtime->timestamp < $NextJumpTime) {
            return $this->jumpError($LNG['in_jump_gate_not_ready_target'] . ' ' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp));
        }

        return $TargetPlanet;
    }

    /**
     * Reads the ships to jump (request ship[<id>] = counts), limits every count to what the planet holds (solar satellites excluded)
     * and subtracts them from the planet state.
     *
     * @return array<string, int> planet column => number of ships leaving
     */
    private function takeShipsForJump(): array
    {
        $e = $this->elements();

        $ShipArray      = [];
        $shipsOut       = [];
        $Ships          = $this->input()->intMap('ship');

        foreach ($e->ids('fleet') as $Ship) {
            if (!isset($Ships[$Ship]) || $Ship == 212) {
                continue;
            }

            $ShipArray[$Ship]   = max(0, min($Ships[$Ship], (int) $this->state->PLANET[$e->column($Ship)]));

            if (empty($ShipArray[$Ship])) {
                continue;
            }

            $shipsOut[$e->column($Ship)] = $ShipArray[$Ship];
            $this->state->PLANET[$e->column($Ship)] -= $ShipArray[$Ship];
        }

        return $shipsOut;
    }

    /**
     * Moves the ships from the current planet to the target gate planet (two UPDATEs) and stamps `last_jump_time` on both.
     *
     * @param array<string, int> $shipsOut planet column => number of ships (clamped integers, columns from $resource)
     */
    private function moveShips(array $shipsOut, int $TargetPlanet): void
    {
        $db = $this->db;
        // ships leave the origin gate planet and arrive at the target; the amounts are clamped integers, the columns come from $resource
        $leave = $db->qb()->update($db->table('PLANETS'));
        $arrive = $db->qb()->update($db->table('PLANETS'));
        $index = 0;
        foreach ($shipsOut as $column => $amount) {
            $leave->set('`' . $column . '`', '`' . $column . '` - :amount' . $index)->setParameter('amount' . $index, $amount);
            $arrive->set('`' . $column . '`', '`' . $column . '` + :amount' . $index)->setParameter('amount' . $index, $amount);
            $index++;
        }
        $db->execute(
            $leave->set('`last_jump_time`', ':jumptime')
                ->where('id = :planetID')
                ->setParameter('jumptime', $this->state->runtime->timestamp)
                ->setParameter('planetID', $this->state->PLANET['id']),
        );
        $db->execute(
            $arrive->set('`last_jump_time`', ':jumptime')
                ->where('id = :targetID')
                ->setParameter('jumptime', $this->state->runtime->timestamp)
                ->setParameter('targetID', $TargetPlanet),
        );
    }

    /**
     * Ships on the current planet that can use the jump gate: every fleet unit with a count above 0 except solar
     * satellites (212), which can't fly.
     *
     * @return array<int, float> element id => count
     */
    private function getAvailableFleets()
    {
        $e = $this->elements();
        $PLANET = $this->state->PLANET;
        $fleetList  = [];

        foreach ($e->ids('fleet') as $Ship) {
            if ($Ship == 212 || $PLANET[$e->column($Ship)] <= 0) {
                continue;
            }

            $fleetList[$Ship]   = $PLANET[$e->column($Ship)];
        }

        return $fleetList;
    }

    /**
     * Removes missiles from the current silo (request: missile[502], missile[503] = interceptor / interplanetary missiles to destroy);
     * answers the remaining counts as JSON.
     */
    #[GameRoute('game', 'game/info/destroy-missiles', 'information', 'destroyMissiles', ['POST'])]
    public function destroyMissiles(): Response
    {
        $e = $this->elements();
        $db = $this->db;

        $Missle = $this->input()->intMap('missile');
        $planetId = (int) $this->state->PLANET['id'];
        $columns = ['`' . $e->column(502) . '`', '`' . $e->column(503) . '`'];

        // The request-start PLANET copy is stale (a launch or an attack may have changed the silo meanwhile): lock the
        // row, clamp against its current counts and deduct relatively.
        $remaining = $db->transactional(function () use ($db, $columns, $Missle, $planetId): array {
            $locked = $db->selectSingle(
                'SELECT ' . $columns[0] . ' AS interceptor, ' . $columns[1] . ' AS interplanetary FROM %%PLANETS%% WHERE `id` = :planetID FOR UPDATE;',
                [':planetID' => $planetId],
            );
            if (empty($locked)) {
                return [0, 0];
            }

            $interceptor = max(0, min(($Missle[502] ?? 0), (int) $locked['interceptor']));
            $interplanetary = max(0, min(($Missle[503] ?? 0), (int) $locked['interplanetary']));

            $db->update(
                'UPDATE %%PLANETS%% SET ' . $columns[0] . ' = GREATEST(0, ' . $columns[0] . ' - :interceptor), '
                . $columns[1] . ' = GREATEST(0, ' . $columns[1] . ' - :interplanetary) WHERE `id` = :planetID;',
                [':interceptor' => $interceptor, ':interplanetary' => $interplanetary, ':planetID' => $planetId],
            );

            return [(int) $locked['interceptor'] - $interceptor, (int) $locked['interplanetary'] - $interplanetary];
        });

        $this->state->PLANET[$e->column(502)] = $remaining[0];
        $this->state->PLANET[$e->column(503)] = $remaining[1];

        return $this->jsonResponse($remaining);
    }

    /**
     * The player's other moons with a jump gate (planet type 3), ordered by the player's planet sort setting.
     */
    private function getTargetGates()
    {
        $e = $this->elements();
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $db = $this->db;

        $order = $USER['planet_sort_order'] == 1 ? "DESC" : "ASC" ;
        $sort  = $USER['planet_sort'];

        $moonQuery = $db->qb()
            ->select('id', 'name', 'galaxy', '`system`', 'planet', 'last_jump_time', 'sprungtor', '`' . $e->column(43) . '`')
            ->from($db->table('PLANETS'))
            ->where('id != :planetID')
            ->andWhere('id_owner = :userID')
            ->andWhere("planet_type = '3'")
            ->andWhere('`' . $e->column(43) . '` > 0')
            ->setParameter('planetID', $PLANET['id'])
            ->setParameter('userID', $USER['id']);
        PlanetQueries::orderBySetting($moonQuery, $sort, $order);
        $moonResult = $db->fetchAll($moonQuery);

        $moonList   = [];

        foreach ($moonResult as $moonRow) {
            $NextJumpTime               = $this->getNextJumpWaitTime($moonRow['last_jump_time'], $moonRow['sprungtor']);
            $moonList[$moonRow['id']]   = '[' . $moonRow['galaxy'] . ':' . $moonRow['system'] . ':' . $moonRow['planet']
                . '] ' . $moonRow['name']
                . ($this->state->runtime->timestamp < $NextJumpTime ? ' (' . $this->viewHelpers()->prettyTime($NextJumpTime - $this->state->runtime->timestamp) . ')' : '');
        }

        return $moonList;
    }

    /**
     * Element info popup (request: id = element id): description, production and consumption table for 15 levels around the current one
     * for producers, storage capacity, rapid fire / combat values for ships and defense, missile list for the silo and the jump gate target list
     * for the jump gate. The tables evaluate the formulas from the game data (ProdGrid).
     */
    #[GameRoute('game', 'game/info/{id}', 'information', requirements: ['id' => '\d+'])]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $elementID  = $this->input()->int('id');

        // fragment for the modal loader, the full shell when the page is opened directly
        $this->setWindow($this->state->runtime->ajax ? 'popup' : 'full');

        $productionTable    = [];
        $FleetInfo          = [];
        $MissileList        = [];
        $gateData           = [];

        $CurrentLevel       = 0;

        $ressIDs            = array_merge([], $e->resourceIds(1), $e->resourceIds(2));

        if (in_array($elementID, $e->ids('prod'))) {
            $CurrentLevel       = $this->state->PLANET[$e->column($elementID)];
            $this->addProductionLevels($productionTable, $elementID, $ressIDs, $CurrentLevel);
        }
        if (in_array($elementID, $e->ids('storage'))) {
            $CurrentLevel       = $this->state->PLANET[$e->column($elementID)];
            $this->addStorageLevels($productionTable, $elementID, $ressIDs, $CurrentLevel);
        }
        if (in_array($elementID, $e->ids('fleet'))) {
            $FleetInfo = $this->shipInfo($elementID);
        }
        if (in_array($elementID, $e->ids('defense'))) {
            $FleetInfo = $this->defenseInfo($elementID);
        }

        if ($elementID == 43 && $this->state->PLANET[$e->column(43)] > 0) {
            $this->addScript('gate.js');
            $nextTime   = $this->getNextJumpWaitTime($this->state->PLANET['last_jump_time'], $this->state->PLANET['sprungtor']);
            $gateData   = [
                'nextTime'  => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $nextTime, $this->state->USER['timezone']),
                'restTime'  => max(0, $nextTime - $this->state->runtime->timestamp),
                'startLink' => $this->state->PLANET['name'] . ' ' . strip_tags(BuildPlanetAddressLink($this->state->PLANET)),
                'gateList'  => $this->getTargetGates(),
                'fleetList' => $this->getAvailableFleets(),
            ];
        } elseif ($elementID == 44 && $this->state->PLANET[$e->column(44)] > 0) {
            $MissileList    = [
                502 => $this->state->PLANET[$e->column(502)],
                503 => $this->state->PLANET[$e->column(503)],
            ];
        }

        return $this->render('game/page.information.default.html.twig', [
            'elementID'             => $elementID,
            'productionTable'       => $productionTable,
            'CurrentLevel'          => $CurrentLevel,
            'MissileList'           => $MissileList,
            'FleetInfo'             => $FleetInfo,
            'gateData'              => $gateData,
            'spytechStatsEnabled'   => $this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS),
            'head'                  => $this->headFor($elementID),
        ]);
    }

    /**
     * Header block of the info page: current level, cost of the next level (units: cost of one), the requirements with the
     * player's own levels and the page where the element is built. Null for ids that are not buildable elements.
     *
     * @return array<string, mixed>|null
     */
    private function headFor(int $elementID): ?array
    {
        $e = $this->elements();
        if (!$e->has($elementID)) {
            return null;
        }

        $group = null;
        foreach (['build' => 'buildings', 'tech' => 'research', 'fleet' => 'shipyard', 'defense' => 'defense', 'missile' => 'defense'] as $name => $page) {
            if (in_array($elementID, $e->ids($name))) {
                $group = ['name' => $name, 'page' => $page];
                break;
            }
        }
        if ($group === null) {
            return null;
        }

        $userModel = $this->models()->user($this->state->USER);
        $planetModel = $this->models()->planet($this->state->PLANET);
        $column = $e->column($elementID);
        $level = null;
        $costs = [];
        if ($group['name'] === 'build' || $group['name'] === 'tech') {
            $level = (int) ($group['name'] === 'build' ? $this->state->PLANET[$column] : $this->state->USER[$column]);
            $maxLevel = $e->get($elementID)->maxLevel;
            if ($maxLevel != $level) {
                $costs = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $elementID, false, $level + 1);
            }
        } else {
            $costs = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $elementID);
        }

        $requirements = [];
        foreach ($e->get($elementID)->requirements as $requireID => $needed) {
            $requireColumn = $e->column($requireID);
            $requirements[$requireID] = [
                'count' => $needed,
                'own' => $this->state->PLANET[$requireColumn] ?? $this->state->USER[$requireColumn] ?? 0,
            ];
        }

        return [
            'group' => $group['name'],
            'page' => $group['page'],
            'level' => $level,
            'costs' => array_filter($costs, static fn($amount) => $amount > 0),
            'requirements' => $requirements,
        ];
    }

    /**
     * Fills the production table of a producing building: production per resource for the 15 levels starting two below the
     * current one (ProdGrid formulas with the current production percentage, energy tech and planet temperature, scaled by the
     * energy/resource multiplier), and the list of resources it produces or consumes.
     *
     * @param array<string, mixed> $productionTable gets 'production' (level => resource id => amount) and 'usedResource'
     * @param list<int> $ressIDs resources and energy ids
     */
    private function addProductionLevels(array &$productionTable, mixed $elementID, array $ressIDs, int|string $CurrentLevel): void
    {
        $e = $this->elements();

        /* Data for eval */
        $BuildEnergy        = $this->state->USER[$e->column(113)];
        $BuildTemp          = $this->state->PLANET['temp_max'];
        $BuildLevelFactor   = $this->state->PLANET[$e->column($elementID) . '_porcent'];

        $BuildStartLvl      = max($CurrentLevel - 2, 0);
        for ($BuildLevel = $BuildStartLvl; $BuildLevel < $BuildStartLvl + 15; $BuildLevel++) {
            foreach ($ressIDs as $ID) {
                $formula = $e->productionFormula((int) $elementID, $ID);
                if ($formula === null) {
                    continue;
                }

                $Production = FormulaEvaluator::evaluate($formula, compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp'));

                if (in_array($ID, $e->resourceIds(2))) {
                    $Production *= $this->configs->get()->energy_multiplier;
                    if ($Production > 0 && in_array((int) $elementID, [solar_plant, SHIP_SOLSAT], true)) {
                        $Production *= $this->eventModifiers()->factor($this->player()->universe, \App\Game\Engagement\Events\EventModifiers::SOLAR); // universe event
                    }
                } else {
                    $Production *= ($this->configs->get()->resource_multiplier / 2500);
                }

                $productionTable['production'][$BuildLevel][$ID]    = $Production;
            }
        }

        $productionTable['usedResource']    = array_keys($productionTable['production'][$BuildStartLvl]);
    }

    /**
     * Fills the storage table of a storage building: capacity per resource for the 15 levels starting two below the current one
     * (ProdGrid formula, rounded, times the storage multiplier) and the list of resources it stores.
     *
     * @param array<string, mixed> $productionTable gets 'storage' (level => resource id => capacity) and 'usedResource'
     * @param list<int> $ressIDs resources and energy ids
     */
    private function addStorageLevels(array &$productionTable, mixed $elementID, array $ressIDs, int|string $CurrentLevel): void
    {
        $e = $this->elements();
        $BuildStartLvl      = max($CurrentLevel - 2, 0);

        for ($BuildLevel = $BuildStartLvl; $BuildLevel < $BuildStartLvl + 15; $BuildLevel++) {
            foreach ($ressIDs as $ID) {
                $formula = $e->storageFormula((int) $elementID, $ID);
                if ($formula === null) {
                    continue;
                }

                $production = round(FormulaEvaluator::evaluate($formula, compact('BuildLevel')));
                $production *= $this->configs->get()->storage_multiplier;

                $productionTable['storage'][$BuildLevel][$ID]   = $production;
            }
        }

        $productionTable['usedResource']    = array_keys($productionTable['storage'][$BuildStartLvl]);
    }

    /**
     * Combat and flight data of a ship: structure, required techs, attack, shield, cargo capacity, speeds and consumption for the three
     * drives, and its rapid fire against and from other ships/defenses.
     *
     * @return array<string, mixed>
     */
    private function shipInfo(mixed $elementID): array
    {
        $e = $this->elements();
        $element = $e->get((int) $elementID);
        $FleetInfo  = [
            'structure'     => $e->cost((int) $elementID)->metalCrystal(),
            'tech1'         => $element->speedTech(1),
            'tech2'         => $element->speedTech(2),
            'tech3'         => $element->speedTech(3),
            'attack'        => $e->combat((int) $elementID)->attack,
            'shield'        => $e->combat((int) $elementID)->shield,
            'capacity'      => $e->capacity((int) $elementID),
            'speed1'        => $element->speed(1),
            'speed2'        => $element->speed(2),
            'speed3'        => $element->speed(3),
            'consumption1'  => $element->consumption(1),
            'consumption2'  => $element->consumption(2),
            'consumption3'  => $element->consumption(3),
            'rapidfire'     => $this->rapidFire($elementID),
        ];

        return $FleetInfo;
    }

    /**
     * Combat data of a defense structure: structure, attack, shield and its rapid fire against and from other ships/defenses.
     *
     * @return array<string, mixed>
     */
    private function defenseInfo(mixed $elementID): array
    {
        $e = $this->elements();

        return [
            'structure'     => $e->cost((int) $elementID)->metalCrystal(),
            'attack'        => $e->combat((int) $elementID)->attack,
            'shield'        => $e->combat((int) $elementID)->shield,
            'rapidfire'     => $this->rapidFire($elementID),
        ];
    }

    /**
     * Rapid fire of $elementID in percent of the shots that are saved: 'to' = against which ships/defenses it has rapid fire, 'from' = which
     * ships/defenses have rapid fire against it (rapid fire value v gives (v-1)/v of the shots).
     *
     * @return array{from: array<int, float>, to: array<int, float>}
     */
    private function rapidFire(mixed $elementID): array
    {
        $e = $this->elements();
        $rapidfire = [
            'from'  => [],
            'to'    => [],
        ];

        $fleetIDs   = array_merge($e->ids('fleet'), $e->ids('defense'));

        foreach ($fleetIDs as $fleetID) {
            $shoots = $e->combat((int) $elementID)->rapidFireAgainst($fleetID);
            if ($shoots > 1) {
                $rapidfire['to'][$fleetID] = round(($shoots - 1) / $shoots * 100, 2);
            }

            $shoots = $e->combat($fleetID)->rapidFireAgainst((int) $elementID);
            if ($shoots > 1) {
                $rapidfire['from'][$fleetID] = round(($shoots - 1) / $shoots * 100, 2);
            }
        }

        return $rapidfire;
    }
}
