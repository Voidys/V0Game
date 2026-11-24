<?php

declare(strict_types=1);

namespace App\Game\Engagement\MapContent;

/**
 * Derelict fields and the pirate mothership as targets on the galaxy map. Both are rows of `pirate_bases` with owner 0 and
 * a kind (1 = derelict field, 2 = mothership), so the pirate base missions (recycling, attack, spy) work on them unchanged.
 * This class is the one place that finds them for the fleet pages (target row shaped like a planet row), the galaxy view and
 * the dispatch rules; it only returns rows of features the universe has switched on.
 */
final class NpcTargets
{
    use \App\Game\Core\GameUtilities;
    public const KIND_DERELICT = 1;
    public const KIND_MOTHERSHIP = 2;

    /** `aks.target` of a combat group aimed at an NPC: the base id plus this offset (planet ids never get that high), so no planet join matches it. */
    public const ACS_OFFSET = 1_000_000_000;

    public function __construct(
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
    ) {}

    /** Whether the universe shows NPC targets of this kind (feature switched on). */
    public function kindEnabled(int $universe, int $kind): bool
    {
        $config = $this->configs->get($universe);

        return match ($kind) {
            self::KIND_DERELICT => !empty($config->derelicts_enabled),
            self::KIND_MOTHERSHIP => !empty($config->mothership_enabled),
            default => false,
        };
    }

    /**
     * The NPC target at a galaxy slot (derelict or mothership), shaped like a planet row for the fleet pages: id, id_owner 0,
     * der_metal/der_crystal, tf_active 0, destruyed 0, ally_deposit 0, name, owner_name, npc_kind, acs_target and its coordinates.
     *
     * @return array<string, mixed>|null null when there is none or its feature is off
     */
    public function atSlot(int $universe, int $galaxy, int $system, int $planet): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT * FROM %%PIRATE_BASES%% WHERE `universe` = :universe AND `kind` > 0 AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet LIMIT 1;',
            [':universe' => $universe, ':galaxy' => $galaxy, ':system' => $system, ':planet' => $planet],
        );

        return $this->asTarget($row ?: null, $universe);
    }

    /**
     * The NPC target with this pirate_bases id.
     *
     * @return array<string, mixed>|null
     */
    public function byId(int $universe, int $baseId): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT * FROM %%PIRATE_BASES%% WHERE `universe` = :universe AND `kind` > 0 AND `id` = :id;',
            [':universe' => $universe, ':id' => $baseId],
        );

        return $this->asTarget($row ?: null, $universe);
    }

    /**
     * All NPC targets of a system for the galaxy view, by slot, with display data: kind, der_metal, der_crystal, expires_at and,
     * for the mothership, the HP in percent of its start value. Empty when both features are off.
     *
     * @return array<int, array<string, mixed>> slot => row
     */
    public function inSystem(int $universe, int $galaxy, int $system): array
    {
        if (!$this->kindEnabled($universe, self::KIND_DERELICT) && !$this->kindEnabled($universe, self::KIND_MOTHERSHIP)) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT b.*, d.`expires_at` AS derelict_expires, m.`expires_at` AS mothership_expires, m.`start_value`, m.`id` AS mothership_id, m.`min_players`
            FROM %%PIRATE_BASES%% b
            LEFT JOIN %%ENG_DERELICTS%% d ON d.`base_id` = b.`id` AND b.`kind` = 1 AND d.`removed_at` IS NULL
            LEFT JOIN %%ENG_MOTHERSHIPS%% m ON m.`base_id` = b.`id` AND b.`kind` = 2 AND m.`status` = 0
            WHERE b.`universe` = :universe AND b.`kind` > 0 AND b.`galaxy` = :galaxy AND b.`system` = :system;',
            [':universe' => $universe, ':galaxy' => $galaxy, ':system' => $system],
        );
        $result = [];
        foreach ($rows as $row) {
            $kind = (int) $row['kind'];
            if (!$this->kindEnabled($universe, $kind)) {
                continue;
            }
            $row['expires_at'] = (int) ($kind === self::KIND_DERELICT ? $row['derelict_expires'] : $row['mothership_expires']);
            $row['hp_percent'] = $kind === self::KIND_MOTHERSHIP ? $this->hpPercent($row) : null;
            $result[(int) $row['planet']] = $row;
        }

        return $result;
    }

    /**
     * Runs $work while holding a database lock that serialises all spawns of NPC targets in the universe (slot choice and insert
     * must not race with each other); the lock is released afterwards and a failure to get it within 10 seconds skips the work.
     *
     * @param callable(): mixed $work
     */
    public function withSlotLock(int $universe, callable $work): mixed
    {
        $name = 'v0_npc_slots_' . $universe . '_' . $this->db->selectSingle('SELECT DATABASE() AS d', [], 'd');
        if (!(int) $this->db->selectSingle('SELECT GET_LOCK(:n, 10) AS l', [':n' => $name], 'l')) {
            return null;
        }
        try {
            return $work();
        } finally {
            $this->db->selectSingle('SELECT RELEASE_LOCK(:n) AS l', [':n' => $name], 'l');
        }
    }

    /**
     * A random slot (1..max_planets) of a random system of the galaxy that holds no planet, moon or other NPC target.
     *
     * @return array{system: int, planet: int}|null null after 40 tries without a free slot
     */
    public function freeSlot(int $universe, int $galaxy): ?array
    {
        $config = $this->configs->get($universe);
        for ($try = 0; $try < 40; $try++) {
            $system = random_int(1, max(1, (int) $config->max_system));
            $planet = random_int(1, max(1, (int) $config->max_planets));
            $taken = $this->db->selectSingle(
                "SELECT (SELECT COUNT(*) FROM %%PLANETS%% WHERE `universe` = :universe AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet)
                      + (SELECT COUNT(*) FROM %%PIRATE_BASES%% WHERE `universe` = :universe AND `kind` > 0 AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet)
                      + (SELECT COUNT(*) FROM %%FLEETS%% WHERE `universe` = :universe AND `mission` = " . \App\Game\Enum\Mission::Colonisation->value . " AND `end_galaxy` = :galaxy AND `end_system` = :system AND `end_planet` = :planet) AS n;",
                [':universe' => $universe, ':galaxy' => $galaxy, ':system' => $system, ':planet' => $planet],
                'n',
            );
            if ((int) $taken === 0) {
                return ['system' => $system, 'planet' => $planet];
            }
        }

        return null;
    }

    /** Remaining fleet value (metal + crystal of the ships) in percent of the start value of a mothership row (0..100). */
    public function hpPercent(array $row): int
    {
        $start = (float) ($row['start_value'] ?? 0);
        if ($start <= 0) {
            return 0;
        }

        return (int) max(0, min(100, round(100 * $this->shipValue((string) ($row['ships'] ?? '')) / $start)));
    }

    /** Metal + crystal value of a serialized ship list ("id,count;id,count"). */
    public function shipValue(string $ships): float
    {
        $catalog = $this->elements();
        $value = 0.0;
        foreach (array_filter(explode(';', $ships)) as $part) {
            [$id, $count] = array_pad(explode(',', $part), 2, '0');
            if ($catalog->has((int) $id)) {
                $cost = $catalog->cost((int) $id);
                $value += ($cost->metal + $cost->crystal) * (float) $count;
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function asTarget(?array $row, int $universe): ?array
    {
        if ($row === null || !$this->kindEnabled($universe, (int) $row['kind'])) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'id_owner' => 0,
            'der_metal' => (float) $row['der_metal'],
            'der_crystal' => (float) $row['der_crystal'],
            'tf_active' => 0,
            'destruyed' => 0,
            'ally_deposit' => 0,
            'name' => '',
            'owner_name' => '',
            'npc_kind' => (int) $row['kind'],
            'acs_target' => self::ACS_OFFSET + (int) $row['id'],
            'galaxy' => (int) $row['galaxy'],
            'system' => (int) $row['system'],
            'planet' => (int) $row['planet'],
            'planet_type' => (int) $row['planet_type'],
        ];
    }
}
