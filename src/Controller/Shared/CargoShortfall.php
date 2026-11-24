<?php

declare(strict_types=1);

namespace App\Controller\Shared;

/**
 * Cargo maths shared by the fleet page's cargo calculator and the watched build/research cards: how many small and
 * large cargos carry a set of resources (plus the 1000 deuterium reserve).
 */
trait CargoShortfall
{
    /**
     * Total to carry (resources plus reserve) and the small/large cargos needed for it.
     *
     * @return array{total: float|int, needed202: float, needed203: float}
     */
    private function cargoNeeds(float|int $metal, float|int $crystal, float|int $deuterium): array
    {
        $total = $metal + $crystal + $deuterium + 1000;

        return [
            'total' => $total,
            'needed202' => ceil($total / $this->elements()->capacity(202)),
            'needed203' => ceil($total / $this->elements()->capacity(203)),
        ];
    }

    /**
     * What a watched card still lacks on the current planet, rounded up to 10 like the cargo calculator; null when nothing is
     * missing. `source` is the player's other planet or moon that holds the most of the missing resources (ties: nearest)
     * with the cargo ships of that planet to carry them, null when the player has no other planet.
     *
     * @param array<int, float|int> $costOverflow missing amount per resource id (BuildFunctions::restPriceFor)
     * @return array{metal: float, crystal: float, deuterium: float, source: array{id: int, ships: string}|null}|null
     */
    private function watchedShortfall(array $costOverflow): ?array
    {
        $metal = ceil(($costOverflow[901] ?? 0) / 10) * 10;
        $crystal = ceil(($costOverflow[902] ?? 0) / 10) * 10;
        $deuterium = ceil(($costOverflow[903] ?? 0) / 10) * 10;
        if ($metal + $crystal + $deuterium <= 0) {
            return null;
        }

        return [
            'metal' => $metal,
            'crystal' => $crystal,
            'deuterium' => $deuterium,
            'source' => $this->watchSource($metal, $crystal, $deuterium),
        ];
    }

    /**
     * The own planet or moon (not the current one) that covers most of the missing resources, nearest first on ties, and the
     * small/large cargo preset computed from its own ships.
     *
     * @return array{id: int, ships: string}|null
     */
    private function watchSource(float $metal, float $crystal, float $deuterium): ?array
    {
        $e = $this->elements();
        $current = $this->state->PLANET;
        $rows = $this->db->select(
            'SELECT id, galaxy, `system`, metal, crystal, deuterium, `' . $e->column(202) . '` AS s202, `' . $e->column(203) . '` AS s203
                FROM %%PLANETS%% WHERE id_owner = :owner AND destruyed = 0 AND id != :id;',
            [':owner' => $this->state->USER['id'], ':id' => $current['id']],
        );

        $best = null;
        $bestKey = null;
        foreach ($rows as $row) {
            $covered = min($row['metal'], $metal) + min($row['crystal'], $crystal) + min($row['deuterium'], $deuterium);
            $distance = $row['galaxy'] == $current['galaxy'] ? abs($row['system'] - $current['system']) : 100000 + abs($row['galaxy'] - $current['galaxy']);
            $key = [-$covered, $distance];
            if ($bestKey === null || $key < $bestKey) {
                $best = $row;
                $bestKey = $key;
            }
        }
        if ($best === null) {
            return null;
        }

        $ship = ($best['s203'] <= 0 && $best['s202'] > 0) ? 202 : 203;
        $needs = $this->cargoNeeds($metal, $crystal, $deuterium);

        return ['id' => (int) $best['id'], 'ships' => $ship . ':' . (int) $needs['needed' . $ship]];
    }

    /**
     * Coordinates of the current planet, the target of the "transport here" link of a watched card.
     *
     * @return array{galaxy: mixed, system: mixed, planet: mixed, planet_type: mixed}
     */
    private function watchTarget(): array
    {
        $planet = $this->state->PLANET;

        return [
            'galaxy' => $planet['galaxy'],
            'system' => $planet['system'],
            'planet' => $planet['planet'],
            'planet_type' => $planet['planet_type'],
        ];
    }
}
