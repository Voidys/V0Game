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
 * Scouting: what a player sees when he browses the galaxy view around his planets, and the choice of whom to look at closer.
 *
 * A bot sees what the galaxy page shows: owner name, points, alliance, the activity marker (planet or moon updated within 15
 * minutes, minutes up to an hour, nothing later), the status classes of ProtectionRules::userStatus() (vacation, banned,
 * inactive 7 days / long inactive 28 days, noob / strong protection) and the diplomacy and buddy marks relative to the bot.
 * Not visible to a player, so not used here: exact last online time, resources, fleet, research.
 *
 * Never targets: oneself, own alliance, accepted buddies, alliances with an accepted pact (every level except war),
 * players in vacation mode or banned, staff accounts (always, even when the universe lets staff be attacked), noob- or
 * strong-protected players (the same rule as an attack), kinds of players the profile does not want (bots / humans), and
 * players already hit as often today as the profile (and the game's bash limit) allows.
 */
class RaidTargetFinder
{
    use GameUtilities;

    /** Seconds a skipped (unprofitable / unknown / failed) target is left alone (default of the bot knob `bots_target_cooldown_hours`). */
    public const COOLDOWN = 7200;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * Scans the systems around the bot's planets, filters like a player would and ranks the rest.
     *
     * @param array<string, mixed> $settings profile settings (`scout_range`, `attack_bots`, `attack_humans`, `prefer_inactive`, `max_attacks_per_target_per_day`)
     * @param array<int, array<string, mixed>> $targets raid memory of known targets by planet id (`skip_until`, `last_loot`, `last_hit`)
     * @param array<int, float> $grudges the bot's grudges by player id (revenge): such players rank first and are wanted even when active
     * @return array{candidates: list<array<string, mixed>>, skipped: array<string, list<string>>, seen: int, systems: int}
     *         candidates best first; skipped = reason => planet labels; seen = planets looked at
     */
    public function scout(BotActions $ctx, array $settings, array $targets, array $grudges = []): array
    {
        $now = $this->state->runtime->timestamp;
        $range = $this->range($ctx, $settings);
        $rows = $this->galaxyRows($ctx, $range);
        $attacks = $this->recentAttacks((int) $ctx->user['id']);
        $maxPerDay = $this->attackLimit($settings);

        $candidates = [];
        $skipped = [];
        foreach ($rows as $row) {
            $reason = $this->excludeReason($ctx, $row, $settings, ($grudges[(int) $row['id']] ?? 0.0) > 0);
            $planetId = (int) $row['planet_id'];
            if ($reason === null && ($attacks[(int) $row['id']] ?? 0) >= $maxPerDay) {
                $reason = 'bash limit';
            }
            if ($reason === null && ($targets[$planetId]['skip_until'] ?? 0) > $now) {
                $reason = 'checked recently';
            }
            if ($reason !== null) {
                $skipped[$reason][] = $row['username'] . ' [' . $row['p_galaxy'] . ':' . $row['p_system'] . ':' . $row['p_planet'] . ']';
                continue;
            }
            $candidates[] = $this->candidate($ctx, $row, $settings, $targets[$planetId] ?? [], $grudges[(int) $row['id']] ?? 0.0);
        }
        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return [
            'candidates' => $candidates,
            'skipped' => $skipped,
            'seen' => count($rows),
            'systems' => $range * 2 + 1,
        ];
    }

    /**
     * Systems the bot looks around each of its planets: the profile's `scout_range`, never more than a player browsing the galaxy view
     * would cover (`bots_scout_range_max`).
     *
     * @param array<string, mixed> $settings profile settings
     */
    public function range(BotActions $ctx, array $settings): int
    {
        return max(1, min((int) $settings['scout_range'], $ctx->bot->scoutRangeMax));
    }

    /**
     * Daily attack limit per target of this bot: its profile setting, never above the game's bash limit per player (when set).
     *
     * @param array<string, mixed> $settings profile settings
     */
    public function attackLimit(array $settings): int
    {
        $limit = max(1, (int) $settings['max_attacks_per_target_per_day']);
        $bash = (int) $this->configs->get()->bash_per_user;

        return $bash > 0 ? min($limit, $bash) : $limit;
    }

    /**
     * Number of attacks the bot flew (or has under way) against each player within the bash window, by target user id.
     *
     * @return array<int, int>
     */
    public function recentAttacks(int $botId): array
    {
        $out = [];
        $rows = $this->db->select(
            'SELECT target_owner, COUNT(*) AS c FROM %%LOG_FLEETS%%
            WHERE owner = :bot AND mission IN (:att, :acs, :dest) AND arrival_time > :since AND hasCanceled = 0
            GROUP BY target_owner;',
            [':bot' => $botId, ':att' => Mission::Attack->value, ':acs' => Mission::Acs->value, ':dest' => Mission::Destruction->value, ':since' => $this->state->runtime->timestamp - BASH_TIME],
        );
        foreach ($rows as $row) {
            $out[(int) $row['target_owner']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * The planets (not moons) of the systems within `$range` of any own planet in the same galaxy, with owner, statistics, alliance,
     * diplomacy to the bot's alliance, buddy flag and bot flag: the galaxy view's data of those systems.
     *
     * @return list<array<string, mixed>> user row columns plus `planet_id`, `p_galaxy`, `p_system`, `p_planet`, `p_name`, `p_last_update`, `m_last_update`, `total_points`, `ally_tag`, `diplo_level`, `is_buddy`, `is_bot`
     */
    private function galaxyRows(BotActions $ctx, int $range): array
    {
        $conditions = [];
        $params = [
            ':uni' => $this->universe()->current(),
            ':me' => (int) $ctx->user['id'],
            ':ally' => (int) $ctx->user['ally_id'],
            ':accept' => 1,
            ':stat' => 1,
            ':plain' => 1,
        ];
        $i = 0;
        foreach ($ctx->planets as $planet) {
            $conditions[] = '(p.galaxy = :g' . $i . ' AND p.`system` BETWEEN :a' . $i . ' AND :b' . $i . ')';
            $params[':g' . $i] = (int) $planet['galaxy'];
            $params[':a' . $i] = max(1, (int) $planet['system'] - $range);
            $params[':b' . $i] = (int) $planet['system'] + $range;
            $i++;
        }
        if ($conditions === []) {
            return [];
        }

        return $this->db->select(
            'SELECT u.*, p.id AS planet_id, p.galaxy AS p_galaxy, p.`system` AS p_system, p.planet AS p_planet, p.name AS p_name,
                p.last_update AS p_last_update, COALESCE(m.last_update, 0) AS m_last_update, s.total_points, a.ally_tag,
                d.level AS diplo_level,
                EXISTS (SELECT 1 FROM %%BUDDY%% bd LEFT JOIN %%BUDDY_REQUEST%% br ON br.id = bd.id
                    WHERE br.id IS NULL AND ((bd.sender = :me AND bd.owner = u.id) OR (bd.sender = u.id AND bd.owner = :me))) AS is_buddy,
                (bp.user_id IS NOT NULL) AS is_bot
            FROM %%PLANETS%% p
            INNER JOIN %%USERS%% u ON u.id = p.id_owner
            LEFT JOIN %%PLANETS%% m ON m.id = p.id_luna
            LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = :stat
            LEFT JOIN %%ALLIANCE%% a ON a.id = u.ally_id
            LEFT JOIN %%DIPLO%% d ON d.accept = :accept AND :ally > 0 AND u.ally_id > 0
                AND ((d.owner_1 = :ally AND d.owner_2 = u.ally_id) OR (d.owner_1 = u.ally_id AND d.owner_2 = :ally))
            LEFT JOIN %%BOT_PLAYERS%% bp ON bp.user_id = u.id
            WHERE p.universe = :uni AND p.planet_type = :plain AND p.destruyed = 0 AND p.id_owner <> :me
              AND (' . implode(' OR ', $conditions) . ');',
            $params,
        );
    }

    /**
     * Why a galaxy row is no target, null when it may be one.
     *
     * @param array<string, mixed> $row see galaxyRows()
     * @param array<string, mixed> $settings profile settings
     * @param bool $grudge the bot holds a grudge against the owner (revenge: size and activity rules are lifted)
     */
    private function excludeReason(BotActions $ctx, array $row, array $settings, bool $grudge = false): ?string
    {
        $now = $this->state->runtime->timestamp;
        $me = $ctx->user;
        $reason = match (true) {
            (int) $row['authlevel'] > AuthLevel::User->value => 'staff',
            (int) $row['urlaubs_modus'] === 1 => 'vacation',
            (int) $row['banaday'] > $now => 'banned',
            (int) $me['ally_id'] > 0 && (int) $row['ally_id'] === (int) $me['ally_id'] => 'own alliance',
            (int) $row['is_buddy'] === 1 => 'buddy',
            $row['diplo_level'] !== null && (int) $row['diplo_level'] !== 5 => 'pact partner',
            (bool) $row['is_bot'] && empty($settings['attack_bots']) => 'bot (not wanted)',
            !(bool) $row['is_bot'] && empty($settings['attack_humans']) => 'human (not wanted)',
            default => $this->sizeReason($ctx, $row, $grudge),
        };
        if ($reason !== null) {
            return $reason;
        }

        $noob = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($me), $this->models()->user($row));
        if ($noob['NoobPlayer']) {
            return 'noob protection';
        }

        return $noob['StrongPlayer'] ? 'strong protection' : null;
    }

    /**
     * The bot knobs on whom to attack: players active within the hour only when `attack_actives` is on, and the target's points
     * against the minimum points and the minimum share of the bot's own points.
     *
     * @param array<string, mixed> $row see galaxyRows()
     * @param bool $grudge revenge on this player: the size and activity rules do not apply
     */
    private function sizeReason(BotActions $ctx, array $row, bool $grudge = false): ?string
    {
        $bot = $ctx->bot;
        $points = (float) ($row['total_points'] ?? 0);
        if ($grudge) {
            return null;
        }
        $warmonger = $bot->warmongerActive((float) ($ctx->user['total_points'] ?? 0));
        if (!$bot->attackActives && !$warmonger && ($this->state->runtime->timestamp - max((int) $row['p_last_update'], (int) $row['m_last_update'])) < 3600) {
            return 'active player (not wanted)';
        }
        if ($bot->minTargetPoints > 0 && $points < $bot->minTargetPoints) {
            return 'too few points';
        }
        if ($bot->minTargetRatioPercent > 0 && $points * 100 < max(1.0, (float) ($ctx->user['total_points'] ?? 1)) * $bot->minTargetRatioPercent) {
            return 'too small against the bot';
        }

        return null;
    }

    /**
     * A target as the bot rates it from the galaxy view. Score: base 100, plus the profile's weight for inactive players (7 days
     * `i`, 28 days `I`), minus for players active within 15 / 60 minutes, plus for weaker players (fewer points), minus
     * for distance, plus for farm-list targets (hit before with loot).
     *
     * @param array<string, mixed> $row see galaxyRows()
     * @param array<string, mixed> $settings profile settings
     * @param array<string, mixed> $known raid memory of this planet
     * @param float $grudge the bot's grudge against the owner (0 = none): raises the score, marks the target `revenge`
     * @return array<string, mixed>
     */
    private function candidate(BotActions $ctx, array $row, array $settings, array $known, float $grudge = 0.0): array
    {
        $now = $this->state->runtime->timestamp;
        $minutes = (int) floor(($now - max((int) $row['p_last_update'], (int) $row['m_last_update'])) / 60);
        $status = $this->protectionRules()->userStatus($row);
        $distance = $this->systemDistance($ctx, $row);
        $myPoints = max(1.0, (float) ($ctx->user['total_points'] ?? 1));
        $points = (float) ($row['total_points'] ?? 0);

        $score = 100.0;
        $prefer = (int) $settings['prefer_inactive'];
        $score += in_array('longinactive', $status, true) ? $prefer * 1.0 : (in_array('inactive', $status, true) ? $prefer * 0.7 : 0);
        $warmonger = !(bool) $row['is_bot'] && $ctx->bot->warmongerActive($myPoints);
        $score -= $warmonger ? 0 : ($minutes < 15 ? 45 : ($minutes < 60 ? 20 : 0));
        $score += $warmonger && $minutes < 60 ? 20 : 0;
        $score += min(150.0, $grudge * 6);
        $score += 30 * (1 - min(2.0, $points / $myPoints)) / 1.0;
        $score -= 35 * $distance / $this->range($ctx, $settings);
        $score += !empty($known['last_loot']) ? 35 : 0;
        $score += mt_rand(-150, 150) / 10; // players differ in taste: several bots do not all pick the same victim

        return [
            'planet_id' => (int) $row['planet_id'],
            'user_id' => (int) $row['id'],
            'name' => (string) $row['username'],
            'planet_name' => (string) $row['p_name'],
            'galaxy' => (int) $row['p_galaxy'],
            'system' => (int) $row['p_system'],
            'planet' => (int) $row['p_planet'],
            'coords' => $row['p_galaxy'] . ':' . $row['p_system'] . ':' . $row['p_planet'],
            'points' => $points,
            'ally_tag' => (string) ($row['ally_tag'] ?? ''),
            'is_bot' => (bool) $row['is_bot'],
            'activity_minutes' => $minutes < 60 ? $minutes : null,
            'status' => $status,
            'revenge' => $grudge > 0,
            'distance' => $distance,
            'score' => round($score, 2),
        ];
    }

    /**
     * Systems between the target and the nearest own planet in its galaxy (a planet of another galaxy counts as 1000).
     *
     * @param array<string, mixed> $row
     */
    private function systemDistance(BotActions $ctx, array $row): int
    {
        $best = 1000;
        foreach ($ctx->planets as $planet) {
            if ((int) $planet['galaxy'] === (int) $row['p_galaxy']) {
                $best = min($best, abs((int) $planet['system'] - (int) $row['p_system']));
            }
        }

        return $best;
    }
}
