<?php

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotLog;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Enum\Mission;

/**
 * Debris harvesting (knob `recycling`). The bot looks at the debris fields a player sees in the galaxy view around its planets (the
 * scouting range), fields of its own recent fights first, and sends recyclers when the harvest, valued with metal 1 and crystal 1.5,
 * is at least twice the fuel of the flight (and a minimum). Without recyclers a bot that sees a rich field builds a few (shipyard
 * rules, never when the ship weight knob is 0, at most as many as the harvest needs and five). One recycler flight per field.
 */
class BotRecycling
{
    use GameUtilities;

    /** Smallest harvest value (and debris value for building recyclers: five times as much) worth a flight. */
    private const MIN_HARVEST = 3000;

    /** Seconds between two looks at the debris. */
    private const COOLDOWN = 300;

    /** Most recyclers a bot builds because of one field. */
    private const BUILD_MAX = 5;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidPlanner $planner,
        private readonly RaidDispatcher $dispatcher,
        private readonly RaidTargetFinder $finder,
        private readonly BotLog $botLog,
    ) {}

    /**
     * One look at the debris: sends recyclers to the best field (or queues recyclers when it has none).
     *
     * @param array<string, mixed> $s profile settings (`scout_range`)
     * @param array<string, mixed> $personality changed in place (`_rec` time of the last look)
     * @return int actions taken (flights sent, recyclers queued)
     */
    public function play(BotActions $ctx, array $s, array &$personality, int $now): int
    {
        if (!$ctx->bot->recycling || $now - (int) ($personality['_rec'] ?? 0) < self::COOLDOWN || $ctx->freeFleetSlots() < 1) {
            return 0;
        }
        $personality['_rec'] = $now;
        $hot = [];
        foreach ((array) (RaidMemory::of($personality)['targets'] ?? []) as $planetId => $known) {
            if (($known['last_hit'] ?? 0) > $now - 7200) {
                $hot[(int) $planetId] = true;
            }
        }
        $botId = (int) $ctx->user['id'];
        foreach (array_slice($this->fields($ctx, $s, $hot), 0, 3) as $field) {
            $done = $this->harvest($ctx, $botId, $field);
            if ($done > 0) {
                return $done;
            }
        }

        return 0;
    }

    /**
     * Debris fields in the systems around the bot's planets (the galaxy view's range), richest first, own fights ahead.
     *
     * @param array<string, mixed> $s profile settings
     * @param array<int, bool> $hot planet ids of the bot's own recent fights
     * @return list<array<string, mixed>> planet row columns plus `value`
     */
    private function fields(BotActions $ctx, array $s, array $hot): array
    {
        $range = $this->finder->range($ctx, $s);
        $conditions = [];
        $params = [':uni' => $ctx->universe];
        foreach (array_values($ctx->planets) as $i => $planet) {
            $conditions[] = '(galaxy = :g' . $i . ' AND `system` BETWEEN :a' . $i . ' AND :b' . $i . ')';
            $params[':g' . $i] = (int) $planet['galaxy'];
            $params[':a' . $i] = max(1, (int) $planet['system'] - $range);
            $params[':b' . $i] = (int) $planet['system'] + $range;
        }
        if ($conditions === []) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT id, id_owner, galaxy, `system`, planet, der_metal, der_crystal FROM %%PLANETS%%
            WHERE universe = :uni AND planet_type = \'1\' AND der_metal + der_crystal >= ' . self::MIN_HARVEST . ' AND (' . implode(' OR ', $conditions) . ');',
            $params,
        );
        foreach ($rows as &$row) {
            $row['value'] = (float) $row['der_metal'] + 1.5 * (float) $row['der_crystal'] + (isset($hot[(int) $row['id']]) ? 1e9 : 0);
        }
        unset($row);
        usort($rows, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);

        return $rows;
    }

    /**
     * Sends recyclers to one field (when the harvest beats the fuel and no recycler flight is on its way there) or builds some.
     *
     * @param array<string, mixed> $field a row of fields()
     * @return int actions taken
     */
    private function harvest(BotActions $ctx, int $botId, array $field): int
    {
        $e = $this->elements();
        $busy = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE owner = :o AND mission = :m AND end_id = :p;',
            [':o' => $botId, ':m' => Mission::Recycling->value, ':p' => (int) $field['id']],
            'c',
        );
        if ($busy > 0) {
            return 0;
        }
        $capacity = max(1, (int) $e->capacity(SHIP_RECYCLER));
        $debris = (float) $field['der_metal'] + (float) $field['der_crystal'];
        $wanted = (int) ceil($debris / $capacity);
        $origin = $this->nearestWithRecyclers($ctx, $field);
        if ($origin === null) {
            return $this->build($ctx, $botId, $field, $wanted);
        }
        $planet = $ctx->originRef($origin);
        $n = min($wanted, (int) $planet[$e->column(SHIP_RECYCLER)]);
        $flight = $this->planner->flight($ctx, $planet, [SHIP_RECYCLER => $n], (int) $field['galaxy'], (int) $field['system'], (int) $field['planet']);
        $share = min(1.0, $n * $capacity / max(1.0, $debris));
        $harvest = $share * ((float) $field['der_metal'] + 1.5 * (float) $field['der_crystal']);
        $fuel = $flight['consumption'] * RaidPlanner::VALUE[RESOURCE_DEUT];
        if ($harvest < max(self::MIN_HARVEST, 2 * $fuel)) {
            return 0;
        }
        $sent = $this->dispatcher->send($ctx, $ctx->originRef($origin), [SHIP_RECYCLER => $n], Mission::Recycling->value, (int) $field['id'], (int) $field['id_owner'], [], 10, 0, 2);
        $this->botLog->log($botId, $ctx->universe, $sent['ok'] ? 'recycle' : 'raid_skip', $sent['ok']
            ? $n . ' recyclers to the debris at ' . $field['galaxy'] . ':' . $field['system'] . ':' . $field['planet'] . ' (harvest about ' . round($harvest) . ' against fuel ' . round($fuel) . ')'
            : 'recycling refused (' . $sent['reason'] . ')');

        return $sent['ok'] ? 1 : 0;
    }

    /**
     * The base with recyclers nearest to the field.
     *
     * @param array<string, mixed> $field
     * @return int|null planet or moon id
     */
    private function nearestWithRecyclers(BotActions $ctx, array $field): ?int
    {
        $e = $this->elements();
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($ctx->planets + $ctx->moons as $id => $planet) {
            if ((int) $planet[$e->column(SHIP_RECYCLER)] < 1) {
                continue;
            }
            $d = $this->fleetFunctions()->getTargetDistance([$planet['galaxy'], $planet['system'], $planet['planet']], [$field['galaxy'], $field['system'], $field['planet']]);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * No recycler at all: queues a few at the planet nearest to a rich field, unless some are in the shipyards already or the ship
     * weight is 0.
     *
     * @param array<string, mixed> $field
     * @return int 1 when a batch was queued
     */
    private function build(BotActions $ctx, int $botId, array $field, int $wanted): int
    {
        $value = (float) $field['der_metal'] + 1.5 * (float) $field['der_crystal'];
        if ($value < 5 * self::MIN_HARVEST || $ctx->bot->shipWeightPercent < 1) {
            return 0;
        }
        foreach ($ctx->planets as $planet) {
            foreach ((array) (!empty($planet['b_hangar_id']) ? \App\Game\Core\StoredData::decode($planet['b_hangar_id']) : []) as $item) {
                if ((int) $item[0] === SHIP_RECYCLER) {
                    return 0;
                }
            }
        }
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($ctx->planets as $id => $planet) {
            $d = $this->fleetFunctions()->getTargetDistance([$planet['galaxy'], $planet['system'], $planet['planet']], [$field['galaxy'], $field['system'], $field['planet']]);
            if ($d < $bestDistance && $ctx->canQueueUnits($planet)) {
                $bestDistance = $d;
                $best = $id;
            }
        }
        $detail = $best === null ? null : $ctx->units($best, SHIP_RECYCLER, min($wanted, self::BUILD_MAX));

        if ($detail !== null) {
            $this->botLog->log($botId, $ctx->universe, 'recycle_prep', 'building recyclers for a debris field: ' . $detail);
        }

        return $detail === null ? 0 : 1;
    }
}
