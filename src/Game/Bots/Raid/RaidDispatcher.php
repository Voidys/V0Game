<?php

namespace App\Game\Bots\Raid;

use App\Game\Enum\AuthLevel;
use App\Game\Enum\Mission;
use App\Game\Bots\BotActions;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Sends a bot's espionage, attack or transport (own planets, with cargo, any speed setting) fleet and recalls it, under the rules a player's fleet dispatch applies (FleetDispatch,
 * fleet step 3): own vacation mode, free fleet slots, existing target that is not own, ships on the planet, the mission offered
 * for that target and fleet (FleetFunctions::getFleetMissions), target in vacation, the bash rule for attacks (BashRules via
 * FleetFunctions::checkBash, probe-only fleets exempt), admin protection, noob / strong protection, deuterium for the flight.
 * Relations (own alliance, buddy, pact partner) are checked again at the moment of sending. Where a player would become an outlaw (attacking a "strong" player with the outlaw rule on) the bot refuses instead. Staff
 * accounts are refused even when the universe allows attacking staff.
 *
 * The caller holds the bot's rows locked in a transaction (BotActions::load()); the in-memory planet is kept in sync.
 */
class RaidDispatcher
{
    use GameUtilities;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidPlanner $planner,
    ) {}

    /**
     * Sends `$fleet` from `$planet` to the target planet on a hostile mission (attack or spy).
     *
     * @param array<string, mixed> $planet start planet row of the bot (`BotActions::$planets`), updated in place
     * @param array<int, int> $fleet ship id => count
     * @param int $mission Mission::Attack->value or Mission::Spy->value
     * @param int $targetPlanetId id of the planet to hit
     * @param int $targetUserId owner the bot decided to hit (a changed owner refuses)
     * @param array<int, int|float> $cargo resources to carry (901 / 902 / 903 => amount), for a transport to an own planet
     * @param int $speedSetting 1-10, 10 = full speed (slow flights are how fleet saving is timed)
     * @param int $group ACS group to join (mission Acs): the fleet arrives with the group, a flight that would arrive after it is refused
     * @param int $targetType 2 = the debris field of the target planet (recycling), else the planet or moon itself
     * @return array{ok: bool, reason: string, id: int, arrival: int, duration: int, consumption: float}
     */
    public function send(BotActions $ctx, array &$planet, array $fleet, int $mission, int $targetPlanetId, int $targetUserId, array $cargo = [], int $speedSetting = 10, int $group = 0, int $targetType = 1): array
    {
        $cargo = [RESOURCE_METAL => floor((float) ($cargo[RESOURCE_METAL] ?? 0)), RESOURCE_CRYSTAL => floor((float) ($cargo[RESOURCE_CRYSTAL] ?? 0)), RESOURCE_DEUT => floor((float) ($cargo[RESOURCE_DEUT] ?? 0))];
        $refusal = $this->refusal($ctx, $planet, $fleet, $mission, $targetPlanetId, $targetUserId, $group, $targetType);
        if (is_string($refusal)) {
            return ['ok' => false, 'reason' => $refusal, 'id' => 0, 'arrival' => 0, 'duration' => 0, 'consumption' => 0.0];
        }
        [$targetPlanet] = $refusal;
        $e = $this->elements();

        $flight = $this->planner->flight($ctx, $planet, $fleet, (int) $targetPlanet['galaxy'], (int) $targetPlanet['system'], (int) $targetPlanet['planet'], max(1, min(10, $speedSetting)));
        $no = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'id' => 0, 'arrival' => 0, 'duration' => 0, 'consumption' => 0.0];
        if ((float) $planet[$e->column(RESOURCE_DEUT)] < $flight['consumption'] + $cargo[RESOURCE_DEUT]) {
            return $no('not enough deuterium');
        }
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL] as $resourceId) {
            if ((float) $planet[$e->column($resourceId)] < $cargo[$resourceId]) {
                return $no('not enough ' . $e->column($resourceId));
            }
        }
        if (array_sum($cargo) > $this->fleetFunctions()->getFleetRoom($fleet) - $flight['consumption']) {
            return $no('cargo does not fit');
        }

        $arrival = (int) (floor($flight['duration']) + $this->state->runtime->timestamp);
        if ($group > 0) {
            $groupTime = (int) $this->db->selectSingle('SELECT ankunft FROM %%AKS%% WHERE id = :id;', [':id' => $group], 'ankunft');
            if ($groupTime <= $this->state->runtime->timestamp || $arrival > $groupTime) {
                return $no('would not arrive with the group');
            }
            $arrival = $groupTime; // a joiner who is early waits and arrives with the group
        }
        $id = $this->fleetFunctions()->sendFleet(
            $fleet,
            $mission,
            (int) $ctx->user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            (int) $planet['planet_type'],
            $targetUserId,
            $targetPlanetId,
            (int) $targetPlanet['galaxy'],
            (int) $targetPlanet['system'],
            (int) $targetPlanet['planet'],
            $targetType === 2 ? 2 : (int) $targetPlanet['planet_type'],
            $cargo,
            $arrival,
            $arrival,
            (int) floor($arrival + $flight['duration']),
            $group,
            0,
            0,
            $flight['consumption'],
        );

        foreach ($fleet as $ship => $count) {
            $planet[$e->column($ship)] -= $count;
        }
        $planet[$e->column(RESOURCE_DEUT)] -= $flight['consumption'];
        foreach ($cargo as $resourceId => $amount) {
            $planet[$e->column($resourceId)] -= $amount;
        }

        return ['ok' => true, 'reason' => '', 'id' => $id, 'arrival' => $arrival, 'duration' => $flight['duration'], 'consumption' => $flight['consumption']];
    }

    /**
     * Launches interplanetary missiles at the defense of a planet under the rules of the missile page: module on, silo level 4, impulse
     * drive researched, same galaxy, target within the missile range, the protection and bash rules of an attack (the dispatch checks of
     * send()), missiles on the planet. Missiles take no fleet slot and cannot be recalled.
     *
     * @param array<string, mixed> $planet start planet row (`BotActions::$planets`), updated in place
     * @param int $primaryTarget defense id hit first (0 = missile launchers first)
     * @return array{ok: bool, reason: string, id: int, arrival: int}
     */
    public function sendMissiles(BotActions $ctx, array &$planet, int $count, int $primaryTarget, int $targetPlanetId, int $targetUserId): array
    {
        $no = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'id' => 0, 'arrival' => 0];
        $e = $this->elements();
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSILEATTACK)) {
            return $no('missile attacks are off');
        }
        $refusal = $this->refusal($ctx, $planet, [INTERPLANETARY_MISSILE => $count], Mission::Missile->value, $targetPlanetId, $targetUserId);
        if (is_string($refusal)) {
            return $no($refusal);
        }
        [$target] = $refusal;
        if ((int) $planet[$e->column(SILO)] < 4 || (int) $ctx->user[$e->column(117)] < 1) {
            return $no('no silo level 4 or no impulse drive');
        }
        if ((int) $target['galaxy'] !== (int) $planet['galaxy'] || (int) $target['planet_type'] !== 1 || !$this->fleetFunctions()->inMissileRange($planet, $target, (int) $ctx->user[$e->column(117)])) {
            return $no('target out of missile range');
        }
        $arrival = $this->state->runtime->timestamp + (int) $this->fleetFunctions()->getMIPDuration((int) $planet['system'], (int) $target['system']);
        $id = $this->fleetFunctions()->sendFleet(
            [INTERPLANETARY_MISSILE => $count],
            Mission::Missile->value,
            (int) $ctx->user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            (int) $planet['planet_type'],
            $targetUserId,
            $targetPlanetId,
            (int) $target['galaxy'],
            (int) $target['system'],
            (int) $target['planet'],
            1,
            [RESOURCE_METAL => 0, RESOURCE_CRYSTAL => 0, RESOURCE_DEUT => 0],
            $arrival,
            $arrival,
            $arrival,
            0,
            $primaryTarget,
            1,
        );
        $planet[$e->column(INTERPLANETARY_MISSILE)] -= $count;

        return ['ok' => true, 'reason' => '', 'id' => $id, 'arrival' => $arrival];
    }

    /**
     * The game's bash rule for an attack on a planet (probe-only fleets exempt are the caller's business).
     *
     * @param array<string, mixed> $user the bot's user row
     */
    public function bashRefused(array $user, int $planetId, int $group = 0): bool
    {
        return $this->bash($planetId, $user, $group);
    }

    /**
     * Recalls a fleet of the bot (the game's normal fleet return, as the fleet page does for a player).
     *
     * @return bool false when the game refuses the recall (fleet gone, already returning, not cancelable)
     */
    public function recall(BotActions $ctx, int $fleetId): bool
    {
        return $this->fleetFunctions()->sendFleetBack($ctx->user, $fleetId);
    }

    /**
     * All checks of a dispatch; the reason of the first failing one, or the loaded target rows when everything passes.
     *
     * @param array<string, mixed> $fleet
     * @param array<string, mixed> $planet
     * @return string|array{0: array<string, mixed>, 1: array<string, mixed>} refusal text, or [target planet row, target user row]
     */
    private function refusal(BotActions $ctx, array $planet, array $fleet, int $mission, int $targetPlanetId, int $targetUserId, int $group = 0, int $targetType = 1): string|array
    {
        $e = $this->elements();
        $user = $ctx->user;
        if (IsVacationMode($user)) {
            return 'own vacation mode';
        }
        if ($mission !== Mission::Missile->value && $ctx->freeFleetSlots() < 1) {
            return 'no free fleet slot';
        }
        foreach ($fleet as $ship => $count) {
            if ($count < 1 || $count > (int) $planet[$e->column($ship)]) {
                return 'ships not on the planet';
            }
        }

        $target = $this->db->selectSingle(
            'SELECT `id`, `id_owner`, `galaxy`, `system`, `planet`, `planet_type`, `der_metal`, `der_crystal`, `destruyed`, `ally_deposit`
            FROM %%PLANETS%% WHERE `id` = :id AND `universe` = :uni;',
            [':id' => $targetPlanetId, ':uni' => $this->universe()->current()],
        );
        $debris = $mission === Mission::Recycling->value;
        if (empty($target) || (!empty($target['destruyed']) && !$debris) || (int) $target['id_owner'] !== $targetUserId) {
            return 'target gone';
        }
        $own = (int) $target['id_owner'] === (int) $user['id'];
        if ($own && !in_array($mission, [Mission::Transport->value, Mission::Station->value, Mission::Recycling->value], true)) {
            return 'own planet';
        }
        $targetUser = $this->db->selectSingle(
            'SELECT u.*, s.total_points FROM %%USERS%% u LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1 WHERE u.id = :id;',
            [':id' => $targetUserId],
        );
        if (empty($targetUser)) {
            return 'target gone';
        }

        return $this->missionRefusal($ctx, $fleet, $mission, $target, $targetUser, $own, $group, $targetType) ?? [$target, $targetUser];
    }

    /**
     * The mission rules of FleetDispatch for a hostile mission: offered mission, vacation, bash, admin / staff, noob protection.
     *
     * @param array<int, int> $fleet ship id => count
     * @param array<string, mixed> $target target planet row
     * @param array<string, mixed> $targetUser target user row (with `total_points`)
     * @return string|null refusal text, null when the mission is allowed
     */
    private function missionRefusal(BotActions $ctx, array $fleet, int $mission, array $target, array $targetUser, bool $own = false, int $group = 0, int $targetType = 1): ?string
    {
        $user = $ctx->user;
        $info = ['galaxy' => $target['galaxy'], 'system' => $target['system'], 'planet' => $target['planet'], 'planettype' => $targetType === 2 ? 2 : (int) $target['planet_type'], 'IsAKS' => $group, 'Ship' => $fleet];
        if ($mission !== Mission::Missile->value) { // missiles are no fleet mission of the selector
            $available = $this->fleetFunctions()->getFleetMissions($user, $info, $target);
            if (!in_array($mission, $available['MissionSelector'], true)) {
                return 'mission not available for this target';
            }
        }
        if ($own || $mission === Mission::Recycling->value) {
            return null; // own planets and debris fields: no protection or bash rule applies
        }
        if (IsVacationMode($targetUser)) {
            return 'target in vacation mode';
        }
        if ((int) $targetUser['authlevel'] > AuthLevel::User->value || ($this->configs->get()->adm_attack == 1 && $targetUser['authattack'] > $user['authlevel'])) {
            return 'target is protected staff';
        }
        $relation = $this->relationRefusal($user, $targetUser);
        if ($relation !== null) {
            return $relation;
        }
        $hits = [Mission::Attack->value, Mission::Acs->value, Mission::Missile->value];
        if (in_array($mission, $hits, true) && (count($fleet) !== 1 || !array_key_exists(SHIP_PROBE, $fleet)) && $this->bash((int) $target['id'], $user, $group)) {
            return 'bash limit';
        }

        $noob = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($user), $this->models()->user($targetUser));
        if ($noob['NoobPlayer']) {
            return 'target under noob protection';
        }

        return $noob['StrongPlayer'] ? 'target is protected from small players (strong)' : null;
    }

    /**
     * The relations a bot respects at the moment of sending (and again before the impact): a fleet never goes against the bot's own
     * alliance, an accepted buddy or an alliance with an accepted pact, even when the relation arose after the target was scouted.
     *
     * @param array<string, mixed> $user the bot's user row
     * @param array<string, mixed> $targetUser target user row
     * @return string|null refusal text, null when nothing speaks against the fleet
     */
    public function relationRefusal(array $user, array $targetUser): ?string
    {
        $me = (int) $user['id'];
        $other = (int) $targetUser['id'];
        if ((int) $user['ally_id'] > 0 && (int) $targetUser['ally_id'] === (int) $user['ally_id']) {
            return 'target is in the own alliance';
        }
        $buddy = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%BUDDY%% bd LEFT JOIN %%BUDDY_REQUEST%% br ON br.id = bd.id
            WHERE br.id IS NULL AND ((bd.sender = :me AND bd.owner = :other) OR (bd.sender = :other AND bd.owner = :me));',
            [':me' => $me, ':other' => $other],
            'c',
        );
        if ($buddy > 0) {
            return 'target is a buddy';
        }
        if ((int) $user['ally_id'] > 0 && (int) $targetUser['ally_id'] > 0) {
            $level = $this->db->selectSingle(
                'SELECT level FROM %%DIPLO%% WHERE accept = 1 AND ((owner_1 = :a AND owner_2 = :b) OR (owner_1 = :b AND owner_2 = :a));',
                [':a' => (int) $user['ally_id'], ':b' => (int) $targetUser['ally_id']],
                'level',
            );
            if ($level !== null && $level !== false && (int) $level !== 5) {
                return 'target is a pact partner';
            }
        }

        return null;
    }

    /**
     * Same check from the target's user id (loads the row).
     *
     * @param array<string, mixed> $user the bot's user row
     */
    public function relationRefusalFor(array $user, int $targetUserId): ?string
    {
        $targetUser = $this->db->selectSingle('SELECT id, ally_id FROM %%USERS%% WHERE id = :id;', [':id' => $targetUserId]);

        return empty($targetUser) ? null : $this->relationRefusal($user, $targetUser);
    }

    /**
     * BashRules::checkBash() for the bot (the rule reads the acting user from the game state, set for the call).
     *
     * @param array<string, mixed> $user the bot's user row
     */
    private function bash(int $planetId, array $user, int $group = 0): bool
    {
        $previous = $this->state->USER;
        $this->state->USER = $user;
        try {
            return $this->fleetFunctions()->checkBash($planetId, $group);
        } finally {
            $this->state->USER = $previous;
        }
    }
}
