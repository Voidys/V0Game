<?php

namespace App\Game\Core;

use App\Game\Enum\Mission;

/**
 * Which missions a fleet may fly to a target (module switches, target ownership and type, ship composition) and the
 * phalanx scan rules of the galaxy view.
 *
 * Collaborators: ViewHelpers::isModuleAvailable (module switches), ConfigRegistry, Singleton (reslist, current
 * user/planet), ProtectionRules (noob protection), FleetMath (phalanx range). Read-only, no DB writes.
 */
class FleetMissionRules
{
    use \App\Game\Core\GameUtilities;

    /** Hold durations (hours) the fleet page offers for the hold mission; the largest one also caps the stay at an Alliance Depot. */
    public const HOLD_STAY_HOURS = [1, 2, 4, 8, 12, 16, 32];

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Mission selector data for the fleet page: available missions plus the stay-time options of expedition / hold /
     * trade missions (expedition hours are divided by the universe's expo_hold_multiplier).
     *
     * @param array<string, mixed> $USER flying user
     * @param array<mixed> $MisInfo target info (planet, planettype, Ship, IsAKS)
     * @param array<mixed> $Planet target planet row (may be empty)
     * @return array{MissionSelector: array, StayBlock: array, Exchange: bool}
     */
    public function getFleetMissions(array $USER, array $MisInfo, array $Planet): array
    {
        $elements = $this->elements();
        $Missions = $this->getAvailableMissions($USER, $MisInfo, $Planet);
        $stayBlock = [];
        $exchange = false;

        $haltSpeed = $this->configs->get($USER['universe'])->expo_hold_multiplier;

        if (in_array(Mission::Expedition->value, $Missions)) {
            for ($i = 1; $i <= $USER[$elements->column(124)]; $i++) {
                $stayBlock[$i] = round($i / $haltSpeed, 2);
            }
        } elseif (in_array(Mission::Hold->value, $Missions)) {
            $stayBlock = array_combine(self::HOLD_STAY_HOURS, self::HOLD_STAY_HOURS);
        } elseif (in_array(Mission::Trade->value, $Missions)) {
            $stayBlock = [1 => 1, 2 => 2, 4 => 4, 8 => 8, 12 => 12, 24 => 24, 48 => 48, 168 => 168];
            $exchange = true;
        }

        return ['MissionSelector' => $Missions, 'StayBlock' => $stayBlock, 'Exchange' => $exchange];
    }

    /**
     * @param array<mixed> $Ships
     */
    private function onlyShipByID(array $Ships, int $ShipID): bool
    {
        return isset($Ships[$ShipID]) && count($Ships) === 1;
    }

    /**
     * Mission ids the fleet may fly to the target. Rules depend on the target position (expedition, trade, pirate base
     * slots beyond max_planets), debris fields (recycling), ACS invitations, free vs. used planets, own vs. foreign
     * planets, spy-probe-only fleets, destroy-capable ships on moons, and on every mission's module switch.
     *
     * @param array<string, mixed> $USER flying user
     * @param array<mixed> $MissionInfo target info (planet, planettype, Ship [shipId => count], IsAKS)
     * @param array<mixed> $GetInfoPlanet target planet row (may be empty)
     * @return list<int>
     */
    public function getAvailableMissions(array $USER, array $MissionInfo, array $GetInfoPlanet): array
    {
        $view = $this->viewHelpers();
        if (!empty($GetInfoPlanet['npc_kind'])) {
            return $this->npcMissions($GetInfoPlanet, $MissionInfo);
        }
        $YourPlanet = (!empty($GetInfoPlanet['id_owner']) && $GetInfoPlanet['id_owner'] == $USER['id']) ? true : false;
        $UsedPlanet = (!empty($GetInfoPlanet['id_owner'])) ? true : false;
        $ExpeditionPosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 1)) ? true : false;
        $TradePosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 2)) ? true : false;
        $PirateBasePosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 3)) ? true : false;
        $availableMissions = [];

        if ($ExpeditionPosition && $view->isModuleAvailable(MODULE_MISSION_EXPEDITION)) {
            $availableMissions[] = Mission::Expedition->value;
        } elseif ($TradePosition && $view->isModuleAvailable(MODULE_MISSION_TRADE)) {
            $availableMissions[] = Mission::Trade->value;
        } elseif ($PirateBasePosition && $view->isModuleAvailable(MODULE_PIRATE_BASES) && $MissionInfo['planettype'] != 2) {
            $availableMissions = $this->pirateBaseMissions($MissionInfo, $YourPlanet);
        } elseif ($MissionInfo['planettype'] == 2 && $view->isModuleAvailable(MODULE_MISSION_RECYCLE)) {
            $availableMissions = $this->recyclingMissions($MissionInfo, $GetInfoPlanet, $UsedPlanet, $YourPlanet, $PirateBasePosition);
        } elseif (
            !empty($MissionInfo['IsAKS']) && !$YourPlanet && $UsedPlanet
            && ($view->isModuleAvailable(MODULE_MISSION_ATTACK) || $view->isModuleAvailable(MODULE_MISSION_ACS))
        ) {
            if ($view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                $availableMissions[] = Mission::Attack->value;
            }
            if ($view->isModuleAvailable(MODULE_MISSION_ACS)) {
                $availableMissions[] = Mission::Acs->value;
            }
        } elseif ($ExpeditionPosition || $TradePosition || $PirateBasePosition) {
            // the special slot's module is off: it is not an ordinary (colonisable) planet slot
            $availableMissions = [];
        } else {
            $availableMissions = $this->planetMissions($MissionInfo, $UsedPlanet, $YourPlanet);
        }

        return $availableMissions;
    }

    /**
     * Missions to an NPC target of the map content features: recycling on a derelict field (debris, recyclers needed), on the
     * pirate mothership (a planet-like target) spying, the attack mission, or the ACS mission of a joining fleet (the lead flies the
     * attack mission and opens the combat group; the group size is checked when it arrives).
     *
     * @param array<mixed> $target NPC row from NpcTargets (npc_kind 1 = derelict, 2 = mothership)
     * @param array<mixed> $MissionInfo
     * @return list<int>
     */
    private function npcMissions(array $target, array $MissionInfo): array
    {
        $view = $this->viewHelpers();
        $missions = [];
        if ((int) $target['npc_kind'] === \App\Game\Engagement\MapContent\NpcTargets::KIND_DERELICT) {
            if ($MissionInfo['planettype'] == 2 && $view->isModuleAvailable(MODULE_MISSION_RECYCLE) && $this->hasRecycler($MissionInfo['Ship'])) {
                $missions[] = Mission::PiratebaseRecycling->value;
            }

            return $missions;
        }
        if ($MissionInfo['planettype'] != 1) {
            return $missions;
        }
        if (!empty($MissionInfo['IsAKS'])) {
            return $view->isModuleAvailable(MODULE_MISSION_ACS) ? [Mission::Acs->value] : [];
        }
        if ($view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
            $missions[] = Mission::PiratebaseAttack->value;
        }
        if ($this->onlyShipByID($MissionInfo['Ship'], 210) && $view->isModuleAvailable(MODULE_MISSION_SPY)) {
            $missions[] = Mission::PiratebaseSpy->value;
        }

        return $missions;
    }

    /**
     * @param array<mixed> $ships ship id => count
     */
    private function hasRecycler(array $ships): bool
    {
        foreach ($ships as $shipId => $count) {
            if ($count > 0 && $this->elements()->isShipType((int) $shipId, SHIP_TYPE_RECYCLER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Missions to a pirate base slot (not a debris field): attack on own, ACS, spy with a single probe type.
     *
     * @param array<mixed> $MissionInfo
     * @return list<int>
     */
    private function pirateBaseMissions(array $MissionInfo, bool $YourPlanet): array
    {
        $view = $this->viewHelpers();
        $availableMissions = [];

        if ($YourPlanet && $view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
            $availableMissions[] = Mission::PiratebaseAttack->value;
        }
        if (!empty($MissionInfo['IsAKS']) && $view->isModuleAvailable(MODULE_MISSION_ACS)) {
            $availableMissions[] = Mission::Acs->value;
        }
        if ($YourPlanet && $this->onlyShipByID($MissionInfo['Ship'], 210) && $view->isModuleAvailable(MODULE_MISSION_SPY)) {
            $availableMissions[] = Mission::PiratebaseSpy->value;
        }

        return $availableMissions;
    }

    /**
     * Recycling missions to a debris field: needs a recycler in the fleet and a used target with debris
     * (or an active wreckfield), or an own pirate base.
     *
     * @param array<mixed> $GetInfoPlanet
     * @param array<mixed> $MissionInfo
     * @return list<int>
     */
    private function recyclingMissions(array $MissionInfo, array $GetInfoPlanet, bool $UsedPlanet, bool $YourPlanet, bool $PirateBasePosition): array
    {
        $elements = $this->elements();
        $availableMissions = [];

        $recs = false;
        foreach ($MissionInfo['Ship'] as $ShipID => $ShipCount) {
            if ($elements->isShipType((int) $ShipID, SHIP_TYPE_RECYCLER) && $ShipCount > 0) {
                $recs = true;
                break;
            }
        }
        if ($recs && $UsedPlanet && !empty($GetInfoPlanet)) {
            if ($PirateBasePosition && $YourPlanet && $this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
                $availableMissions[] = Mission::PiratebaseRecycling->value;
            } elseif (($GetInfoPlanet['der_metal'] + $GetInfoPlanet['der_crystal']) > 0 || $GetInfoPlanet['tf_active'] == 1) {
                $availableMissions[] = Mission::Recycling->value;
            }
        }

        return $availableMissions;
    }

    /**
     * Missions to a regular planet or moon: colonisation of free planets; transport, spy, transfer, attack, hold,
     * station and destruction (moons, with a Deathstar/Void Titan) for used ones.
     *
     * @param array<mixed> $MissionInfo
     * @return list<int>
     */
    private function planetMissions(array $MissionInfo, bool $UsedPlanet, bool $YourPlanet): array
    {
        $view = $this->viewHelpers();
        $availableMissions = [];

        if (!$UsedPlanet) {
            if (
                isset($MissionInfo['Ship'][208]) && $MissionInfo['planettype'] == 1
                && $view->isModuleAvailable(MODULE_MISSION_COLONY)
            ) {
                $availableMissions[] = Mission::Colonisation->value;
            }
        } else {
            if ($view->isModuleAvailable(MODULE_MISSION_TRANSPORT)) {
                $availableMissions[] = Mission::Transport->value;
            }

            if (
                !$YourPlanet && $this->onlyShipByID($MissionInfo['Ship'], 210)
                && $view->isModuleAvailable(MODULE_MISSION_SPY)
            ) {
                $availableMissions[] = Mission::Spy->value;
            }

            if (!$YourPlanet) {
                if ($view->isModuleAvailable(MODULE_MISSION_TRANSFER)) {
                    $availableMissions[] = Mission::Transfer->value;
                }

                if ($view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                    $availableMissions[] = Mission::Attack->value;
                }
                if ($view->isModuleAvailable(MODULE_MISSION_HOLD)) {
                    $availableMissions[] = Mission::Hold->value;
                }
            } elseif ($view->isModuleAvailable(MODULE_MISSION_STATION)) {
                $availableMissions[] = Mission::Station->value;
            }

            if (
                !$YourPlanet && $MissionInfo['planettype'] == 3
                && (isset($MissionInfo['Ship'][SHIP_RIP]) || isset($MissionInfo['Ship'][VOID_TITAN]))
                && $view->isModuleAvailable(MODULE_MISSION_DESTROY)
            ) {
                $availableMissions[] = Mission::Destruction->value;
            }
        }

        return $availableMissions;
    }

    /**
     * Phalanx scan rules (moved from the phalanx page, used by it and the galaxy view): whether the current planet's
     * phalanx may scan $toGalaxy:$toSystem (same galaxy, phalanx built, module on, enough deuterium, not on vacation,
     * target not noob/strong protected, system within the phalanx range - wrapping in circular galaxies).
     */
    public function allowPhalanx($toGalaxy, $toSystem, $targetUser)
    {
        $PLANET = $this->state->PLANET;
        $elements = $this->elements();
        $USER = $this->state->USER;
        $checknoob = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($targetUser));

        if ($PLANET['galaxy'] != $toGalaxy || $PLANET[$elements->column(42)] == 0 || !$this->viewHelpers()->isModuleAvailable(MODULE_PHALANX) || $PLANET[$elements->column(903)] < PHALANX_DEUTERIUM || $USER['urlaubs_modus'] == 1 || $checknoob['NoobPlayer'] || $checknoob['StrongPlayer']) {
            return false;
        }

        $PhRange	= (new FleetMath($this->db, $this->configs, $this->state))->getPhalanxRange($PLANET[$elements->column(42)]);
        if ($this->configs->get()->galaxy_type == 2) {
            // same rule as the missile range (FleetMath::inMissileRange): flat distance, range > 1 reaches every system
            return $PhRange > 1;
        } elseif ($this->configs->get()->galaxy_type == 1) {
            $max = $this->configs->get()->max_system;
            $thisSystem = $PLANET['system'];

            if ($thisSystem + $PhRange > $max) {
                $systemMax = $thisSystem + $PhRange - $max;
            } else {
                $systemMax = $thisSystem + $PhRange;
            }

            if ($thisSystem - $PhRange < 1) {
                $systemMin = $max + ($thisSystem - $PhRange);
            } else {
                $systemMin = $thisSystem - $PhRange;
            }

            if ($PhRange * 2 >= $max) {
                return true;
            } elseif ($systemMin <= $systemMax) {
                return $toSystem >= $systemMin && $toSystem <= $systemMax;
            } else {
                return ($toSystem >= $systemMin && $toSystem <= $max) || ($toSystem >= 1 && $toSystem <= $systemMax);
            }
        } else {
            $systemMin  = max(1, $PLANET['system'] - $PhRange);
            $systemMax  = $PLANET['system'] + $PhRange;

            return $toSystem >= $systemMin && $toSystem <= $systemMax;
        }
    }
}
