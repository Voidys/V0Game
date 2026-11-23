<?php

declare(strict_types=1);

namespace App\Game\Core;

use App\Game\Enum\MessageType;
use App\Game\Enum\PlanetType;

/**
 * Raid planning numbers for a spy report (used by the report view and the farm list): loot, cargo ships needed, the
 * deuterium for the trip from the current planet, net profit, the value of the visible fleet and defense, and the
 * age of the report. The maths lives in SpyReportAnalysis; this class only feeds it with the game data and the
 * player's fleet rules.
 *
 * Loot uses the base share (half of the stocks, no honor tier: the target's honor points are not part of the report; pirate
 * bases pay out the full stock as BattleHandler does). Cargo capacity is the plain ship capacity, because raids do not apply any
 * hyperspace-tech capacity bonus in this game.
 */
class SpyReportTools
{
    use \App\Game\Core\GameUtilities;

    /** @var array<int, float> the honor fuel factor per player, read once per instance (an instance lives for one request or cron run) */
    private array $honorBoost = [];

    /** @var array<string, int> round trip fuel per "ships:distance" */
    private array $fuelCache = [];

    /** @var array<int, array{0: int|float, 1: int|float, 2: int|float}>|null */
    private ?array $unitCostCache = null;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * The latest spy report per target planet among the player's own, not deleted spy report messages (shared by the farm
     * list and the "farm woke up" notification).
     *
     * @param int $limit newest report messages read (the latest per target is picked out of them)
     * @param int $since only reports made at or after this time (0 = all)
     * @param int $onlineSince only targets whose owner was online at or after this time and is not in vacation (0 = all targets)
     * @return list<array<string, mixed>> message_id, message_text, message_time, planet_id, id_owner, username, onlinetime
     */
    public function latestReports(int $userId, int $universe, int $limit, int $since = 0, int $onlineSince = 0): array
    {
        $reports = $this->db->select(
            "SELECT m.message_id, m.message_text, m.message_time, p.id AS planet_id, p.id_owner, u.username, u.onlinetime
            FROM %%MESSAGES%% m
            INNER JOIN %%USERS_TO_MESSAGES%% um ON m.message_id = um.message_id
            INNER JOIN %%PLANETS%% p ON p.id = CAST(SUBSTRING(m.message_group, 3) AS UNSIGNED)
            LEFT JOIN %%USERS%% u ON u.id = p.id_owner
            WHERE m.message_type = :messageType
              AND um.user_id = :userId
              AND um.deleted_at IS NULL
              AND p.universe = :universe
              AND p.id_owner <> :userId
              AND m.message_time >= :since
              AND (:onlineSince = 0 OR (u.onlinetime >= :onlineSince AND u.urlaubs_modus = 0))
            ORDER BY m.message_time DESC
            LIMIT " . max(1, $limit),
            [
                ':messageType' => MessageType::SpyReport->value,
                ':userId' => $userId,
                ':universe' => $universe,
                ':since' => $since,
                ':onlineSince' => $onlineSince,
            ],
        );

        $latest = [];
        foreach ($reports as $report) {
            $latest[$report['planet_id']] ??= $report; // newest first: later rows are older reports of a listed target
        }

        return array_values($latest);
    }

    /**
     * Numbers for one report seen from the current planet.
     *
     * @param array<string, mixed> $reportData the decoded report JSON
     * @return array<string, mixed> loot (metal, crystal, deuterium), lootTotal, small, large (cargo ships needed), fuel (deuterium
     *         for the round trip with the large cargos), net, fleetValue, defenseValue, age (seconds), distance, spyShips, attackUrlQuery
     */
    public function analyse(array $reportData, ?int $now = null): array
    {
        $e = $this->elements();
        $now ??= $this->state->runtime->timestamp;
        $target = $reportData['targetPlanet'];
        $isPirateBase = (int) $target['planet_type'] === PlanetType::PirateBase->value;
        $factor = $isPirateBase ? 2.0 : 1.0;

        $res = SpyReportAnalysis::resources($reportData['spyData'] ?? []);
        $loot = SpyReportAnalysis::loot($res, INF, SpyReportAnalysis::BASE_LOOT_SHARE, $factor);
        $capacity = SpyReportAnalysis::minCapacity($res, SpyReportAnalysis::BASE_LOOT_SHARE, $factor);
        $small = SpyReportAnalysis::shipsNeeded($capacity, $e->capacity(SHIP_SMALL_CARGO));
        $large = SpyReportAnalysis::shipsNeeded($capacity, $e->capacity(SHIP_LARGE_CARGO));

        $distance = $this->distanceTo($target);
        $fuel = $this->roundTripFuel($large, $distance);
        $lootTotal = SpyReportAnalysis::total($loot);
        $values = SpyReportAnalysis::unitValues($reportData['spyData'] ?? [], $this->unitCosts());

        return [
            'loot' => $loot,
            'lootTotal' => $lootTotal,
            'small' => $small,
            'large' => $large,
            'fuel' => $fuel,
            'net' => $lootTotal - $fuel,
            'fleetValue' => $values['fleet'],
            'defenseValue' => $values['defense'],
            'age' => max(0, $now - (int) ($reportData['time'] ?? $now)),
            'distance' => $distance,
            'spyShips' => max(1, (int) ($this->state->USER['spio_anz'] ?? 1)),
        ];
    }

    /**
     * Distance in game units from the current planet to the report's target.
     *
     * @param array<string, mixed> $target the report's targetPlanet block
     */
    public function distanceTo(array $target): int
    {
        $PLANET = $this->state->PLANET;

        return $this->fleetFunctions()->getTargetDistance(
            [(int) $PLANET['galaxy'], (int) $PLANET['system'], (int) $PLANET['planet']],
            [(int) $target['galaxy'], (int) $target['system'], (int) $target['planet']],
        );
    }

    /** Deuterium for there and back with $ships large cargos at full speed; 0 without ships or when the fleet cannot fly. */
    public function roundTripFuel(int $ships, int $distance): int
    {
        if ($ships <= 0) {
            return 0;
        }

        return $this->fuelCache[$ships . ':' . $distance] ??= $this->computeRoundTripFuel($ships, $distance);
    }

    private function computeRoundTripFuel(int $ships, int $distance): int
    {
        $fleet = [SHIP_LARGE_CARGO => $ships];
        $user = $this->player();
        $ff = $this->fleetFunctions();
        $speed = $ff->fleetMaxSpeedFor($fleet, $user);
        if ($speed <= 0) {
            return 0;
        }

        $gameSpeed = $ff->getGameSpeedFactor();
        $duration = $ff->missionDurationFor(10, $speed, $distance, $gameSpeed, $user);

        return (int) (2 * $ff->fleetConsumptionFor($fleet, $duration, $distance, $user, $gameSpeed, $this->honorBoost($user->id)));
    }

    private function honorBoost(int $userId): float
    {
        return $this->honorBoost[$userId] ??= $this->honorPoints()->calculate_honorpoints_boost($userId);
    }

    /**
     * Cost per unit of all ships and defense: element id => [metal, crystal, deuterium].
     *
     * @return array<int, array{0: int|float, 1: int|float, 2: int|float}>
     */
    private function unitCosts(): array
    {
        if ($this->unitCostCache !== null) {
            return $this->unitCostCache;
        }
        $e = $this->elements();
        $costs = [];
        foreach (array_merge($e->ids('fleet'), $e->ids('defense')) as $id) {
            $cost = $e->cost($id);
            $costs[$id] = [$cost->metal, $cost->crystal, $cost->deuterium];
        }

        return $this->unitCostCache = $costs;
    }
}
