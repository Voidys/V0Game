<?php

namespace App\Game\Core;

use App\Game\Engagement\Events\EventModifiers;
use App\Game\Model\User;

/**
 * Pure fleet mathematics: distance between coordinates, ship speed per drive tech, fleet speed (slowest ship), flight
 * duration, fuel consumption, cargo room, fleet slots, expedition limit, missile/phalanx ranges, missile flight time.
 *
 * Collaborators: ConfigRegistry (universe geometry and speed factors), Singleton (pricelist/resource tables),
 * HonorPoints (honor point fuel boost). No DB writes. The formulas are mirrored in the client JS
 * (scripts/game/flotten.js) and pinned by FleetFunctionsTest goldens.
 */
class FleetMath
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Fuel consumption per distance unit of one ship type for the player's drive technologies, reduced by
     * 2 % per fuel efficiency level. The drive that applies is the highest one whose tech requirement is met.
     */
    private function getShipConsumption(int $Ship, User $Player): float
    {
        $ship = $this->elements()->get($Ship);

        if (($ship->speedTech(3) > 1 && $Player->level(HYPERSPACE_MOTOR_TECH) >= $ship->speedTech(3))
            || $ship->speedTech(3) == 1) {
            $consumption = $ship->consumption(3);
        } elseif (($ship->speedTech(2) > 1 && $Player->level(IMPULSE_MOTOR_TECH) >= $ship->speedTech(2))
            || $ship->speedTech(2) == 1) {
            $consumption = $ship->consumption(2);
        } else {
            $consumption = $ship->consumption(1);
        }

        $consumption *= (1 - (0.02 * $Player->level(FUEL_EFFICIENCY_TECH)));

        return $consumption;
    }

    /**
     * Speed of one ship type: base speed of the best available drive, multiplied by that drive tech (hyperspace +30 %,
     * impulse +20 %, combustion +10 % per level).
     */
    private function getShipSpeed(int $Ship, User $Player): int
    {
        $ship = $this->elements()->get($Ship);

        if (($ship->speedTech(3) > 1 && $Player->level(HYPERSPACE_MOTOR_TECH) >= $ship->speedTech(3))
            || $ship->speedTech(3) == 1) {
            $base_speed = $ship->speed(3);
            $speed = $base_speed * (1 + (0.3 * $Player->level(HYPERSPACE_MOTOR_TECH)));
        } elseif (($ship->speedTech(2) > 1 && $Player->level(IMPULSE_MOTOR_TECH) >= $ship->speedTech(2))
            || $ship->speedTech(2) == 1) {
            $base_speed = $ship->speed(2);
            $speed = $base_speed * (1 + (0.2 * $Player->level(IMPULSE_MOTOR_TECH)));
        } else {
            $base_speed = $ship->speed(1);
            $speed = $base_speed * (1 + (0.1 * $Player->level(COMBUSTION_TECH)));
        }

        return $speed;
    }

    /**
     * Number of simultaneous expeditions: floor(sqrt(expedition tech level)).
     *
     * @param array<string, mixed> $USER player state array
     * @deprecated use expeditionLimitFor() with a typed User
     */
    public function getExpeditionLimit(array $USER): int
    {
        return $this->expeditionLimitFor($this->models()->user($USER));
    }

    /** Typed version of getExpeditionLimit(). */
    public function expeditionLimitFor(User $user): int
    {
        return floor(sqrt($user->level(EXPEDITION_TECH)));
    }

    /**
     * Whether the target planet is inside the missile range of the source planet (ion engine level based; wraps
     * around in circular galaxies, galaxy_type 1).
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $target
     */
    public function inMissileRange(array $planet, array $target, int $levelIonEngine): bool
    {
        if ($target['galaxy'] != $planet['galaxy']) {
            return false;
        }
        $config = $this->configs->get();
        $Range = max(floor((($levelIonEngine * 5) - 1) * $config->missile_range_factor), 0);

        if ($config->galaxy_type == 2) {
            return $Range > 1;
        } elseif ($config->galaxy_type == 1) {
            $max = $config->max_system;
            $thisSystem = $planet['system'];
            $toSystem = $target['system'];

            if ($thisSystem + $Range > $max) {
                $systemMax = $thisSystem + $Range - $max;
            } else {
                $systemMax = $thisSystem + $Range;
            }

            if ($thisSystem - $Range < 1) {
                $systemMin = $max + ($thisSystem - $Range);
            } else {
                $systemMin = $thisSystem - $Range;
            }

            if ($Range * 2 >= $max) {
                return true;
            } elseif ($systemMin <= $systemMax) {
                return $toSystem >= $systemMin && $toSystem <= $systemMax;
            } else {
                return ($toSystem >= $systemMin && $toSystem <= $max) || ($toSystem >= 1 && $toSystem <= $systemMax);
            }
        } else {
            $systemMin = $planet['system'] - $Range;
            $systemMax = $planet['system'] + $Range;

            return $target['system'] >= $systemMin && $target['system'] <= $systemMax;
        }
    }

    /**
     * True if the speed percentage chosen in the fleet form (10..100 in steps of 10) is one of FleetFunctions::$allowedSpeed.
     */
    public function checkUserSpeed(int $speed): bool
    {
        return isset(FleetFunctions::$allowedSpeed[$speed]);
    }

    /**
     * Calculates the distance to the target
     *
     * Changes needs to be done in scripts/game/flotten.js#GetDistance() aswell
     *
     * @param array<mixed> $start
     * @param array<string, mixed> $target
     * @return int
     */
    public function getTargetDistance(array $start, array $target): int
    {
        $thisGalaxy = $start[0];
        $thisSystem = $start[1];
        $thisPlanet = $start[2];
        $targetGalaxy = $target[0];
        $targetSystem = $target[1];
        $targetPlanet = $target[2];
        $config = $this->configs->get();

        if ($thisGalaxy != $targetGalaxy) {
            if ($config->uni_type == 2) {
                return $config->galaxy_distance_factor;
            } elseif ($config->uni_type == 1) {
                $max = $config->max_galaxy;
                $dist = 0;

                if (abs($thisGalaxy - $targetGalaxy) < ceil($max / 2)) {
                    $dist = abs($thisGalaxy - $targetGalaxy);
                } else {
                    $dist = floor($max / 2) - (abs($thisGalaxy - $targetGalaxy) - ceil($max / 2));
                }
                return $dist * $config->galaxy_distance_factor;
            } else {
                return abs($thisGalaxy - $targetGalaxy) * $config->galaxy_distance_factor;
            }
        } elseif ($thisSystem != $targetSystem) {
            if ($config->galaxy_type == 2) {
                return $config->system_distance_factor + $config->system_distance;
            } elseif ($config->galaxy_type == 1) {
                $max = $config->max_system;
                $dist = 0;

                if (abs($thisSystem - $targetSystem) < ceil($max / 2)) {
                    $dist = abs($thisSystem - $targetSystem);
                } else {
                    $dist = floor($max / 2) - (abs($thisSystem - $targetSystem) - ceil($max / 2));
                }
                return $dist * $config->system_distance_factor + $config->system_distance;
            } else {
                return abs($thisSystem - $targetSystem) * $config->system_distance_factor + $config->system_distance;
            }
        } elseif ($thisPlanet != $targetPlanet) {
            return abs($thisPlanet - $targetPlanet) * $config->planet_distance_factor + $config->planet_distance;
        }
        return 5;
    }

    /**
     * Travel time in seconds.
     * Keep scripts/game/flotten.js#GetRawDuration() and the duration helper in public/scripts/game in sync.
     *
     * @param array<string, mixed> $USER
     */
    public function getMissionDuration(int $SpeedFactor, int $MaxFleetSpeed, int $Distance, int|float $GameSpeed, array $USER): int
    {
        return $this->missionDurationFor($SpeedFactor, $MaxFleetSpeed, $Distance, $GameSpeed, $this->models()->user($USER));
    }

    /**
     * Typed version of getMissionDuration(): the player's FlyTime bonus factor (e.g. -0.1 = 10 % faster) scales the
     * duration; never below MIN_FLEET_TIME.
     *
     * @throws \DomainException when the chosen speed setting or the fleet's top speed is not positive (the formula divides by both)
     */
    public function missionDurationFor(int $SpeedFactor, int $MaxFleetSpeed, int $Distance, int|float $GameSpeed, User $user): int
    {
        if ($SpeedFactor <= 0 || $MaxFleetSpeed <= 0) {
            throw new \DomainException(sprintf('Flight duration needs a positive speed setting and fleet speed, got %d and %d.', $SpeedFactor, $MaxFleetSpeed));
        }

        $duration = (3500 / ($SpeedFactor * 0.1));
        $duration *= pow($Distance * 10 / $MaxFleetSpeed, 0.5);
        $duration += 10;
        $duration /= $GameSpeed;

        $flyTime = $user->factor('FlyTime');
        if ($flyTime !== null) {
            $duration *= max(0, 1 + $flyTime);
        }

        return floor(max($duration, MIN_FLEET_TIME));
    }

    /**
     * Flight time in seconds of an interplanetary missile between two systems of one galaxy: (30 + 60 * system
     * distance) / game speed, at least MIN_FLEET_TIME. The system distance wraps in circular galaxies
     * (galaxy_type 1) and is fixed at 2 in galaxy_type 2.
     */
    public function getMIPDuration(int $startSystem, int $targetSystem): int
    {
        $config = $this->configs->get();
        if ($config->galaxy_type == 2) {
            $Distance = 2;
        } elseif ($config->galaxy_type == 1) {
            $max = $config->max_system;
            if (abs($startSystem - $targetSystem) < ceil($max / 2)) {
                $Distance = abs($startSystem - $targetSystem);
            } else {
                $Distance = floor($max / 2) - (abs($startSystem - $targetSystem) - ceil($max / 2));
            }
        } else {
            $Distance = abs($startSystem - $targetSystem);
        }

        $Duration = max(round((30 + 60 * $Distance) / $this->getGameSpeedFactor()), MIN_FLEET_TIME);

        return floor($Duration);
    }

    /**
     * Fleet speed multiplier of the universe: config fleet_speed / 2500 (2500 = normal speed), times the factor of the
     * running universe events (EventModifiers::FLEET_SPEED, 1.0 when none). Fleets already flying keep their times.
     */
    public function getGameSpeedFactor(): int|float
    {
        $config = $this->configs->get();
        $factor = $config->fleet_speed / 2500;
        $event = $this->eventModifiers()->factor((int) $config->uni, EventModifiers::FLEET_SPEED);

        return $event === 1.0 ? $factor : $factor * $event;
    }

    /**
     * Fleet slots of a player: 1 + computer tech level.
     *
     * @param array<string, mixed> $USER player state array
     * @deprecated use maxFleetSlotsFor() with a typed User
     */
    public function getMaxFleetSlots(array $USER): int
    {
        return $this->maxFleetSlotsFor($this->models()->user($USER));
    }

    /** Typed version of getMaxFleetSlots(). */
    public function maxFleetSlotsFor(User $user): int
    {
        return 1 + $user->level(COMPUTER_TECH);
    }

    /**
     * Total cargo capacity of a fleet (sum of pricelist capacity * amount; fleet tech does not change it).
     * @param array<int,int|float> $Fleet ship id => amount.
     */
    public function getFleetRoom(array $Fleet): int
    {
        $elements = $this->elements();
        $FleetRoom = 0;
        foreach ($Fleet as $ShipID => $amount) {
            $FleetRoom += ($elements->has((int) $ShipID) ? $elements->capacity((int) $ShipID) : 0.0) * $amount;
        }
        return floor($FleetRoom);
    }

    /**
     * Speed of the slowest ship of the fleet (a single ship id counts as one ship of that type); 0 for an empty fleet.
     *
     * @param array<mixed> $Fleets
     * @param array<string, mixed> $Player
     */
    public function getFleetMaxSpeed(array|int $Fleets, array $Player): int
    {
        return $this->fleetMaxSpeedFor($Fleets, $this->models()->user($Player));
    }

    /**
     * Typed version of getFleetMaxSpeed().
     *
     * @param array<int, mixed>|int $Fleets ship id => count, or a single ship id
     */
    public function fleetMaxSpeedFor(array|int $Fleets, User $Player): int
    {
        if (empty($Fleets)) {
            return 0;
        }

        $FleetArray = (!is_array($Fleets)) ? [$Fleets => 1] : $Fleets;
        $speedalls = [];

        foreach ($FleetArray as $ShipID => $Count) {
            $speedalls[$ShipID] = $this->getShipSpeed($ShipID, $Player);
        }

        return min($speedalls);
    }

    /**
     * Deuterium consumption of the whole fleet for the given duration/distance (including the honor point boost, plus 1).
     *
     * @param array<mixed> $FleetArray
     * @param array<string, mixed> $Player
     */
    public function getFleetConsumption(array $FleetArray, int $MissionDuration, int $MissionDistance, array $Player, int|float $GameSpeed, ?int $startPlanetId = null): float
    {
        return $this->fleetConsumptionFor($FleetArray, $MissionDuration, $MissionDistance, $this->models()->user($Player), $GameSpeed, null, $startPlanetId);
    }

    /**
     * Typed version of getFleetConsumption(). Reads the player's honor points from the DB (fuel boost).
     *
     * @param array<int, mixed> $FleetArray ship id => count
     * @param float|null $honorBoost the player's honor fuel factor when the caller already knows it (saves the DB reads)
     * @param int|null $startPlanetId planet the fleet starts from: its relic fuel bonus reduces the consumption (null = none)
     */
    public function fleetConsumptionFor(array $FleetArray, int $MissionDuration, int $MissionDistance, User $Player, int|float $GameSpeed, ?float $honorBoost = null, ?int $startPlanetId = null): float
    {
        $consumption = 0;

        foreach ($FleetArray as $ShipID => $Count) {
            $ShipSpeed = $this->getShipSpeed($ShipID, $Player);
            $ShipConsumption = $this->getShipConsumption($ShipID, $Player);

            $spd = 35000 / max($MissionDuration * $GameSpeed - 10, 1) * sqrt($MissionDistance * 10 / $ShipSpeed);
            $basicConsumption = $ShipConsumption * $Count;
            $consumption += $basicConsumption * $MissionDistance / 35000 * (($spd / 10) + 1) * (($spd / 10) + 1);
        }
        $relicFuel = $startPlanetId === null ? 1.0 : $this->relicBonus()->reductionFactor($Player->id, $Player->universe, $startPlanetId, \App\Game\Engagement\Relics\RelicCatalog::FUEL);

        return (round($consumption * ($honorBoost ?? $this->honorPoints()->calculate_honorpoints_boost($Player->id)) * $relicFuel) + 1);
    }

    /**
     * @param array<mixed> $FleetArray
     * @param array<string, mixed> $Player
     * @return array<mixed>
     */
    public function getFleetShipInfo(array $FleetArray, array $Player): array
    {
        return $this->fleetShipInfoFor($FleetArray, $this->models()->user($Player));
    }

    /**
     * Typed version of getFleetShipInfo().
     *
     * @param array<int, mixed> $FleetArray ship id => count
     * @return array<int, array{consumption: float, speed: int, amount: string}> per ship id
     */
    public function fleetShipInfoFor(array $FleetArray, User $Player): array
    {
        $FleetInfo = [];
        foreach ($FleetArray as $ShipID => $Amount) {
            $FleetInfo[$ShipID] = [
                'consumption' => $this->getShipConsumption($ShipID, $Player),
                'speed' => $this->fleetMaxSpeedFor($ShipID, $Player),
                'amount' => floatToString($Amount),
            ];
        }
        return $FleetInfo;
    }

    public function getPhalanxRange($PhalanxLevel)
    {
        $config = $this->configs->get();
        return ($PhalanxLevel == 1) ? 0 : floor((pow($PhalanxLevel, 2) - 1) * $config->phalanx_range_factor);
    }
}
