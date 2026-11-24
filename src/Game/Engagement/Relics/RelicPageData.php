<?php

declare(strict_types=1);

namespace App\Game\Engagement\Relics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Enum\PlanetType;

/**
 * Builds the template context `relics` of the resources page (the contract with the UI): the four slots of the current
 * planet, the active set bonuses, the planet's totals after caps, the whole account storage and the filter lists. Every
 * string is plain text (Twig escapes it). Returns null when the feature is off or the planet is a moon.
 */
final class RelicPageData
{
    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /**
     * @param array<string, mixed> $user the player's row
     * @param array<string, mixed> $planet the current planet's row
     * @param \ArrayAccess<string, mixed> $LNG language with the ingame texts
     * @param array{text: string, error: bool}|null $notice result of the last action
     * @return array<string, mixed>|null
     */
    public function build(array $user, array $planet, \ArrayAccess $LNG, int $now, ?array $notice = null): ?array
    {
        $universe = (int) $user['universe'];
        $catalog = new RelicCatalog($this->db, $this->configs, $this->state);
        if (!$catalog->enabled($universe) || (int) $planet['planet_type'] !== PlanetType::Planet->value) {
            return null;
        }
        $config = $catalog->config($universe);
        $inventory = new RelicInventory($this->db, $this->configs, $this->state);
        $rows = $inventory->relicsOf((int) $user['id']);
        $cooldown = (int) ($this->configs->get($universe)->relics_move_cooldown_hours ?? 6) * 3600;
        $power = (float) ($this->configs->get($universe)->relics_power_percent ?? 100);
        $planetId = (int) $planet['id'];

        $storage = [];
        $here = [];
        foreach ($rows as $row) {
            $relic = $this->relic($row, $config, $LNG, $planetId, $cooldown, $now, $universe, $inventory);
            $storage[] = $relic;
            if ((int) ($row['planet_id'] ?? 0) === $planetId) {
                $here[] = $row;
            }
        }
        $bonus = RelicBonus::compute($here, $config, $power);

        return [
            'planetId' => $planetId,
            'planetName' => (string) $planet['name'],
            'slots' => $this->slots($storage, $LNG),
            'sets' => $this->sets($bonus['sets'], $config, $LNG, $power),
            'totals' => $this->totals($bonus, $LNG),
            'storage' => $storage,
            'dust' => $inventory->dustOf((int) $user['id']),
            'count' => count($rows),
            'capacity' => max(1, (int) ($this->configs->get($universe)->relics_inventory_max ?? 60)),
            'filters' => $this->filters($config, $LNG),
            'notice' => $notice,
        ];
    }

    /**
     * One relic for the template (see the contract).
     *
     * @param array<string, mixed> $row relic row joined with its planet
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function relic(array $row, array $config, \ArrayAccess $LNG, int $planetId, int $cooldown, int $now, int $universe, RelicInventory $inventory): array
    {
        $rarity = (int) $row['rarity'];
        $slot = (int) $row['slot'];
        $eff = RelicBonus::effectiveStats($row, $config);
        $subs = [];
        foreach ($eff['subs'] as $index => $sub) {
            $subs[] = $this->statLine($sub['stat'], $sub['value'], $LNG, !$sub['active'] || !$eff['usable']) + ['index' => $index];
        }
        $onPlanet = $row['planet_id'] !== null;

        return [
            'id' => (int) $row['id'],
            'slot' => $slot,
            'slotCode' => RelicCatalog::SLOTS[$slot],
            'slotLabel' => (string) $LNG['rl_slot_' . RelicCatalog::SLOTS[$slot]],
            'set' => (string) $row['set_code'],
            'setLabel' => RelicCatalog::setLabel($LNG, $config, (string) $row['set_code']),
            'rarity' => $rarity,
            'rarityKey' => RelicCatalog::RARITIES[$rarity],
            'rarityLabel' => (string) $LNG['rl_rarity_' . RelicCatalog::RARITIES[$rarity]],
            'main' => $this->statLine($eff['main']['stat'], $eff['main']['value'], $LNG, !$eff['main']['active'] || !$eff['usable']),
            'subs' => $subs,
            'planetId' => $onPlanet ? (int) $row['planet_id'] : null,
            'planetName' => $onPlanet ? (string) $row['planet_name'] : null,
            'planetCoords' => $onPlanet ? $row['planet_galaxy'] . ':' . $row['planet_system'] . ':' . $row['planet_pos'] : null,
            'equippedHere' => $onPlanet && (int) $row['planet_id'] === $planetId,
            'locked' => (int) $row['locked'] === 1,
            'movableIn' => max(0, (int) $row['moved_at'] + $cooldown - $now),
            'reforgeCost' => $inventory->reforgeCost($config, $rarity, $universe),
            'dismantleDust' => (int) $config['rarities'][$rarity]['dust'],
        ];
    }

    /**
     * @param bool $inactive the catalog does not allow this stat on the relic any more: it gives nothing (value is the stored one)
     * @return array{stat: string, label: string, value: float, text: string, inactive: bool}
     */
    private function statLine(string $stat, float $value, \ArrayAccess $LNG, bool $inactive = false): array
    {
        return ['stat' => $stat, 'label' => (string) $LNG['rl_stat_' . $stat], 'value' => $value, 'text' => RelicCatalog::formatValue($stat, $value), 'inactive' => $inactive];
    }

    /**
     * The four slots of the planet with the relic that sits in each.
     *
     * @param list<array<string, mixed>> $storage
     * @return list<array<string, mixed>>
     */
    private function slots(array $storage, \ArrayAccess $LNG): array
    {
        $slots = [];
        foreach (RelicCatalog::SLOTS as $slot => $code) {
            $relic = null;
            foreach ($storage as $candidate) {
                if ($candidate['slot'] === $slot && $candidate['equippedHere']) {
                    $relic = $candidate;
                    break;
                }
            }
            $slots[] = ['slot' => $slot, 'code' => $code, 'label' => (string) $LNG['rl_slot_' . $code], 'relic' => $relic];
        }

        return $slots;
    }

    /**
     * The sets with at least one piece on the planet and whether their 2 and 4 piece bonuses are active.
     *
     * @param array<string, int> $pieces set code => pieces on the planet
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function sets(array $pieces, array $config, \ArrayAccess $LNG, float $power): array
    {
        $sets = [];
        foreach ($pieces as $code => $count) {
            $set = $config['sets'][$code] ?? null;
            if ($set === null) {
                continue;
            }
            $sets[] = [
                'code' => $code,
                'label' => RelicCatalog::setLabel($LNG, $config, $code),
                'pieces' => $count,
                'bonus2' => ['text' => $this->bonusText($set['b2'], $LNG, $power), 'active' => $set['enabled'] && $count >= 2],
                'bonus4' => ['text' => $this->bonusText($set['b4'], $LNG, $power), 'active' => $set['enabled'] && $count >= 4],
            ];
        }

        return $sets;
    }

    /**
     * "Metal +1.50 %, Crystal +1.00 %" (the values the planet gets: configured value times the power percent).
     *
     * @param list<array{stat: string, value: float}> $lines
     */
    private function bonusText(array $lines, \ArrayAccess $LNG, float $power): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $parts[] = $LNG['rl_stat_' . $line['stat']] . ' ' . RelicCatalog::formatValue($line['stat'], round($line['value'] * $power / 100, 2));
        }

        return implode(', ', $parts);
    }

    /**
     * @param array{values: array<string, float>, capped: array<string, bool>, sets: array<string, int>} $bonus
     * @return list<array{stat: string, label: string, text: string, capped: bool}>
     */
    private function totals(array $bonus, \ArrayAccess $LNG): array
    {
        $totals = [];
        foreach (RelicCatalog::STATS as $stat) {
            if (isset($bonus['values'][$stat])) {
                $totals[] = [
                    'stat' => $stat,
                    'label' => (string) $LNG['rl_stat_' . $stat],
                    'text' => RelicCatalog::formatValue($stat, $bonus['values'][$stat]),
                    'capped' => $bonus['capped'][$stat] ?? false,
                ];
            }
        }

        return $totals;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{slots: list<array{code: string, label: string}>, sets: list<array{code: string, label: string}>, rarities: list<array{key: string, label: string}>}
     */
    private function filters(array $config, \ArrayAccess $LNG): array
    {
        $slots = [];
        foreach (RelicCatalog::SLOTS as $code) {
            $slots[] = ['code' => $code, 'label' => (string) $LNG['rl_slot_' . $code]];
        }
        $sets = [];
        foreach (array_keys($config['sets']) as $code) {
            $sets[] = ['code' => $code, 'label' => RelicCatalog::setLabel($LNG, $config, $code)];
        }
        $rarities = [];
        foreach (RelicCatalog::RARITIES as $key) {
            $rarities[] = ['key' => $key, 'label' => (string) $LNG['rl_rarity_' . $key]];
        }

        return ['slots' => $slots, 'sets' => $sets, 'rarities' => $rarities];
    }
}
