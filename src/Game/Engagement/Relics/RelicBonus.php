<?php

declare(strict_types=1);

namespace App\Game\Engagement\Relics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * The relic bonuses of a planet: relic stats plus the set bonuses of the pieces on the planet, multiplied by the
 * universe's relics_power_percent, then capped per stat. Everything is a no-op without a query while the feature is off
 * (value() = 0, factors = 1.0, signature = ''). The equipped relics of a player are read once per process with one query
 * and the result per planet is memoised; RelicInventory calls flush() after every change.
 */
final class RelicBonus
{
    /** @var array<int, array<int, list<array<string, mixed>>>> user id => planet id => equipped relics */
    private static array $equipped = [];

    /** @var array<string, array<string, mixed>> "user:planet" => compute() result */
    private static array $computed = [];

    private const NONE = ['values' => [], 'capped' => [], 'sets' => [], 'production' => []];

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /** Forgets everything read so far (after relics changed within the process). */
    public static function flush(): void
    {
        self::$equipped = [];
        self::$computed = [];
        RelicCatalog::flush();
    }

    /** Whether relics are switched on for the universe (no query). */
    public function enabled(int $universe): bool
    {
        return (bool) ($this->configs->get($universe)->relics_enabled ?? false);
    }

    /**
     * What a relic gives under the CURRENT catalog: a stat counts only while the slot may still roll it (main stat of the
     * slot, substat with a maximum that lists the slot), and its stored value is cut to the top of the rarity band of the
     * current maximum, so the admin can nerf existing relics by lowering maxima. A relic of a slot without any main stat
     * is unusable (gives nothing, counts for no set).
     *
     * @param array<string, mixed> $relic row with slot, rarity, main_stat, main_value, subs (list or JSON)
     * @param array<string, mixed> $config effective catalog
     * @return array{usable: bool, main: array{stat: string, value: float, active: bool}, subs: list<array{stat: string, value: float, active: bool}>}
     */
    public static function effectiveStats(array $relic, array $config): array
    {
        $slot = (int) $relic['slot'];
        $top = (float) ($config['rarities'][(int) $relic['rarity']]['max'] ?? 100) / 100;
        $cut = static fn(float $value, float $max): float => round(min($value, $max * $top), 2);

        $mainMax = $config['slots'][$slot][$relic['main_stat']] ?? null;
        $main = ['stat' => (string) $relic['main_stat'], 'value' => (float) $relic['main_value'], 'active' => $mainMax !== null];
        if ($mainMax !== null) {
            $main['value'] = $cut($main['value'], (float) $mainMax);
        }
        $subs = [];
        $stored = is_string($relic['subs']) ? (array) json_decode($relic['subs'], true) : (array) $relic['subs'];
        foreach ($stored as $sub) {
            if (!isset($sub['stat'], $sub['value'])) {
                continue;
            }
            $row = $config['subs'][$sub['stat']] ?? null;
            $active = $row !== null && $row['max'] > 0 && in_array($slot, $row['slots'], true);
            $subs[] = ['stat' => (string) $sub['stat'], 'value' => $active ? $cut((float) $sub['value'], (float) $row['max']) : (float) $sub['value'], 'active' => $active];
        }

        return ['usable' => ($config['slots'][$slot] ?? []) !== [], 'main' => $main, 'subs' => $subs];
    }

    /**
     * Pure bonus maths of the relics on one planet (stats through effectiveStats()).
     *
     * @param list<array<string, mixed>> $relics rows with set_code, slot, rarity, main_stat, main_value and subs (list of stat/value or its JSON)
     * @param array<string, mixed> $config effective catalog
     * @param float $power relics_power_percent
     * @return array{values: array<string, float>, capped: array<string, bool>, sets: array<string, int>, production: array<string, float>} bonus per stat after power and caps (only stats above 0), whether the cap cut it, pieces per set, and the effective mine bonus per resource: min(cap of the resource, resource + all resources)
     */
    public static function compute(array $relics, array $config, float $power): array
    {
        $sum = [];
        $pieces = [];
        foreach ($relics as $relic) {
            $eff = self::effectiveStats($relic, $config);
            if (!$eff['usable']) {
                continue;
            }
            foreach (array_merge([$eff['main']], $eff['subs']) as $line) {
                if ($line['active']) {
                    $sum[$line['stat']] = ($sum[$line['stat']] ?? 0.0) + $line['value'];
                }
            }
            $pieces[$relic['set_code']] = ($pieces[$relic['set_code']] ?? 0) + 1;
        }
        foreach ($pieces as $code => $count) {
            $set = $config['sets'][$code] ?? null;
            if ($set === null || !$set['enabled']) {
                continue;
            }
            foreach (array_merge($count >= 2 ? $set['b2'] : [], $count >= 4 ? $set['b4'] : []) as $line) {
                $sum[$line['stat']] = ($sum[$line['stat']] ?? 0.0) + (float) $line['value'];
            }
        }

        $values = [];
        $capped = [];
        foreach ($sum as $stat => $value) {
            $value *= $power / 100;
            $cap = (float) ($config['caps'][$stat] ?? RelicCatalog::MAX_VALUE);
            $capped[$stat] = $value > $cap + 1e-9;
            $value = round(min($value, $cap), 4);
            if ($value > 0) {
                $values[$stat] = $value;
            }
        }
        $production = [];
        foreach ([RelicCatalog::METAL, RelicCatalog::CRYSTAL, RelicCatalog::DEUTERIUM] as $stat) {
            $total = round(min((float) ($config['caps'][$stat] ?? RelicCatalog::MAX_VALUE), ($values[$stat] ?? 0.0) + ($values[RelicCatalog::ALL] ?? 0.0)), 4);
            if ($total > 0) {
                $production[$stat] = $total;
            }
        }

        return ['values' => $values, 'capped' => $capped, 'sets' => $pieces, 'production' => $production];
    }

    /**
     * The bonuses of a planet (see compute()); empty when the feature is off or the planet holds no relic.
     *
     * @return array{values: array<string, float>, capped: array<string, bool>, sets: array<string, int>, production: array<string, float>}
     */
    public function forPlanet(int $userId, int $universe, int $planetId): array
    {
        if ($userId <= 0 || $planetId <= 0 || !$this->enabled($universe)) {
            return self::NONE;
        }
        $key = $userId . ':' . $planetId;
        if (!isset(self::$computed[$key])) {
            $relics = $this->equippedOf($userId)[$planetId] ?? [];
            self::$computed[$key] = $relics === []
                ? self::NONE
                : self::compute(
                    $relics,
                    (new RelicCatalog($this->db, $this->configs, $this->state))->config($universe),
                    (float) ($this->configs->get($universe)->relics_power_percent ?? 100),
                );
        }

        return self::$computed[$key];
    }

    /** Bonus of one stat in percent points (0.0 = none). */
    public function value(int $userId, int $universe, int $planetId, string $stat): float
    {
        return $this->forPlanet($userId, $universe, $planetId)['values'][$stat] ?? 0.0;
    }

    /** Production factor of a resource mine: 1 + min(cap of the resource, resource + all resources stat) / 100, exactly 1.0 without a bonus. */
    public function productionFactor(int $userId, int $universe, int $planetId, string $resourceStat): float
    {
        return 1.0 + ($this->forPlanet($userId, $universe, $planetId)['production'][$resourceStat] ?? 0.0) / 100;
    }

    /** Factor of a gain stat (energy, storage, expedition finds): 1 + value / 100. */
    public function gainFactor(int $userId, int $universe, int $planetId, string $stat): float
    {
        return 1.0 + $this->value($userId, $universe, $planetId, $stat) / 100;
    }

    /** Factor of a reduction stat (times, fuel, fusion use): 1 - value / 100, never below 0.1. */
    public function reductionFactor(int $userId, int $universe, int $planetId, string $stat): float
    {
        return max(0.1, 1.0 - $this->value($userId, $universe, $planetId, $stat) / 100);
    }

    /**
     * Fingerprint of the planet's relic bonuses for the production hash: '' without a bonus, so the hash of a planet
     * without relics (or with the feature off) stays what it was.
     */
    public function signature(int $userId, int $universe, int $planetId): string
    {
        $bonus = $this->forPlanet($userId, $universe, $planetId);
        if ($bonus['values'] === []) {
            return '';
        }
        ksort($bonus['values']);
        ksort($bonus['production']);

        return 'relics' . md5((string) json_encode([$bonus['values'], $bonus['production']]));
    }

    /**
     * All equipped relics of a player grouped by planet: one query per process and player.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function equippedOf(int $userId): array
    {
        if (!isset(self::$equipped[$userId])) {
            self::$equipped[$userId] = [];
            $rows = $this->db->select(
                'SELECT `id`, `set_code`, `slot`, `rarity`, `main_stat`, `main_value`, `subs`, `planet_id` FROM %%ENG_RELICS%% WHERE `user_id` = :user AND `planet_id` IS NOT NULL;',
                [':user' => $userId],
            );
            foreach ($rows as $row) {
                self::$equipped[$userId][(int) $row['planet_id']][] = $row;
            }
        }

        return self::$equipped[$userId];
    }
}
