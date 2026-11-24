<?php

declare(strict_types=1);

namespace App\Game\Engagement\AllianceGoals;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\Cosmetics\Cosmetics;
use App\Game\Engagement\EngagementFeature;
use App\Game\Engagement\FightValidity;
use App\Game\Engagement\Week;
use App\Game\Enum\MessageType;

/**
 * Alliance goals: the leader (or a member with the ADMIN right) sets one goal per ISO week, the members' expeditions, recycled
 * resources or destroyed enemy value fill it. A finished goal sends an alliance message and gives the badge
 * ally_goal_1/5/10/25 to the members who joined at least 7 days earlier. Only members who joined before the goal was set
 * contribute, and a goal cannot be changed once it has progress.
 *
 * The hooks run inside the fleet event transaction, so they only issue plain statements (no nested transaction).
 */
final class AllianceGoals extends EngagementFeature
{
    public const KIND_EXPEDITIONS = 'expeditions';
    public const KIND_RECYCLED = 'recycled';
    public const KIND_DESTROYED = 'destroyed';

    /** kind => target unit per member and per `alliance_goals_min_per_member` (expeditions count 1, resources 10 000 / 25 000) */
    public const KINDS = [
        self::KIND_EXPEDITIONS => 1,
        self::KIND_RECYCLED => 10000,
        self::KIND_DESTROYED => 25000,
    ];

    /** Completed goals that give a badge. */
    private const BADGE_COUNTS = [1, 5, 10, 25];

    /** Members need to be in the alliance this long (seconds) to get the badge. */
    private const BADGE_MIN_MEMBERSHIP = 7 * 86400;

    /** @var array<int, array<string, mixed>|null> user id => ally_id, ally_register_time (null: no alliance, bot) */
    private array $members = [];

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'alliance_goals_enabled', false);
    }

    /** Member expeditions and recycled resources feed the alliance goal of the week. */
    public function onStat(int $universe, int $userId, AdvancedStatsEnum $key, int $value): void
    {
        $kind = match ($key->name) {
            'EXPO_COUNT' => self::KIND_EXPEDITIONS,
            'RECYCLED_METAL', 'RECYCLED_CRYSTAL' => self::KIND_RECYCLED,
            default => null,
        };
        if ($kind !== null) {
            $this->advance($universe, $userId, $kind, $value);
        }
    }

    /** Destroyed enemy value of valid fights (see FightValidity) feeds the goal, shared equally by the users of a side. */
    public function onBattle(BattleOutcome $outcome): void
    {
        if ($outcome->kind === 'pirate') {
            return;
        }
        $validity = new FightValidity($this->db, $this->configs, $this->state);
        foreach ([[$outcome->attackers, $outcome->losses->defenderValue], [$outcome->defenders, $outcome->losses->attackerValue]] as [$users, $destroyed]) {
            $share = max(1, count($users));
            foreach (array_keys($users) as $userId) {
                // the cheap lookup of an open goal comes first, the fight is only judged for members who could profit
                if ($this->openGoalId((int) $userId, self::KIND_DESTROYED, $outcome->time) !== null && $validity->counts($outcome, (int) $userId)) {
                    $this->advance($outcome->universe, (int) $userId, self::KIND_DESTROYED, (int) floor($destroyed / $share), $outcome->time);
                }
            }
        }
    }

    /** Smallest target a goal of this kind may have for an alliance of $members members. */
    public function minTarget(int $universe, string $kind, int $members): int
    {
        $perMember = max(1, (int) $this->setting($universe, 'alliance_goals_min_per_member', 2));

        return $perMember * max(1, $members) * (self::KINDS[$kind] ?? 1);
    }

    /**
     * Id of the open goal of the member's alliance for the kind in the week of $time that the member may feed (he joined before
     * the goal was set), null when there is none or the user is no human alliance member.
     */
    private function openGoalId(int $userId, string $kind, int $time): ?int
    {
        $member = $this->memberOf($userId);
        if ($member === null) {
            return null;
        }
        $goal = $this->db->selectSingle(
            'SELECT `id` FROM %%ENG_ALLIANCE_GOALS%%
            WHERE `alliance_id` = :ally AND `week` = :week AND `kind` = :kind AND `completed_at` IS NULL AND `created_at` >= :joined;',
            [':ally' => $member['ally_id'], ':week' => Week::key($time), ':kind' => $kind, ':joined' => $member['ally_register_time']],
            'id',
        );

        return empty($goal) ? null : (int) $goal;
    }

    /**
     * Adds a member's contribution to the alliance goal of the week of the event and completes the goal when the target is reached.
     */
    private function advance(int $universe, int $userId, string $kind, int $amount, ?int $eventTime = null): void
    {
        $now = $this->state->runtime->timestamp;
        $goal = $amount > 0 ? $this->openGoalId($userId, $kind, $eventTime ?? $now) : null;
        if ($goal === null) {
            return;
        }
        $this->db->update(
            'UPDATE %%ENG_ALLIANCE_GOALS%% SET `progress` = LEAST(`target`, `progress` + :amount) WHERE `id` = :id AND `completed_at` IS NULL;',
            [':amount' => $amount, ':id' => $goal],
        );
        // only the statement that flips the goal to completed (row count 1) announces it
        $this->db->update(
            'UPDATE %%ENG_ALLIANCE_GOALS%% SET `completed_at` = :now WHERE `id` = :id AND `completed_at` IS NULL AND `progress` >= `target`;',
            [':now' => $now, ':id' => $goal],
        );
        if ($this->db->rowCount() === 1) {
            $this->complete($universe, (int) $this->memberOf($userId)['ally_id'], $now);
        }
    }

    /**
     * Alliance and join time of a player, null for players without alliance and for bots.
     *
     * @return array<string, mixed>|null
     */
    private function memberOf(int $userId): ?array
    {
        if (!array_key_exists($userId, $this->members)) {
            $row = $this->db->selectSingle(
                'SELECT u.`ally_id`, u.`ally_register_time`, b.`user_id` AS bot FROM %%USERS%% u
                LEFT JOIN %%BOT_PLAYERS%% b ON b.`user_id` = u.`id` WHERE u.`id` = :user;',
                [':user' => $userId],
            );
            $this->members[$userId] = (!empty($row) && (int) $row['ally_id'] > 0 && empty($row['bot'])) ? $row : null;
        }

        return $this->members[$userId];
    }

    /**
     * A goal was reached: message to every member and the badge of the completed-goal count to the members who joined at
     * least 7 days ago.
     */
    private function complete(int $universe, int $allyId, int $now): void
    {
        $count = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` = :ally AND `completed_at` IS NOT NULL;',
            [':ally' => $allyId],
            'anz',
        );
        $members = $this->db->select(
            'SELECT u.`id`, u.`ally_register_time`, us.`lang`, b.`user_id` AS bot FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id`
            LEFT JOIN %%BOT_PLAYERS%% b ON b.`user_id` = u.`id`
            WHERE u.`ally_id` = :ally;',
            [':ally' => $allyId],
        );
        $cosmetics = new Cosmetics($this->db);
        foreach ($members as $member) {
            $lang = $this->playerLookup()->getLanguage($member['lang']);
            $this->playerMessenger()->sendMessage(
                (int) $member['id'],
                0,
                $lang['al_alliance'],
                MessageType::Alliance->value,
                $lang['eng_ag_done_subject'],
                $lang->format('eng_ag_done_text', $count),
                $now,
                1,
                $universe,
            );
            if (in_array($count, self::BADGE_COUNTS, true) && empty($member['bot']) && (int) $member['ally_register_time'] <= $now - self::BADGE_MIN_MEMBERSHIP) {
                $cosmetics->grant((int) $member['id'], Cosmetics::KIND_BADGE, 'ally_goal_' . $count, 'alliance_goal');
            }
        }
    }

    /**
     * Sets (or, while it has no progress, changes) the goal of the alliance for this week. The caller has checked that the
     * player may manage goals (leader or ADMIN right) and passes his alliance row.
     *
     * @param array<string, mixed> $alliance the alliance row (id, ally_members)
     * @return string|null null when saved, otherwise the translation key of the refusal
     */
    public function setGoal(int $universe, array $alliance, int $userId, string $kind, int $target, int $now): ?string
    {
        if (!isset(self::KINDS[$kind])) {
            return 'eng_ag_err_kind';
        }
        $members = (int) $alliance['ally_members'];
        if ($members < max(1, (int) $this->setting($universe, 'alliance_goals_min_members', 3))) {
            return 'eng_ag_err_members';
        }
        $min = $this->minTarget($universe, $kind, $members);
        if ($target < $min) {
            return 'eng_ag_err_target_low';
        }
        if ($target > $min * 100) {
            return 'eng_ag_err_target_high';
        }
        $week = Week::key($now);
        $this->db->insert(
            'INSERT INTO %%ENG_ALLIANCE_GOALS%% (`alliance_id`, `universe`, `week`, `kind`, `target`, `created_by`, `created_at`)
            VALUES (:ally, :universe, :week, :kind, :target, :user, :now)
            ON DUPLICATE KEY UPDATE
                `kind` = IF(`progress` = 0 AND `completed_at` IS NULL, VALUES(`kind`), `kind`),
                `target` = IF(`progress` = 0 AND `completed_at` IS NULL, VALUES(`target`), `target`),
                `created_by` = IF(`progress` = 0 AND `completed_at` IS NULL, VALUES(`created_by`), `created_by`),
                `created_at` = IF(`progress` = 0 AND `completed_at` IS NULL, VALUES(`created_at`), `created_at`);',
            [':ally' => $alliance['id'], ':universe' => $universe, ':week' => $week, ':kind' => $kind, ':target' => $target, ':user' => $userId, ':now' => $now],
        );
        $goal = $this->db->selectSingle('SELECT `kind`, `target` FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` = :ally AND `week` = :week;', [':ally' => $alliance['id'], ':week' => $week]);

        return ($goal['kind'] ?? '') === $kind && (int) ($goal['target'] ?? 0) === $target ? null : 'eng_ag_err_locked';
    }

    /**
     * What the alliance page shows: this week's goal with its progress, the completed count and the minimum targets for the
     * set-goal form (only when $mayManage).
     *
     * @param array<string, mixed> $alliance the alliance row (id, ally_members)
     * @return array<string, mixed>
     */
    public function panel(int $universe, array $alliance, bool $mayManage, int $now): array
    {
        $goal = $this->db->selectSingle(
            'SELECT `kind`, `target`, `progress`, `completed_at` FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` = :ally AND `week` = :week;',
            [':ally' => $alliance['id'], ':week' => Week::key($now)],
        );
        $done = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` = :ally AND `completed_at` IS NOT NULL;',
            [':ally' => $alliance['id']],
            'anz',
        );
        $members = (int) $alliance['ally_members'];
        $minimums = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $minimums[$kind] = $this->minTarget($universe, $kind, $members);
        }
        $enoughMembers = $members >= max(1, (int) $this->setting($universe, 'alliance_goals_min_members', 3));
        $editable = empty($goal) || ((int) $goal['progress'] === 0 && $goal['completed_at'] === null);

        return [
            'goal' => empty($goal) ? null : [
                'kind' => $goal['kind'],
                'target' => (int) $goal['target'],
                'progress' => (int) $goal['progress'],
                'percent' => (int) floor(100 * $goal['progress'] / max(1, $goal['target'])),
                'completed' => $goal['completed_at'] !== null,
            ],
            'completedCount' => $done,
            'canSet' => $mayManage && $enoughMembers && $editable,
            'enoughMembers' => $enoughMembers,
            'minMembers' => max(1, (int) $this->setting($universe, 'alliance_goals_min_members', 3)),
            'minimums' => $minimums,
            'endsAt' => Week::end($now),
        ];
    }
}
