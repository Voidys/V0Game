<?php

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotLog;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Enum\Mission;

/**
 * Attack combat groups (ACS) of bots of one alliance, through the game's own mechanics. The leader finds that a target is too strong
 * for its own fleet but winnable together with allied bots that are online right now (RaidPlanner::planJoint()), sends its attack,
 * opens the group for it (AKS row, the fleet's `group`) and invites those allies. An invited bot joins in its own tick like a player
 * would: a fleet on the ACS mission with the group's arrival time (RaidDispatcher::send() refuses a flight that cannot arrive in time).
 * The leader's pre-impact re-spy judges the whole group's fleet and recalls it (and with it the group) when the fight is not safe,
 * for example when too few helpers made it. All attack rules and the BotEnvelope caps apply to the leader and to every joiner; the
 * group counts as one attack for the envelope.
 */
class BotAcs
{
    use GameUtilities;

    /** Seconds an ally must stay online (session) to be asked to help. */
    private const ONLINE_MARGIN = 300;

    /** Seconds before the group's arrival after which nobody joins any more. */
    private const JOIN_MARGIN = 60;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidPlanner $planner,
        private readonly RaidDispatcher $dispatcher,
        private readonly BotEnvelope $envelope,
        private readonly BotLog $botLog,
    ) {}

    /**
     * A joint plan for a target the bot cannot beat alone: warships of allied bots that are online (same alliance, bots, a session that
     * lasts), as many as the group may hold, and the plan that wins with them.
     *
     * @param array<string, mixed> $report parsed spy report
     * @param array<string, mixed> $target scouted target
     * @return array<string, mixed> plan (see RaidPlanner::planJoint()), plus `helpers` (user ids) when ok
     */
    public function plan(BotActions $ctx, array $report, array $target, float $minRatio, int $now): array
    {
        if (!$ctx->bot->botAcs || (int) $ctx->user['ally_id'] < 1 || !empty($target['is_bot'])) {
            return ['ok' => false, 'reason' => 'no ACS'];
        }
        $helpers = $this->helpers($ctx, $now);
        if ($helpers === []) {
            return ['ok' => false, 'reason' => 'no allied bot online with warships'];
        }
        $ships = [];
        foreach ($helpers as $fleet) {
            foreach ($fleet as $id => $count) {
                $ships[$id] = ($ships[$id] ?? 0) + $count;
            }
        }
        $plan = $this->planner->planJoint($ctx, $report, $target, $ships, $minRatio);
        if ($plan['ok']) {
            $plan['helpers'] = array_keys($helpers);
        }

        return $plan;
    }

    /**
     * Warships (the planet with the most) of every allied bot that is online for a while, at most as many as the group has room for.
     *
     * @return array<int, array<int, int>> user id => ship id => count
     */
    private function helpers(BotActions $ctx, int $now): array
    {
        $rows = $this->db->select(
            'SELECT u.id FROM %%USERS%% u INNER JOIN %%BOT_PLAYERS%% bp ON bp.user_id = u.id
            WHERE u.ally_id = :ally AND u.id <> :me AND bp.universe = :uni AND bp.session_until > :until;',
            [':ally' => (int) $ctx->user['ally_id'], ':me' => (int) $ctx->user['id'], ':uni' => $ctx->universe, ':until' => $now + self::ONLINE_MARGIN],
        );
        $max = max(0, min((int) $ctx->config->max_participants_per_acs, (int) $ctx->config->max_fleets_per_acs) - 1);
        $out = [];
        foreach ($rows as $row) {
            $fleet = $this->bestFleet((int) $row['id']);
            if ($fleet !== [] && count($out) < $max) {
                $out[(int) $row['id']] = $fleet;
            }
        }

        return $out;
    }

    /**
     * The warships on the planet of a user that holds the most.
     *
     * @return array<int, int> ship id => count
     */
    private function bestFleet(int $userId): array
    {
        $best = [];
        $planets = $this->db->select('SELECT * FROM %%PLANETS%% WHERE id_owner = :id AND destruyed = 0 AND planet_type = \'1\';', [':id' => $userId]);
        foreach ($planets as $planet) {
            $ships = $this->planner->warships($planet);
            if (array_sum($ships) > array_sum($best)) {
                $best = $ships;
            }
        }

        return $best;
    }

    /**
     * Opens the group for the leader's attack that was just sent and invites the helpers (the game's create-group action: AKS row with
     * the arrival time and the target planet, the invitations, `group` on the flight and its log row).
     *
     * @param int $fleetId the leader's attack flight
     * @param int $arrival its arrival time
     * @param list<int> $helpers invited bot user ids
     * @return int the group id
     */
    public function open(BotActions $ctx, int $fleetId, int $targetPlanetId, int $arrival, array $helpers): int
    {
        $db = $this->db;
        $db->insert('INSERT INTO %%AKS%% SET public_id = :public, name = :name, ankunft = :time, target = :target;', [
            ':public' => bin2hex(random_bytes(16)),
            ':name' => 'AG' . random_int(100000, 999999999),
            ':time' => $arrival,
            ':target' => $targetPlanetId,
        ]);
        $group = (int) $db->lastInsertId();
        foreach (array_merge([(int) $ctx->user['id']], $helpers) as $user) {
            $db->insert('INSERT INTO %%USERS_TO_ACS%% SET acsID = :acs, userID = :user;', [':acs' => $group, ':user' => $user]);
        }
        foreach (['FLEETS', 'LOG_FLEETS'] as $table) {
            $db->update('UPDATE %%' . $table . '%% SET `group` = :acs WHERE id = :id AND owner = :owner;', [':acs' => $group, ':id' => $fleetId, ':owner' => (int) $ctx->user['id']]);
        }

        return $group;
    }

    /**
     * Ships of all outbound flights of an ACS group added up (what the fight at the target will see on the attacker's side).
     *
     * @return array<int, int> ship id => count
     */
    public function groupShips(int $group): array
    {
        $total = [];
        $rows = $this->db->select('SELECT ships FROM %%FLEETS%% WHERE `group` = :g AND status = :s;', [':g' => $group, ':s' => FLEET_OUTWARD]);
        foreach ($rows as $row) {
            foreach ($this->fleetFunctions()->unserialize((string) $row['ships']) as $id => $count) {
                $total[(int) $id] = ($total[(int) $id] ?? 0) + (int) $count;
            }
        }

        return $total;
    }

    /**
     * Joins the groups this bot is invited to (one per call): the flight with the warships of the nearest base that can arrive with the
     * group. Skipped groups: the arrival is too near or past, the bot already flies in it, the leader is not a bot of its alliance, the
     * envelope or the game's rules refuse the attack.
     *
     * @param array<string, mixed> $bot bot row
     * @return int flights sent (0 or 1)
     */
    public function join(BotActions $ctx, array $bot, int $now): int
    {
        if (!$ctx->bot->botAcs) {
            return 0;
        }
        $me = (int) $ctx->user['id'];
        $groups = $this->db->select(
            'SELECT a.id, a.ankunft, a.target, p.id_owner AS target_owner, (SELECT f.owner FROM %%FLEETS%% f WHERE f.`group` = a.id ORDER BY f.id ASC LIMIT 1) AS leader
            FROM %%AKS%% a INNER JOIN %%USERS_TO_ACS%% i ON i.acsID = a.id AND i.userID = :me INNER JOIN %%PLANETS%% p ON p.id = a.target
            WHERE a.ankunft > :min AND NOT EXISTS (SELECT 1 FROM %%FLEETS%% f WHERE f.`group` = a.id AND f.owner = :me2)
            ORDER BY a.ankunft ASC;',
            [':me' => $me, ':me2' => $me, ':min' => $now + self::JOIN_MARGIN],
        );
        foreach ($groups as $group) {
            if ($this->leaderIsAlly($ctx, (int) $group['leader']) && $this->joinGroup($ctx, (int) $bot['universe'], $group, $now)) {
                return 1;
            }
        }

        return 0;
    }

    /** Whether the leader is a bot of the joiner's alliance. */
    private function leaderIsAlly(BotActions $ctx, int $leader): bool
    {
        if ($leader < 1 || (int) $ctx->user['ally_id'] < 1) {
            return false;
        }
        $row = $this->db->selectSingle(
            'SELECT u.id FROM %%USERS%% u INNER JOIN %%BOT_PLAYERS%% bp ON bp.user_id = u.id WHERE u.id = :id AND u.ally_id = :ally;',
            [':id' => $leader, ':ally' => (int) $ctx->user['ally_id']],
        );

        return !empty($row);
    }

    /**
     * Sends the warships of one base into the group.
     *
     * @param array<string, mixed> $group `id`, `ankunft`, `target`, `target_owner`
     */
    private function joinGroup(BotActions $ctx, int $uni, array $group, int $now): bool
    {
        $botId = (int) $ctx->user['id'];
        $target = $this->db->selectSingle('SELECT galaxy, `system`, planet FROM %%PLANETS%% WHERE id = :id;', [':id' => $group['target']]);
        $isBot = !empty($this->db->selectSingle('SELECT user_id FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $group['target_owner']]));
        $refusal = $isBot ? null : $this->envelope->refusal($ctx, (int) $group['target_owner'], Mission::Acs->value, (int) $group['id']);
        if (empty($target) || $refusal !== null || $ctx->freeFleetSlots() < 1) {
            $this->botLog->log($botId, $uni, 'raid_skip', 'no help for the group #' . $group['id'] . ': ' . ($refusal ?? 'no free fleet slot'));

            return false;
        }
        $origins = $this->planner->origins($ctx, $target);
        foreach ($origins as $origin) {
            $fleet = $this->planner->warships($ctx->originRef($origin));
            if ($fleet === []) {
                continue;
            }
            $sent = $this->dispatcher->send($ctx, $ctx->originRef($origin), $fleet, Mission::Acs->value, (int) $group['target'], (int) $group['target_owner'], [], 10, (int) $group['id']);
            if ($sent['ok']) {
                $this->botLog->log($botId, $uni, 'raid_acs_join', array_sum($fleet) . ' ships join group #' . $group['id'] . ', arrival ' . date('H:i:s', $sent['arrival']));

                return true;
            }
            $this->botLog->log($botId, $uni, 'raid_skip', 'cannot join group #' . $group['id'] . ': ' . $sent['reason']);
        }

        return false;
    }
}
