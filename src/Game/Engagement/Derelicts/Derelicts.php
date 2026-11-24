<?php

declare(strict_types=1);

namespace App\Game\Engagement\Derelicts;

use App\Game\Engagement\EngagementFeature;
use App\Game\Engagement\MapContent\NpcTargets;

/**
 * Derelict fields: salvage fields with metal and crystal debris that appear at random empty galaxy slots for a limited time
 * (settings derelicts_*). A field is a `pirate_bases` row of kind 1 (owner 0) whose debris columns hold the salvage, so the
 * existing "pirate base recycling" mission collects it: it locks the row, takes at most its capacity and leaves the rest for
 * the next fleet. Every field is logged in `eng_derelicts` (spawn interval, expiry, admin list). The tick removes expired and
 * emptied fields and spawns new ones when the interval is due.
 */
final class Derelicts extends EngagementFeature
{
    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'derelicts_enabled', false);
    }

    /** Removes expired and emptied fields, then spawns a round when the interval since the last one has passed. */
    public function tick(int $universe, int $now): void
    {
        $this->cleanup($universe, $now);

        $interval = max(1, (int) $this->setting($universe, 'derelicts_interval_hours', 12)) * 3600;
        $last = (int) $this->db->selectSingle(
            'SELECT MAX(`created_at`) AS last FROM %%ENG_DERELICTS%% WHERE `universe` = :universe;',
            [':universe' => $universe],
            'last',
        );
        if ($last === 0 || $now - $last >= $interval) {
            $this->spawn($universe, $now);
        }
    }

    /**
     * Spawns derelicts_per_galaxy fields in every galaxy (admin button and tick; the interval is checked by the tick).
     *
     * @return int number of fields created
     */
    public function spawn(int $universe, int $now): int
    {
        $perGalaxy = max(0, (int) $this->setting($universe, 'derelicts_per_galaxy', 2));
        $min = max(0, (int) $this->setting($universe, 'derelicts_min', 200000));
        $max = max($min, (int) $this->setting($universe, 'derelicts_max', 2000000));
        $lifetime = max(1, (int) $this->setting($universe, 'derelicts_lifetime_hours', 24)) * 3600;

        return (int) (new NpcTargets($this->db, $this->configs, $this->state))->withSlotLock($universe, fn(): int => $this->spawnLocked($universe, $now, $perGalaxy, $min, $max, $lifetime));
    }

    /** The spawn loop, run while the slot lock is held. */
    private function spawnLocked(int $universe, int $now, int $perGalaxy, int $min, int $max, int $lifetime): int
    {
        $config = $this->configs->get($universe);
        $created = 0;
        for ($galaxy = 1; $galaxy <= (int) $config->max_galaxy; $galaxy++) {
            for ($i = 0; $i < $perGalaxy; $i++) {
                $slot = (new NpcTargets($this->db, $this->configs, $this->state))->freeSlot($universe, $galaxy);
                if ($slot === null) {
                    continue;
                }
                $this->create($universe, $galaxy, $slot, random_int($min, $max), random_int($min, $max), $now, $now + $lifetime);
                $created++;
            }
        }

        return $created;
    }

    /**
     * The live fields of the universe for the admin list.
     *
     * @return list<array<string, mixed>> eng_derelicts rows plus the current debris (der_metal, der_crystal)
     */
    public function live(int $universe): array
    {
        return $this->db->select(
            'SELECT d.*, b.`der_metal`, b.`der_crystal` FROM %%ENG_DERELICTS%% d
            INNER JOIN %%PIRATE_BASES%% b ON b.`id` = d.`base_id`
            WHERE d.`universe` = :universe AND d.`removed_at` IS NULL ORDER BY d.`expires_at` ASC;',
            [':universe' => $universe],
        );
    }

    /** Deletes the rows of expired fields and of fields that were collected completely. */
    public function cleanup(int $universe, int $now): void
    {
        $gone = $this->db->select(
            'SELECT d.`id`, d.`base_id` FROM %%ENG_DERELICTS%% d
            LEFT JOIN %%PIRATE_BASES%% b ON b.`id` = d.`base_id`
            WHERE d.`universe` = :universe AND d.`removed_at` IS NULL
              AND (d.`expires_at` <= :now OR b.`id` IS NULL OR b.`der_metal` + b.`der_crystal` <= 0);',
            [':universe' => $universe, ':now' => $now],
        );
        foreach ($gone as $row) {
            $this->db->transactional(function () use ($row, $now): void {
                // locked like the recycling mission does, so a fleet that is collecting right now finishes first
                $this->db->selectSingle('SELECT `id` FROM %%PIRATE_BASES%% WHERE `id` = :id AND `kind` = :kind FOR UPDATE;', [
                    ':id' => $row['base_id'], ':kind' => NpcTargets::KIND_DERELICT,
                ]);
                $this->db->delete('DELETE FROM %%PIRATE_BASES%% WHERE `id` = :id AND `kind` = :kind;', [
                    ':id' => $row['base_id'], ':kind' => NpcTargets::KIND_DERELICT,
                ]);
                $this->db->update('UPDATE %%ENG_DERELICTS%% SET `removed_at` = :now WHERE `id` = :id;', [':now' => $now, ':id' => $row['id']]);
            });
        }
    }

    /** @param array{system: int, planet: int} $slot */
    private function create(int $universe, int $galaxy, array $slot, int $metal, int $crystal, int $now, int $expires): void
    {
        $this->db->transactional(function () use ($universe, $galaxy, $slot, $metal, $crystal, $now, $expires): void {
            $this->db->insert(
                'INSERT INTO %%PIRATE_BASES%% SET `id_owner` = 0, `universe` = :universe, `kind` = :kind, `galaxy` = :galaxy, `system` = :system,
                    `planet` = :planet, `planet_type` = 2, `ships` = \'\', `der_metal` = :metal, `der_crystal` = :crystal;',
                [
                    ':universe' => $universe, ':kind' => NpcTargets::KIND_DERELICT, ':galaxy' => $galaxy, ':system' => $slot['system'],
                    ':planet' => $slot['planet'], ':metal' => $metal, ':crystal' => $crystal,
                ],
            );
            $baseId = $this->db->lastInsertId();
            $this->db->insert(
                'INSERT INTO %%ENG_DERELICTS%% SET `universe` = :universe, `base_id` = :base, `galaxy` = :galaxy, `system` = :system, `planet` = :planet,
                    `metal` = :metal, `crystal` = :crystal, `created_at` = :now, `expires_at` = :expires;',
                [
                    ':universe' => $universe, ':base' => $baseId, ':galaxy' => $galaxy, ':system' => $slot['system'], ':planet' => $slot['planet'],
                    ':metal' => $metal, ':crystal' => $crystal, ':now' => $now, ':expires' => $expires,
                ],
            );
        });
    }
}
