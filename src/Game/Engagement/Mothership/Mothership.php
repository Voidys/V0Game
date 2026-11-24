<?php

declare(strict_types=1);

namespace App\Game\Engagement\Mothership;

use App\Game\Engagement\EngagementFeature;
use App\Game\Engagement\Events\EventModifiers;
use App\Game\Engagement\MapContent\NpcTargets;

/**
 * Pirate mothership (world boss): every mothership_interval_days a heavy pirate fleet appears at a random empty slot, as a
 * `pirate_bases` row of kind 2 (owner 0) so the pirate base spy and attack missions work on it, with its state in `eng_motherships`.
 * Its fleet value is mothership_strength_percent of the average fleet value of the top 10 non-bot players (times the event factor
 * pirate_strength); the loot is mothership_loot_hours of their average production (times pirate_loot). It can only be attacked by an
 * alliance combat group of mothership_min_players different players (see MothershipBattle for the fight, damage and payout). It
 * leaves without loot after mothership_lifetime_hours.
 */
final class Mothership extends EngagementFeature
{
    /** Share of the fleet value per ship type (heavy mix); ships the universe does not know are skipped. */
    private const MIX = [SHIP_BATTLESHIP => 0.30, SHIP_BATTLECRUISER => 0.30, SHIP_CRUISER => 0.15, SHIP_DESTROYER => 0.15, SHIP_BOMBER => 0.10];

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'mothership_enabled', false);
    }

    /** Lets an expired mothership leave, then spawns a new one when none lives and the interval since the last spawn has passed. */
    public function tick(int $universe, int $now): void
    {
        $this->expire($universe, $now);
        if ($this->aliveRow($universe) !== null) {
            return;
        }
        $interval = max(1, (int) $this->setting($universe, 'mothership_interval_days', 14)) * 86400;
        $last = (int) $this->db->selectSingle('SELECT MAX(`spawned_at`) AS last FROM %%ENG_MOTHERSHIPS%% WHERE `universe` = :universe;', [':universe' => $universe], 'last');
        if ($last === 0 || $now - $last >= $interval) {
            $this->spawn($universe, $now);
        }
    }

    /**
     * Spawns a mothership (admin button and tick; the interval is checked by the tick).
     *
     * @return int|null the eng_motherships id, null when one lives, there is no free slot or no top players to measure
     */
    public function spawn(int $universe, int $now): ?int
    {
        if ($this->aliveRow($universe) !== null) {
            return null;
        }
        $slotFinder = new NpcTargets($this->db, $this->configs, $this->state);

        return $slotFinder->withSlotLock($universe, fn(): ?int => $this->spawnLocked($universe, $now, $slotFinder));
    }

    /** The spawn, run while the slot lock is held. */
    private function spawnLocked(int $universe, int $now, NpcTargets $slotFinder): ?int
    {
        if ($this->aliveRow($universe) !== null) {
            return null;
        }
        $top = $this->topAverages($universe);
        $galaxy = random_int(1, max(1, (int) $this->configs->get($universe)->max_galaxy));
        $slot = $slotFinder->freeSlot($universe, $galaxy);
        $strength = (float) $this->setting($universe, 'mothership_strength_percent', 300) / 100 * $this->eventFactor($universe, 'PIRATE_STRENGTH');
        $ships = $top['fleet'] > 0 ? $this->composeFleet($top['fleet'] * $strength) : [];
        if ($slot === null || $ships === []) {
            return null;
        }
        $hours = max(0, (int) $this->setting($universe, 'mothership_loot_hours', 48));
        $lootFactor = $this->eventFactor($universe, 'PIRATE_LOOT');
        $loot = array_map(fn(float $perHour): float => floor($perHour * $hours * $lootFactor), $top['production']);
        $lifetime = max(1, (int) $this->setting($universe, 'mothership_lifetime_hours', 72)) * 3600;
        $serialized = $this->fleetFunctions()->serialize($ships);

        return $this->db->transactional(function () use ($universe, $galaxy, $slot, $serialized, $loot, $now, $lifetime, $slotFinder): int {
            $this->db->insert(
                'INSERT INTO %%PIRATE_BASES%% SET `id_owner` = 0, `universe` = :universe, `kind` = :kind, `galaxy` = :galaxy, `system` = :system, `planet` = :planet,
                    `planet_type` = 1, `metal` = :m, `crystal` = :c, `deuterium` = :d, `ships` = :ships;',
                [
                    ':universe' => $universe, ':kind' => NpcTargets::KIND_MOTHERSHIP, ':galaxy' => $galaxy, ':system' => $slot['system'], ':planet' => $slot['planet'],
                    ':m' => $loot[0], ':c' => $loot[1], ':d' => $loot[2], ':ships' => $serialized,
                ],
            );
            $baseId = $this->db->lastInsertId();
            $this->db->insert(
                'INSERT INTO %%ENG_MOTHERSHIPS%% SET `universe` = :universe, `base_id` = :base, `galaxy` = :galaxy, `system` = :system, `planet` = :planet,
                    `start_value` = :value, `loot_metal` = :m, `loot_crystal` = :c, `loot_deuterium` = :d, `min_players` = :min, `spawned_at` = :now, `expires_at` = :expires;',
                [
                    ':universe' => $universe, ':base' => $baseId, ':galaxy' => $galaxy, ':system' => $slot['system'], ':planet' => $slot['planet'],
                    ':value' => $slotFinder->shipValue($serialized), ':m' => $loot[0], ':c' => $loot[1], ':d' => $loot[2],
                    ':min' => max(1, (int) $this->setting($universe, 'mothership_min_players', 2)), ':now' => $now, ':expires' => $now + $lifetime,
                ],
            );

            return $this->db->lastInsertId();
        });
    }

    /**
     * The living mothership with its HP in percent and the damage list, for the admin page.
     *
     * @return array<string, mixed>|null
     */
    public function live(int $universe): ?array
    {
        $row = $this->aliveRow($universe);
        if ($row === null) {
            return null;
        }
        $base = $this->db->selectSingle('SELECT `ships` FROM %%PIRATE_BASES%% WHERE `id` = :id;', [':id' => $row['base_id']]);
        $row['hp_percent'] = (new NpcTargets($this->db, $this->configs, $this->state))->hpPercent($row + ['ships' => $base['ships'] ?? '']);
        $row['damage'] = $this->db->select(
            'SELECT d.`user_id`, d.`damage`, d.`fights`, u.`username` FROM %%ENG_MOTHERSHIP_DAMAGE%% d INNER JOIN %%USERS%% u ON u.`id` = d.`user_id`
            WHERE d.`mothership_id` = :id ORDER BY d.`damage` DESC;',
            [':id' => $row['id']],
        );

        return $row;
    }

    /** @return array<string, mixed>|null the living mothership of the universe */
    private function aliveRow(int $universe): ?array
    {
        $row = $this->db->selectSingle('SELECT * FROM %%ENG_MOTHERSHIPS%% WHERE `universe` = :universe AND `status` = 0 ORDER BY `id` DESC LIMIT 1;', [':universe' => $universe]);

        return $row ?: null;
    }

    /** Status 2 (left) for a mothership whose lifetime passed; its base row goes, the participants hear about it. */
    public function expire(int $universe, int $now): void
    {
        $row = $this->aliveRow($universe);
        if ($row === null || (int) $row['expires_at'] > $now) {
            return;
        }
        $left = $this->db->transactional(function () use ($row, $now): bool {
            // same lock order as the fight (pirate base row first); waits for a fight that is running right now
            $this->db->selectSingle('SELECT `id` FROM %%PIRATE_BASES%% WHERE `id` = :id FOR UPDATE;', [':id' => $row['base_id']]);
            $this->db->selectSingle('SELECT `id` FROM %%ENG_MOTHERSHIPS%% WHERE `id` = :id FOR UPDATE;', [':id' => $row['id']]);
            $this->db->update('UPDATE %%ENG_MOTHERSHIPS%% SET `status` = 2, `ended_at` = :now WHERE `id` = :id AND `status` = 0;', [':now' => $now, ':id' => $row['id']]);
            if ($this->db->rowCount() !== 1) {
                return false;
            }
            $this->db->delete('DELETE FROM %%PIRATE_BASES%% WHERE `id` = :id AND `kind` = :kind;', [':id' => $row['base_id'], ':kind' => NpcTargets::KIND_MOTHERSHIP]);

            return true;
        });
        if ($left) {
            (new MothershipBattle($this->db, $this->configs, $this->state))->notifyLeft($row, $now);
        }
    }

    /**
     * Average fleet value (resource cost) and hourly production of the top 10 non-bot, non-admin players by total points.
     *
     * @return array{fleet: float, production: array{0: float, 1: float, 2: float}} production = metal, crystal, deuterium per hour
     */
    private function topAverages(int $universe): array
    {
        $top = $this->db->select(
            'SELECT s.`id_owner`, s.`fleet_points` FROM %%STATPOINTS%% s
            INNER JOIN %%USERS%% u ON u.`id` = s.`id_owner`
            LEFT JOIN %%BOT_PLAYERS%% bp ON bp.`user_id` = u.`id`
            WHERE s.`stat_type` = 1 AND s.`universe` = :universe AND bp.`user_id` IS NULL AND u.`authlevel` = 0
            ORDER BY s.`total_points` DESC LIMIT 10;',
            [':universe' => $universe],
        );
        if ($top === []) {
            return ['fleet' => 0.0, 'production' => [0.0, 0.0, 0.0]];
        }
        $count = count($top);
        // statistic points are worth 1000 resource units each
        $fleet = array_sum(array_map('floatval', array_column($top, 'fleet_points'))) * 1000 / $count;
        $ids = implode(',', array_map('intval', array_column($top, 'id_owner')));
        $sum = $this->db->selectSingle(
            "SELECT SUM(`metal_perhour`) AS m, SUM(`crystal_perhour`) AS c, SUM(`deuterium_perhour`) AS d FROM %%PLANETS%% WHERE `id_owner` IN ($ids) AND `destruyed` = 0 AND `planet_type` = 1;",
        );

        return ['fleet' => $fleet, 'production' => [(float) $sum['m'] / $count, (float) $sum['c'] / $count, (float) $sum['d'] / $count]];
    }

    /**
     * Ships worth about $value (metal + crystal) in the heavy mix.
     *
     * @return array<int, int> ship id => amount
     */
    private function composeFleet(float $value): array
    {
        $catalog = $this->elements();
        $ships = [];
        foreach (self::MIX as $shipId => $share) {
            if (!$catalog->has($shipId)) {
                continue;
            }
            $cost = $catalog->cost($shipId)->metal + $catalog->cost($shipId)->crystal;
            $count = $cost > 0 ? (int) floor($value * $share / $cost) : 0;
            if ($count > 0) {
                $ships[$shipId] = $count;
            }
        }

        return $ships;
    }

    /** Event factor (1.0 when the events feature does not exist or is off). */
    private function eventFactor(int $universe, string $constant): float
    {
        if (!class_exists(EventModifiers::class)) {
            return 1.0;
        }

        return (new EventModifiers($this->db, $this->configs, $this->state))->factor($universe, constant(EventModifiers::class . '::' . $constant));
    }
}
