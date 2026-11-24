<?php

namespace App\Game\Core;

use App\Game\Engagement\Events\EventModifiers;
use App\Game\Engagement\Relics\RelicCatalog;
use App\Game\Enum\PlanetType;
use App\Game\Model\Planet;
use App\Game\Model\ProductionResult;
use App\Game\Model\User;

/**
 * Pure production maths of a planet: eco hash, storage/energy/production-per-hour rebuild from the vars-table formulas
 * (via FormulaEvaluator) and the stock integration over a time span.
 *
 * Collaborators: Singleton (game data tables: ProdGrid, resource, reslist), Database (research network lookup only).
 * Works on plain planet/user arrays and returns the changed planet; the only DB access is getNetworkLevel() (read).
 * Used by ResourceUpdate, which owns the planet/user state and decides when to recalculate.
 */
class ResourceProduction
{
    use GameUtilities;

    /** solar factor to rebuild with instead of the current one (the average over the window that is being integrated) */
    public ?float $solarOverride = null;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Fingerprint of every input of the production rebuild (building levels and percentages, basic incomes,
     * multipliers, storage buildings, energy/plasma/... techs). Equal hash means perhour/max/energy are still valid.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @deprecated use hashFor() with typed User/Planet
     */
    public function createHash(array $user, array $planet, Config $config): string
    {
        $resource = $this->elements()->columnMap();

        return $this->hashFor(User::fromState($user, $resource), Planet::fromState($planet, $resource), $config);
    }

    /**
     * Typed version of createHash(). The hash string is identical to the array version (it is stored in
     * `planets.eco_hash` and compared on every update).
     */
    public function hashFor(User $user, Planet $planet, Config $config): string
    {
        $elements = $this->elements();
        $hash = [];
        foreach ($elements->ids('prod') as $id) {
            $hash[] = $planet->level($id);
            $hash[] = $planet->productionRate($id);
        }

        $ressource = array_merge([], $elements->resourceIds(1), $elements->resourceIds(2));
        foreach ($ressource as $id) {
            $hash[] = $config->{$elements->column($id) . '_basic_income'};
        }

        $hash[] = $config->resource_multiplier;
        $hash[] = $config->storage_multiplier;
        $hash[] = $config->energy_multiplier;
        $hash[] = $planet->level(METAL_STORE);
        $hash[] = $planet->level(CRYSTAL_STORE);
        $hash[] = $planet->level(DEUTERIUM_STORE);
        $hash[] = $user->level(ENERGY_TECH);
        $hash[] = $user->level(METAL_PROC_TECH);
        $hash[] = $user->level(CRYSTAL_PROC_TECH);
        $hash[] = $user->level(DEUTERIUM_PROC_TECH);

        // engagement boosts change the stored figures; they join the hash only while active (hash unchanged when off)
        $solar = $this->eventModifiers()->factor($user->universe, EventModifiers::SOLAR);
        if ($solar !== 1.0) {
            $hash[] = 'solar' . $solar;
        }
        $catchUp = $this->catchUpBoost()->percentFor($user->id, $user->universe, $user->registerTime);
        if ($catchUp > 0) {
            $hash[] = 'catchup' . round($catchUp);
        }
        $relics = $this->relicBonus()->signature($user->id, $user->universe, $planet->id);
        if ($relics !== '') {
            $hash[] = $relics;
        }

        return md5(implode('::', $hash));
    }

    /**
     * Recomputes storage capacity (metal/crystal/deuterium _max), energy and energy_used and the hourly production
     * (metal/crystal/deuterium _perhour) of a planet from the building levels, the user's techs and the universe config.
     *
     * Game rules: energy shortage throttles every producer by min(1, energy / energy_used); with no consumers the
     * production is not throttled; the planet resource bonus applies only if config planet_ressource_bonus is set;
     * production techs (131-133) add 2 % per level; consumption (negative production) is never throttled or boosted.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array<string, mixed> the planet with the recomputed fields
     * @throws FormulaException when a stored formula is invalid
     * @deprecated use rebuildFor() with typed User/Planet
     */
    public function rebuild(array $user, array $planet, Config $config): array
    {
        $resource = $this->elements()->columnMap();

        return array_replace(
            $planet,
            $this->rebuildFor(User::fromState($user, $resource), Planet::fromState($planet, $resource), $config),
        );
    }

    /**
     * Typed version of rebuild(): returns only the recomputed planet fields (see rebuild() for the rules), keyed by
     * column name, so the caller can merge them into the state array.
     *
     * @return array<string, float|int> metal_max, crystal_max, deuterium_max, energy, energy_used, metal_perhour,
     *                                  crystal_perhour, deuterium_perhour
     * @throws FormulaException when a stored formula is invalid
     */
    public function rebuildFor(User $user, Planet $planet, Config $config): array
    {
        // Moons get no basic income: produce() returns early for them, the shared config stays untouched.
        $temp = $this->sumBuildings($user, $planet);

        $out = [];
        // relic bonuses of the planet (empty without relics or with the feature off: nothing below changes then)
        $relicBonus = $this->relicBonus()->forPlanet($user->id, $user->universe, $planet->id);
        $relics = $relicBonus['values'];
        $storageFactor = 1 + ($relics[RelicCatalog::STORAGE] ?? 0) / 100;

        $out['metal_max'] = $temp[901]['max'] * $config->storage_multiplier * $storageFactor;
        $out['crystal_max'] = $temp[902]['max'] * $config->storage_multiplier * $storageFactor;
        $out['deuterium_max'] = $temp[903]['max'] * $config->storage_multiplier * $storageFactor;

        // the configured base energy production is added on planets only (moons get no basic income)
        $energyBasic = $planet->isMoon() ? 0 : $config->energy_basic_income * $config->energy_multiplier;
        $out['energy'] = round($temp[911]['plus'] * $config->energy_multiplier * (1 + ($relics[RelicCatalog::ENERGY] ?? 0) / 100) + $energyBasic);
        // both figures are stored rounded (double(50,0)), so the throttle must use the rounded values too
        $out['energy_used'] = round($temp[911]['minus'] * $config->energy_multiplier);
        // Without energy consumers (all mines off) there is nothing to throttle, but the fusion plant still burns its
        // deuterium (like OGame, the reactor runs at its set level regardless of consumers), so production is not zeroed.
        $energyUsed = abs($out['energy_used']);
        $prodLevel = $energyUsed > 0 ? min(1, $out['energy'] / $energyUsed) : 1;

        $metalBonus = 1;
        $crystalBonus = 1;
        $deuteriumBonus = 1;
        // late-joiner boost: mines only (metal/crystal/deuterium), never energy
        $catchUp = 1 + round($this->catchUpBoost()->percentFor($user->id, $user->universe, $user->registerTime)) / 100;
        if ($config->planet_ressource_bonus) {
            $metalBonus = 1 + $planet->metalBonusPercent / 100;
            $crystalBonus = 1 + $planet->crystalBonusPercent / 100;
            $deuteriumBonus = 1 + $planet->deuteriumBonusPercent / 100;
        }

        // relics: mine bonus per resource (+ all resources) on the plus part, fusion deuterium use on the consumption
        $metalBonus *= 1 + ($relicBonus['production'][RelicCatalog::METAL] ?? 0) / 100;
        $crystalBonus *= 1 + ($relicBonus['production'][RelicCatalog::CRYSTAL] ?? 0) / 100;
        $deuteriumBonus *= 1 + ($relicBonus['production'][RelicCatalog::DEUTERIUM] ?? 0) / 100;
        $deuteriumUse = $temp[903]['minus'] * (1 - ($relics[RelicCatalog::FUSION] ?? 0) / 100);

        // $temp[x]['plus'] regular growth, (1 + 0.02 * tech) maximiser, $prodLevel energy factor on deficit,
        // $temp[x]['minus'] consumption (deuterium), resource_multiplier economy speed
        $out['metal_perhour'] = (
            ($temp[901]['plus'] * $metalBonus) * (1 + 0.02 * $user->level(METAL_PROC_TECH)) * $prodLevel * $catchUp + $temp[901]['minus']
        ) * ($config->resource_multiplier / 2500);

        $out['crystal_perhour'] = (
            ($temp[902]['plus'] * $crystalBonus) * (1 + 0.02 * $user->level(CRYSTAL_PROC_TECH)) * $prodLevel * $catchUp + $temp[902]['minus']
        ) * ($config->resource_multiplier / 2500);

        $out['deuterium_perhour'] = (
            ($temp[903]['plus'] * $deuteriumBonus) * (1 + 0.02 * $user->level(DEUTERIUM_PROC_TECH)) * $prodLevel * $catchUp + $deuteriumUse
        ) * ($config->resource_multiplier / 2500);

        return $out;
    }

    /**
     * Integrates the stock over $productionTime seconds (basic income + perhour, capped by storage * max_overflow,
     * consumption floors at 0). Moons (type 3) produce nothing.
     *
     * Array adapter of produceFor(): applies the result to the array (stock += delta) and extends $builded by the same deltas.
     *
     * @param array<string, mixed> $planet
     * @param array<int, float|int> $builded running delta per resource id, extended by the produced amounts
     * @return array<string, mixed> the planet with the new stock
     */
    public function produce(array $planet, int|float $productionTime, Config $config, array &$builded): array
    {
        $resource = $this->elements()->columnMap();
        if ($planet['planet_type'] == PlanetType::Moon->value) {
            return $planet;
        }

        $result = $this->produceFor(Planet::fromState($planet, $resource), $productionTime, $config);
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
            $delta = $result->delta($resourceId);
            $builded[$resourceId] += $delta;
            $planet[$resource[$resourceId]] += $delta;
        }

        return $planet;
    }

    /**
     * Typed, pure version of produce(): what a planet's production adds to its stock over $productionTime seconds. Reads only
     * the planet snapshot and the universe config; changes nothing.
     *
     * Rules: per resource the theoretical production is seconds * (basic income * resource_multiplier / 2500 + production per
     * hour) / 3600; consumption (negative) never takes the stock below 0; production fills the stock only up to
     * storage * max_overflow; a stock that is already above that limit stays as it is. Moons produce nothing.
     *
     * @param Planet $planet planet snapshot: stocks, production per hour, storage (the planet's energy figures are passed through)
     * @param int|float $productionTime elapsed seconds since the last update
     */
    public function produceFor(Planet $planet, int|float $productionTime, Config $config): ProductionResult
    {
        if ($planet->isMoon()) {
            return new ProductionResult(0, 0, 0, $planet->metal, $planet->crystal, $planet->deuterium, $planet->energy, $planet->energyUsed);
        }

        $metalDelta = $this->cappedProduction(
            $planet->metal,
            $productionTime * (($config->metal_basic_income * ($config->resource_multiplier / 2500)) + $planet->metalPerHour) / 3600,
            $planet->metalMax * $config->max_overflow,
        );
        $crystalDelta = $this->cappedProduction(
            $planet->crystal,
            $productionTime * (($config->crystal_basic_income * ($config->resource_multiplier / 2500)) + $planet->crystalPerHour) / 3600,
            $planet->crystalMax * $config->max_overflow,
        );
        $deuteriumDelta = $this->cappedProduction(
            $planet->deuterium,
            $productionTime * (($config->deuterium_basic_income * ($config->resource_multiplier / 2500)) + $planet->deuteriumPerHour) / 3600,
            $planet->deuteriumMax * $config->max_overflow,
        );

        return new ProductionResult(
            $metalDelta,
            $crystalDelta,
            $deuteriumDelta,
            $planet->metal + $metalDelta,
            $planet->crystal + $crystalDelta,
            $planet->deuterium + $deuteriumDelta,
            $planet->energy,
            $planet->energyUsed,
        );
    }

    /**
     * Levels of the research labs that count for a research on $planet: the planet's own lab plus the highest labs of
     * the user's other planets, as many as the intergalactic research network tech (123) allows.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return list<int|string>
     * @deprecated use networkLevelFor() with typed User/Planet
     */
    public function getNetworkLevel(array $user, array $planet): array
    {
        $resource = $this->elements()->columnMap();

        return $this->networkLevelFor(User::fromState($user, $resource), Planet::fromState($planet, $resource));
    }

    /**
     * Typed version of getNetworkLevel(). Reads the DB (the user's other planets, best labs first).
     *
     * @return list<int>
     */
    public function networkLevelFor(User $user, Planet $planet): array
    {
        $labColumn = $this->elements()->column(RESEARCH_LABORATORY);

        $researchLevelList = [$planet->level(RESEARCH_LABORATORY)];
        if ($user->level(INTERGALACTIC_TECH) > 0) {
            $sql = 'SELECT ' . $labColumn
                    . ' FROM %%PLANETS%% WHERE id != :planetId AND id_owner = :userId AND destruyed = 0 ORDER BY '
                    . $labColumn . ' DESC LIMIT :limit;';

            $researchResult = $this->db->select($sql, [
                ':limit'    => $user->level(INTERGALACTIC_TECH),
                ':planetId' => $planet->id,
                ':userId'   => $user->id,
            ]);

            foreach ($researchResult as $researchRow) {
                $researchLevelList[] = (int) $researchRow[$labColumn];
            }
        }

        return $researchLevelList;
    }

    /**
     * Evaluates a production/storage formula of the vars table with the four variables formulas may use
     * (see FormulaEvaluator for the grammar; compiled once per process, no eval()).
     *
     * @throws FormulaException when the stored formula is invalid
     */
    private function runFormula(string $formula, mixed $BuildLevel, mixed $BuildTemp, mixed $BuildEnergy, mixed $BuildLevelFactor): mixed
    {
        return FormulaEvaluator::evaluate($formula, compact('BuildLevel', 'BuildTemp', 'BuildEnergy', 'BuildLevelFactor'));
    }

    /**
     * Sums the storage capacity per resource and the production/consumption per resource and energy over all
     * storage and production buildings.
     *
     * @param User $user
     * @param Planet $planet
     * @return array<int, array<string, float|int>> keyed by 901/902/903 (max, plus, minus) and 911 (plus, minus)
     */
    private function sumBuildings(User $user, Planet $planet): array
    {
        $elements = $this->elements();

        $temp = [
            901 => ['max' => 0, 'plus' => 0, 'minus' => 0],
            902 => ['max' => 0, 'plus' => 0, 'minus' => 0],
            903 => ['max' => 0, 'plus' => 0, 'minus' => 0],
            911 => ['plus' => 0, 'minus' => 0],
        ];

        /* Data for the formulas */
        $BuildTemp = $planet->tempMax;
        $BuildEnergy = $user->level(ENERGY_TECH);

        foreach ($elements->ids('storage') as $ProdID) {
            foreach ($elements->resourceIds(1) as $ID) {
                $storageFormula = $elements->get($ProdID)->storageFormula($ID);
                if ($storageFormula === null) {
                    continue;
                }

                $BuildLevel = $planet->level($ProdID);
                $temp[$ID]['max'] += round($this->runFormula(
                    $storageFormula,
                    $BuildLevel,
                    $BuildTemp,
                    $BuildEnergy,
                    null,
                ));
            }
        }

        $ressIDs = array_merge([], $elements->resourceIds(1), $elements->resourceIds(2));
        $solar = $this->solarOverride ?? $this->eventModifiers()->factor($user->universe, EventModifiers::SOLAR);

        foreach ($elements->ids('prod') as $ProdID) {
            $BuildLevelFactor = $planet->productionRate($ProdID);
            $BuildLevel = $planet->level($ProdID);

            foreach ($ressIDs as $ID) {
                $productionFormula = $elements->get($ProdID)->productionFormula($ID);
                if ($productionFormula === null) {
                    continue;
                }

                $Production = $this->runFormula(
                    $productionFormula,
                    $BuildLevel,
                    $BuildTemp,
                    $BuildEnergy,
                    $BuildLevelFactor,
                );

                if ($Production > 0) {
                    if ($ID == RESOURCE_ENERGY && in_array((int) $ProdID, [solar_plant, SHIP_SOLSAT], true)) {
                        $Production *= $solar; // solar plant / satellite, universe event
                    }
                    $temp[$ID]['plus'] += $Production;
                } else {
                    // Consumption (fusion plant deuterium) is always counted, even at a stock of 0: the stock is not part
                    // of the eco hash, so skipping it here froze a wrong production until the next hash change. A
                    // negative net production simply keeps the stock at 0 (see addProduction).
                    $temp[$ID]['minus'] += $Production;
                }
            }
        }

        return $temp;
    }

    /**
     * The amount of $produced that really changes a stock: consumption never takes the stock below 0 (it empties it exactly),
     * production never pushes it above $storage, and a stock already above storage stays as it is (delta 0).
     */
    private function cappedProduction(int|float $stock, int|float $produced, int|float $storage): int|float
    {
        if ($produced < 0) {
            if ($stock + $produced < 0) {
                // consumption larger than the stock empties it exactly (was 1 + the fraction left over)
                $produced = -$stock;
            }
        } elseif ($stock <= $storage) {
            if ($stock + $produced > $storage) {
                $produced = $storage - $stock;
            }
        } else {
            $produced = 0;
        }

        return $produced;
    }
}
