<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use App\Game\Model\FieldProjection;
use App\Game\Model\Planet;
use App\Game\Model\User;
use Exception;

/**
 * Build rules for buildings, research, ships, defense and missiles: prices (with the catch-up and destroy
 * variants), build/research/ship time, requirements and accessibility, affordability, maximum buildable amounts,
 * and the field projection of a building queue. Pure calculations on user/planet state (array or typed
 * Model\User/Planet); the queues themselves are processed in BuildingQueueProcessor, ResearchQueueProcessor and
 * HangarQueueProcessor.
 */
class BuildFunctions
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /** @var array<mixed> */
    public static array $bonusList = [
        'Attack',
        'Defensive',
        'Shield',
        'BuildTime',
        'ResearchTime',
        'ShipTime',
        'DefensiveTime',
        'Resource',
        'Energy',
        'ResourceStorage',
        'ShipStorage',
        'FlyTime',
        'FleetSlots',
        'Planets',
        'SpyPower',
        'Expedition',
        'GateCoolTime',
        'MoreFound',
    ];

    /**
     * @return array<mixed>
     */
    public function getBonusList(): array
    {
        return self::$bonusList;
    }

    /**
     * Resources still missing for an element.
     *
     * @param array<string, mixed> $USER player state array
     * @param array<string, mixed> $PLANET planet state array
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     * @return array<int, float|int> missing amount per resource id (0 when covered)
     * @deprecated use restPriceFor() with typed User/Planet
     */
    public function getRestPrice(array $USER, array $PLANET, int $Element, ?array $elementPrice = null): array
    {
        return $this->restPriceFor($this->models()->user($USER), $this->models()->planet($PLANET), $Element, $elementPrice);
    }

    /**
     * Typed version of getRestPrice(): per resource of the price, how much the planet (or, for non-planet
     * resources, the player) still lacks; compared with the exact stock, like the charge is taken.
     *
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     * @return array<int, float|int> missing amount per resource id (0 when covered)
     */
    public function restPriceFor(User $user, Planet $planet, int $element, ?array $elementPrice = null): array
    {
        if (!isset($elementPrice)) {
            $elementPrice = $this->elementPriceFor($user, $planet, $element);
        }

        $overflow = [];

        foreach ($elementPrice as $resType => $resPrice) {
            $available = $planet->has($resType) ? $planet->count($resType) : $user->level($resType);
            if ($resType == RESOURCE_ENERGY) {
                // energy is a requirement, not a stock: what counts is the surplus (production minus consumption)
                $available += $planet->energyUsed;
            }
            $overflow[$resType] = max($resPrice - $available, 0);
        }

        return $overflow;
    }

    /**
     * Resource price of an element at its current (or the given) level.
     *
     * @param array<string, mixed> $USER player state array
     * @param array<string, mixed> $PLANET planet state array
     * @return array<int, float|int> price per resource id; empty when the element is unknown to player and planet
     * @deprecated use elementPriceFor() with typed User/Planet
     */
    public function getElementPrice(array $USER, array $PLANET, int $Element, bool $forDestroy = false, ?int $forLevel = null): array
    {
        return $this->elementPriceFor($this->models()->user($USER), $this->models()->planet($PLANET), $Element, $forDestroy, $forLevel);
    }

    /**
     * Typed version of getElementPrice(): base cost times factor^(level - 1), reduced by the catch-up factor for
     * buildings and technologies (not for ships, defense, missiles, nor for energy). Ships, defense and missiles
     * are priced for $forLevel units; demolition costs half.
     *
     * Reads the DB through the catch-up factor (statpoints, records).
     *
     * @param bool $forDestroy price of tearing the level down (half price)
     * @param int|null $forLevel level (or unit count) to price instead of the current one
     * @return array<int, float|int> price per resource id; empty when the element is unknown to player and planet
     */
    public function elementPriceFor(User $user, Planet $planet, int $element, bool $forDestroy = false, ?int $forLevel = null): array
    {
        $elements = $this->elements();

        $catchUpFactor = $this->protectionRules()->catchUpFactorFor($user);
        $isHangarUnit = $elements->isHangarUnit($element);
        if ($isHangarUnit) {
            $elementLevel = $forLevel ?? 0;
            $catchUpFactor = 1.0; // no reduction for fleet/defence/missiles
        } elseif (isset($forLevel)) {
            $elementLevel = $forLevel;
        } elseif ($planet->has($element)) {
            $elementLevel = $planet->level($element);
        } elseif ($user->has($element)) {
            $elementLevel = $user->level($element);
        } else {
            return [];
        }
        $catchUpFactor = $this->protectionRules()->getCatchUpFactorForElement($catchUpFactor, $element, $elementLevel);

        $price = [];
        if (!$elements->has($element)) {
            return $price;
        }
        $data = $elements->get($element);
        foreach ($elements->ids('ressources') as $resType) {
            $ressourceAmount = $data->cost->of($resType);

            if ($ressourceAmount == 0) {
                continue;
            }

            if ($resType != RESOURCE_ENERGY) {
                $price[$resType] = $ressourceAmount * $catchUpFactor;
            } else {
                $price[$resType] = $ressourceAmount;
            }

            if ($data->factor != 0 && $data->factor != 1) {
                $price[$resType] *= pow($data->factor, $elementLevel - 1);
            }

            if ($forLevel && $isHangarUnit) {
                $price[$resType] *= $elementLevel;
            }

            if ($forDestroy === true) {
                $price[$resType]    /= 2;
            }
        }

        return $price;
    }

    /**
     * Whether the requirements of an element (tech tree) are met.
     *
     * @param array<string, mixed> $USER player state array
     * @param array<string, mixed> $PLANET planet state array
     * @deprecated use isAccessibleFor() with typed User/Planet
     */
    public function isTechnologieAccessible(array $USER, array $PLANET, int $Element): bool
    {
        return $this->isAccessibleFor($this->models()->user($USER), $this->models()->planet($PLANET), $Element);
    }

    /**
     * Typed version of isTechnologieAccessible(): every required element must reach its level, looked up on the
     * player (technologies) and on the planet (buildings); an element neither of them carries does not block.
     */
    public function isAccessibleFor(User $user, Planet $planet, int $element): bool
    {
        if (!$this->elements()->has($element)) {
            return true;
        }

        foreach ($this->elements()->get($element)->requirements as $reqElement => $eleLevel) {
            if (
                ($user->has($reqElement) && $user->level($reqElement) < $eleLevel)
                || ($planet->has($reqElement) && $planet->level($reqElement) < $eleLevel)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks if an element is enabled in the current universe.
     * Buldings, ships etc are enabled for all universes via table vars.
     * To disable individual elements one can extend the switch cases and check e.g. for an active module.
     *
     * @param integer $elementID
     * @return boolean
     */
    public function isEnabled(int $elementID): bool
    {
        switch ($elementID) {
            case REPAIR_DOCK:
                return $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK);
            default:
                return true;
        }
    }

    /**
     * Construction/research/shipyard time of an element in seconds.
     *
     * @param array<string, mixed> $USER player state array
     * @param array<string, mixed> $PLANET planet state array (research: `<lab>_inter` list of network labs)
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     * @deprecated use buildingTimeFor() with typed User/Planet
     */
    public function getBuildingTime(
        array $USER,
        array $PLANET,
        int $Element,
        ?array $elementPrice = null,
        bool $forDestroy = false,
        ?int $forLevel = null,
        int $maxQueueIndex = 0,
    ): float {
        return $this->buildingTimeFor(
            $this->models()->user($USER),
            $this->models()->planet($PLANET),
            $Element,
            $elementPrice,
            $forDestroy,
            $forLevel,
            $maxQueueIndex,
        );
    }

    /**
     * Typed version of getBuildingTime(). Time = (metal + crystal cost) / (speed * lab/factory levels), floored to
     * seconds, at least the universe's min_build_time.
     *
     * - buildings: robotics and nanite level, counting the first $maxQueueIndex queue entries of the planet
     *   (queued robotics/nanite builds raise, demolitions lower the level);
     * - ships, defense, missiles: shipyard and nanite level;
     * - technologies: laboratory level (sum of the network labs that meet the research's lab requirement, or the
     *   own lab without network) and the university discount.
     * Demolition takes a fixed fraction (1300/3600 of the build time).
     *
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     */
    public function buildingTimeFor(
        User $user,
        Planet $planet,
        int $element,
        ?array $elementPrice = null,
        bool $forDestroy = false,
        ?int $forLevel = null,
        int $maxQueueIndex = 0,
    ): float {
        $elements = $this->elements();
        $config = $this->configs->get($user->universe);

        $time = 0;

        if (!isset($elementPrice)) {
            $elementPrice = $this->elementPriceFor($user, $planet, $element, $forDestroy, $forLevel);
        }

        $elementCost = 0;

        if (isset($elementPrice[RESOURCE_METAL])) {
            $elementCost += $elementPrice[RESOURCE_METAL];
        }

        if (isset($elementPrice[RESOURCE_CRYSTAL])) {
            $elementCost += $elementPrice[RESOURCE_CRYSTAL];
        }

        if ($elements->isBuilding($element)) {
            $time = $this->constructionSeconds($planet, $elementCost, $maxQueueIndex, $config);
        } elseif ($elements->isHangarUnit($element)) {
            $time = $elementCost / ($config->shipyard_speed * (1 + $planet->level(SHIPYARD)))
                * pow(0.5, $planet->level(NANITE_FACTORY));
        } elseif ($elements->isResearch($element)) {
            $time = $this->researchSeconds($planet, $element, $elementCost, $config);
        }

        if ($forDestroy) {
            $time = floor($time * 1300);
        } else {
            $time = floor($time * 3600);
        }

        return max($time, $config->min_build_time);
    }

    /**
     * Seconds (before the 3600 factor) to construct a building: cost / (building speed * (1 + robotics)) * 0.5^nanite.
     * Queued robotics/nanite builds among the first $maxQueueIndex queue entries raise the level, queued demolitions
     * lower it.
     */
    private function constructionSeconds(Planet $planet, int|float $elementCost, int $maxQueueIndex, object $config): int|float
    {
        $maxQueueIndex = max(0, $maxQueueIndex);
        $robo = $planet->level(ROBOT_FACTORY);
        $nani = $planet->level(NANITE_FACTORY);
        $CurrentQueue = $planet->buildingQueue !== '' ? StoredData::decode($planet->buildingQueue) : [];
        if (!empty($CurrentQueue) && count($CurrentQueue) != 0) {
            foreach ($CurrentQueue as $index => $QueueSubArray) {
                if ($index >= $maxQueueIndex) {
                    break;
                }
                if ($QueueSubArray[0] == ROBOT_FACTORY) {
                    if ($QueueSubArray[4] == 'build') {
                        $robo++;
                    } else {
                        $robo--;
                    }
                } elseif ($QueueSubArray[0] == NANITE_FACTORY) {
                    if ($QueueSubArray[4] == 'build') {
                        $nani++;
                    } else {
                        $nani--;
                    }
                }
            }
        }

        return $elementCost / ($config->building_speed * (1 + $robo))
            * pow(0.5, $nani);
    }

    /**
     * Seconds (before the 3600 factor) to research: cost / (1000 * (1 + lab level)) / (research speed / 2500), reduced
     * by the university discount per level. The lab level is the sum of the network labs that meet the research's lab
     * requirement (the own lab without network).
     */
    private function researchSeconds(Planet $planet, int $element, int|float $elementCost, object $config): int|float
    {
        $requiredLab = $this->elements()->has($element)
            ? $this->elements()->get($element)->requiredLevel(RESEARCH_LABORATORY)
            : null;
        if ($planet->labNetwork === null) {
            $Level = $planet->level(RESEARCH_LABORATORY);
        } else {
            $Level = 0;
            foreach ($planet->labNetwork as $Levels) {
                if ($requiredLab === null || $Levels >= $requiredLab) {
                    $Level += $Levels;
                }
            }
        }

        return $elementCost / (1000 * (1 + $Level)) / ($config->research_speed / 2500)
            * pow(1 - $config->factor_university / 100, $planet->level(university));
    }

    /**
     * Whether the planet can pay the element's price right now.
     *
     * @param array<string, mixed> $USER player state array
     * @param array<string, mixed> $PLANET planet state array
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     * @deprecated use isBuyableFor() with typed User/Planet
     */
    public function isElementBuyable(
        array $USER,
        array $PLANET,
        int $Element,
        ?array $elementPrice = null,
    ): bool {
        return $this->isBuyableFor($this->models()->user($USER), $this->models()->planet($PLANET), $Element, $elementPrice);
    }

    /**
     * Typed version of isElementBuyable(): true when restPriceFor() leaves nothing missing.
     *
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     */
    public function isBuyableFor(User $user, Planet $planet, int $element, ?array $elementPrice = null): bool
    {
        $rest = $this->restPriceFor($user, $planet, $element, $elementPrice);
        return count(array_filter($rest)) === 0;
    }

    /**
     * How many units of an element the planet can pay (limited by the scarcest resource, 1 for one-per-planet).
     *
     * @param array<string, mixed> $USER player state array
     * @param array<string, mixed> $PLANET planet state array
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     * @throws Exception when the price contains a resource neither planet nor player carries
     * @deprecated use maxConstructibleFor() with typed User/Planet
     */
    public function getMaxConstructibleElements(array $USER, array $PLANET, int $Element, ?array $elementPrice = null): int
    {
        return $this->maxConstructibleFor($this->models()->user($USER), $this->models()->planet($PLANET), $Element, $elementPrice);
    }

    /**
     * Typed version of getMaxConstructibleElements().
     *
     * @param array<int, float|int>|null $elementPrice price to use instead of the computed one
     * @throws Exception when the price contains a resource neither planet nor player carries
     */
    public function maxConstructibleFor(User $user, Planet $planet, int $element, ?array $elementPrice = null): int
    {
        if (!isset($elementPrice)) {
            $elementPrice = $this->elementPriceFor($user, $planet, $element);
        }

        $maxElement = [];

        foreach ($elementPrice as $resourceID => $price) {
            if ($planet->has($resourceID)) {
                $maxElement[] = floor($planet->count($resourceID) / $price);
            } elseif ($user->has($resourceID)) {
                $maxElement[] = floor($user->level($resourceID) / $price);
            } else {
                throw new Exception("Unknown Ressource " . $resourceID . " at element " . $element . ".");
            }
        }

        if ($this->elements()->isOnePerPlanet($element)) {
            $maxElement[] = 1;
        }

        return min($maxElement);
    }

    /**
     * How many interceptor/interplanetary missiles still fit into the silo.
     *
     * @param array<string, mixed> $PLANET planet state array
     * @param array<int, float|int>|null $Missiles missiles per element id to start from instead of the planet's stock
     * @return array<int, float|int> room for interceptor missiles and (half of it) for interplanetary missiles
     * @deprecated use maxRocketsFor() with a typed Planet
     */
    public function getMaxConstructibleRockets(array $PLANET, ?array $Missiles = null): array
    {
        return $this->maxRocketsFor($this->models()->planet($PLANET), $Missiles);
    }

    /**
     * Typed version of getMaxConstructibleRockets(): capacity is 10 slots per silo level (times the universe silo
     * factor, at least 1); an interplanetary missile takes two slots; missiles in the hangar queue count as built.
     *
     * @param array<int, float|int>|null $Missiles missiles per element id to start from instead of the planet's stock
     * @return array<int, float|int> room for interceptor missiles and (half of it) for interplanetary missiles
     */
    public function maxRocketsFor(Planet $planet, ?array $Missiles = null): array
    {
        if (!isset($Missiles)) {
            $Missiles = [];

            foreach ($this->elements()->ids('missile') as $elementID) {
                $Missiles[$elementID] = $planet->count($elementID);
            }
        }

        $BuildArray = $planet->hangarQueue !== '' ? StoredData::decode($planet->hangarQueue) : [];
        $MaxMissiles = $planet->level(SILO) * 10 * max($this->configs->get()->silo_factor, 1);

        foreach ($BuildArray as $ElementArray) {
            if (isset($Missiles[$ElementArray[0]])) {
                $Missiles[$ElementArray[0]] += $ElementArray[1];
            }
        }

        $ActuMissiles = $Missiles[INTERCEPTOR_MISSILE] + (2 * $Missiles[INTERPLANETARY_MISSILE]);
        $MissilesSpace = max(0, $MaxMissiles - $ActuMissiles);

        return [
            INTERCEPTOR_MISSILE => $MissilesSpace,
            INTERPLANETARY_MISSILE => floor($MissilesSpace / 2),
        ];
    }

    /**
     * Ship category used for sorting/filtering (civil, military, recycler) from `reslist['ship_type']`; ids in none
     * of the lists count as civil.
     * @return string civil|military|recycler
     */
    public function getType(int $Element): string
    {
        $elements = $this->elements();

        if (in_array($Element, $elements->shipTypeIds('civil'))) {
            return "civil";
        } elseif (in_array($Element, $elements->shipTypeIds('military'))) {
            return "military";
        } elseif (in_array($Element, $elements->shipTypeIds('recycler'))) {
            return "recycler";
        }
        return "civil";
    }

    /**
     * Replays the queue entry by entry against the field limit, without
     * changing the planet. Build entries need a free field at their turn (otherwise the projection is invalid and the replay
     * stops); destroy entries free one. A queued terraformer or moon base raises the limit only for the entries after it
     * (a destroyed one takes its bonus away again at once), so the replay tracks the two levels instead of changing a
     * planet copy.
     *
     * @param Planet $planet planet snapshot: occupied fields, field limit, terraformer and moon base levels
     * @param array<int, array<int, mixed>> $queue queue items [elementId, level, time, endTime, mode, cost?]; mode 'destroy' or (default) build
     */
    public function queueFieldProjectionFor(Planet $planet, array $queue): FieldProjection
    {
        $planets = $this->planetQueries();
        $current = $planet->fieldsCurrent;
        $levels = [TERRAFORMER => $planet->count(TERRAFORMER), MOONBASE => $planet->count(MOONBASE)];
        $valid = true;

        foreach ($queue as $item) {
            $element = (int) $item[0];
            $level = (int) $item[1];
            $mode = $item[4] ?? 'build';

            if ($mode === 'destroy') {
                $current--;
                // Destroyed expanders must drop their bonus before later items run.
                if ($element === TERRAFORMER || $element === MOONBASE) {
                    $levels[$element] = max(0, $level - 1);
                }
                continue;
            }

            $max = (int) $planets->maxFieldsFor($planet, $levels[TERRAFORMER], $levels[MOONBASE]);
            if ($current >= $max) {
                $valid = false;
                break;
            }

            $current++;
            if ($element === TERRAFORMER || $element === MOONBASE) {
                $levels[$element] = $level;
            }
        }

        return new FieldProjection(
            $current,
            (int) $planets->maxFieldsFor($planet, $levels[TERRAFORMER], $levels[MOONBASE]),
            $valid,
        );
    }

    /**
     * Whether another building can be queued (or started) without exceeding fields.
     *
     * Queued Terraformers and Moon Bases increase later capacity, but they still
     * need a free field of their own at the point they are built.
     *
     * Array adapter of canQueueOnFieldsFor().
     *
     * @param array<string, mixed> $PLANET
     * @param array<mixed> $queue
     */
    public function canQueueBuildingOnFields(array $PLANET, array $queue, bool $addMode = true): bool
    {
        if (!$addMode) {
            return true;
        }

        return $this->canQueueOnFieldsFor($this->models()->planet($PLANET), $queue);
    }

    /**
     * Typed version of canQueueBuildingOnFields() for adding a building: true when the queue is valid and a field is free after it.
     *
     * @param array<int, array<int, mixed>> $queue current building queue items
     */
    public function canQueueOnFieldsFor(Planet $planet, array $queue): bool
    {
        return $this->queueFieldProjectionFor($planet, $queue)->hasFreeField();
    }
}
