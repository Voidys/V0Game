<?php

namespace App\Game\BattleEngine;

use App\Game\Enum\PlanetType;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;

/**
 * Entry point for fights: picks the first available engine (Rust, else the PHP SteemNova engine) and holds the
 * combat-adjacent rules that surround a fight: report data assembly, rebuild protection, wreck fields and
 * looting. Used by the attack/ACS/pirate/expedition missions (see AbstractBattleMission) and the simulator.
 */
class BattleHandler
{
    use \App\Game\Core\GameUtilities;

    public BattleEngineInterface $engine;

    public function __construct(
        bool $sim,
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
    ) {
        $this->engine = $this->resolve($sim);
    }

    /**
     * @return array<BattleEngineInterface>
     */
    private function candidates(): array
    {
        return [
            BattleEngineRust::class,
            BattleEngineSteemNova::class,
        ];
    }

    private function resolve(bool $sim = false): BattleEngineInterface
    {
        foreach ($this->candidates() as $class) {
            $engine = new $class($this->db, $this->configs, $this->state);

            if ($engine->isAvailable($sim)) {
                return $engine;
            }
        }

        throw new \RuntimeException('Keine Battle\-Engine verfügbar');
    }

    /**
     * Rebuild protection: when a player loses nearly everything in one fight, that player is shielded from
     * being attacked for a while. Triggers for a user if the lost value (metal+crystal) exceeds
     * noobProtectionPoints*1000 (capped at 5000 points) and is more than 70% of the user's fleet+defense value
     * before the fight, or the lost transport capacity exceeds that threshold and is more than 80% of the capacity
     * before. Skipped while `rebuild_protection_locked_until` (set to one month after a trigger) is still running.
     * Side effects: UPDATE users (rebuild_protection = fight time, rebuild_protection_attackers = JSON of the
     * attacker user ids, rebuild_protection_locked_until = +1 month).
     * No protection when every opponent is linked to the loser (AccountLinks incl. alliance and buddies).
     * @param array<int, array{totalUnits:int|float, totalCapacity:int|float}> $currentTotalFleetState per user id, before the fight.
     * @param BattleResult $combatResult result of BattleEngineInterface::fight().
     * @param int $combatTime fight timestamp.
     */
    public function CheckRebuildProtection(array $currentTotalFleetState, BattleResult $combatResult, int $combatTime, int $noobProtectionPoints)
    {
        $db = $this->db;
        $losses = $combatResult->losses;

        //Limit to a max of 5.000 points, which is 5.000.000 units, so that it still triggers in universes with high noob protection numbers
        $noobProtectionPoints = min($noobProtectionPoints, 5000);

        $attacker = $losses->userIds(BattleSide::Attacker);
        $defender = $losses->userIds(BattleSide::Defender);
        $links = new \App\Game\Core\AccountLinks($db);

        $noobProtection = $noobProtectionPoints * 1000;
        foreach ($currentTotalFleetState as $userID => $state) {
            $sql = "SELECT `rebuild_protection_locked_until` FROM %%USERS%% WHERE `id` = :userID;";
            $until = $db->selectSingle($sql, [':userID' => $userID], 'rebuild_protection_locked_until');
            if ($until > $combatTime) {
                continue;
            }

            $lostUnits = $losses->userValue(BattleSide::Attacker, $userID) + $losses->userValue(BattleSide::Defender, $userID);
            $lostCapacity = $losses->userCapacity(BattleSide::Attacker, $userID) + $losses->userCapacity(BattleSide::Defender, $userID);

            if (($lostUnits > 0 && $lostUnits > $noobProtection && ($lostUnits / $state['totalUnits']) > 0.7)
                || ($lostCapacity > 0 && $lostCapacity > $noobProtection && ($lostCapacity / $state['totalCapacity']) > 0.8)) {
                // no protection when every opponent is linked to the loser (IP, multi group, alliance, buddy): feeding fleets
                $opponents = in_array($userID, $defender) ? $attacker : $defender;
                $unlinked = false;
                foreach ($opponents as $opponent) {
                    if (!$links->linked((int) $userID, (int) $opponent, true)) {
                        $unlinked = true;
                        break;
                    }
                }
                if (!$unlinked) {
                    continue;
                }

                $sql = "UPDATE %%USERS%% SET `rebuild_protection` = :someTime, `rebuild_protection_attackers` = :attackers, `rebuild_protection_locked_until` = :someTimeLater
                WHERE `id` = :userID;";
                $db->update($sql, [
                    ':someTime'      => $combatTime,
                    ':attackers'     => json_encode(array_values($opponents)),
                    ':someTimeLater' => $combatTime + TIME_1_MONTH,
                    ':userID'        => $userID,
                ]);
            }
        }
    }

    /**
     * Turns the engine result into the combat report array that is stored (and rendered later): header data
     * (coordinates, loot, debris, moon chances, repaired defenses, wreck field), players with their techs and
     * home coordinates, and per-round ship stats. The Rust engine's round log is stored as it is; for the PHP engine
     * it is reshaped to [amount, att, shield, def] per ship.
     * For REAL_FIGHT it also runs the battle achievements and, with the honor module, adds `honorpoints` (single
     * or multi variant depending on the number of distinct users per side); for EXPO_FIGHT only the expedition
     * achievements. Side effects: achievement/honor writes via the injected services.
     * @param int $battleType REAL_FIGHT or EXPO_FIGHT (or a simulator constant, no side effects then).
     * @param BattleResult $combatResult result of the fight.
     * @param array<string, mixed> $reportInfo header data (fleet row, debris, loot, moon chances, ...).
     * @param array<int|string, BattleFleet> $attackers attacking fleets that still exist after the fight (destroyed ones are already removed; the `players` entries come from the start state of the result, so wiped-out fleets keep theirs).
     * @param array<int|string, BattleFleet> $defenders likewise for the defending side.
     * @param string|null $battleEngineName engine name stored with the result ('rust' selects the raw round format).
     * @return array<string, mixed> report data
     */
    public function GenerateReport(BattleResult $combatResult, array $reportInfo, int $battleType, array $attackers, array $defenders, ?string $battleEngineName = null): array
    {
        $DATA = $this->reportHeader($combatResult, $reportInfo);

        $players = $this->reportPlayers([$combatResult->startAttackers() + $attackers, $combatResult->startDefenders() + $defenders]);
        if ($players !== []) {
            $DATA['players'] = $players;
        }

        if ($battleEngineName !== 'rust') {
            $rounds = $this->reportRounds($combatResult);
            if ($rounds !== []) {
                $DATA['rounds'] = $rounds;
            }
        } else {
            $rounds = $combatResult->rounds;
            if (isset($rounds[0])) {
                $rounds[0] = self::scrubStartState($rounds[0]);
            }
            $DATA['rounds'] = $rounds;
        }
        if ($battleType == REAL_FIGHT) {
            $this->battleAchievements()->checkBattleAchievements($reportInfo, $combatResult, $DATA);

            if ($this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
                $DATA['honorpoints'] = $this->calculateHonorPoints($combatResult);
            }
        }

        if ($battleType == EXPO_FIGHT) {
            $this->battleAchievements()->checkExpoBattleAchievements($reportInfo, $combatResult);
        }

        return $DATA;
    }

    /**
     * Start state (round 0) of a Rust report reduced to what the report pages and the simulator prefill read: per fleet
     * the owner's id, name and combat techs, the start coordinates of the fleet and the units. The stored report is
     * readable by every participant, so the rest of the user row and the fleet row must not end up in it.
     * Idempotent; the migration of old reports keeps its own copy of this whitelist.
     *
     * @param array<string, mixed> $round0 rounds[0] with the fleet lists `attackers` and `defenders`
     * @return array<string, mixed>
     */
    public static function scrubStartState(array $round0): array
    {
        $playerKeys = array_flip(['id', 'username', 'military_tech', 'shield_tech', 'defence_tech']);
        $fleetKeys = array_flip(['start_galaxy', 'start_system', 'start_planet', 'start_type']);
        foreach (['attackers', 'defenders'] as $side) {
            foreach ($round0[$side] ?? [] as $key => $fleet) {
                if (!is_array($fleet)) {
                    continue;
                }
                if (isset($fleet['player']) && is_array($fleet['player'])) {
                    $fleet['player'] = array_intersect_key($fleet['player'], $playerKeys);
                }
                if (isset($fleet['fleetDetail']) && is_array($fleet['fleetDetail'])) {
                    $fleet['fleetDetail'] = array_intersect_key($fleet['fleetDetail'], $fleetKeys);
                }
                $round0[$side][$key] = $fleet;
            }
        }

        return $round0;
    }

    /**
     * Header of the combat report: mode, time, start and target coordinates, lost values, debris, loot, result,
     * duration, moon data (chances and outcomes as integers), repaired defense, wreck field and additional info.
     *
     * @param array<string, mixed> $reportInfo see GenerateReport()
     * @return array<string, mixed>
     */
    private function reportHeader(BattleResult $combatResult, array $reportInfo): array
    {
        $DATA = [];
        $DATA['mode'] = (int) $reportInfo['moonDestroy'];
        $DATA['time'] = $reportInfo['thisFleet']['arrival_time'];
        $DATA['start'] = [$reportInfo['thisFleet']['start_galaxy'], $reportInfo['thisFleet']['start_system'], $reportInfo['thisFleet']['start_planet'], $reportInfo['thisFleet']['start_type']];
        $DATA['koords'] = [$reportInfo['thisFleet']['end_galaxy'], $reportInfo['thisFleet']['end_system'], $reportInfo['thisFleet']['end_planet'], $reportInfo['thisFleet']['end_type']];
        $DATA['units'] = [$combatResult->losses->attackerValue, $combatResult->losses->defenderValue];
        $DATA['debris'] = $reportInfo['debris'];
        $DATA['steal'] = $reportInfo['stealResource'];
        $DATA['result'] = $combatResult->won;
        $DATA['duration'] = $combatResult->duration;
        $DATA['moon'] = [
            'moonName'            => $reportInfo['moonName'],
            'moonChance'          => (int) $reportInfo['moonChance'],
            'additionalChance'    => (int) $reportInfo['additionalChance'],
            'moonDestroyChance'   => (int) $reportInfo['moonDestroyChance'],
            'moonDestroySuccess'  => (int) $reportInfo['moonDestroySuccess'],
            'fleetDestroyChance'  => (int) $reportInfo['fleetDestroyChance'],
            'fleetDestroySuccess' => (int) $reportInfo['fleetDestroySuccess'],
        ];
        $DATA['repaired'] = $combatResult->repaired;
        $DATA['wreckfield'] = $combatResult->wreckfield;
        if (isset($reportInfo['additionalInfo'])) {
            $DATA['additionalInfo'] = $reportInfo['additionalInfo'];
        } else {
            $DATA['additionalInfo'] = "";
        }

        return $DATA;
    }

    /**
     * The players of the report: name, home coordinates and techs (in percent) of every fleet owner still in the fight.
     *
     * @param list<array<int|string, BattleFleet>> $sides attacking and defending fleets
     * @return array<int|string, array<string, mixed>> by user id
     */
    private function reportPlayers(array $sides): array
    {
        $players = [];
        foreach ($sides as $fleets) {
            foreach ($fleets as $fleet) {
                $players[$fleet->userId()] = [
                    'name'   => $fleet->player['username'],
                    'koords' => [
                        $fleet->fleetDetail['start_galaxy'],
                        $fleet->fleetDetail['start_system'],
                        $fleet->fleetDetail['start_planet'],
                        $fleet->fleetDetail['start_type'],
                    ],

                    'tech' => [
                        $fleet->techs->weapons * 100,
                        $fleet->techs->shields * 100,
                        $fleet->techs->armor * 100,
                    ],
                ];
            }
        }

        return $players;
    }

    /**
     * Rounds of the PHP engine reshaped to [amount, att, shield, def] per ship type of each fleet (a destroyed fleet
     * has no ships), plus the round totals (null when the engine gave none).
     *
     * @return array<int, array<string, mixed>> by round
     */
    private function reportRounds(BattleResult $combatResult): array
    {
        $rounds = [];
        foreach ($combatResult->rounds as $Round => $RoundInfo) {
            foreach ($RoundInfo['attackers'] as $FleetID => $player) {
                $rounds[$Round]['attacker'][] = $this->reportRoundFleet($player, $RoundInfo['infoA'][$FleetID] ?? []);
            }

            foreach ($RoundInfo['defenders'] as $FleetID => $player) {
                $rounds[$Round]['defender'][] = $this->reportRoundFleet($player, $RoundInfo['infoD'][$FleetID] ?? []);
            }

            if (isset($RoundInfo['attack'], $RoundInfo['attackShield'], $RoundInfo['defense'], $RoundInfo['defShield'])) {
                $rounds[$Round]['info'] = [$RoundInfo['attack'], $RoundInfo['attackShield'], $RoundInfo['defense'], $RoundInfo['defShield']];
            } else {
                $rounds[$Round]['info'] = [null, null, null, null];
            }
        }

        return $rounds;
    }

    /**
     * One fleet of a round: its user and the ships still alive as [amount, att, shield, def].
     *
     * @param array<string, mixed> $player round entry of the fleet (player, unit)
     * @param array<int|string, array<string, mixed>> $infoByShip per ship type att/shield/def of the round
     * @return array{userID: int|string, ships: array<int|string, list<mixed>>}
     */
    private function reportRoundFleet(array $player, array $infoByShip): array
    {
        $playerData = ['userID' => $player['player']['id'], 'ships' => []];

        if (array_sum($player['unit']) == 0) {
            return $playerData;
        }

        foreach ($player['unit'] as $ShipID => $Amount) {
            if ($Amount <= 0) {
                continue;
            }

            $ShipInfo = $infoByShip[$ShipID];
            $playerData['ships'][$ShipID] = [
                $Amount,
                $ShipInfo['att'],
                $ShipInfo['shield'],
                $ShipInfo['def'],
            ];
        }

        return $playerData;
    }

    /**
     * Honor points of a real fight: the single variant when one user fought on each side, otherwise the multi variant
     * over the first round's fleets.
     *
     * @return mixed what HonorPoints::calculate_honorpoints_single()/_multi() return
     */
    private function calculateHonorPoints(BattleResult $combatResult): mixed
    {
        $startAttackers = $combatResult->startAttackers();
        $startDefenders = $combatResult->startDefenders();
        $attackerIds = array_values(array_unique(array_map(static fn(BattleFleet $fleet): int => (int) $fleet->userId(), $startAttackers)));
        $defenderIds = array_values(array_unique(array_map(static fn(BattleFleet $fleet): int => (int) $fleet->userId(), $startDefenders)));

        if (count($attackerIds) == 1 && count($defenderIds) == 1) {
            return $this->honorPoints()->calculate_honorpoints_single($attackerIds[0], $defenderIds[0], $combatResult->losses->attackerValue, $combatResult->losses->defenderValue);
        }

        return $this->honorPoints()->calculate_honorpoints_multi($combatResult->rounds[0]['attackers'], $combatResult->rounds[0]['defenders'], $combatResult->losses->attackerValue, $combatResult->losses->defenderValue);
    }

    /**
     * Repair dock: stores the destroyed defender ships of a fight in the planet's wreck field (adds to an
     * existing field and refreshes its `created` time, else inserts a new one) and starts the defender's
     * wreckfield cooldown (config wreckfield_cooldown, seconds). No-op without the repair dock module or
     * when the result holds no wreck field (the engine already applied the minimum-loss rules).
     * @param int $planetID planet the field belongs to.
     */
    public function GenerateWreckField($planetID, BattleResult $combatResult, int $defenderPlayerID, int $universe)
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) || empty($combatResult->wreckfield)) {
            return;
        }

        $db = $this->db;

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID FOR UPDATE;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $planetID]);

        if (!empty($wreckfield)) {
            $fleetArray = $this->fleetFunctions()->unserialize($wreckfield['ships']);
            foreach ($combatResult->wreckfield as $elementID => $amount) {
                if ($amount > 0) {
                    if (!isset($fleetArray[$elementID])) {
                        $fleetArray[$elementID] = $amount;
                    } else {
                        $fleetArray[$elementID] += $amount;
                    }
                }
            }
            $sql = "UPDATE %%PLANET_WRECKFIELD%%
            SET `created` = :created, `ships` = :shipArray
            WHERE `planetID` = :planetID;";
            $db->update($sql, [
                ':planetID'  => $planetID,
                ':created'   => $this->state->runtime->timestamp,
                ':shipArray' => $this->fleetFunctions()->serialize($fleetArray),
            ]);
        } else {
            $sql = "INSERT INTO %%PLANET_WRECKFIELD%%
            SET `planetID` = :planetID, `created` = :created, `ships` = :shipArray;";
            $db->insert($sql, [
                ':planetID'  => $planetID,
                ':created'   => $this->state->runtime->timestamp,
                ':shipArray' => $this->fleetFunctions()->serialize($combatResult->wreckfield),
            ]);
        }

        // Update defender's wreckfield cooldown
        $sql = "UPDATE %%USERS%% SET `wreckfield_cooldown_until` = :cooldownUntil WHERE `id` = :playerID;";
        $db->update($sql, [
            ':cooldownUntil' => $this->state->runtime->timestamp + $this->configs->get($universe)->wreckfield_cooldown,
            ':playerID'      => $defenderPlayerID,
        ]);
    }

    /**
     * Loot of a successful raid (see owiki "Beute"). Capacity = cargo capacity of all attacking fleets minus
     * what they already carry (spy drones, id 210, do not count). Order: up to 1/3 of the capacity in metal, then
     * 1/2 of the rest in crystal, the rest in deuterium, then the remaining capacity is refilled with metal and
     * crystal. Per resource at most half the stock may be taken (times the honor factor: reduced for repeatedly
     * attacked defenders by calculate_honorpoints_decrease, doubled = 100% on pirate bases, planet type 4), and
     * with the resource stash module a protected part (1% of the daily production per storage level, max 10%)
     * is always left. Amounts are floored.
     * With $simulate = false the loot is split over the fleets in proportion to their free capacity (floored
     * shares, remainder to the first fleet), added to the flying fleets (`fleets`, `log_fleets.gained_*`) and
     * counted in the raided/stolen advanced stats; the planet itself is NOT debited here.
     * @param array<int|string, BattleFleet> $attackFleets the attacking fleets that survived the fight (as set up by AbstractBattleMission).
     * @param array<mixed> $defenderPlanet planet row (stocks, *_perhour, store levels, planet_type).
     * @param bool $simulate true: only return the amounts (no honor factor, no stash, no writes).
     * @return array<int,float|int> loot by resource id (901 metal, 902 crystal, 903 deuterium)
     */
    public function calculateSteal(array $attackFleets, $defenderPlanet, $universe, $simulate = false)
    {
        // See: http://www.owiki.de/Beute
        $e = $this->elements();
        $firstResource = RESOURCE_METAL;
        $secondResource = RESOURCE_CRYSTAL;
        $thirdResource = RESOURCE_DEUT;

        $stealResource = [
            $firstResource  => 0,
            $secondResource => 0,
            $thirdResource  => 0,
        ];

        [$SortFleets, $capacity] = $this->raidCapacities($attackFleets);

        $AllCapacity = $capacity;
        if ($AllCapacity <= 0) {
            return $stealResource;
        }

        $planetResource = [
            $firstResource  => $defenderPlanet[$e->column($firstResource)],
            $secondResource => $defenderPlanet[$e->column($secondResource)],
            $thirdResource  => $defenderPlanet[$e->column($thirdResource)],
        ];
        $protected = [
            $firstResource  => 0,
            $secondResource => 0,
            $thirdResource  => 0,
        ];

        if ($this->viewHelpers()->isModuleAvailable(MODULE_RESOURCE_STASH) && !$simulate && $defenderPlanet['planet_type'] != PlanetType::PirateBase->value) {
            $protected = $this->protectedStock($defenderPlanet, $universe);
        }

        $factorHonorPoints = 1;
        if ($defenderPlanet['planet_type'] == PlanetType::PirateBase->value) {
            $factorHonorPoints = 2; // Pirate Base raids take 100% resources
        } elseif (!$simulate && isset($defenderPlanet['id_owner'])) {
            $factorHonorPoints = $this->honorPoints()->calculate_honorpoints_decrease($defenderPlanet['id_owner']);
        }

        $stealResource = $this->fillCapacity($capacity, $planetResource, $protected, $factorHonorPoints);

        if ($simulate) {
            return $stealResource;
        }

        $this->distributeLoot($attackFleets, $SortFleets, $AllCapacity, $stealResource, $defenderPlanet);

        return $stealResource;
    }

    /**
     * Free cargo capacity per attacking fleet: capacity of the ships (spy drones, id 210, do not count) minus the cargo
     * already on board, never below 0 (a full fleet whose drones are not counted would otherwise have a negative share).
     *
     * @param array<int|string, BattleFleet> $attackFleets
     * @return array{0: array<int|string, int|float>, 1: int|float} free capacity by fleet id, and the sum
     */
    private function raidCapacities(array $attackFleets): array
    {
        $e = $this->elements();
        $SortFleets = [];
        $capacity = 0;

        foreach ($attackFleets as $FleetID => $Attacker) {
            $SortFleets[$FleetID] = 0;

            foreach ($Attacker->units as $Element => $amount) {
                if ($Element != 210) { //Exclude spy drone from raid capacity
                    $SortFleets[$FleetID] += $e->capacity($Element) * $amount;
                }
            }

            $SortFleets[$FleetID] -= $Attacker->fleetDetail['resource_metal'];
            $SortFleets[$FleetID] -= $Attacker->fleetDetail['resource_crystal'];
            $SortFleets[$FleetID] -= $Attacker->fleetDetail['resource_deuterium'];
            if ($SortFleets[$FleetID] < 0) {//if fleet is already full but spydrones are not factored in,
                $SortFleets[$FleetID] = 0; //capacity can be negative, resulting in negative steel numbers.
            }
            $capacity += $SortFleets[$FleetID];
        }

        return [$SortFleets, $capacity];
    }

    /**
     * Resource stash module: up to 10% (1% per resource store level) of the daily production of each resource is
     * protected from stealing.
     *
     * @param array<string, mixed> $defenderPlanet planet row (*_perhour, store levels)
     * @return array<int, int|float> protected amount by resource id
     */
    private function protectedStock(array $defenderPlanet, int|string $universe): array
    {
        $e = $this->elements();
        $firstResource = RESOURCE_METAL;
        $secondResource = RESOURCE_CRYSTAL;
        $thirdResource = RESOURCE_DEUT;
        $config = $this->configs->get($universe);
        $dailyProduction = [
            $firstResource  => ($defenderPlanet['metal_perhour'] + $config->metal_basic_income * ($config->resource_multiplier / 2500)) / 3600 * TIME_24_HOURS,
            $secondResource => ($defenderPlanet['crystal_perhour'] + $config->crystal_basic_income * ($config->resource_multiplier / 2500)) / 3600 * TIME_24_HOURS,
            $thirdResource  => ($defenderPlanet['deuterium_perhour'] + $config->deuterium_basic_income * ($config->resource_multiplier / 2500)) / 3600 * TIME_24_HOURS,
        ];

        return [
            $firstResource  => $dailyProduction[$firstResource] * min(0.1, 0.01 * $defenderPlanet[$e->column(METAL_STORE)]),
            $secondResource => $dailyProduction[$secondResource] * min(0.1, 0.01 * $defenderPlanet[$e->column(CRYSTAL_STORE)]),
            $thirdResource  => $dailyProduction[$thirdResource] * min(0.1, 0.01 * $defenderPlanet[$e->column(DEUTERIUM_STORE)]),
        ];
    }

    /**
     * The loot by the owiki rules, in five steps: 1/3 of the capacity in metal, 1/2 of the rest in crystal, the rest in
     * deuterium, then the remaining capacity refills metal (half) and crystal (rest). Per resource at most half the
     * stock (times the honor factor) minus the protected part. Amounts are floored.
     *
     * @param int|float $capacity total free capacity
     * @param array<int, int|float|string> $planetResource stock by resource id
     * @param array<int, int|float> $protected protected amount by resource id
     * @param int|float $factorHonorPoints 1 normally, less for repeatedly raided defenders, 2 for pirate bases
     * @return array<int, int|float> loot by resource id
     */
    private function fillCapacity(int|float $capacity, array $planetResource, array $protected, int|float $factorHonorPoints): array
    {
        $firstResource = RESOURCE_METAL;
        $secondResource = RESOURCE_CRYSTAL;
        $thirdResource = RESOURCE_DEUT;
        $stealResource = [
            $firstResource  => 0,
            $secondResource => 0,
            $thirdResource  => 0,
        ];

        // Step 1
        $stealResource[$firstResource] = min(
            $capacity / 3, //one third of capacity
            $planetResource[$firstResource] / 2 * $factorHonorPoints, //half of metal
            max(0, $planetResource[$firstResource] - $protected[$firstResource]), //leave protected amount
        );
        $capacity -= $stealResource[$firstResource];

        // Step 2
        $stealResource[$secondResource] = min(
            $capacity / 2, //half of rest capacity (= one third or more)
            $planetResource[$secondResource] / 2 * $factorHonorPoints, //half of crystal
            max(0, $planetResource[$secondResource] - $protected[$secondResource]), //leave protected amount
        );
        $capacity -= $stealResource[$secondResource];

        // Step 3
        $stealResource[$thirdResource] = min(
            $capacity, //rest capacity (= one third or more)
            $planetResource[$thirdResource] / 2 * $factorHonorPoints, //half of deut
            max(0, $planetResource[$thirdResource] - $protected[$thirdResource]), //leave protected amount
        );
        $capacity -= $stealResource[$thirdResource];

        // Step 4
        $oldMetalBooty = $stealResource[$firstResource];
        $stealResource[$firstResource] += min(
            $capacity / 2, //half of rest capacity
            $planetResource[$firstResource] / 2 * $factorHonorPoints - $stealResource[$firstResource], //half of metal minus already stolen
            max(0, $planetResource[$firstResource] - $stealResource[$firstResource] - $protected[$firstResource]), //leave protected amount
        );
        $capacity -= $stealResource[$firstResource] - $oldMetalBooty;

        // Step 5
        $stealResource[$secondResource] += min(
            $capacity, //other half of rest capacity (or more)
            $planetResource[$secondResource] / 2 * $factorHonorPoints - $stealResource[$secondResource], //half of crystal minus already stolen
            max(0, $planetResource[$secondResource] - $stealResource[$secondResource] - $protected[$secondResource]), //leave protected amount
        );

        $stealResource[$firstResource] = floor(max(0, $stealResource[$firstResource]));
        $stealResource[$secondResource] = floor(max(0, $stealResource[$secondResource]));
        $stealResource[$thirdResource] = floor(max(0, $stealResource[$thirdResource]));

        return $stealResource;
    }

    /**
     * Splits the loot over the fleets in proportion to their free capacity (shares floored, the remainder goes to the
     * first fleet, so the fleets together get exactly what the planet loses), adds it to the flying fleets and their
     * log (gained_*) and counts the raided/stolen advanced stats. The planet itself is NOT debited here.
     *
     * @param array<int|string, BattleFleet> $attackFleets
     * @param array<int|string, int|float> $SortFleets free capacity by fleet id
     * @param int|float $AllCapacity sum of the free capacities
     * @param array<int, int|float> $stealResource loot by resource id
     * @param array<string, mixed> $defenderPlanet planet row (planet_type, id_owner)
     */
    private function distributeLoot(array $attackFleets, array $SortFleets, int|float $AllCapacity, array $stealResource, array $defenderPlanet): void
    {
        $firstResource = RESOURCE_METAL;
        $secondResource = RESOURCE_CRYSTAL;
        $thirdResource = RESOURCE_DEUT;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $shares = [];
        $remainder = [
            $firstResource  => $stealResource[$firstResource],
            $secondResource => $stealResource[$secondResource],
            $thirdResource  => $stealResource[$thirdResource],
        ];
        foreach ($SortFleets as $FleetID => $Capacity) {
            foreach ($stealResource as $resourceID => $stolen) {
                $shares[$FleetID][$resourceID] = floor($stolen * $Capacity / $AllCapacity);
                $remainder[$resourceID] -= $shares[$FleetID][$resourceID];
            }
        }
        // the rounding remainder goes to the first fleets that still have room (the first fleet alone could be
        // pushed over its own capacity); the group total never exceeds the group capacity
        foreach ($remainder as $resourceID => $rest) {
            foreach ($SortFleets as $FleetID => $Capacity) {
                if ($rest <= 0) {
                    break;
                }
                $give = min($rest, max(0, $Capacity - array_sum($shares[$FleetID])));
                $shares[$FleetID][$resourceID] += $give;
                $rest -= $give;
            }
        }

        foreach ($SortFleets as $FleetID => $Capacity) {
            $share = $shares[$FleetID];
            $statsHandler->increment($attackFleets[$FleetID]->userId(), AdvancedStatsEnum::RAIDED_METAL, $share[$firstResource]);
            $statsHandler->increment($attackFleets[$FleetID]->userId(), AdvancedStatsEnum::RAIDED_CRYSTAL, $share[$secondResource]);
            $statsHandler->increment($attackFleets[$FleetID]->userId(), AdvancedStatsEnum::RAIDED_DEUTERIUM, $share[$thirdResource]);

            if ($defenderPlanet['planet_type'] != PlanetType::PirateBase->value) {
                $statsHandler->increment($defenderPlanet['id_owner'], AdvancedStatsEnum::STOLEN_METAL, $share[$firstResource]);
                $statsHandler->increment($defenderPlanet['id_owner'], AdvancedStatsEnum::STOLEN_CRYSTAL, $share[$secondResource]);
                $statsHandler->increment($defenderPlanet['id_owner'], AdvancedStatsEnum::STOLEN_DEUTERIUM, $share[$thirdResource]);
            }

            $db->updateRows('FLEETS', [], ['id' => $FleetID], [], [
                'resource_metal' => $share[$firstResource],
                'resource_crystal' => $share[$secondResource],
                'resource_deuterium' => $share[$thirdResource],
            ]);
            $db->updateRows('LOG_FLEETS', [], ['id' => $FleetID], [], [
                'gained_metal' => $share[$firstResource],
                'gained_crystal' => $share[$secondResource],
                'gained_deuterium' => $share[$thirdResource],
            ]);
        }
    }

    /**
     * Smallest total cargo capacity with which calculateSteal() would take the maximum possible (half of each
     * stock). Binary search over capacity using canStealHalf(); used by the fleet UI to suggest recyclers/cargo.
     * @param array<mixed> $defenderPlanet
     * @return int capacity in cargo units
     */
    public function calculateMinCapacity(array $defenderPlanet): int
    {
        $e = $this->elements();

        $targetResource = [
            RESOURCE_METAL   => $defenderPlanet[$e->column(RESOURCE_METAL)]   / 2,
            RESOURCE_CRYSTAL => $defenderPlanet[$e->column(RESOURCE_CRYSTAL)] / 2,
            RESOURCE_DEUT    => $defenderPlanet[$e->column(RESOURCE_DEUT)]    / 2,
        ];

        $shipCapacity = (int) $e->capacity(SHIP_SMALL_CARGO);

        $low  = 0;
        $high = max(
            $shipCapacity,
            (int) ceil(array_sum($targetResource) / $shipCapacity) * $shipCapacity,
        );

        while (!$this->canStealHalf($high, $targetResource)) {
            $high *= 2;
        }

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->canStealHalf($mid, $targetResource)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $high;
    }

    /**
     * Dry run of the calculateSteal() distribution order for a given capacity: true if it reaches half of every resource.
     *
     * @param array<mixed> $targetResource
     */
    private function canStealHalf(int $capacity, array $targetResource): bool
    {
        $stolenMetal = min($capacity / 3, $targetResource[RESOURCE_METAL]);
        $remaining   = $capacity - $stolenMetal;

        $stolenCrystal = min($remaining / 2, $targetResource[RESOURCE_CRYSTAL]);
        $remaining    -= $stolenCrystal;

        $stolenDeut = min($remaining, $targetResource[RESOURCE_DEUT]);
        $remaining -= $stolenDeut;

        $extraMetal   = min($remaining / 2, max(0, $targetResource[RESOURCE_METAL] - $stolenMetal));
        $stolenMetal += $extraMetal;
        $remaining   -= $extraMetal;

        $stolenCrystal += min($remaining, max(0, $targetResource[RESOURCE_CRYSTAL] - $stolenCrystal));

        return $stolenMetal   >= $targetResource[RESOURCE_METAL]
            && $stolenCrystal >= $targetResource[RESOURCE_CRYSTAL]
            && $stolenDeut    >= $targetResource[RESOURCE_DEUT];
    }
}
