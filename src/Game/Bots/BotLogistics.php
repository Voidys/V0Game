<?php

namespace App\Game\Bots;

use App\Game\Enum\Mission;
use App\Game\Bots\Raid\RaidDispatcher;
use App\Game\Bots\Raid\RaidPlanner;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Core\StoredData;

/**
 * Fleet movements of a bot that are neither raids nor expeditions: consolidation (cargo ships bring resources to the planet where the
 * next goal is built), fleetsaving (the fleet and most resources leave on a slow transport to an own planet and come back after the
 * bot's next likely online time) and the reaction to incoming attacks (what the overview shows, spy-technology dependent: fleetsave or
 * defense). All flights go through RaidDispatcher, so they follow the player rules.
 *
 * Fleetsave timing: a transport arrives, hands over its cargo and returns by itself, so the fleet is away for twice the flight time. The
 * speed setting (10 = full ... 1 = a tenth) is the fastest one whose round trip covers the time the bot expects to stay offline.
 */
class BotLogistics
{
    use GameUtilities;

    /** Ships that never go on a fleetsave: solar satellites (they cannot fly), colony ships (kept for the colony plan). */
    private const STAY = [SHIP_SOLSAT, SHIP_COLOSHIP];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidDispatcher $dispatcher,
        private readonly RaidPlanner $planner,
        private readonly BotLog $botLog,
    ) {}

    /**
     * Brings resources to the planet of the goal: from the other own planet with the biggest surplus, by transport with as many cargo
     * ships as needed (not more than present), when the flight takes at most three hours, costs less than a tenth of the load in fuel and a
     * slot is free. Resources already on the way to that planet count. Returns the log text, or null when nothing was sent.
     *
     * @param array<string, mixed> $goal goal candidate of BotBrain (`type` build or research, `planet`, `cost`, `label`)
     */
    public function consolidate(BotActions $ctx, array $goal): ?string
    {
        $e = $this->elements();
        $target = $ctx->planets[$goal['planet']] ?? null;
        if (!in_array($goal['type'] ?? 'build', ['build', 'research'], true) || $target === null || count($ctx->planets) < 2 || $ctx->freeFleetSlots() < 1) {
            return null;
        }
        $missing = $this->missing($ctx, $target, $goal['cost']);
        if (array_sum($missing) < 400) {
            return null;
        }
        $best = null;
        foreach ($ctx->planets as $id => $planet) {
            if ($id === (int) $target['id']) {
                continue;
            }
            $load = $this->surplus($planet, $missing);
            $ships = (int) $planet[$e->column(SHIP_LARGE_CARGO)] + (int) $planet[$e->column(SHIP_SMALL_CARGO)];
            if ($ships > 0 && array_sum($load) >= 400 && ($best === null || array_sum($load) > array_sum($best[1]))) {
                $best = [$id, $load];
            }
        }
        if ($best === null) {
            return null;
        }
        [$from, $load] = $best;
        $planet = &$ctx->planets[$from];
        $fleet = $this->cargoFleet($planet, (int) array_sum($load));
        $flight = $this->planner->flight($ctx, $planet, $fleet, (int) $target['galaxy'], (int) $target['system'], (int) $target['planet']);
        $cargoValue = $load[RESOURCE_METAL] + 1.5 * $load[RESOURCE_CRYSTAL] + 2 * $load[RESOURCE_DEUT];
        if ($flight['duration'] > 10800 || 2 * $flight['consumption'] > 0.1 * $cargoValue || array_sum($load) > $this->fleetFunctions()->getFleetRoom($fleet) - $flight['consumption']) {
            return null;
        }
        $sent = $this->dispatcher->send($ctx, $planet, $fleet, Mission::Transport->value, (int) $target['id'], (int) $ctx->user['id'], $load);
        if (!$sent['ok']) {
            return null;
        }
        $this->botLog->log((int) $ctx->user['id'], $ctx->universe, 'transport', round($load[RESOURCE_METAL]) . ' metal, ' . round($load[RESOURCE_CRYSTAL]) . ' crystal, ' . round($load[RESOURCE_DEUT]) . ' deuterium from ' . $ctx->planetLabel($planet) . ' to ' . $ctx->planetLabel($target) . ' for ' . $goal['label'] . ' (arrives ' . date('H:i', $sent['arrival']) . ')');

        return 'transport';
    }

    /**
     * What the goal planet still lacks of a cost, minus what own transports on the way will bring.
     *
     * @param array<string, mixed> $target goal planet row
     * @param array<int, int|float> $cost resource id => amount
     * @return array<int, float>
     */
    private function missing(BotActions $ctx, array $target, array $cost): array
    {
        $e = $this->elements();
        $incoming = [RESOURCE_METAL => 0.0, RESOURCE_CRYSTAL => 0.0, RESOURCE_DEUT => 0.0];
        $rows = $this->db->select(
            'SELECT resource_metal, resource_crystal, resource_deuterium FROM %%FLEETS%% WHERE owner = :u AND end_id = :p AND mission = :m AND status = :s;',
            [':u' => $ctx->user['id'], ':p' => $target['id'], ':m' => Mission::Transport->value, ':s' => FLEET_OUTWARD],
        );
        foreach ($rows as $row) {
            $incoming[RESOURCE_METAL] += (float) $row['resource_metal'];
            $incoming[RESOURCE_CRYSTAL] += (float) $row['resource_crystal'];
            $incoming[RESOURCE_DEUT] += (float) $row['resource_deuterium'];
        }
        $out = [];
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $id) {
            $out[$id] = max(0.0, (float) ($cost[$id] ?? 0) - floor((float) $target[$e->column($id)]) - $incoming[$id]);
        }

        return $out;
    }

    /**
     * What a planet can give: for each resource the stock above a reserve of 15 % of the store, at most what is missing.
     *
     * @param array<string, mixed> $planet donor planet row
     * @param array<int, float> $missing wanted amounts
     * @return array<int, float>
     */
    private function surplus(array $planet, array $missing): array
    {
        $e = $this->elements();
        $out = [];
        foreach ($missing as $id => $want) {
            $reserve = 0.15 * (float) ($planet[$e->column($id) . '_max'] ?? 0);
            $out[$id] = floor(max(0.0, min($want, (float) $planet[$e->column($id)] - $reserve)));
        }

        return $out;
    }

    /**
     * Cargo ships for a load: large cargos first, small ones for the rest, never more than the planet has.
     *
     * @param array<string, mixed> $planet planet row
     * @return array<int, int> ship id => count
     */
    private function cargoFleet(array $planet, int $amount): array
    {
        $e = $this->elements();
        $fleet = [];
        foreach ([SHIP_LARGE_CARGO, SHIP_SMALL_CARGO] as $id) {
            $n = min((int) $planet[$e->column($id)], (int) ceil($amount * 1.05 / max(1, (int) $e->capacity($id))));
            if ($n > 0) {
                $fleet[$id] = $n;
                $amount -= $n * (int) $e->capacity($id);
            }
            if ($amount <= 0) {
                break;
            }
        }

        return $fleet;
    }

    /**
     * Fleetsave of the planets in `$only` (all planets with ships when null): per planet one slow transport to the nearest other own planet
     * carrying the ships (not STAY) and 80 % of the resources, back after about `$offline` seconds (a bot with one planet sends the ships
     * on a slow expedition instead, expeditionSave()). Rolled with the profile's `fleetsave_chance`; a session end under the minimum offline time (`bots_fleetsave_min_offline_minutes`) is not worth it. Returns the number of fleets sent.
     *
     * @param array<string, mixed> $s profile settings
     * @param array<int, int>|null $only planet ids
     * @param string $reason text for the log ('session end', 'attack from X')
     */
    public function fleetSave(BotActions $ctx, array $s, int $offline, string $reason, ?array $only = null, bool $force = false): int
    {
        $botId = (int) $ctx->user['id'];
        if (!$force && (mt_rand(1, 100) > (int) $s['fleetsave_chance'] || $offline < $ctx->bot->fleetsaveMinOfflineMinutes * 60)) {
            return 0;
        }
        $sent = $ctx->bot->moons ? $this->stationOnMoons($ctx, $only, $reason) : 0;
        if (!$this->hasFleet($ctx)) {
            return $sent;
        }
        if (count($ctx->planets) < 2) {
            return $sent + $this->expeditionSave($ctx, $offline, $reason);
        }
        foreach ($only ?? array_keys($ctx->planets) as $id) {
            if (isset($ctx->planets[$id]) && $ctx->freeFleetSlots(true) > 0 && $this->saveFrom($ctx, $id, $offline, $reason)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * With the knob `moons`: the ships (not probes, satellites, colony ships) of every planet that has a moon are deployed on that moon
     * (deploy mission, the fleet stays there). An attack on the planet then finds no fleet; the raids start from the moon later
     * (RaidPlanner::origins()). The deploy takes no resources along.
     *
     * @param array<int, int>|null $only planet ids (all with a moon when null)
     * @return int fleets sent
     */
    private function stationOnMoons(BotActions $ctx, ?array $only, string $reason): int
    {
        $e = $this->elements();
        $sent = 0;
        foreach ($only ?? array_keys($ctx->planets) as $id) {
            $moon = isset($ctx->planets[$id]) ? $ctx->moonOf($ctx->planets[$id]) : null;
            if ($moon === null || $ctx->freeFleetSlots(true) < 1) {
                continue;
            }
            $fleet = [];
            foreach ($this->elements()->ids('fleet') as $ship) {
                if (!in_array($ship, [...self::STAY, SHIP_PROBE], true) && (int) $ctx->planets[$id][$e->column($ship)] > 0) {
                    $fleet[$ship] = (int) $ctx->planets[$id][$e->column($ship)];
                }
            }
            if ($fleet === []) {
                continue;
            }
            $result = $this->dispatcher->send($ctx, $ctx->planets[$id], $fleet, Mission::Station->value, (int) $moon['id'], (int) $ctx->user['id']);
            $label = $ctx->planetLabel($ctx->planets[$id]);
            $this->botLog->log((int) $ctx->user['id'], $ctx->universe, $result['ok'] ? 'fleetsave' : 'fleetsave_skip', $result['ok']
                ? array_sum($fleet) . ' ships from ' . $label . ' deployed on its moon (' . $reason . ')'
                : $label . ': moon deploy refused (' . $result['reason'] . ', ' . $reason . ')');
            $sent += $result['ok'] ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Fleetsave of a bot with a single planet: the ships fly a slow expedition to a far, empty position (the only mission a player with
     * one planet has for it), long enough to be away for `$offline` seconds. No resources travel (an expedition carries none). Needs the
     * expedition rules of the game (module, free slot, expedition limit); fewer ships (70 / 50 / 30 / 15 %) when the fuel does not suffice.
     *
     * @return int fleets sent (0 or 1)
     */
    private function expeditionSave(BotActions $ctx, int $offline, string $reason): int
    {
        $botId = (int) $ctx->user['id'];
        if (!$ctx->canSendExpedition()) {
            $this->botLog->log($botId, $ctx->universe, 'fleetsave_skip', 'no second planet and no expedition possible to hide the fleet (' . $reason . ')');

            return 0;
        }
        $e = $this->elements();
        $id = (int) array_key_first($ctx->planets);
        $planet = &$ctx->planets[$id];
        $fleet = [];
        foreach ($e->ids('fleet') as $ship) {
            if (!in_array($ship, self::STAY, true) && (int) $planet[$e->column($ship)] > 0) {
                $fleet[$ship] = (int) $planet[$e->column($ship)];
            }
        }
        foreach ([1.0, 0.7, 0.5, 0.3, 0.15] as $share) {
            $part = $share === 1.0 ? $fleet : array_filter(array_map(static fn(int $n): int => (int) floor($n * $share), $fleet));
            $plan = $part === [] ? null : $this->expeditionPlan($ctx, $planet, $part, $offline);
            if ($plan === null) {
                continue;
            }
            $fleetId = $ctx->dispatch($planet, $part, Mission::Expedition->value, (int) $planet['galaxy'], $plan['system'], (int) $ctx->config->max_planets + 1, $plan['hours'], $plan['speed']);
            if ($fleetId !== null) {
                $this->botLog->log($botId, $ctx->universe, 'fleetsave', array_sum($part) . ' ships from ' . $ctx->planetLabel($planet) . ' on a slow expedition to system ' . $plan['system'] . ' at ' . ($plan['speed'] * 10) . ' % speed, ' . $plan['hours'] . ' h hold, home again in about ' . round($plan['total'] / 60) . ' min (offline about ' . round($offline / 60) . ' min; ' . $reason . ')');

                return 1;
            }
        }
        $this->botLog->log($botId, $ctx->universe, 'fleetsave_skip', $ctx->planetLabel($planet) . ': the expedition for the fleetsave was refused (fuel or rules, ' . $reason . ')');

        return 0;
    }

    /**
     * Where, how slowly and how long an expedition has to fly to keep the fleet away for `$offline` seconds: the longest hold the game
     * offers that fits, in the own system first, then systems 25 and 60 away (the first whose round trip at the fastest fitting speed
     * is long enough; the slowest speed of the farthest one when none is).
     *
     * @param array<string, mixed> $planet start planet
     * @param array<int, int> $fleet ships
     * @return array{system: int, speed: int, hours: int, total: int}|null null when the game offers no expedition for the fleet
     */
    private function expeditionPlan(BotActions $ctx, array $planet, array $fleet, int $offline): ?array
    {
        $position = (int) $ctx->config->max_planets + 1;
        $available = $this->fleetFunctions()->getFleetMissions($ctx->user, ['galaxy' => $planet['galaxy'], 'system' => $planet['system'], 'planet' => $position, 'planettype' => 1, 'IsAKS' => 0, 'Ship' => $fleet], ['id' => 0, 'id_owner' => 0]);
        if (!in_array(Mission::Expedition->value, $available['MissionSelector'], true) || empty($available['StayBlock'])) {
            return null;
        }
        $holds = array_keys($available['StayBlock']);
        sort($holds);
        $hours = (int) $holds[0];
        foreach ($holds as $h) {
            if ($available['StayBlock'][$h] * 3600 <= $offline) {
                $hours = (int) $h;
            }
        }
        $stay = (int) round($available['StayBlock'][$hours] * 3600);
        $maxSystem = max(1, (int) $ctx->config->max_system);
        $best = null;
        foreach ([0, 25, -25, 60, -60] as $delta) {
            $system = (int) $planet['system'] + $delta;
            if ($system < 1 || $system > $maxSystem) {
                continue;
            }
            for ($speed = 10; $speed >= 1; $speed--) {
                $flight = $this->planner->flight($ctx, $planet, $fleet, (int) $planet['galaxy'], $system, $position, $speed);
                $best = ['system' => $system, 'speed' => $speed, 'hours' => $hours, 'total' => 2 * $flight['duration'] + $stay];
                if ($best['total'] >= $offline) {
                    return $best;
                }
            }
        }

        return $best;
    }

    /** Whether any planet has ships that could be saved. */
    private function hasFleet(BotActions $ctx): bool
    {
        $e = $this->elements();
        foreach ($ctx->planets as $planet) {
            foreach ($this->elements()->ids('fleet') as $id) {
                if (!in_array($id, self::STAY, true) && (int) $planet[$e->column($id)] > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One fleetsave flight from a planet.
     *
     * @return bool whether a fleet left
     */
    private function saveFrom(BotActions $ctx, int $from, int $offline, string $reason): bool
    {
        $e = $this->elements();
        $planet = &$ctx->planets[$from];
        $fleet = [];
        foreach ($this->elements()->ids('fleet') as $id) {
            if (!in_array($id, self::STAY, true) && (int) $planet[$e->column($id)] > 0) {
                $fleet[$id] = (int) $planet[$e->column($id)];
            }
        }
        if ($fleet === []) {
            return false;
        }
        $to = $this->nearest($ctx, $planet);
        if ($to === null) {
            return false;
        }
        $target = $ctx->planets[$to];
        [$fleet, $speed, $flight] = $this->affordable($ctx, $planet, $fleet, $target, $offline);
        $room = $this->fleetFunctions()->getFleetRoom($fleet) - $flight['consumption'];
        $cargo = $this->cargoOf($ctx, $planet, max(0.0, $room), $flight['consumption']);
        $sent = $this->dispatcher->send($ctx, $planet, $fleet, Mission::Transport->value, (int) $target['id'], (int) $ctx->user['id'], $cargo, $speed);
        if (!$sent['ok']) {
            $this->botLog->log((int) $ctx->user['id'], $ctx->universe, 'fleetsave_skip', $ctx->planetLabel($planet) . ': ' . $sent['reason'] . ' (' . $reason . ')');

            return false;
        }
        $this->botLog->log((int) $ctx->user['id'], $ctx->universe, 'fleetsave', array_sum($fleet) . ' ships and ' . round(array_sum($cargo)) . ' resources from ' . $ctx->planetLabel($planet) . ' to ' . $ctx->planetLabel($target) . ' at ' . ($speed * 10) . ' % speed, home again in ' . round(2 * $sent['duration'] / 60) . ' min (offline about ' . round($offline / 60) . ' min; ' . $reason . ')');

        return true;
    }

    /**
     * The nearest other own planet.
     *
     * @param array<string, mixed> $planet start planet
     * @return int|null planet id
     */
    private function nearest(BotActions $ctx, array $planet): ?int
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($ctx->planets as $id => $other) {
            if ($id === (int) $planet['id']) {
                continue;
            }
            $d = $this->fleetFunctions()->getTargetDistance([$planet['galaxy'], $planet['system'], $planet['planet']], [$other['galaxy'], $other['system'], $other['planet']]);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * The fleet that the planet's deuterium can send: all ships first, then 70 %, 50 %, 30 %, 15 % of every ship type when the fuel does not
     * suffice (the rest stays behind). Returns the fleet with its speed setting and flight.
     *
     * @param array<string, mixed> $planet start planet
     * @param array<int, int> $fleet ships
     * @param array<string, mixed> $target destination planet
     * @return array{0: array<int, int>, 1: int, 2: array{distance: int, duration: int, consumption: float}}
     */
    private function affordable(BotActions $ctx, array $planet, array $fleet, array $target, int $offline): array
    {
        $e = $this->elements();
        foreach ([1.0, 0.7, 0.5, 0.3, 0.15] as $share) {
            $part = $share === 1.0 ? $fleet : array_filter(array_map(static fn(int $n): int => (int) floor($n * $share), $fleet));
            if ($part === []) {
                break;
            }
            [$speed, $flight] = $this->speedFor($ctx, $planet, $part, $target, $offline);
            if ((float) $planet[$e->column(RESOURCE_DEUT)] >= $flight['consumption']) {
                return [$part, $speed, $flight];
            }
        }

        return [$fleet, $speed ?? 10, $flight ?? ['distance' => 0, 'duration' => 0, 'consumption' => 0.0]];
    }

    /**
     * The fastest speed setting (10 ... 1) whose round trip (twice the flight) lasts at least `$offline` seconds; 1 when even that is short.
     *
     * @param array<string, mixed> $planet start planet
     * @param array<int, int> $fleet ships
     * @param array<string, mixed> $target destination planet
     * @return array{0: int, 1: array{distance: int, duration: int, consumption: float}}
     */
    public function speedFor(BotActions $ctx, array $planet, array $fleet, array $target, int $offline): array
    {
        $flight = null;
        for ($speed = 10; $speed >= 1; $speed--) {
            $flight = $this->planner->flight($ctx, $planet, $fleet, (int) $target['galaxy'], (int) $target['system'], (int) $target['planet'], $speed);
            if (2 * $flight['duration'] >= $offline) {
                break;
            }
        }

        return [$speed, $flight];
    }

    /**
     * Resources a fleetsave takes along: 80 % of every stock up to the free cargo room (deuterium also keeps the fuel).
     *
     * @param array<string, mixed> $planet start planet
     * @return array<int, float>
     */
    private function cargoOf(BotActions $ctx, array $planet, float $room, float $fuel): array
    {
        $e = $this->elements();
        $cargo = [];
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $id) {
            $have = (float) $planet[$e->column($id)] * (1 - $ctx->bot->fleetsaveKeep()) - ($id === RESOURCE_DEUT ? $fuel : 0);
            $take = floor(max(0.0, min($have, $room)));
            $cargo[$id] = $take;
            $room -= $take;
        }

        return $cargo;
    }

    /**
     * Looks at the hostile fleets heading for the bot's planets within the threat window (three hours by default), as the overview shows
     * them to a player: fleets of others aimed at the bot's own planets on an attack, ACS or destroy mission (an ACS group is one
     * entry), the number of ships with spy technology 2, the types with 4, exact numbers with 8. Only called while the bot is online:
     * like a human it reacts when it looks (the fleetsave at logout covers the time away). Each fleet is judged once (memory
     * `_fs.seen`): fleets whose attack the planet would not survive (fight against what is visible; an unseen fleet counts as dangerous)
     * trigger a fleetsave of that planet, timed to return after the impact; harmless ones get defense units queued on that planet that
     * finish before the impact. Planets that were hit lately get defense afterwards (afterHits()).
     *
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality changed in place (`_fs`)
     * @return int fleets sent or defense batches queued
     */
    public function threats(BotActions $ctx, array $s, array &$personality, int $now): int
    {
        $acted = 0;
        foreach ($this->overviewThreats($ctx, $now) as $row) {
            $seen = $personality['_fs']['seen'] ?? [];
            if (isset($seen[$row['id']]) || !isset($ctx->planets[(int) $row['end_id']])) {
                continue;
            }
            $personality['_fs']['seen'] = array_slice($seen + [(int) $row['id'] => $now], -20, null, true);
            $acted += $this->react($ctx, $s, $row, $now);
        }

        return $acted + $this->afterHits($ctx, $personality, $now);
    }

    /**
     * The hostile movements the overview would list for the bot within the threat window: foreign fleets on an attack, ACS or destroy
     * mission still on their way to one of the bot's planets, one entry per ACS group (ships and ship count of the group added up).
     *
     * @return list<array<string, mixed>> fleet rows, `fleet_array` = ship id => count
     */
    private function overviewThreats(BotActions $ctx, int $now): array
    {
        $botId = (int) $ctx->user['id'];
        $rows = $this->db->select(
            'SELECT * FROM %%FLEETS%% WHERE target_owner = :u AND owner <> :u2 AND mission IN (:a, :b, :c) AND status = :s AND arrival_time > :now AND arrival_time < :soon ORDER BY arrival_time, id;',
            [':u' => $botId, ':u2' => $botId, ':a' => Mission::Attack->value, ':b' => Mission::Acs->value, ':c' => Mission::Destruction->value, ':s' => FLEET_OUTWARD, ':now' => $now, ':soon' => $now + $ctx->bot->threatWindowSeconds()],
        );
        $out = [];
        foreach ($rows as $row) {
            $row['fleet_array'] = $this->fleetFunctions()->unserialize((string) $row['ships']);
            $key = (int) $row['group'] > 0 ? 'g' . $row['group'] : 'f' . $row['id'];
            if (!isset($out[$key])) {
                $out[$key] = $row;

                continue;
            }
            $out[$key]['ship_amount'] = (int) $out[$key]['ship_amount'] + (int) $row['ship_amount'];
            foreach ($row['fleet_array'] as $ship => $count) {
                $out[$key]['fleet_array'][$ship] = (int) ($out[$key]['fleet_array'][$ship] ?? 0) + (int) $count;
            }
        }

        return array_values($out);
    }

    /**
     * The reaction to one incoming fleet. A fleet the bot cannot identify (spy technology below 2) on an attack mission counts as
     * dangerous: the fleet is saved, and defense is queued too as far as the time to the impact allows.
     *
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $row fleet row (see overviewThreats())
     */
    private function react(BotActions $ctx, array $s, array $row, int $now): int
    {
        $botId = (int) $ctx->user['id'];
        $planet = $ctx->planets[(int) $row['end_id']];
        $visible = $this->visibleFleet($ctx, $row);
        $holds = $visible !== null && $this->planner->planetHolds($ctx, $planet, $visible);
        $left = (int) $row['arrival_time'] - $now;
        $size = (int) $ctx->user[$this->elements()->column(SPY_TECH)] >= 2 ? $row['ship_amount'] . ' ships' : 'size unknown';
        $label = 'fleet of #' . $row['owner'] . ' (' . $size . ($visible === null ? ', not identifiable' : '') . ') heading for ' . $ctx->planetLabel($planet) . ', impact ' . date('H:i:s', (int) $row['arrival_time']);
        if ($holds) {
            $detail = $this->defend($ctx, $planet, $left, 0.5);
            $this->botLog->log($botId, $ctx->universe, 'threat', $label . ': the planet should hold' . ($detail === null ? '' : ', ' . $detail));

            return $detail === null ? 0 : 1;
        }
        $this->botLog->log($botId, $ctx->universe, 'threat', $label . ($visible === null ? ': unknown, treated as hostile' : ': likely stronger than the defense'));
        $acted = 0;
        if ($visible === null && ($detail = $this->defend($ctx, $planet, $left, 0.5)) !== null) {
            $this->botLog->log($botId, $ctx->universe, 'threat', $detail);
            $acted++;
        }
        if (mt_rand(1, 100) > (int) $s['fleetsave_chance']) {
            $this->botLog->log($botId, $ctx->universe, 'threat', 'does not react (forgot, or too sure)');

            return $acted;
        }

        return $acted + $this->fleetSave($ctx, $s, $left + 900, 'attack incoming', [(int) $planet['id']], true);
    }

    /**
     * The attacking ships as far as the overview shows them to this bot (null when nothing identifiable: spy technology below 2).
     *
     * @param array<string, mixed> $row fleet row (see overviewThreats())
     * @return array<int, int>|null
     */
    private function visibleFleet(BotActions $ctx, array $row): ?array
    {
        $spy = (int) $ctx->user[$this->elements()->column(SPY_TECH)];
        if ($spy < 2) {
            return null;
        }
        $ships = $row['fleet_array'] ?? $this->fleetFunctions()->unserialize((string) $row['ships']);
        if ($spy >= 8) {
            return $ships;
        }
        if ($spy >= 4) {
            $each = max(1, (int) floor((int) $row['ship_amount'] / max(1, count($ships))));

            return array_map(static fn($n): int => $each, $ships);
        }

        return [SHIP_CRUISER => max(1, (int) $row['ship_amount'])]; // only the number is visible: assume a cruiser fleet
    }

    /**
     * Queues defense on a planet that is going to be attacked by something it can hold off: the best affordable of the cheap defenses, a
     * batch that is finished before the impact (the planet's shipyard queue counted) and at most `$share` of what the planet can pay.
     *
     * @param array<string, mixed> $planet planet row
     * @param int $secondsLeft seconds until the impact (0 or less = no deadline, defense after a hit)
     * @param float $share share of the affordable amount to build
     * @return string|null log text
     */
    private function defend(BotActions $ctx, array $planet, int $secondsLeft, float $share): ?string
    {
        $queued = $this->queueSeconds($ctx, $planet);
        foreach ([MISSILE_LAUNCHER, 402, 403] as $id) {
            $count = max(1, (int) ($this->buildFunctions()->getMaxConstructibleElements($ctx->user, $planet, $id) * $share));
            if ($secondsLeft > 0) {
                $unit = max(1.0, $this->buildFunctions()->getBuildingTime($ctx->user, $planet, $id));
                $count = min($count, (int) floor(($secondsLeft - $queued - 30) / $unit));
                if ($count < 1) {
                    continue;
                }
            }
            $detail = $ctx->units((int) $planet['id'], $id, $count);
            if ($detail !== null) {
                return 'queued ' . $detail;
            }
        }

        return null;
    }

    /**
     * Seconds the planet's shipyard queue still needs.
     *
     * @param array<string, mixed> $planet planet row
     */
    private function queueSeconds(BotActions $ctx, array $planet): int
    {
        $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];
        $sum = 0.0;
        foreach (is_array($queue) ? $queue : [] as $item) {
            $sum += (int) $item[1] * $this->buildFunctions()->getBuildingTime($ctx->user, $planet, (int) $item[0]);
        }

        return (int) max(0, $sum - (float) ($planet['b_hangar'] ?? 0));
    }

    /**
     * Defense after a hit: attacks that landed on the bot's planets within the last day (the battle reports a player reads) that were
     * not answered yet get defense queued on the planet that was hit, at most the share of the affordable amount the defense weight
     * allows (`bots_defense_weight_percent`, half at 100 %). One answer per hit (`_fs.hit` = newest answered arrival time).
     *
     * @param array<string, mixed> $personality changed in place (`_fs.hit`)
     * @return int defense batches queued
     */
    private function afterHits(BotActions $ctx, array &$personality, int $now): int
    {
        $botId = (int) $ctx->user['id'];
        $since = max($now - 86400, (int) ($personality['_fs']['hit'] ?? 0));
        $rows = $this->db->select(
            'SELECT end_id, MAX(arrival_time) AS hit_at FROM %%LOG_FLEETS%% WHERE target_owner = :u AND owner <> :u2 AND mission IN (:a, :b, :c) AND arrival_time > :since AND arrival_time <= :now AND hasCanceled = 0 GROUP BY end_id ORDER BY hit_at;',
            [':u' => $botId, ':u2' => $botId, ':a' => Mission::Attack->value, ':b' => Mission::Acs->value, ':c' => Mission::Destruction->value, ':since' => $since, ':now' => $now],
        );
        $share = max(0.1, min(1.0, 0.5 * $ctx->bot->defenseWeightPercent / 100));
        $acted = 0;
        foreach ($rows as $row) {
            $personality['_fs']['hit'] = max((int) ($personality['_fs']['hit'] ?? 0), (int) $row['hit_at']);
            $planet = $ctx->planets[(int) $row['end_id']] ?? null;
            $detail = $planet === null || $ctx->bot->defenseWeightPercent < 1 ? null : $this->defend($ctx, $planet, 0, $share);
            if ($detail !== null) {
                $this->botLog->log($botId, $ctx->universe, 'threat', 'hit on ' . $ctx->planetLabel($planet) . ' at ' . date('H:i', (int) $row['hit_at']) . ', ' . $detail);
                $acted++;
            }
        }

        return $acted;
    }
}
