<?php

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Decides whether a raid is worth it and with which ships. Input is only what a spy report shows (resources always; fleet,
 * defense and research when the report has those sections). Expected loot comes from the game's own plunder rule
 * (BattleHandler::calculateSteal() in its simulation mode: cargo capacity of the surviving ships, one third metal first, half
 * of each stock at most), the expected losses from fights of the game's battle engine (the one the simulator uses) on virtual
 * fleets, the fuel from FleetFunctions for the planned distance and duration.
 *
 * Fleet choice: enough cargo ships for the loot, plus the smallest share (0, 15, 30, 50, 75, 100 %) of the planet's warships that
 * wins every simulated fight; among the fleets that pass, the one with the best profit (loot minus fuel and losses) wins.
 * An attack is worth it when loot >= the bot knob min_loot (default MIN_LOOT) and loot / (fuel + losses) >= the profile's `min_profit_ratio`.
 */
class RaidPlanner
{
    use GameUtilities;

    /** Value of one unit of metal, crystal, deuterium when loot, fuel and losses are compared. */
    public const VALUE = [RESOURCE_METAL => 1.0, RESOURCE_CRYSTAL => 1.5, RESOURCE_DEUT => 2.0];

    /** Smallest loot (in value units) that is worth the effort at all (default of the bot knob `bots_min_loot`). */
    public const MIN_LOOT = 3000;

    /** Ships that never go raiding: colony ship, recycler, probe, solar satellite. */
    private const NOT_RAIDERS = [SHIP_COLOSHIP, SHIP_RECYCLER, SHIP_PROBE, SHIP_SOLSAT];

    /** @var array<string, array<string, mixed>> simulated outcomes of this process by fleet and report */
    private array $outcomes = [];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * Plans the raid on the target from the best of the bot's planets.
     *
     * @param array<string, mixed> $report parsed spy report (see RaidSpyReports::parse())
     * @param array<string, mixed> $target scouted target (`galaxy`, `system`, `planet`, `activity_minutes`)
     * @param array<string, mixed> $settings profile settings (`min_profit_ratio`)
     * @return array<string, mixed> `ok`, `reason` and, when a fleet was found, `origin` (planet id), `fleet`, `loot`, `loot_res`, `fuel`, `losses`, `cost`, `ratio`, `duration`, `consumption`, `techs_assumed`
     */
    public function plan(BotActions $ctx, array $report, array $target, array $settings): array
    {
        if ($report['fleet'] === null || $report['defense'] === null) {
            return ['ok' => false, 'reason' => 'unknown defense (report has no ' . ($report['fleet'] === null ? 'fleet' : 'defense') . ' section)'];
        }

        $best = null;
        $reason = 'no ships at hand';
        // a revenge raid is allowed a thinner profit: the grudge is worth something to the bot
        $revenge = !empty($target['revenge']);
        $minRatio = (float) $settings['min_profit_ratio'] * ($revenge ? 0.7 : 1.0);
        $minLoot = (int) round($ctx->bot->minLoot * ($revenge ? 0.5 : 1.0));
        foreach ($this->origins($ctx, $target) as $planetId) {
            $plan = $this->planFrom($ctx, $ctx->originRef($planetId), $report, $target, $minRatio, $minLoot);
            if ($plan['ok'] && ($best === null || $plan['loot'] - $plan['cost'] > $best['loot'] - $best['cost'])) {
                $best = $plan;
            } elseif (!$plan['ok'] && $best === null) {
                $reason = $plan['reason'];
            }
        }

        return $best ?? ['ok' => false, 'reason' => $reason];
    }

    /**
     * The raid with helpers (ACS): the leader's best base sends all its warships and the cargo for the loot, allied bots add warships. The
     * fight is simulated for the combined fleet (with the leader's techs: the helpers' own techs are not known to the leader, the
     * helpers are bots of the leader's alliance and coordinate through the group). Fuel is the leader's; the losses are the whole
     * group's. Only wins count.
     *
     * @param array<string, mixed> $report parsed spy report
     * @param array<string, mixed> $target scouted target
     * @param array<int, int> $helperShips warships the helpers bring together (id => count)
     * @return array<string, mixed> see plan(), plus `joint` => true and `helper_ships`
     */
    public function planJoint(BotActions $ctx, array $report, array $target, array $helperShips, float $minRatio): array
    {
        if ($report['fleet'] === null || $report['defense'] === null || $helperShips === []) {
            return ['ok' => false, 'reason' => 'no joint plan'];
        }
        $origins = $this->origins($ctx, $target);
        if ($origins === []) {
            return ['ok' => false, 'reason' => 'no ships at hand'];
        }
        $e = $this->elements();
        $planet = $ctx->originRef($origins[0]);
        $fleet = $this->compose($planet, $this->warships($planet), $this->capacityNeeded($report['resources']), 1.0);
        if ($fleet === []) {
            return ['ok' => false, 'reason' => 'no ships at hand'];
        }
        $flight = $this->flight($ctx, $planet, $fleet, (int) $target['galaxy'], (int) $target['system'], (int) $target['planet']);
        if ((float) $planet[$e->column(RESOURCE_DEUT)] < $flight['consumption']) {
            return ['ok' => false, 'reason' => 'not enough deuterium for the flight'];
        }
        $combined = $fleet;
        foreach ($helperShips as $id => $count) {
            $combined[$id] = ($combined[$id] ?? 0) + $count;
        }
        $outcome = $this->simulate($ctx, $combined, $report, $target['activity_minutes'] ?? null);
        if (!$outcome['win']) {
            return ['ok' => false, 'reason' => 'not winnable even together'];
        }
        $fuel = $flight['consumption'] * self::VALUE[RESOURCE_DEUT];
        $losses = $outcome['losses'] * $ctx->lossMultiplier;
        $cost = max(1.0, $fuel + $losses);
        $ratio = $outcome['loot'] / $cost;
        $ok = $outcome['loot'] >= $ctx->bot->minLoot && $ratio >= $minRatio;

        return [
            'ok' => $ok, 'reason' => $ok ? '' : 'joint raid not worth it (ratio ' . round($ratio, 1) . ')',
            'origin' => (int) $planet['id'], 'fleet' => $fleet, 'loot' => $outcome['loot'], 'loot_res' => $outcome['loot_res'],
            'fuel' => $fuel, 'losses' => $losses, 'losses_sim' => $outcome['losses'], 'cost' => $cost, 'ratio' => $ratio,
            'duration' => $flight['duration'], 'consumption' => $flight['consumption'], 'techs_assumed' => $outcome['techs_assumed'],
            'activity_minutes' => $target['activity_minutes'] ?? null, 'joint' => true, 'helper_ships' => $helperShips,
        ];
    }

    /**
     * Pre-impact check of a fleet already under way: the same fight and plunder simulation against a newer report.
     *
     * @param array<int, int> $fleet ships of the attack fleet (id => count)
     * @param array<string, mixed> $report the new report (fleet and defense sections needed)
     * @param array<string, mixed> $plan the plan the attack was launched with (`fuel`, `activity_minutes`)
     * @return array{win: bool, loot: float, losses: float, ratio: float}|null null when the report does not show fleet and defense
     */
    public function assess(BotActions $ctx, array $fleet, array $report, array $plan): ?array
    {
        if ($report['fleet'] === null || $report['defense'] === null) {
            return null;
        }
        $outcome = $this->simulate($ctx, $fleet, $report, isset($plan['activity_minutes']) ? (int) $plan['activity_minutes'] : null);
        $losses = $outcome['losses'] * $ctx->lossMultiplier;
        $cost = max(1.0, (float) ($plan['fuel'] ?? 0) + $losses);

        return ['win' => $outcome['win'], 'loot' => $outcome['loot'], 'losses' => $losses, 'ratio' => $outcome['loot'] / $cost];
    }

    /**
     * Distance, duration and fuel of a fleet from a planet to the target coordinates.
     *
     * @param array<int, int> $fleet ship id => count
     * @param int $speedSetting 1-10 (10 = full speed, the fleet page's 100 %)
     * @param array<string, mixed> $planet
     * @return array{distance: int, duration: int, consumption: float}
     */
    public function flight(BotActions $ctx, array $planet, array $fleet, int $galaxy, int $system, int $position, int $speedSetting = 10): array
    {
        $ff = $this->fleetFunctions();
        $distance = $ff->getTargetDistance([$planet['galaxy'], $planet['system'], $planet['planet']], [$galaxy, $system, $position]);
        $speed = $ff->getFleetMaxSpeed($fleet, $ctx->user);
        $factor = $ff->getGameSpeedFactor();
        $duration = $ff->getMissionDuration($speedSetting, $speed, $distance, $factor, $ctx->user);

        return [
            'distance' => $distance,
            'duration' => (int) $duration,
            'consumption' => (float) $ff->getFleetConsumption($fleet, $duration, $distance, $ctx->user, $factor),
        ];
    }

    /**
     * Own planets (and, with the knob `moons`, moons) that hold ships, nearest to the target first, at most three: a raid leaves from the
     * base next to the target that has the fleet, the plan with the best profit among them wins.
     *
     * @param array<string, mixed> $target scouted target
     * @return list<int> planet ids
     */
    public function origins(BotActions $ctx, array $target): array
    {
        $e = $this->elements();
        $list = [];
        foreach ($ctx->planets + $ctx->moons as $id => $planet) {
            $ships = (int) $planet[$e->column(SHIP_SMALL_CARGO)] + (int) $planet[$e->column(SHIP_LARGE_CARGO)];
            if ($ships + array_sum($this->warships($planet)) > 0) {
                $list[$id] = $this->fleetFunctions()->getTargetDistance([$planet['galaxy'], $planet['system'], $planet['planet']], [$target['galaxy'], $target['system'], $target['planet']]);
            }
        }
        asort($list);

        return array_slice(array_keys($list), 0, 3);
    }

    /**
     * Warships (ships that are neither cargo nor in NOT_RAIDERS and can attack) on a planet.
     *
     * @param array<string, mixed> $planet
     * @return array<int, int> ship id => count
     */
    public function warships(array $planet): array
    {
        $e = $this->elements();
        $out = [];
        foreach ($this->elements()->ids('fleet') as $id) {
            $count = (int) ($planet[$e->column($id)] ?? 0);
            if ($count > 0 && !in_array($id, self::NOT_RAIDERS, true) && !in_array($id, [SHIP_SMALL_CARGO, SHIP_LARGE_CARGO], true) && $e->combat($id)->attack > 0) {
                $out[$id] = $count;
            }
        }

        return $out;
    }

    /**
     * The best fleet from one planet: tries the escort shares in turn.
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $report
     * @param array<string, mixed> $target
     * @return array<string, mixed> see plan()
     */
    private function planFrom(BotActions $ctx, array $planet, array $report, array $target, float $minRatio, int $minLoot): array
    {
        $e = $this->elements();
        $warships = $this->warships($planet);
        $needed = $this->capacityNeeded($report['resources']);
        $best = null;
        $seen = [];
        $reason = 'no fleet that wins';
        foreach ($ctx->bot->escortShares as $share) {
            $fleet = $this->compose($planet, $warships, $needed, $share);
            $key = json_encode($fleet);
            if ($fleet === [] || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $flight = $this->flight($ctx, $planet, $fleet, (int) $target['galaxy'], (int) $target['system'], (int) $target['planet']);
            if ((float) $planet[$e->column(RESOURCE_DEUT)] < $flight['consumption']) {
                $reason = 'not enough deuterium for the flight';
                continue;
            }
            $outcome = $this->simulate($ctx, $fleet, $report, $target['activity_minutes'] ?? null);
            if (!$outcome['win']) {
                continue;
            }
            $fuel = $flight['consumption'] * self::VALUE[RESOURCE_DEUT];
            $losses = $outcome['losses'] * $ctx->lossMultiplier;
            $cost = max(1.0, $fuel + $losses);
            $ratio = $outcome['loot'] / $cost;
            $candidate = [
                'ok' => $outcome['loot'] >= $minLoot && $ratio >= $minRatio,
                'reason' => $outcome['loot'] < $minLoot ? 'loot too small (' . round($outcome['loot']) . ')' : 'not worth it (ratio ' . round($ratio, 1) . ' < ' . $minRatio . ')',
                'origin' => (int) $planet['id'], 'fleet' => $fleet, 'loot' => $outcome['loot'], 'loot_res' => $outcome['loot_res'],
                'fuel' => $fuel, 'losses' => $losses, 'losses_sim' => $outcome['losses'], 'cost' => $cost, 'ratio' => $ratio,
                'duration' => $flight['duration'], 'consumption' => $flight['consumption'], 'techs_assumed' => $outcome['techs_assumed'],
                'activity_minutes' => $target['activity_minutes'] ?? null,
            ];
            if ($candidate['ok'] && ($best === null || $candidate['loot'] - $candidate['cost'] > $best['loot'] - $best['cost'])) {
                $best = $candidate;
            }
            $reason = $candidate['reason'];
            if ($candidate['ok'] && $losses <= 0.01 * $outcome['loot']) {
                break; // wins without noticeable losses: more escort only adds fuel
            }
        }

        return $best ?? ['ok' => false, 'reason' => $reason];
    }

    /**
     * Cargo capacity needed to carry everything the plunder rule lets a raid take from these stocks.
     *
     * @param array<int, float> $resources metal, crystal, deuterium of the report
     */
    private function capacityNeeded(array $resources): int
    {
        return $this->handler()->calculateMinCapacity($this->named($resources));
    }

    /**
     * Fleet for an escort share: that share of every warship type, plus large then small cargos for the capacity still missing.
     *
     * @param array<int, int> $warships ship id => count on the planet
     * @param array<string, mixed> $planet
     * @return array<int, int> ship id => count, empty when nothing can be sent
     */
    private function compose(array $planet, array $warships, int $needed, float $share): array
    {
        $e = $this->elements();
        $fleet = [];
        $capacity = 0;
        foreach ($warships as $id => $count) {
            $n = $share > 0 ? max(1, (int) floor($count * $share)) : 0;
            if ($n > 0) {
                $fleet[$id] = $n;
                $capacity += $n * (int) $e->capacity($id);
            }
        }
        foreach ([SHIP_LARGE_CARGO, SHIP_SMALL_CARGO] as $id) {
            $have = (int) $planet[$e->column($id)];
            $missing = $needed - $capacity;
            $n = $missing > 0 ? min($have, (int) ceil($missing / max(1, (int) $e->capacity($id)))) : 0;
            if ($n > 0) {
                $fleet[$id] = $n;
                $capacity += $n * (int) $e->capacity($id);
            }
        }

        return $fleet;
    }

    /**
     * Fights of the fleet against the reported planet (simulation_runs times, virtual fleets, nothing is written) and the plunder after them.
     *
     * @param array<int, int> $fleet attacking ships
     * @param int|null $activityMinutes minutes since the target was last active when under 60 (an active target may react: loot is discounted)
     * @param array<string, mixed> $report
     * @return array{win: bool, loot: float, loot_res: array<int, float>, losses: float, techs_assumed: bool}
     */
    private function simulate(BotActions $ctx, array $fleet, array $report, ?int $activityMinutes): array
    {
        // the same fleet against the same units and techs gives the same fights: bounded work when several origins or ticks ask again
        $key = md5(json_encode([$fleet, $report['fleet'], $report['defense'], $report['resources'], $report['techs'], $activityMinutes, $ctx->user['id'], $ctx->bot->simulationRuns]));
        if (isset($this->outcomes[$key])) {
            return $this->outcomes[$key];
        }
        if (count($this->outcomes) > 200) {
            $this->outcomes = [];
        }

        return $this->outcomes[$key] = $this->fight($ctx, $fleet, $report, $activityMinutes);
    }

    /**
     * The fights behind simulate() (not cached).
     *
     * @param array<int, int> $fleet attacking ships
     * @param array<string, mixed> $report
     * @return array{win: bool, loot: float, loot_res: array<int, float>, losses: float, techs_assumed: bool}
     */
    private function fight(BotActions $ctx, array $fleet, array $report, ?int $activityMinutes): array
    {
        $handler = $this->handler();
        $universe = (int) $this->universe()->current();
        $techs = $this->defenderTechs($ctx, $report);
        $runs = $ctx->bot->simulationRuns;
        $wins = 0;
        $losses = 0.0;
        $lootRes = [RESOURCE_METAL => 0.0, RESOURCE_CRYSTAL => 0.0, RESOURCE_DEUT => 0.0];
        for ($run = 0; $run < $runs; $run++) {
            $attack = $this->attackerFleet($ctx, $fleet);
            $defend = $this->defenderFleet($report, $techs);
            $result = $handler->engine->fight([$attack], [$defend], $universe, true);
            if ($result === false) {
                continue;
            }
            $losses += $this->fleetValue($fleet) - $this->fleetValue($attack->units);
            if ($result->won === ATTACKER) {
                $wins++;
                $steal = $handler->calculateSteal([$attack], $this->named($report['resources']) + ['planet_type' => 1], $universe, true);
                foreach ($lootRes as $id => $_) {
                    $lootRes[$id] += (float) $steal[$id];
                }
            }
        }
        $discount = $activityMinutes === null ? 1.0 : ($activityMinutes < 15 ? 0.6 : 0.8);
        $won = max(1, $wins);
        $loot = 0.0;
        foreach ($lootRes as $id => &$amount) {
            $amount = $amount / $won * 0.9 * $discount; // 0.9: stash / honor rules and production changes are not known
            $loot += $amount * self::VALUE[$id];
        }
        unset($amount);

        return [
            'win' => $wins === $runs,
            'loot' => $loot,
            'loot_res' => $lootRes,
            'losses' => $losses / $runs,
            'techs_assumed' => $report['techs'] === null,
        ];
    }

    /**
     * Whether a planet holds against an incoming attack: a fight of the planet's ships and defense against the attacking ships (the
     * attacker's techs are assumed one level above the bot's own, the cautious guess).
     *
     * @param array<string, mixed> $planet planet row of the bot
     * @param array<int, int> $attackers attacking ships as far as the bot could see them
     */
    public function planetHolds(BotActions $ctx, array $planet, array $attackers): bool
    {
        $e = $this->elements();
        $own = [];
        foreach (array_merge($this->elements()->ids('fleet'), $this->elements()->ids('defense')) as $id) {
            if ((int) ($planet[$e->column($id)] ?? 0) > 0 && $id !== SHIP_SOLSAT) {
                $own[$id] = (int) $planet[$e->column($id)];
            }
        }
        $techs = [];
        foreach ([MILITARY_TECH, SHIELD_TECH, DEFENCE_TECH] as $id) {
            $techs[$e->column($id)] = (int) $ctx->user[$e->column($id)];
        }
        $attacker = new BattleFleet(1, ['id' => 1001, 'username' => 'attacker'] + array_map(static fn(int $t): int => $t + 1, $techs), $attackers, $this->fleetDetail());
        $defender = new BattleFleet(0, ['id' => 2001, 'username' => (string) $ctx->user['username']] + $techs, $own, $this->fleetDetail());
        $result = $this->handler()->engine->fight([$attacker], [$defender], (int) $this->universe()->current(), true);

        return $result === false || $result->won !== ATTACKER;
    }

    /**
     * Combat techs of the defender: from the report when it has the research section, otherwise the bot assumes the defender is one
     * level ahead of itself in each (a cautious guess).
     *
     * @param array<string, mixed> $report
     * @return array{military_tech: int, shield_tech: int, defence_tech: int}
     */
    private function defenderTechs(BotActions $ctx, array $report): array
    {
        $e = $this->elements();
        $out = [];
        foreach ([MILITARY_TECH, SHIELD_TECH, DEFENCE_TECH] as $id) {
            $out[$e->column($id)] = $report['techs'] !== null ? (int) ($report['techs'][$id] ?? 0) : (int) $ctx->user[$e->column($id)] + 1;
        }

        return $out;
    }

    /**
     * The attacking fleet as virtual battle fleet with the bot's techs.
     *
     * @param array<int, int> $units ship id => count
     */
    private function attackerFleet(BotActions $ctx, array $units): BattleFleet
    {
        $e = $this->elements();
        $player = ['id' => 1001, 'username' => (string) $ctx->user['username']];
        foreach ([MILITARY_TECH, SHIELD_TECH, DEFENCE_TECH] as $id) {
            $player[$e->column($id)] = (int) $ctx->user[$e->column($id)];
        }

        return new BattleFleet(1, $player, $units, $this->fleetDetail());
    }

    /**
     * The defending planet (fleet 0) with the reported ships and defense.
     *
     * @param array<string, mixed> $techs defender tech levels by column name
     * @param array<string, mixed> $report
     */
    private function defenderFleet(array $report, array $techs): BattleFleet
    {
        $player = ['id' => 2001, 'username' => 'target'] + $techs;

        return new BattleFleet(0, $player, ($report['fleet'] ?? []) + ($report['defense'] ?? []), $this->fleetDetail());
    }

    /**
     * Fleet row of a virtual fleet: no cargo on board (the loot rule counts free capacity).
     *
     * @return array<string, int>
     */
    private function fleetDetail(): array
    {
        return [
            'start_galaxy' => 1, 'start_system' => 1, 'start_planet' => 1, 'start_type' => 1,
            'end_galaxy' => 1, 'end_system' => 1, 'end_planet' => 1, 'end_type' => 1,
            'resource_metal' => 0, 'resource_crystal' => 0, 'resource_deuterium' => 0,
        ];
    }

    /**
     * Value of units (cost in metal, crystal and deuterium weighed with VALUE).
     *
     * @param array<int, int|float> $units unit id => count
     */
    public function fleetValue(array $units): float
    {
        $e = $this->elements();
        $sum = 0.0;
        foreach ($units as $id => $count) {
            foreach (self::VALUE as $res => $weight) {
                $sum += (float) $count * (float) ($e->find($id)?->cost->of($res) ?? 0) * $weight;
            }
        }

        return $sum;
    }

    /**
     * Resource amounts keyed by the planet column names (`metal`, ...), the shape the battle code expects.
     *
     * @param array<int, float> $resources by resource id
     * @return array<string, float>
     */
    private function named(array $resources): array
    {
        $e = $this->elements();

        return [
            $e->column(RESOURCE_METAL) => $resources[RESOURCE_METAL],
            $e->column(RESOURCE_CRYSTAL) => $resources[RESOURCE_CRYSTAL],
            $e->column(RESOURCE_DEUT) => $resources[RESOURCE_DEUT],
        ];
    }

    /** Battle handler in simulation mode (picks Rust or PHP engine like the simulator). */
    private function handler(): BattleHandler
    {
        return new BattleHandler(true, $this->db, $this->configs, $this->state);
    }
}
