<?php

namespace App\Game\BattleEngine;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/**
 * Rust battle engine. bin/battlerust (a small launcher built from tools/battlerust-cli/) loads
 * bin/libbattlerust.so (source: tools/battlerust/) and exchanges the battle as JSON over stdin/stdout:
 * createRustJson() builds the input, parseRustJson() applies the result. No PHP extension needed; one process start
 * (about 2 ms) per battle. Linux x86_64 only; elsewhere BattleHandler falls back to the PHP engine.
 *
 * The library fights like BattleEngineSteemNova ship by ship with PHP's mt_rand; with useSeed() both engines produce
 * the same fight, which the parity tests rely on. The rounds of the library are
 * only the fight; losses, debris, defense rebuild and wreck field are computed here, in the order and with the
 * random draws of the PHP engine.
 */
class BattleEngineRust extends AbstractBattleEngine
{
    /** Seed handed to the library (tests and diagnostics only); null = the library seeds itself from the OS. */
    private ?int $seed = null;

    /**
     * Makes the next fights reproducible: the library is seeded with $seed and, afterwards, PHP's generator is
     * moved to the point where the PHP engine would stand after the same fight (mt_srand($seed) plus the draws the
     * library consumed), so the defense rebuild rolls match the PHP engine seeded with mt_srand($seed).
     * Pass null to go back to random fights. Not used by the game itself.
     */
    public function useSeed(?int $seed): void
    {
        $this->seed = $seed;
    }
    /** True once a fight fell back to the PHP engine (the library refused or failed), see calculateAttack(). */
    private bool $fellBack = false;

    /**
     * Stored with every report: 'rust' reports are compressed and use the rust report template. After a fight that fell
     * back to the PHP engine the name is that engine's, because the round log of the report has its format.
     */
    public function getName(): string
    {
        return $this->fellBack ? 'steemnova' : 'rust';
    }

    /**
     * @return bool true on Linux with proc_open, an executable bin/battlerust and bin/libbattlerust.so.
     */
    public function isAvailable(bool $sim = false): bool
    {
        return \function_exists('proc_open')
            && \PHP_OS_FAMILY === 'Linux'
            && \is_executable(static::binary())
            && \is_file(\App\Runtime\ProjectPaths::ROOT . 'bin/libbattlerust.so');
    }

    /**
     * Serialises the fleets to JSON, runs the Rust library and converts its round log back into a BattleResult (same
     * content as the PHP engine's). The surviving units are written back into the given fleets.
     * When the library refuses the fight (more ships than its memory guard, 60 million) or the process fails, the
     * fight is run by the PHP engine instead (which scales huge fleets down), so a battle is never cancelled; the engine
     * name (getName()) then reports 'steemnova'. The failure is logged.
     * @return BattleResult|false false only when the PHP engine fails as well.
     */
    public function fight(array $attackers, array $defenders, int $universe, bool $sim = false, int $simTries = 1): BattleResult|false
    {
        $executionTime = microtime(true);

        $json = $this->engineJson($attackers, $defenders);
        $output = $this->runEngine($json);
        if ($output === null) {
            \error_log('battlerust: the Rust engine failed or refused the fight, using the PHP engine');
            $this->fellBack = true;
            $result = (new BattleEngineSteemNova($this->db, $this->configs, $this->state))->fight($attackers, $defenders, $universe, $sim, $simTries);
            $this->fellBack = $result !== false;

            return $result;
        }
        $this->fellBack = false;

        return $this->parseRustJson($output, $attackers, $defenders, $universe, $sim, $executionTime);
    }

    /** Seconds the Rust process may run before it is killed (the fight then falls back to the PHP engine). */
    protected const ENGINE_TIMEOUT = 60;

    /** battle input JSON → result JSON (null: the engine failed or ran longer than ENGINE_TIMEOUT and was killed) */
    protected function runEngine(string $json): ?string
    {
        // stderr (the library's progress lines) goes to /dev/null, so a full pipe can never block the engine
        $process = \proc_open([static::binary()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }

        \fwrite($pipes[0], $json);
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);

        $output = '';
        $deadline = \microtime(true) + static::ENGINE_TIMEOUT;
        $timedOut = false;
        while (!\feof($pipes[1])) {
            $read = [$pipes[1]];
            $write = $except = null;
            if (\stream_select($read, $write, $except, 0, 200000) > 0) {
                $output .= (string) \fread($pipes[1], 65536);
            }
            if (\microtime(true) > $deadline) {
                $timedOut = true;
                \proc_terminate($process, 9);
                break;
            }
        }
        \fclose($pipes[1]);
        $exitCode = \proc_close($process);

        return !$timedOut && $exitCode === 0 && $output !== '' ? $output : null;
    }

    protected static function binary(): string
    {
        return \App\Runtime\ProjectPaths::ROOT . 'bin/battlerust';
    }

    /**
     * Builds the engine input: six rounds, per side a list of fleets with one entry per unit type (attack with
     * +10%/level weapon tech, hull = (metal+crystal)/10 with armor tech, shield with shield tech, rapid fire table,
     * bigshield/stun flags, explode threshold 0.7 for ships (ids 2xx), none for defenses), and the seed when useSeed() was
     * called. The values are NOT rounded: they are the exact numbers of the PHP engine, which seeded parity depends on.
     * The key order is part of the format and pinned by a test.
     *
     * @param array<int|string, BattleFleet> $attackers attacking fleets in firing order
     * @param array<int|string, BattleFleet> $defenders defending fleets in firing order (the planet is fleet 0)
     * @return array<string, mixed> the document json_encode()d by engineJson()
     */
    public function engineInput(array $attackers, array $defenders): array
    {
        $data = [
            'rounds'   => 6,
            'attacker' => $this->engineFleets($attackers),
            'defender' => $this->engineFleets($defenders),
        ];

        if ($this->seed !== null) {
            $data['seed'] = $this->seed;
        }

        return $data;
    }

    /**
     * The engine input of engineInput() as the JSON string sent to bin/battlerust (default json_encode flags: floats keep
     * their shortest round-trip form, whole floats are written without a fraction).
     *
     * @param array<int|string, BattleFleet> $attackers
     * @param array<int|string, BattleFleet> $defenders
     */
    public function engineJson(array $attackers, array $defenders): string
    {
        return json_encode($this->engineInput($attackers, $defenders));
    }

    /**
     * One side of the engine input: a list of {name, ships[]} with a ship entry per unit type of the fleet, in unit order.
     *
     * @param array<int|string, BattleFleet> $fleets
     * @return list<array{name: int|string, ships: list<array<string, mixed>>}>
     */
    private function engineFleets(array $fleets): array
    {
        $e = $this->elements();

        $list = [];
        foreach ($fleets as $fleetID => $fleet) {
            $techs = $fleet->techs;
            $ships = [];
            foreach ($fleet->units as $unit => $amount) {
                $ships[] = [
                    'shipid'    => (int) $unit,
                    'attack'    => $e->combat($unit)->attack * $techs->attackMultiplier(),
                    'hull'      => ($e->cost($unit)->metal + $e->cost($unit)->crystal) / 10 * $techs->armorMultiplier(),
                    'shield'    => $e->combat($unit)->shield * $techs->shieldMultiplier(),
                    'bigshield' => (bool) $e->combat($unit)->bigShield,
                    'stun'      => $e->combat($unit)->stun,
                    'explode'   => floor((int) $unit / 100) == 2 ? 0.7 : 0, //TODO: move to database
                    'amount'    => (int) $amount,
                    'rapidfire' => $e->combat($unit)->rapidFire ?: new \stdClass(),
                ];
            }
            $list[] = ['name' => $fleetID, 'ships' => $ships];
        }

        return $list;
    }

    /**
     * Applies the engine output: per-fleet losses and kill statistics from the round log, remaining units, debris
     * (fleet_debris_percentage for ships, def_debris_percentage for defenses), defense rebuild (60-80%, or 70% per
     * unit for 10 or fewer losses), wreck field, per-user lost value and transport capacity; writes advanced stats
     * unless $sim. The result has the same content as BattleEngineSteemNova::fight().
     * @param array<int|string, BattleFleet> $attackers the fleets that were sent; their units are reduced to the survivors
     * @param array<int|string, BattleFleet> $defenders likewise; the planet also gets its rebuilt defense back
     * @param bool $sim true for simulator runs: nothing is persisted.
     */
    private function parseRustJson(string $json, array $attackers, array $defenders, int $universe, bool $sim, float $executionTime): BattleResult
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $config = $this->configs->get($universe);

        $decoded = json_decode($json, true);

        $this->resumeRandomStream($decoded);

        $won = $this->outcomeOf($decoded['outcome']);

        $rounds = $decoded['rounds'];

        // Snapshot for the report before mission cases unset() fully destroyed
        // fleets from $attackers/$defenders (keys must match Roundstats fleet IDs)
        $rounds[0]['attackers'] = BattleFleet::listToArrays($attackers);
        $rounds[0]['defenders'] = BattleFleet::listToArrays($defenders);

        $stats = $this->collectKillStats($rounds);

        $debris = [
            'attacker' => [
                901 => 0,
                902 => 0,
            ],
            'defender' => [
                901 => 0,
                902 => 0,
            ],
        ];

        $totalLost = ['attacker' => 0, 'defender' => 0];
        $unitsLost = ['attacker' => [], 'defender' => []];
        $capacityLost = ['attacker' => [], 'defender' => []];
        // every participating user is listed, also without losses (as the PHP engine does)
        foreach (['attacker' => $attackers, 'defender' => $defenders] as $side => $fleets) {
            foreach ($fleets as $fleet) {
                $unitsLost[$side][$fleet->userId()] ??= 0;
                $capacityLost[$side][$fleet->userId()] ??= 0;
            }
        }

        $this->applyAttackerLosses($stats, $attackers, $defenders, $sim, $statsHandler, $config, $debris, $totalLost, $unitsLost, $capacityLost);

        // wreckfield requirements
        $defendingPlayer = [
            'lost' => 0,
        ];
        [$repairedDef, $wreckfield] = $this->applyDefenderLosses($stats, $rounds, $defenders, $sim, $statsHandler, $config, $debris, $totalLost, $unitsLost, $capacityLost, $defendingPlayer);

        if (!$sim && $defenders[0]->userId() != 0) {
            $this->recordFightStats($statsHandler, $attackers, $defenders, $debris, $totalLost, $unitsLost);
        }

        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)
            || $defenders[0]->userId() === 0
            || !isset($defenders[0]->currentTotalFleetState['totalUnits'])
            || $defenders[0]->currentTotalFleetState['totalUnits'] <= 0
            || (!empty($defenders[0]->player['wreckfield_cooldown_until']) && $defenders[0]->player['wreckfield_cooldown_until'] > $this->state->runtime->timestamp)
            || $defendingPlayer['lost'] < $config->wreckfield_min_units
            || $defendingPlayer['lost'] / $defenders[0]->currentTotalFleetState['totalUnits'] < ($config->wreckfield_min_percent / 100)) {
            $wreckfield = [];
        }

        return new BattleResult(
            $won,
            new BattleDebris($debris['attacker'][RESOURCE_METAL], $debris['attacker'][RESOURCE_CRYSTAL], $debris['defender'][RESOURCE_METAL], $debris['defender'][RESOURCE_CRYSTAL]),
            $rounds,
            new BattleLosses($totalLost['attacker'], $totalLost['defender'], $unitsLost, $capacityLost),
            $repairedDef,
            $wreckfield,
            microtime(true) - $executionTime,
        );
    }

    /**
     * Seeded fights: continues the random stream where the PHP engine would stand after this fight (see useSeed()), so
     * the defense rebuild that follows rolls the same numbers.
     *
     * @param array<string, mixed> $decoded decoded engine output (rngDraws)
     */
    private function resumeRandomStream(array $decoded): void
    {
        if ($this->seed !== null) {
            // continue the random stream where the PHP engine would stand after this fight (see useSeed())
            mt_srand($this->seed);
            for ($draw = 0, $draws = (int) ($decoded['rngDraws'] ?? 0); $draw < $draws; $draw++) {
                mt_rand();
            }
        }
    }

    /**
     * Maps the engine's outcome (1 attacker, -1 defender, 0 draw) to the game's result constant.
     */
    private function outcomeOf(int $outcome): ?string
    {
        switch ($outcome) {
            case 1:
                return ATTACKER;
            case -1:
                return DEFENDER;
            case 0:
                return DRAW;
        }

        return null;
    }

    /**
     * Sums the per-round statistics of the engine output per side and fleet: the ships `lost` per element and the ships
     * `destroyed` (shot down or exploded) per element. Order of insertion per round: attacker kills (destroyed, exploded),
     * then defender kills.
     *
     * @param array<int, array<string, mixed>> $rounds decoded round log
     * @return array{attacker: array<int|string, array<string, array<int|string, int|float>>>, defender: array<int|string, array<string, array<int|string, int|float>>>}
     *         side => fleet id => `lost`/`destroyed` => element => amount
     */
    private function collectKillStats(array $rounds): array
    {
        $stats = [
            'attacker' => [],
            'defender' => [],
        ];

        foreach ($rounds as $round) {
            foreach (['attacker', 'defender'] as $side) {
                foreach ($round[$side]['Roundstats'] as $fleetID => $fleet) {
                    foreach ($fleet as $element => $ship) {
                        if ($ship['lost'] > 0) {
                            $stats[$side][$fleetID]['lost'][$element] = ($stats[$side][$fleetID]['lost'][$element] ?? 0) + $ship['lost'];
                        }
                    }
                }
            }

            /**
             * "ship_destroyed" => array:1 [
             * 1 => array:1 [ from
             * 2 => array:1 [ to
             * 207 => array:1 [ from element
             * 206 => 1.0 -> to element amount
             * ]
             * ]
             * ]
             * ]
             */
            foreach (['attacker', 'defender'] as $side) {
                $this->addDestroyed($stats, $side, $round[$side]['Statistics']['ship_destroyed']['maps']);
                $this->addDestroyed($stats, $side, $round[$side]['Statistics']['explosion_triggered']['maps']);
            }
        }

        return $stats;
    }

    /**
     * Adds a kill map (from fleet => to fleet => from element => to element => amount) of one side to the `destroyed`
     * sums of that side, keyed by the killing fleet and the element that was destroyed.
     *
     * @param array<string, array> $stats from collectKillStats(), changed in place
     * @param array<int|string, array> $maps
     * @param array<mixed> $maps
     * @param array<mixed> $stats
     */
    private function addDestroyed(array &$stats, string $side, array $maps): void
    {
        foreach ($maps as $fromFleetID => $fromFleet) {
            foreach ($fromFleet as $toFleet) {
                foreach ($toFleet as $toElements) {
                    foreach ($toElements as $toElement => $amount) {
                        if ($amount > 0) {
                            $stats[$side][$fromFleetID]['destroyed'][$toElement] = ($stats[$side][$fromFleetID]['destroyed'][$toElement] ?? 0) + $amount;
                        }
                    }
                }
            }
        }
    }

    /**
     * Applies the attackers' losses: reduces the fleet units, adds their debris (fleet_debris_percentage), the lost value
     * and capacity per user and writes the lost/destroyed advanced stats (unless a simulation; against the AI (user 0) as
     * expedition losses). Kills are only counted for fights against real players.
     *
     * @param array<string, array> $stats from collectKillStats()
     * @param array<int|string, BattleFleet> $attackers units are reduced in place (objects)
     * @param array<int|string, BattleFleet> $defenders only read (side of the AI)
     * @param array<string, array<int, int|float>> $debris out: debris of both sides by resource
     * @param array<string, int|float> $totalLost out: lost value per side
     * @param array<string, array<int|string, int|float>> $unitsLost out: lost value per user and side
     * @param array<string, array<int|string, int|float>> $capacityLost out: lost capacity per user and side
     * @param array<mixed> $stats
     */
    private function applyAttackerLosses(array $stats, array $attackers, array $defenders, bool $sim, AdvancedStatsHandler $statsHandler, object $config, array &$debris, array &$totalLost, array &$unitsLost, array &$capacityLost): void
    {
        $e = $this->elements();

        // PlayerID for AI is 0

        foreach ($stats['attacker'] as $fleetID => $attacker) {
            foreach ($attacker['lost'] ?? [] as $element => $amount) {
                if ($element > 0 && ($e->isFleet($element) || $e->isDefense($element)) && $amount > 0) {
                    if ($defenders[0]->userId() === 0) { // PlayerID for AI is 0
                        // Expo
                        if (!$sim) {
                            $statsHandler->updateExpoLostAdvancedStats($attackers[$fleetID]->userId(), [$element => $amount]);
                        }
                    } else {
                        // Attack
                        if (!$sim) {
                            $statsHandler->updateLostAdvancedStats($attackers[$fleetID]->userId(), [$element => $amount]);
                        }
                    }

                    $attackers[$fleetID]->units[$element] -= $amount;

                    $debris['attacker'][RESOURCE_METAL] += $e->cost($element)->metal * $amount * ($config->fleet_debris_percentage / 100);
                    $debris['attacker'][RESOURCE_CRYSTAL] += $e->cost($element)->crystal * $amount * ($config->fleet_debris_percentage / 100);

                    $totalLost['attacker'] += $amount * ($e->cost($element)->metal + $e->cost($element)->crystal);
                    $unitsLost['attacker'][$attackers[$fleetID]->userId()] ??= 0;
                    $unitsLost['attacker'][$attackers[$fleetID]->userId()] += $amount * ($e->cost($element)->metal + $e->cost($element)->crystal);
                    $capacityLost['attacker'][$attackers[$fleetID]->userId()] ??= 0;
                    $capacityLost['attacker'][$attackers[$fleetID]->userId()] += $amount * $e->capacity($element);
                }
            }

            if (!$sim && $defenders[0]->userId() !== 0) {
                foreach ($attacker['destroyed'] ?? [] as $element => $amount) {
                    if ($element > 0 && ($e->isFleet($element) || $e->isDefense($element)) && $amount > 0) {
                        $statsHandler->updateDestroyedAdvancedStats($attackers[$fleetID]->userId(), 0, $element, $amount);
                    }
                }
            }
        }
    }

    /**
     * Applies the defenders' losses: reduces the units, collects the wreck field candidates (ships lost from the planet
     * with the repair dock), rebuilds destroyed defense (60-80 percent, or 70 percent per unit for 10 or fewer lost),
     * adds the debris (def_debris_percentage for defense of the not rebuilt part), lost value and capacity, and writes
     * the lost/destroyed advanced stats. The rebuild rolls rand() in the order of the PHP engine (units in the order of
     * the fleet, not of the first loss), which seeded fights depend on.
     *
     * @param array<string, array> $stats from collectKillStats(); the order of `lost` is changed
     * @param array<int, array<string, mixed>> $rounds round log (round 0 holds the start state)
     * @param array<int|string, BattleFleet> $defenders units are reduced/rebuilt in place (objects)
     * @param array<string, array<int, int|float>> $debris out
     * @param array<string, int|float> $totalLost out
     * @param array<string, array<int|string, int|float>> $unitsLost out
     * @param array<string, array<int|string, int|float>> $capacityLost out
     * @param array{lost: int|float} $defendingPlayer out: lost value of the planet's ships
     * @param array<mixed> $stats
     * @return array{0: array<int, array{units: int|float, percent: int|float}>, 1: array<int, int|float>} repaired defense, wreck field candidates
     */
    private function applyDefenderLosses(array $stats, array $rounds, array $defenders, bool $sim, AdvancedStatsHandler $statsHandler, object $config, array &$debris, array &$totalLost, array &$unitsLost, array &$capacityLost, array &$defendingPlayer): array
    {
        $e = $this->elements();
        // restore defense (70% +/- 20%)
        $repairedDef = [];
        // wreckfield ships
        $wreckfield = [];

        // the defense rebuild rolls in the order of the PHP engine: units in the order of the fleet, not of the first loss
        foreach ($stats['defender'] as $fleetID => $defender) {
            if (isset($defender['lost'], $rounds[0]['defenders'][$fleetID]['unit'])) {
                $ordered = [];
                foreach (array_keys($rounds[0]['defenders'][$fleetID]['unit']) as $element) {
                    if (isset($defender['lost'][$element])) {
                        $ordered[$element] = $defender['lost'][$element];
                    }
                }
                $stats['defender'][$fleetID]['lost'] = $ordered + $defender['lost'];
            }
        }

        foreach ($stats['defender'] as $fleetID => $defender) {
            foreach ($defender['lost'] ?? [] as $element => $amount) {
                if ($element > 0 && ($e->isFleet($element) || $e->isDefense($element)) && $amount > 0) {
                    if (!$sim) {
                        $statsHandler->updateLostAdvancedStats($defenders[$fleetID]->userId(), [$element => $amount]);
                    }
                }
                $defenders[$fleetID]->units[$element] -= $amount;

                $giveback = 0;
                if ($fleetID === 0) {
                    if ($element < 300 && $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) { // fleet
                        $wreckfield[$element] = floor($amount * (1 - ($config->fleet_debris_percentage / 100)));
                        $defendingPlayer['lost'] += $amount * ($e->cost($element)->metal + $e->cost($element)->crystal);
                    }

                    if ($element >= 400) { // defence
                        if ($amount > 10) {
                            $giveback = ceil($amount * (rand(60, 80) / 100));
                        } else {
                            for ($i = 0; $i < $amount; $i++) {
                                if (rand(1, 100) <= 70) {
                                    $giveback += 1;
                                }
                            }
                        }
                        $defenders[$fleetID]->units[$element] += $giveback;
                        $repairedDef[$element]['units'] = $giveback;
                        $repairedDef[$element]['percent'] = $giveback / $amount * 100;
                        if (!$sim) {
                            $statsHandler->increment($defenders[0]->userId(), AdvancedStatsEnum::getRepaired($element), $giveback);
                        }
                    }
                }

                $debris['defender'][RESOURCE_METAL] += $e->cost($element)->metal * ($amount - $giveback) * (($element < 400 ? $config->fleet_debris_percentage : $config->def_debris_percentage) / 100);
                $debris['defender'][RESOURCE_CRYSTAL] += $e->cost($element)->crystal * ($amount - $giveback) * (($element < 400 ? $config->fleet_debris_percentage : $config->def_debris_percentage) / 100);

                $totalLost['defender'] += $amount * ($e->cost($element)->metal + $e->cost($element)->crystal);
                if ($element < 300) {
                    $unitsLost['defender'][$defenders[$fleetID]->userId()] ??= 0;
                    $unitsLost['defender'][$defenders[$fleetID]->userId()] += $amount * ($e->cost($element)->metal + $e->cost($element)->crystal);
                    $capacityLost['defender'][$defenders[$fleetID]->userId()] ??= 0;
                    $capacityLost['defender'][$defenders[$fleetID]->userId()] += $amount * $e->capacity($element);
                }
            }

            if (!$sim) {
                foreach ($defender['destroyed'] ?? [] as $element => $amount) {
                    if ($element > 0 && ($e->isFleet($element) || $e->isDefense($element)) && $amount > 0) {
                        $statsHandler->updateDestroyedAdvancedStats($defenders[$fleetID]->userId(), 0, $element, $amount);
                    }
                }
            }
        }

        return [$repairedDef, $wreckfield];
    }

    /**
     * Writes the fight to the advanced stats of every involved player (once per user, not per fleet): the debris of both
     * sides, the destroyed value of the other side and the own lost value. Only for fights against real players.
     *
     * @param array<int|string, BattleFleet> $attackers
     * @param array<int|string, BattleFleet> $defenders
     * @param array<string, array<int, int|float>> $debris debris of both sides by resource
     * @param array<string, int|float> $totalLost lost value per side
     * @param array<string, array<int|string, int|float>> $unitsLost lost value per user and side
     */
    private function recordFightStats(AdvancedStatsHandler $statsHandler, array $attackers, array $defenders, array $debris, array $totalLost, array $unitsLost): void
    {
        $statsDone = [];
        foreach ($attackers as $fleetID => $attacker) {
            if (isset($statsDone[$attacker->userId()])) {
                continue; // once per user, not per fleet
            }
            $statsDone[$attacker->userId()] = true;
            $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::KBMETAL, $debris['attacker'][RESOURCE_METAL] + $debris['defender'][RESOURCE_METAL]);
            $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::KBCRYSTAL, $debris['attacker'][RESOURCE_CRYSTAL] + $debris['defender'][RESOURCE_CRYSTAL]);
            $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::DESUNITS, $totalLost['defender']);
            $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['attacker'][$attacker->userId()] ?? 0));
        }
        $statsDone = [];
        foreach ($defenders as $fleetID => $defender) {
            if (isset($statsDone[$defender->userId()])) {
                continue; // once per user, not per fleet
            }
            $statsDone[$defender->userId()] = true;
            $statsHandler->increment($defender->userId(), AdvancedStatsEnum::KBMETAL, $debris['attacker'][RESOURCE_METAL] + $debris['defender'][RESOURCE_METAL]);
            $statsHandler->increment($defender->userId(), AdvancedStatsEnum::KBCRYSTAL, $debris['attacker'][RESOURCE_CRYSTAL] + $debris['defender'][RESOURCE_CRYSTAL]);
            $statsHandler->increment($defender->userId(), AdvancedStatsEnum::DESUNITS, $totalLost['attacker']);
            $statsHandler->increment($defender->userId(), AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['defender'][$defender->userId()] ?? 0));
        }
    }
}
