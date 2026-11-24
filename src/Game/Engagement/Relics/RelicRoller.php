<?php

declare(strict_types=1);

namespace App\Game\Engagement\Relics;

/**
 * Rolls relics from a catalog (see RelicCatalog::sanitize()): rarity by weight, slot, set, main stat and substats. Values
 * are uniform within the rarity band (share of the stat's maximum) and stored with 2 decimals; the substats of a relic
 * are different stats and never the main stat. The random source is injectable (default random_int) so tests can seed it.
 */
final class RelicRoller
{
    /** @var \Closure(int, int): int */
    private \Closure $rand;

    /**
     * @param array<string, mixed> $config the effective catalog
     * @param (\Closure(int, int): int)|null $rand source of integers in [min, max], null = random_int
     */
    public function __construct(private array $config, ?\Closure $rand = null)
    {
        $this->rand = $rand ?? static fn(int $min, int $max): int => random_int($min, $max);
    }

    /**
     * A rarity drawn by the weights, at least $minRarity (rarities below it are skipped; the best rarity when none of the
     * allowed ones has weight).
     */
    public function rarity(int $minRarity = 0): int
    {
        $minRarity = max(0, min(max(array_keys(RelicCatalog::RARITIES)), $minRarity));
        $weights = [];
        foreach ($this->config['rarities'] as $rarity => $row) {
            if ($rarity >= $minRarity && $row['weight'] > 0) {
                $weights[$rarity] = (int) $row['weight'];
            }
        }
        if ($weights === []) {
            return $minRarity;
        }
        $pick = ($this->rand)(1, array_sum($weights));
        foreach ($weights as $rarity => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return $rarity;
            }
        }

        return array_key_last($weights);
    }

    /** Slots that can roll a relic: at least one main stat configured. @return list<int> */
    public function slots(): array
    {
        return array_values(array_filter(array_keys(RelicCatalog::SLOTS), fn(int $slot): bool => ($this->config['slots'][$slot] ?? []) !== []));
    }

    /** Codes of the enabled sets. @return list<string> */
    public function sets(): array
    {
        return array_keys(array_filter($this->config['sets'], static fn(array $set): bool => $set['enabled']));
    }

    /**
     * One relic of the given rarity.
     *
     * @param int|null $slot 1-4, null = random among the slots that have a main stat
     * @param string|null $set set code (must exist in the catalog), null = random among the enabled sets
     * @return array{slot: int, set: string, rarity: int, main_stat: string, main_value: float, subs: list<array{stat: string, value: float}>}|null null when the slot or set is unusable
     */
    public function roll(int $rarity, ?int $slot = null, ?string $set = null): ?array
    {
        $slots = $this->slots();
        $sets = $this->sets();
        if ($slot === null) {
            $slot = $slots === [] ? 0 : $slots[($this->rand)(0, count($slots) - 1)];
        }
        if ($set === null) {
            $set = $sets === [] ? '' : $sets[($this->rand)(0, count($sets) - 1)];
        }
        if (!in_array($slot, $slots, true) || !isset($this->config['sets'][$set]) || !isset($this->config['rarities'][$rarity])) {
            return null;
        }
        $mains = array_keys($this->config['slots'][$slot]);
        $mainStat = $mains[($this->rand)(0, count($mains) - 1)];

        return [
            'slot' => $slot,
            'set' => $set,
            'rarity' => $rarity,
            'main_stat' => $mainStat,
            'main_value' => $this->value((float) $this->config['slots'][$slot][$mainStat], $rarity),
            'subs' => $this->subs($slot, $rarity, $mainStat),
        ];
    }

    /**
     * A new value for a substat within the band of the rarity (reforge); 0.0 when the stat is not a substat any more.
     */
    public function rerollSub(string $stat, int $rarity): float
    {
        $max = (float) ($this->config['subs'][$stat]['max'] ?? 0.0);

        return $max > 0 ? $this->value($max, $rarity) : 0.0;
    }

    /**
     * Substats of a new relic: as many as the rarity has, distinct, never the main stat, only stats whose slot list
     * contains the slot (fewer when the pool is smaller).
     *
     * @return list<array{stat: string, value: float}>
     */
    private function subs(int $slot, int $rarity, string $mainStat): array
    {
        $pool = [];
        foreach ($this->config['subs'] as $stat => $row) {
            if ($stat !== $mainStat && $row['max'] > 0 && in_array($slot, $row['slots'], true)) {
                $pool[] = $stat;
            }
        }
        $subs = [];
        for ($i = 0, $n = (int) $this->config['rarities'][$rarity]['subs']; $i < $n && $pool !== []; $i++) {
            $index = ($this->rand)(0, count($pool) - 1);
            $stat = $pool[$index];
            array_splice($pool, $index, 1);
            $subs[] = ['stat' => $stat, 'value' => $this->value((float) $this->config['subs'][$stat]['max'], $rarity)];
        }

        return $subs;
    }

    /**
     * A value of a stat with maximum $max: uniform over the rarity band (min - max percent of the maximum), in hundredths,
     * never below 0.01 for a stat with a maximum.
     */
    private function value(float $max, int $rarity): float
    {
        $band = $this->config['rarities'][$rarity];
        $low = (int) round($max * $band['min']);
        $high = (int) round($max * $band['max']);
        $low = max(1, $low);
        $high = max($low, $high);

        return round(($this->rand)($low, $high) / 100, 2);
    }
}
