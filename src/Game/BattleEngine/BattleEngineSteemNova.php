<?php

namespace App\Game\BattleEngine;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/**
 * PHP battle engine (the OGame-style per-ship simulation, fallback when the Rust engine is unavailable).
 * Every ship is an individual object with attack/shield/armor; up to MAX_ATTACK_ROUNDS rounds in which every
 * ship fires once at a random enemy ship (with rapid fire re-shots), then destroyed ships are removed and all
 * shields restored. Also computes the debris field, the 70% (+/-) defense rebuild and the wreck field for the
 * repair dock. Fleet arrays are the format built by BattleHandler. Uses the Ds extension when installed.
 */
class BattleEngineSteemNova extends AbstractBattleEngine
{
    private $dsAvailable = false;
    private int $minScale = 1;
    public function __construct(\App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        // use the php-ds structures only when their classes really exist: a runner can report the extension as
        // loaded without registering the classes (seen on GitHub's PHP builds); the array path gives identical results
        $this->dsAvailable = class_exists(\Ds\Vector::class, false) && class_exists(\Ds\Map::class, false);
    }
    public function getName(): string
    {
        return 'steemnova';
    }

    public function isAvailable(bool $sim = false): bool
    {
        return true;
    }

    /**
     * A battle is never cancelled because one side is very large (a defender could otherwise make a planet
     * unattackable by stacking cheap units). Order of preference: the engines that need no per-ship arrays
     * (Rust, see BattleHandler) -> this engine with memory_limit raised for the fight -> this engine on
     * proportionally scaled fleets (see fightScaled). Only if even that keeps running out of memory is false
     * returned, after the scale was quadrupled several times (down to a few thousand ships).
     */
    public function fight(array $attackers, array $defenders, int $universe, bool $sim = false, int $simTries = 1): BattleResult|false
    {
        // The per-ship simulation works on plain arrays (one array per ship, 500k ships at most); the typed fleets are
        // converted once here and receive the surviving units once the fight succeeded.
        $workAttackers = BattleFleet::listToArrays($attackers);
        $workDefenders = BattleFleet::listToArrays($defenders);
        $originalAttackers = $workAttackers;
        $originalDefenders = $workDefenders;
        $this->minScale = 1;
        try {
            for ($try = 0; $try < 6; $try++) {
                $result = $this->fightScaled($workAttackers, $workDefenders, $universe, $sim, $simTries);
                if ($result !== false) {
                    foreach ($attackers as $id => $fleet) {
                        $fleet->units = $workAttackers[$id]['unit'];
                    }
                    foreach ($defenders as $id => $fleet) {
                        $fleet->units = $workDefenders[$id]['unit'];
                    }

                    return $result;
                }
                $workAttackers = $originalAttackers;
                $workDefenders = $originalDefenders;
                $this->minScale *= 4;
            }
        } finally {
            $this->restoreMemoryLimit();
        }

        return false;
    }

    /**
     * Fights with at most shipBudget() simulated ships. Bigger fleets are divided by a common factor (every
     * unit type keeps at least one ship), fought, and the remaining share of each unit type is applied to the
     * real amounts. Fights are statistical (random targets) and invariant when both sides shrink by the same
     * factor, so the outcome matches the full fight closely; losses, wrecks and debris are computed from the
     * real amounts. Round data of the report is scaled back as well.
     *
     * @param array<int|string, array<string, mixed>> $attackers working copies of the fleets (BattleFleet::toArray()); `unit` ends as the survivors
     * @param array<int|string, array<string, mixed>> $defenders likewise; the planet's `unit` includes the rebuilt defense
     */
    private function fightScaled(array &$attackers, array &$defenders, int $universe, bool $sim, int $simTries): BattleResult|false
    {
        $executionTime = microtime(true);

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $config = $this->configs->get($universe);

        $tally = $this->tallyStartValues($attackers, $defenders);
        [$scale, $realUnits, $scaledUnits] = $this->scaleDown($attackers, $defenders);

        $rounds = $this->fightRounds($attackers, $defenders, $sim, $advancedStats, $att, $def);
        if ($rounds === false) {
            return false; // Memory limit exceeded, return false to indicate failure
        }
        $ROUND = $rounds;

        if ($scale > 1) {
            $this->scaleBack($attackers, $defenders, $ROUND, $advancedStats, $realUnits, $scaledUnits, $scale);
        }

        if (!$sim) {
            $this->recordKills($statsHandler, $advancedStats);
        }

        if ($att['attackAmount']['total'] <= 0 && $def['attackAmount']['total'] > 0) {
            $won = DEFENDER;
        } elseif ($att['attackAmount']['total'] > 0 && $def['attackAmount']['total'] <= 0) {
            $won = ATTACKER;
        } else {
            $won = DRAW;
        }

        $this->subtractSurvivors($tally, $attackers);
        [$repairedDef, $wreckfield, $defendingPlayer, $DRESDefs] = $this->settleDefenders($tally, $defenders, $config, $sim, $statsHandler);

        $unitsLost = $tally['unitsLost'];
        $capacityLost = $tally['capacityLost'];
        $ARES = $tally['ARES'];
        $DRES = $tally['DRES'];
        $TRES = $tally['TRES'];

        $ARES['metal'] = max($ARES['metal'], 0);
        $ARES['crystal'] = max($ARES['crystal'], 0);
        $DRES['metal'] = max($DRES['metal'], 0);
        $DRES['crystal'] = max($DRES['crystal'], 0);
        $TRES['attacker'] = max($TRES['attacker'], 0);
        $TRES['defender'] = max($TRES['defender'], 0);

        $totalLost = ['attacker' => $TRES['attacker'], 'defender' => $TRES['defender']];
        $debAttMet = ($ARES['metal'] * ($config->fleet_debris_percentage / 100));
        $debAttCry = ($ARES['crystal'] * ($config->fleet_debris_percentage / 100));
        $debDefMet = ($DRES['metal'] * ($config->fleet_debris_percentage / 100)) + ($DRESDefs['metal'] * ($config->def_debris_percentage / 100));
        $debDefCry = ($DRES['crystal'] * ($config->fleet_debris_percentage / 100)) + ($DRESDefs['crystal'] * ($config->def_debris_percentage / 100));

        if (!$sim && $defenders[0]['player']['id'] != 0) {
            $this->recordFightStats($statsHandler, $attackers, $defenders, $debAttMet + $debDefMet, $debAttCry + $debDefCry, $totalLost, $unitsLost);
        }

        if (!$this->wreckfieldAllowed($defenders[0], $defendingPlayer['lost'], $config)) {
            $wreckfield = [];
        }

        return new BattleResult(
            $won,
            new BattleDebris($debAttMet, $debAttCry, $debDefMet, $debDefCry),
            $ROUND,
            new BattleLosses($totalLost['attacker'], $totalLost['defender'], $unitsLost, $capacityLost),
            $repairedDef,
            $wreckfield,
            microtime(true) - $executionTime,
        );
    }

    /**
     * Value bookkeeping at the start of a fight: metal+crystal value of both sides (`TRES` total per side; `ARES`/`DRES`
     * attacker and defender fleet value, the defender's defense counts in the total only), the per-player value and cargo
     * capacity of the fleets (the starting point of the lost values) and the defense snapshot `STARTDEF` of the planet
     * (fleet 0) for the 70 percent restore.
     *
     * @param array<int|string, array<string, mixed>> $attackers working copies of the fleets
     * @param array<int|string, array<string, mixed>> $defenders likewise
     * @return array<string, mixed> keys unitsLost, capacityLost, TRES, ARES, DRES, STARTDEF
     */
    private function tallyStartValues(array $attackers, array $defenders): array
    {
        $e = $this->elements();
        $unitsLost = ['attacker' => [], 'defender' => []];
        $capacityLost = ['attacker' => [], 'defender' => []];

        $TRES = ['attacker' => 0, 'defender' => 0];
        $ARES = $DRES = ['metal' => 0, 'crystal' => 0];

        // $STARTDEF - snapshot of defense amount. Needed for 70% restore
        $STARTDEF = [];

        // calculate attackers fleet metal+crystal value
        foreach ($attackers as $fleetID => $attacker) {
            if (!isset($unitsLost['attacker'][$attacker['player']['id']])) {
                $unitsLost['attacker'][$attacker['player']['id']] = 0;
                $capacityLost['attacker'][$attacker['player']['id']] = 0;
            }
            foreach ($attacker['unit'] as $element => $amount) {
                $ARES['metal'] += $e->cost($element)->metal * $amount;
                $ARES['crystal'] += $e->cost($element)->crystal * $amount;

                $unitsLost['attacker'][$attacker['player']['id']] += $e->cost($element)->metal * $amount;
                $unitsLost['attacker'][$attacker['player']['id']] += $e->cost($element)->crystal * $amount;
                $capacityLost['attacker'][$attacker['player']['id']] += $e->capacity($element) * $amount;
            }
        }
        $TRES['attacker'] = $ARES['metal'] + $ARES['crystal'];

        //calculate defenders fleet metal+crystal value
        foreach ($defenders as $fleetID => $defender) {
            if (!isset($unitsLost['defender'][$defender['player']['id']])) {
                $unitsLost['defender'][$defender['player']['id']] = 0;
                $capacityLost['defender'][$defender['player']['id']] = 0;
            }
            foreach ($defender['unit'] as $element => $amount) {
                if ($element < 300) {
                    // ships
                    $DRES['metal'] += $e->cost($element)->metal * $amount;
                    $DRES['crystal'] += $e->cost($element)->crystal * $amount;

                    $unitsLost['defender'][$defender['player']['id']] += $e->cost($element)->metal * $amount;
                    $unitsLost['defender'][$defender['player']['id']] += $e->cost($element)->crystal * $amount;
                    $capacityLost['defender'][$defender['player']['id']] += $e->capacity($element) * $amount;
                }

                if ($fleetID == 0) {
                    if (!isset($STARTDEF[$element])) {
                        $STARTDEF[$element] = 0;
                    }
                    $STARTDEF[$element] += $amount;
                }

                $TRES['defender'] += $e->cost($element)->metal * $amount;
                $TRES['defender'] += $e->cost($element)->crystal * $amount;
            }
        }

        return ['unitsLost' => $unitsLost, 'capacityLost' => $capacityLost, 'TRES' => $TRES, 'ARES' => $ARES, 'DRES' => $DRES, 'STARTDEF' => $STARTDEF];
    }

    /**
     * Scales huge fights down so they fit into memory and time (see calculateAttack): every unit type is divided by the
     * common factor and keeps at least one ship.
     *
     * @param array<int|string, array<string, mixed>> $attackers changed in place when scaled
     * @param array<int|string, array<string, mixed>> $defenders changed in place when scaled
     * @return array{0: int, 1: array<string, array>, 2: array<string, array>} scale factor; the real amounts per side,
     *         fleet and element and the scaled fleets per side (both empty when not scaled)
     */
    private function scaleDown(array &$attackers, array &$defenders): array
    {
        $totalShips = 0;
        foreach ([$attackers, $defenders] as $side) {
            foreach ($side as $fleet) {
                $totalShips += array_sum($fleet['unit']);
            }
        }
        $budget = $this->shipBudget((int) $totalShips);
        $scale = max($this->minScale, $totalShips > $budget ? (int) ceil($totalShips / $budget) : 1);
        $realUnits = ['attackers' => [], 'defenders' => []];
        if ($scale > 1) {
            foreach (['attackers', 'defenders'] as $sideName) {
                foreach ($$sideName as $fleetID => $fleet) {
                    foreach ($fleet['unit'] as $element => $amount) {
                        $realUnits[$sideName][$fleetID][$element] = $amount;
                        if ($amount > 0) {
                            // stochastic rounding keeps the expected weight of every type (a stack below the scale is not inflated to one
                            // full ship); a type that rounds to 0 sits the fight out and shares its fleet's loss ratio (scaleBack)
                            $exact = $amount / $scale;
                            $scaled = (int) floor($exact);
                            if ($exact - $scaled > 0 && rand(0, 999999) < ($exact - $scaled) * 1000000) {
                                $scaled++;
                            }
                            ${$sideName}[$fleetID]['unit'][$element] = $scaled;
                        }
                    }
                }
            }
        }
        $scaledUnits = $scale > 1 ? ['attackers' => $attackers, 'defenders' => $defenders] : [];

        return [$scale, $realUnits, $scaledUnits];
    }

    /**
     * Fights the rounds until one side is out of ships or MAX_ATTACK_ROUNDS is reached: per round the start state of both
     * sides is recorded, then (if both can still shoot) the shots, the destruction and the shield restore.
     *
     * @param array<int|string, array<string, mixed>> $attackers survivors at the end (changed in place)
     * @param array<int|string, array<string, mixed>> $defenders likewise
     * @param array<int|string, mixed> $advancedStats out: kills per attacker/victim/element (also filled in simulations
     *        to have a comparable memory usage; if a simulation crashes the real fight would not work)
     * @param array<string, mixed> $att out: combat values of the attackers at the end of the last round
     * @param array<string, mixed> $def likewise for the defenders
     * @return array<int, array<string, mixed>>|false the round log, false when the memory limit was exceeded
     */
    private function fightRounds(array &$attackers, array &$defenders, bool $sim, ?array &$advancedStats, ?array &$att, ?array &$def): array|false
    {
        $ROUND = [];
        $advancedStats = [];

        for ($ROUNDC = 0; $ROUNDC <= MAX_ATTACK_ROUNDS; $ROUNDC++) {
            if (!$this->checkMemoryUsage()) {
                return false; // Memory limit exceeded, return false to indicate failure
            }
            $att = $this->initCombatValues($attackers, $ROUNDC == 0);
            $def = $this->initCombatValues($defenders, $ROUNDC == 0);

            $ROUND[$ROUNDC] = [
                'attackers' => $this->withoutUnits($attackers),
                'defenders' => $this->withoutUnits($defenders),
                'infoA' => $att['attArray'],
                'infoD' => $def['attArray'],
            ];

            if ($att['attackAmount']['total'] > 0 && $def['attackAmount']['total'] > 0 && $ROUNDC < MAX_ATTACK_ROUNDS) {
                // FIGHT
                $fightResults = $this->fightRound($attackers, $defenders, $sim, $advancedStats);
                if (!$fightResults) {
                    return false; // Memory limit exceeded, return false to indicate failure
                }

                $this->destroy($attackers);
                $this->destroy($defenders);

                $this->restoreShields($attackers);
                $this->restoreShields($defenders);

                $ROUND[$ROUNDC]['attack'] = $fightResults['attack'];
                $ROUND[$ROUNDC]['defense'] = $fightResults['defense'];
                $ROUND[$ROUNDC]['attackShield'] = $fightResults['attackShield'];
                $ROUND[$ROUNDC]['defShield'] = $fightResults['defShield'];
            } else {
                break;
            }
        }

        return $ROUND;
    }

    /**
     * Counts the kills of a real fight in the advanced stats: attacker id 0 is the AI (expedition: the victim's lost
     * units), otherwise the destroyed units of attacker against victim.
     *
     * @param array<int|string, array<int|string, array<int|string, int|float>>> $advancedStats attacker => victim => element => amount
     */
    private function recordKills(AdvancedStatsHandler $statsHandler, array $advancedStats): void
    {
        foreach ($advancedStats as $attacker => $victims) {
            foreach ($victims as $victim => $ships) {
                foreach ($ships as $element => $amount) {
                    if ($attacker == 0) { // PlayerID for AI is 0
                        // Expo
                        $statsHandler->updateExpoLostAdvancedStats($victim, [$element => $amount]);
                    } else {
                        // Attack
                        $statsHandler->updateDestroyedAdvancedStats($attacker, $victim, $element, $amount);
                    }
                }
            }
        }
    }

    /**
     * CDR of the attackers: subtracts the surviving ships from the start values, so the tally holds what was lost.
     *
     * @param array<string, mixed> $tally from tallyStartValues(), changed in place
     * @param array<int|string, array<string, mixed>> $attackers survivors
     */
    private function subtractSurvivors(array &$tally, array $attackers): void
    {
        $e = $this->elements();
        foreach ($attackers as $fleetID => $attacker) {                    // flotte attaquant en CDR
            foreach ($attacker['unit'] as $element => $amount) {
                $tally['TRES']['attacker'] -= $e->cost($element)->metal * $amount;
                $tally['TRES']['attacker'] -= $e->cost($element)->crystal * $amount;

                $tally['ARES']['metal'] -= $e->cost($element)->metal * $amount;
                $tally['ARES']['crystal'] -= $e->cost($element)->crystal * $amount;

                $tally['unitsLost']['attacker'][$attacker['player']['id']] -= $e->cost($element)->metal * $amount;
                $tally['unitsLost']['attacker'][$attacker['player']['id']] -= $e->cost($element)->crystal * $amount;
                $tally['capacityLost']['attacker'][$attacker['player']['id']] -= $e->capacity($element) * $amount;
            }
        }
    }

    /**
     * Settles the defenders: subtracts the surviving ships from the start values, collects the wreck field candidates
     * (ships lost from the planet's fleet, with the repair dock) and restores the destroyed defense (70 percent +/- 20,
     * random per defense type; the destroyed rest counts as debris). Uses rand() in the order of the fleets and
     * units, which seeded fights depend on.
     *
     * @param array<string, mixed> $tally from tallyStartValues(), changed in place
     * @param array<int|string, array<string, mixed>> $defenders survivors; the restored defense is added in place
     * @return array{0: array<int, array{units: int|float, percent: int|float}>, 1: array<int, int|float>, 2: array{lost: int|float}, 3: array{metal: int|float, crystal: int|float}}
     *         repaired defense per element, wreck field candidates, lost value of the planet's ships, value of the
     *         destroyed (not restored) defense
     */
    private function settleDefenders(array &$tally, array &$defenders, object $config, bool $sim, AdvancedStatsHandler $statsHandler): array
    {
        $e = $this->elements();
        $STARTDEF = $tally['STARTDEF'];
        $DRESDefs = ['metal' => 0, 'crystal' => 0];

        // restore defense (70% +/- 20%)
        $repairedDef = [];
        // wreckfield ships
        $wreckfield = [];
        // wreckfield requirements
        $defendingPlayer = [
            'lost' => 0,
        ];
        foreach ($defenders as $fleetID => $defender) {
            foreach ($defender['unit'] as $element => $amount) {
                if ($element < 300) {                           // flotte defenseur en CDR
                    $tally['DRES']['metal'] -= $e->cost($element)->metal * $amount;
                    $tally['DRES']['crystal'] -= $e->cost($element)->crystal * $amount;

                    $tally['TRES']['defender'] -= $e->cost($element)->metal * $amount;
                    $tally['TRES']['defender'] -= $e->cost($element)->crystal * $amount;

                    $tally['unitsLost']['defender'][$defender['player']['id']] -= $e->cost($element)->metal * $amount;
                    $tally['unitsLost']['defender'][$defender['player']['id']] -= $e->cost($element)->crystal * $amount;
                    $tally['capacityLost']['defender'][$defender['player']['id']] -= $e->capacity($element) * $amount;

                    if ($fleetID == 0 && $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) && $STARTDEF[$element] > $amount) {
                        $lost = $STARTDEF[$element] - $amount;

                        $wreckfield[$element] = floor($lost * (1 - ($config->fleet_debris_percentage / 100)));
                        $defendingPlayer['lost'] += $e->cost($element)->metal * $lost;
                        $defendingPlayer['lost'] += $e->cost($element)->crystal * $lost;
                    }
                } else {                                    // defs defenseur en CDR + reconstruction
                    $tally['TRES']['defender'] -= $e->cost($element)->metal * $amount;
                    $tally['TRES']['defender'] -= $e->cost($element)->crystal * $amount;

                    $lost = $STARTDEF[$element] - $amount;
                    $giveback = 0;
                    if ($lost > 10) {
                        $giveback = ceil($lost * (rand(60, 80) / 100));
                    } else {
                        for ($i = 0; $i < $lost; $i++) {
                            if (rand(1, 100) <= 70) {
                                $giveback += 1;
                            }
                        }
                    }
                    $defenders[$fleetID]['unit'][$element] += $giveback;
                    if ($lost > 0) {
                        $repairedDef[$element]['units'] = $giveback;
                        $repairedDef[$element]['percent'] = $giveback / $lost * 100;
                        if (!$sim) {
                            $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::getRepaired($element), $giveback);
                        }
                    }
                    $DRESDefs['metal'] += $e->cost($element)->metal * ($lost - $giveback);
                    $DRESDefs['crystal'] += $e->cost($element)->crystal * ($lost - $giveback);
                }
            }
        }

        return [$repairedDef, $wreckfield, $defendingPlayer, $DRESDefs];
    }

    /**
     * Writes the fight to the advanced stats of every involved player (once per user, not per fleet): the debris, the
     * destroyed value of the other side and the own lost value. Only for fights against real players.
     *
     * @param array<int|string, array<string, mixed>> $attackers survivors
     * @param array<int|string, array<string, mixed>> $defenders survivors
     * @param int|float $debrisMetal metal debris of both sides
     * @param int|float $debrisCrystal crystal debris of both sides
     * @param array{attacker: int|float, defender: int|float} $totalLost lost value per side
     * @param array<string, array<int|string, int|float>> $unitsLost lost value per player and side
     */
    private function recordFightStats(AdvancedStatsHandler $statsHandler, array $attackers, array $defenders, int|float $debrisMetal, int|float $debrisCrystal, array $totalLost, array $unitsLost): void
    {
        $statsDone = [];
        foreach ($attackers as $fleetID => $attacker) {
            if (isset($statsDone[$attacker['player']['id']])) {
                continue; // once per user, not per fleet
            }
            $statsDone[$attacker['player']['id']] = true;
            $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::KBMETAL, $debrisMetal);
            $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::KBCRYSTAL, $debrisCrystal);
            $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::DESUNITS, $totalLost['defender']);
            $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['attacker'][$attacker['player']['id']] ?? 0));
        }
        $statsDone = [];
        foreach ($defenders as $fleetID => $defender) {
            if (isset($statsDone[$defender['player']['id']])) {
                continue; // once per user, not per fleet
            }
            $statsDone[$defender['player']['id']] = true;
            $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::KBMETAL, $debrisMetal);
            $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::KBCRYSTAL, $debrisCrystal);
            $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::DESUNITS, $totalLost['attacker']);
            $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['defender'][$defender['player']['id']] ?? 0));
        }
    }

    /**
     * Whether the destroyed ships of the planet may form a wreck field: repair dock module on, a real player as owner
     * who has ships at all, no wreck field cooldown running, and the lost value reaching the minimum units and the
     * minimum share of his total fleet.
     *
     * @param array<string, mixed> $planetFleet the planet's side (fleet 0) with player and currentTotalFleetState
     * @param int|float $lostValue metal+crystal value of the ships lost from the planet
     */
    private function wreckfieldAllowed(array $planetFleet, int|float $lostValue, object $config): bool
    {
        return !(!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)
            || $planetFleet['player']['id'] === 0
            || !isset($planetFleet['currentTotalFleetState']['totalUnits'])
            || $planetFleet['currentTotalFleetState']['totalUnits'] <= 0
            || (!empty($planetFleet['player']['wreckfield_cooldown_until']) && $planetFleet['player']['wreckfield_cooldown_until'] > $this->state->runtime->timestamp)
            || $lostValue < $config->wreckfield_min_units
            || $lostValue / $planetFleet['currentTotalFleetState']['totalUnits'] < ($config->wreckfield_min_percent / 100));
    }

    /**
     * Fleet data without the per-ship arrays (only needed while fighting, never read from the report rounds).
     *
     * @param array<mixed> $fleets
     * @return array<mixed>
     */
    private function withoutUnits(array $fleets): array
    {
        foreach ($fleets as $fleetID => $fleet) {
            unset($fleets[$fleetID]['units']);
        }

        return $fleets;
    }

    /**
     * Puts a type back that was scaled to 0 ships and sat the fight out: full amount in every round but the last, its
     * share of the fleet's losses at the end.
     *
     * @param array<mixed> $attackers
     * @param array<mixed> $defenders
     * @param array<mixed> $ROUND
     */
    private function restoreSatOut(array &$attackers, array &$defenders, array &$ROUND, string $sideName, int|string $fleetID, int|string $element, int $left, int|float $real): void
    {
        $last = array_key_last($ROUND);
        foreach (array_keys($ROUND) as $r) {
            if (isset($ROUND[$r][$sideName][$fleetID])) {
                $ROUND[$r][$sideName][$fleetID]['unit'][$element] = $r === $last ? $left : $real;
            }
        }
        if ($sideName === 'attackers') {
            $attackers[$fleetID]['unit'][$element] = $left;
        } else {
            $defenders[$fleetID]['unit'][$element] = $left;
        }
    }

    /**
     * Converts the result of a fight on scaled fleets back to the real amounts.
     *
     * @param array<mixed> $realUnits
     * @param array<mixed> $scaledUnits
     */
    private function scaleBack(&$attackers, &$defenders, &$ROUND, &$advancedStats, array $realUnits, array $scaledUnits, int $scale)
    {
        $factor = static function ($real, $scaled) {
            return $scaled > 0 ? $real / $scaled : 1;
        };
        $sides = ['attackers' => 'infoA', 'defenders' => 'infoD'];

        foreach ($sides as $sideName => $infoKey) {
            $survivorFleets = $sideName === 'attackers' ? $attackers : $defenders;
            // survival ratio of the ships that fought (per fleet, else of the whole side): applies to the types that were
            // scaled to 0 and sat the fight out
            $ratio = static fn(float $fought, float $left): float => $fought > 0 ? min(1, $left / $fought) : 1;
            $sideFought = 0;
            $sideLeft = 0;
            foreach ($scaledUnits[$sideName] as $fleetID => $fleet) {
                $sideFought += array_sum($fleet['unit']);
                $sideLeft += array_sum($survivorFleets[$fleetID]['unit'] ?? []);
            }
            foreach ($realUnits[$sideName] as $fleetID => $elements) {
                $fought = array_sum($scaledUnits[$sideName][$fleetID]['unit']);
                $fleetRatio = $fought > 0 ? $ratio($fought, array_sum($survivorFleets[$fleetID]['unit'] ?? [])) : $ratio($sideFought, $sideLeft);
                foreach ($elements as $element => $real) {
                    $start = $scaledUnits[$sideName][$fleetID]['unit'][$element];
                    if ($start <= 0) {
                        $this->restoreSatOut($attackers, $defenders, $ROUND, $sideName, $fleetID, $element, $real <= 0 ? 0 : (int) round($real * $fleetRatio), $real);
                        continue;
                    }
                    $f = $factor($real, $start);
                    foreach ($ROUND as $r => $round) {
                        $left = $round[$sideName][$fleetID]['unit'][$element] ?? 0;
                        $ROUND[$r][$sideName][$fleetID]['unit'][$element] = $left <= 0 ? 0 : ($left >= $start ? $real : max(1, (int) round($left * $f)));
                        if (isset($round[$infoKey][$fleetID][$element])) {
                            foreach (['def', 'shield', 'att'] as $key) {
                                $ROUND[$r][$infoKey][$fleetID][$element][$key] *= $f;
                            }
                        }
                    }
                    $left = $sideName === 'attackers' ? ($attackers[$fleetID]['unit'][$element] ?? 0) : ($defenders[$fleetID]['unit'][$element] ?? 0);
                    $new = $left <= 0 ? 0 : ($left >= $start ? $real : max(1, (int) round($left * $f)));
                    if ($sideName === 'attackers') {
                        $attackers[$fleetID]['unit'][$element] = $new;
                    } else {
                        $defenders[$fleetID]['unit'][$element] = $new;
                    }
                }
            }
        }

        foreach ($ROUND as $r => $round) {
            foreach (['attack', 'defense', 'attackShield', 'defShield'] as $key) {
                if (isset($round[$key])) {
                    $ROUND[$r][$key] *= $scale;
                }
            }
        }
        foreach ($advancedStats as $attacker => $victims) {
            foreach ($victims as $victim => $ships) {
                foreach ($ships as $element => $amount) {
                    $advancedStats[$attacker][$victim][$element] = $amount * $scale;
                }
            }
        }
    }

    /**
     * One round: all attackers shoot, then all defenders shoot (damage is applied immediately on the victim, but
     * destruction is only settled afterwards by destroy()).
     * @param array<mixed> $advancedStats by-ref kill counter [shooterUserId][victimUserId][unitId] => ships destroyed.
     * @return array|\Ds\Map|false per-side damage dealt and shield damage absorbed (keys attack, defense,
     * attackShield, defShield), or false when the memory guard trips.
     */
    private function fightRound(&$attackers, &$defenders, $sim, &$advancedStats)
    {

        $attack = ['attack' => 0, 'shield' => 0];
        $defense = ['attack' => 0, 'shield' => 0];

        if ($this->dsAvailable) {
            $attack = new \Ds\Map(['attack' => 0, 'shield' => 0]);
            $defense = new \Ds\Map(['attack' => 0, 'shield' => 0]);
        }

        // attackers shoot
        foreach ($attackers as $fleetID => $attacker) {
            foreach ($attacker['units'] as $element => $unit) {
                $this->shoot($attacker['player']['id'], $unit, $defenders, $attack, $sim, $advancedStats);
            }
            if (!$this->checkMemoryUsage()) {
                return false; // Memory limit exceeded, return false to indicate failure
            }
        }
        // defenders shoot
        foreach ($defenders as $fleetID => $defender) {
            foreach ($defender['units'] as $element => $unit) {
                $this->shoot($defender['player']['id'], $unit, $attackers, $defense, $sim, $advancedStats);
            }
            if (!$this->checkMemoryUsage()) {
                return false; // Memory limit exceeded, return false to indicate failure
            }
        }

        if ($this->dsAvailable) {
            return new \Ds\Map([
                'attack' => $attack['attack'],
                'defense' => $defense['attack'],
                'attackShield' => $defense['shield'],
                'defShield' => $attack['shield'],
            ]);
        }

        return [
            'attack' => $attack['attack'],
            'defense' => $defense['attack'],
            'attackShield' => $defense['shield'],
            'defShield' => $attack['shield'],
        ];
    }

    /**
     * Removes every ship with armor <= 0 or flagged 'explode' from the per-ship list and decrements the fleet's
     * unit counts accordingly. Called after each round.
     */
    private function destroy(&$attackers)
    {
        foreach ($attackers as $fleetID => &$attacker) {
            // foreach ($attacker['units'] as $element => $unit)
            for ($i = 0; $i < count($attacker['units']); $i++) {
                $unit = $attacker['units'][$i];
                if ($unit['armor'] <= 0 || $unit['explode']) {
                    // destroy unit
                    $attacker['unit'][$unit['unit']] -= 1;
                    if ($this->dsAvailable) {
                        $attacker['units']->remove($i);
                    } else {
                        unset($attacker['units'][$i]);
                        $attacker['units'] = array_values($attacker['units']);
                    }
                    $i--;
                }
            }
        }
    }

    /**
     * One shot of one ship at a uniformly random enemy ship (picked over all ships of all fleets of the other side).
     * Rules: a shot whose attack is below 1% of the target's shield bounces off; otherwise the shield absorbs
     * first, the remainder hits the armor. A ship (id 2xx) below 70% of its initial armor explodes with probability
     * (1 - armor/initialArmor) on every hit. Rapid fire: if the target type is in the shooter's 'sd' table with
     * value N the shooter fires again with probability (N-1)/N (recursive).
     * Side effects: updates damage totals in $ad and kill statistics in $advancedStats.
     * @param int $attacker user id of the shooter (0 = pirates / expedition NPC).
     * @param array<mixed> $unit shooter ship entry (unit id, att, ...).
     */
    private function shoot($attacker, $unit, &$defenders, &$ad, $sim, &$advancedStats)
    {
        // SHOOT
        $e = $this->elements();
        $count = 0;
        foreach ($defenders as $fID => &$defender) {
            $count += count($defender['units']);
        }
        $ran = rand(0, $count - 1);
        $count = 0;
        $victim = 0;
        $victimShip = [];
        $initialArmor = 0;
        foreach ($defenders as $fID => &$defender) {
            $count += count($defender['units']);
            if ($ran < $count) {
                $victim = $defender['player']['id'];
                $victimShipId = rand(0, count($defender['units']) - 1);
                $victimShip = &$defender['units'][$victimShipId];
                $armorTech = (1 + (0.1 * $defender['player']['defence_tech']));
                $initialArmor = ($e->cost($victimShip['unit'])->metal
                        + $e->cost($victimShip['unit'])->crystal) / 10 * $armorTech;
                break;
            }
        }
        $ad['attack'] += $unit['att'];
        if ($unit['att'] * 100 > $victimShip['shield']) {
            $penetration = $unit['att'] - $victimShip['shield'];
            if ($penetration >= 0) {
                //+penetration
                $ad['shield'] += $victimShip['shield'];
                $victimShip['shield'] = 0;
                $victimShip['armor'] -= $penetration; // shoot at armor
            } else {
                //-penetration
                $ad['shield'] += $unit['att'];
                $victimShip['shield'] -= $unit['att']; // shoot at shield
            }

            //check destruction
            if (floor($victimShip['unit'] / 100) == 2 && !$victimShip['explode']) {
                if ($victimShip['armor'] > 0 && $victimShip['armor'] < 0.7 * $initialArmor) {
                    $ran = rand(0, (int) $initialArmor);
                    if ($ran > $victimShip['armor']) {
                        $victimShip['explode'] = true;
                        if (!isset($advancedStats[$attacker][$victim][$victimShip['unit']])) {
                            $advancedStats[$attacker][$victim][$victimShip['unit']] = 0;
                        }
                        $advancedStats[$attacker][$victim][$victimShip['unit']] += 1;
                    }
                }
            }
            if ($victimShip['armor'] <= 0 && !$victimShip['explode']) {
                $victimShip['explode'] = true;
                if (!isset($advancedStats[$attacker][$victim][$victimShip['unit']])) {
                    $advancedStats[$attacker][$victim][$victimShip['unit']] = 0;
                }
                $advancedStats[$attacker][$victim][$victimShip['unit']] += 1;
            }
        }
        // else bounced hit (Weaponry of the shooting unit is less than 1% of the Shielding of the target unit)

        // Rapid fire
        foreach ($e->combat($unit['unit'])->rapidFire as $sdId => $count) {
            if ($victimShip['unit'] == $sdId) {
                $ran = rand(1, $count);
                if ($ran < $count) {
                    $this->shoot($attacker, $unit, $defenders, $ad, $sim, $advancedStats);
                }
            }
        }
    }

    /**
     * Builds the per-ship objects on the first call ($firstInit: one array per ship with attack/shield/armor
     * scaled by the owner's weapon/shield/armor tech, +10% per level, armor = (metal+crystal cost)/10) and
     * returns the per-fleet/per-unit totals used for the round report.
     * @return array{attackAmount: array<int|string,int>, attArray: array} ship counts per fleet ('total' for all)
     * and the summed def/shield/att values per fleet and unit id.
     */
    protected function initCombatValues(&$fleets, $firstInit = false): array
    {
        // INIT COMBAT VALUES
        $e = $this->elements();
        $attackAmount = ['total' => 0];
        $attArray = [];
        foreach ($fleets as $fleetID => $attacker) {
            $attackAmount[$fleetID] = 0;

            // init techs
            $attTech = (1 + (0.1 * $attacker['player']['military_tech']));
            $shieldTech = (1 + (0.1 * $attacker['player']['shield_tech']));
            $armorTech = (1 + (0.1 * $attacker['player']['defence_tech']));

            if ($firstInit) {
                $fleets[$fleetID]['techs'] = [$attTech, $shieldTech, $armorTech];
                $fleets[$fleetID]['units'] = []; // array();
                if ($this->dsAvailable) {
                    $fleets[$fleetID]['units'] = new \Ds\Vector();
                }
            }

            $iter = 0;
            // init single ships
            foreach ($attacker['unit'] as $element => $amount) {
                // dont randomize +/-20% of attack power. The random factor is high enough

                $thisAtt = ($e->combat($element)->attack) * $attTech; // * (rand(80, 120) / 100);
                $thisShield = ($e->combat($element)->shield) * $shieldTech;
                $thisArmor = ($e->cost($element)->metal + $e->cost($element)->crystal) / 10 * $armorTech;

                $attArray[$fleetID][$element]['def'] = 0;
                $attArray[$fleetID][$element]['shield'] = 0;
                $attArray[$fleetID][$element]['att'] = 0;
                for ($ship = 0; $ship < $amount; $ship++, $iter++) {
                    if ($firstInit) {
                        // create new array for EACH ship
                        $fleets[$fleetID]['units'][] = [
                            'unit' => $element,
                            'shield' => $thisShield,
                            'armor' => $thisArmor,
                            'att' => $thisAtt,
                            'explode' => false,
                        ];
                    }
                    $attArray[$fleetID][$element]['def'] += $fleets[$fleetID]['units'][$iter]['armor'];
                    $attArray[$fleetID][$element]['shield'] += $thisShield;
                    $attArray[$fleetID][$element]['att'] += $thisAtt;
                }

                $attackAmount[$fleetID] += $amount;
                $attackAmount['total'] += $amount;
            }
        }

        return ['attackAmount' => $attackAmount, 'attArray' => $attArray];
    }

    /**
     * Resets the shield of every surviving ship to its full value; shields regenerate between rounds.
     */
    private function restoreShields(&$fleets)
    {
        $e = $this->elements();
        foreach ($fleets as $fleetID => $attacker) {
            $shieldTech = (1 + (0.1 * $attacker['player']['shield_tech']));
            foreach ($attacker['units'] as $element => $unit) {
                $fleets[$fleetID]['units'][$element]['shield'] = ($e->combat($unit['unit'])->shield) * $shieldTech;
            }
        }
    }


}
