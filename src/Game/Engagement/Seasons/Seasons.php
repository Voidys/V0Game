<?php

declare(strict_types=1);

namespace App\Game\Engagement\Seasons;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\BattleEngine\BattleSide;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\Cosmetics\Cosmetics;
use App\Game\Engagement\EngagementFeature;
use App\Game\Enum\MessageType;

/**
 * Seasons: a ladder that runs for a configurable number of days (no economy reset). Players earn points in five categories
 * (raider, warrior, defender, explorer, recycler) from fights and statistics; when a season ends the top players of each
 * category get badges (and the winner a title and a name colour), then the next season starts at once.
 * Bots and staff never score and never appear in the ladder (bot opponents count at a reduced factor, repeated fights against the
 * same opponent diminish); fights between neutral players (same alliance, buddies, pact, same IP) and
 * small fights do not count.
 */
final class Seasons extends EngagementFeature
{
    public const CATEGORIES = ['raider', 'warrior', 'defender', 'explorer', 'recycler'];

    private ?FightNeutrality $neutrality = null;

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'seasons_enabled', false);
    }

    /** Categories the admin switched on for the universe. @return list<string> */
    public function categories(int $universe): array
    {
        return array_values(array_filter(
            self::CATEGORIES,
            fn(string $category): bool => (bool) $this->setting($universe, 'season_cat_' . $category, true),
        ));
    }

    /** Explorer (expedition count) and recycler (recycled metal + crystal) points of real players. */
    public function onStat(int $universe, int $userId, AdvancedStatsEnum $key, int $value): void
    {
        $category = match ($key) {
            AdvancedStatsEnum::EXPO_COUNT => 'explorer',
            AdvancedStatsEnum::RECYCLED_METAL, AdvancedStatsEnum::RECYCLED_CRYSTAL => 'recycler',
            default => null,
        };
        if ($category === null || !$this->enabled($universe) || !in_array($category, $this->categories($universe), true)) {
            return;
        }
        $season = $this->openSeason($universe);
        if ($season === null || (int) $season['ends_at'] <= $this->state->runtime->timestamp || !$this->credited($userId)) {
            return;
        }
        $this->addScore((int) $season['id'], $userId, $category, (float) $value);
    }

    /**
     * Fight categories: loot goes to the raiders, the defenders' lost value to the attackers (warrior) and the attackers' lost
     * value to the defenders (defender), each split evenly over the credited players of the side. A player is credited when he
     * is no bot or staff member and has at least one opponent he is not neutral to. His share is weighted: bot opponents count at
     * season_bot_factor_percent, and once he fought the same opponent season_pair_full_fights times, further fights count 10%.
     * Pirate bases and fights below the minimum value do not count.
     */
    public function onBattle(BattleOutcome $outcome): void
    {
        $universe = $outcome->universe;
        if ($outcome->kind === 'pirate' || $outcome->defenders === [] || !$this->enabled($universe)) {
            return;
        }
        $season = $this->openSeason($universe);
        $loot = $outcome->lootTotal();
        if ($season === null || (int) $season['ends_at'] <= $this->state->runtime->timestamp || max($outcome->totalLost(), $loot) < (int) $this->setting($universe, 'season_min_fight_value', 50000)) {
            return;
        }

        $attackers = array_keys($outcome->attackers);
        $defenders = array_keys($outcome->defenders);
        $this->neutrality()->users([...$attackers, ...$defenders]);
        $seasonId = (int) $season['id'];
        $categories = $this->categories($universe);
        $attacking = $this->holdingShare($outcome, $this->creditedWithOpponents($attackers, $defenders));
        $defending = $this->holdingShare($outcome, $this->creditedWithOpponents($defenders, $attackers));
        $sides = [
            [$attacking, ['raider' => $loot, 'warrior' => (float) $outcome->losses->value(BattleSide::Defender)]],
            [$defending, ['defender' => (float) $outcome->losses->value(BattleSide::Attacker)]],
        ];
        foreach ($sides as [$credited, $values]) {
            $weights = $this->weighted($universe, $seasonId, $credited);
            $shares = $this->startShares($outcome, array_keys($weights));
            foreach ($values as $category => $value) {
                if (in_array($category, $categories, true)) {
                    $this->split($seasonId, $weights, $shares, $category, $value);
                }
            }
        }
    }

    /**
     * Opens season 1 (or the next number) when none runs; closes the running one once its end has passed (only the run that wins
     * the UPDATE continues) and starts the next season at once. Then every closed season that was not rewarded yet is rewarded
     * (idempotent, so a failed run is repeated by the next tick).
     */
    public function tick(int $universe, int $now): void
    {
        if (!$this->enabled($universe)) {
            return;
        }
        $season = $this->openSeason($universe);
        if ($season !== null && (int) $season['ends_at'] <= $now) {
            $this->closeSeason((int) $season['id'], $now);
            $season = $this->openSeason($universe);
        }
        if ($season === null) {
            $this->startSeason($universe, $now);
        }

        $pending = $this->db->select(
            'SELECT * FROM %%ENG_SEASONS%% WHERE `universe` = :universe AND `closed_at` IS NOT NULL AND `rewarded_at` IS NULL ORDER BY `id`;',
            [':universe' => $universe],
        );
        foreach ($pending as $closed) {
            $this->reward($universe, $closed);
            $this->db->update(
                'UPDATE %%ENG_SEASONS%% SET `rewarded_at` = :now WHERE `id` = :id AND `rewarded_at` IS NULL;',
                [':now' => $now, ':id' => $closed['id']],
            );
        }
    }

    /**
     * The running season of the universe.
     *
     * @return array<string, mixed>|null
     */
    public function openSeason(int $universe): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT * FROM %%ENG_SEASONS%% WHERE `universe` = :universe AND `closed_at` IS NULL ORDER BY `id` DESC LIMIT 1;',
            [':universe' => $universe],
        );

        return empty($row) ? null : $row;
    }

    /**
     * Top of one category, bots, banned and deleted players left out.
     *
     * @return list<array{user_id: int, username: string, score: float, rank: int}>
     */
    public function ladder(int $universe, int $seasonId, string $category, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT s.`user_id`, u.`username`, s.`score` FROM %%ENG_SEASON_SCORES%% s
            INNER JOIN %%USERS%% u ON u.`id` = s.`user_id`
            WHERE s.`season_id` = :season AND s.`category` = :category AND s.`score` > 0 AND ' . $this->eligibleUserSql() . '
            ORDER BY s.`score` DESC, s.`user_id` ASC LIMIT ' . max(1, $limit) . ';',
            [':season' => $seasonId, ':category' => $category, ':universe' => $universe],
        );
        $result = [];
        foreach ($rows as $index => $row) {
            $result[] = ['user_id' => (int) $row['user_id'], 'username' => (string) $row['username'], 'score' => (float) $row['score'], 'rank' => $index + 1];
        }

        return $result;
    }

    /**
     * Score and rank of a player in a category, null without score (or when he does not appear in the ladder).
     *
     * @return array{score: float, rank: int}|null
     */
    public function standing(int $universe, int $seasonId, string $category, int $userId): ?array
    {
        $own = $this->db->selectSingle(
            'SELECT s.`score` FROM %%ENG_SEASON_SCORES%% s INNER JOIN %%USERS%% u ON u.`id` = s.`user_id`
            WHERE s.`season_id` = :season AND s.`category` = :category AND s.`user_id` = :user AND s.`score` > 0 AND ' . $this->eligibleUserSql() . ';',
            [':season' => $seasonId, ':category' => $category, ':user' => $userId, ':universe' => $universe],
            'score',
        );
        if ($own === false || $own === null) {
            return null;
        }
        $better = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%ENG_SEASON_SCORES%% s INNER JOIN %%USERS%% u ON u.`id` = s.`user_id`
            WHERE s.`season_id` = :season AND s.`category` = :category AND ' . $this->eligibleUserSql() . '
            AND (s.`score` > :score OR (s.`score` = :score AND s.`user_id` < :user));',
            [':season' => $seasonId, ':category' => $category, ':user' => $userId, ':score' => $own, ':universe' => $universe],
            'anz',
        );

        return ['score' => (float) $own, 'rank' => $better + 1];
    }

    /**
     * The last closed seasons with the top 3 of each category that was scored. A closed season never changes, so its podium is
     * computed once and stored in the `archive` column.
     *
     * @return list<array{number: int, ends_at: int, tops: array<string, list<array{user_id: int, username: string, score: float, rank: int}>>}>
     */
    public function archive(int $universe, int $count = 6): array
    {
        $seasons = $this->db->select(
            'SELECT `id`, `number`, `ends_at`, `archive` FROM %%ENG_SEASONS%% WHERE `universe` = :universe AND `closed_at` IS NOT NULL ORDER BY `id` DESC LIMIT ' . max(1, $count) . ';',
            [':universe' => $universe],
        );
        $result = [];
        foreach ($seasons as $season) {
            $tops = is_string($season['archive']) ? json_decode($season['archive'], true) : null;
            if (!is_array($tops)) {
                $tops = [];
                foreach (self::CATEGORIES as $category) {
                    $top = $this->ladder($universe, (int) $season['id'], $category, 3);
                    if ($top !== []) {
                        $tops[$category] = $top;
                    }
                }
                $this->db->update(
                    'UPDATE %%ENG_SEASONS%% SET `archive` = :archive WHERE `id` = :id;',
                    [':archive' => json_encode($tops), ':id' => $season['id']],
                );
            }
            $result[] = ['number' => (int) $season['number'], 'ends_at' => (int) $season['ends_at'], 'tops' => $tops];
        }

        return $result;
    }

    /** Users that count for ladders: this universe, not banned, no staff, no bot (needs :universe and the alias u). */
    private function eligibleUserSql(): string
    {
        return 'u.`universe` = :universe AND u.`bana` = 0 AND u.`authlevel` = 0 AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.`user_id` = u.`id`)';
    }

    private function neutrality(): FightNeutrality
    {
        return $this->neutrality ??= new FightNeutrality($this->db, $this->state->runtime->timestamp);
    }

    /** May the user score at all (exists, no bot, no staff, not banned)? */
    private function credited(int $userId): bool
    {
        $row = $this->neutrality()->user($userId);

        return $row !== null && (int) $row['bot'] === 0 && (int) $row['authlevel'] === 0 && (int) $row['bana'] === 0;
    }

    /**
     * Players of $side that may score and are not neutral to at least one of the $opponents.
     *
     * @param list<int|string> $side
     * @param list<int|string> $opponents
     * @return array<int, list<int>> credited user id => his non-neutral opponents
     */
    private function creditedWithOpponents(array $side, array $opponents): array
    {
        $result = [];
        foreach ($side as $userId) {
            $userId = (int) $userId;
            if (!$this->credited($userId)) {
                continue;
            }
            foreach ($opponents as $opponent) {
                if ((int) $opponent !== $userId && !$this->neutrality()->pairIsNeutral($userId, (int) $opponent)) {
                    $result[$userId][] = (int) $opponent;
                }
            }
        }

        return $result;
    }

    /**
     * Weight of every credited player of a fight: bot opponents only (all counted opponents are bots) count at
     * season_bot_factor_percent; after season_pair_full_fights fights against the same opponent (the highest count of his
     * opponents) a fight counts 10%. Records the fight for the pairs.
     *
     * @param array<int, list<int>> $opponentsByUser
     * @return array<int, float> user id => weight 0..1
     */
    private function weighted(int $universe, int $seasonId, array $opponentsByUser): array
    {
        $full = max(1, min(50, (int) $this->setting($universe, 'season_pair_full_fights', 5)));
        $botFactor = max(0, min(100, (int) $this->setting($universe, 'season_bot_factor_percent', 50))) / 100;
        $weights = [];
        foreach ($opponentsByUser as $userId => $opponents) {
            $onlyBots = true;
            foreach ($opponents as $opponent) {
                $onlyBots = $onlyBots && (int) ($this->neutrality()->user($opponent)['bot'] ?? 0) === 1;
            }
            $fights = (int) $this->db->selectSingle(
                'SELECT MAX(`fights`) AS f FROM %%ENG_SEASON_PAIRS%% WHERE `season_id` = :season AND `user_id` = :user AND `opponent_id` IN (' . implode(',', $opponents) . ');',
                [':season' => $seasonId, ':user' => $userId],
                'f',
            );
            $weights[$userId] = ($fights >= $full ? 0.1 : 1.0) * ($onlyBots ? $botFactor : 1.0);
            foreach ($opponents as $opponent) {
                $this->db->insert(
                    'INSERT INTO %%ENG_SEASON_PAIRS%% (`season_id`, `user_id`, `opponent_id`, `fights`)
                    SELECT `id`, :user, :opponent, 1 FROM %%ENG_SEASONS%% WHERE `id` = :season AND `closed_at` IS NULL
                    ON DUPLICATE KEY UPDATE `fights` = `fights` + 1;',
                    [':season' => $seasonId, ':user' => $userId, ':opponent' => $opponent],
                );
            }
        }

        return $weights;
    }

    /**
     * Credited players that took a real part in the fight (BattleOutcome::holdsShare); the others get no credit.
     *
     * @param array<int, list<int>> $opponentsByUser
     * @return array<int, list<int>>
     */
    private function holdingShare(BattleOutcome $outcome, array $opponentsByUser): array
    {
        return array_filter($opponentsByUser, static fn(int $userId): bool => $outcome->holdsShare($userId), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Each credited player's share of the side's value by the units he brought (equal split when the start values are unknown).
     *
     * @param list<int> $userIds
     * @return array<int, float> user id => share, summing to 1
     */
    private function startShares(BattleOutcome $outcome, array $userIds): array
    {
        $values = [];
        foreach ($userIds as $userId) {
            $values[$userId] = (float) ($outcome->startValuePerUser[$userId] ?? 0);
        }
        $total = array_sum($values);
        $shares = [];
        foreach ($userIds as $userId) {
            $shares[$userId] = $total > 0 ? $values[$userId] / $total : 1 / count($userIds);
        }

        return $shares;
    }

    /**
     * @param array<int, float> $weights credited user id => weight
     * @param array<int, float> $shares credited user id => share of the value; the share is weighted
     */
    private function split(int $seasonId, array $weights, array $shares, string $category, float $value): void
    {
        if ($weights === [] || $value <= 0) {
            return;
        }
        foreach ($weights as $userId => $weight) {
            $this->addScore($seasonId, $userId, $category, $value * ($shares[$userId] ?? 0.0) * $weight);
        }
    }

    /** Adds points; only lands while the season row is still open (a late fight never changes a closed season). */
    private function addScore(int $seasonId, int $userId, string $category, float $score): void
    {
        if ($score <= 0) {
            return;
        }
        $this->db->insert(
            'INSERT INTO %%ENG_SEASON_SCORES%% (`season_id`, `user_id`, `category`, `score`)
            SELECT `id`, :user, :category, :score FROM %%ENG_SEASONS%% WHERE `id` = :season AND `closed_at` IS NULL
            ON DUPLICATE KEY UPDATE `score` = `score` + :add;',
            [':season' => $seasonId, ':user' => $userId, ':category' => $category, ':score' => $score, ':add' => $score],
        );
    }

    /** Starts the next season (the highest number + 1) from $now; a concurrent run that was faster wins (unique number). */
    private function startSeason(int $universe, int $now): void
    {
        $days = max(7, min(90, (int) $this->setting($universe, 'season_length_days', 30)));
        $number = 1 + (int) $this->db->selectSingle('SELECT MAX(`number`) AS n FROM %%ENG_SEASONS%% WHERE `universe` = :universe;', [':universe' => $universe], 'n');
        try {
            $this->db->insert(
                'INSERT INTO %%ENG_SEASONS%% (`universe`, `number`, `starts_at`, `ends_at`) VALUES (:universe, :number, :start, :end);',
                [':universe' => $universe, ':number' => $number, ':start' => $now, ':end' => $now + $days * 86400],
            );
        } catch (\Throwable $e) {
            if (\App\Game\Core\Database::errorNumber($e) !== 1062) {
                throw $e;
            }
        }
    }

    /** Marks the season closed; true only for the one run that changed the row. */
    private function closeSeason(int $seasonId, int $now): bool
    {
        $this->db->update(
            'UPDATE %%ENG_SEASONS%% SET `closed_at` = :now WHERE `id` = :id AND `closed_at` IS NULL;',
            [':now' => $now, ':id' => $seasonId],
        );

        return $this->db->rowCount() === 1;
    }

    /**
     * Badges for the top players of each active category (code season{n}_{category}_{rank}), title and name colour for the winner,
     * a message for everybody whose badge was new (a repeated run grants and messages nothing twice) and one Discord post.
     *
     * @param array<string, mixed> $season the closed season row
     */
    private function reward(int $universe, array $season): void
    {
        $number = (int) $season['number'];
        $top = max(1, min(10, (int) $this->setting($universe, 'season_badges_top', 3)));
        $cosmetics = new Cosmetics($this->db);
        $winners = [];
        $anyNew = false;
        foreach ($this->categories($universe) as $category) {
            foreach ($this->ladder($universe, (int) $season['id'], $category, $top) as $entry) {
                $new = $cosmetics->grant($entry['user_id'], Cosmetics::KIND_BADGE, 'season' . $number . '_' . $category . '_' . $entry['rank'], 'season:' . $number);
                if ($entry['rank'] === 1) {
                    $cosmetics->grant($entry['user_id'], Cosmetics::KIND_TITLE, 'season' . $number . '_' . $category . '_champion', 'season:' . $number);
                    $cosmetics->grant($entry['user_id'], Cosmetics::KIND_COLOR, 'champion_gold', 'season:' . $number);
                    $winners[$category] = $entry['username'];
                }
                if ($new) {
                    $anyNew = true;
                    $this->notifyWinner($universe, $number, $category, $entry, $entry['rank'] === 1);
                }
            }
        }
        if ($anyNew) {
            $this->announce($universe, $number, $winners);
        }
    }

    /**
     * System message to a placed player, in his language.
     *
     * @param array{user_id: int, username: string, score: float, rank: int} $entry
     */
    private function notifyWinner(int $universe, int $number, string $category, array $entry, bool $champion = false): void
    {
        $lang = (string) $this->db->selectSingle('SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :id;', [':id' => $entry['user_id']], 'lang');
        $LNG = new \App\Game\Core\Language($this->configs, $lang !== '' ? $lang : 'en');
        $LNG->includeData(['L18N', 'INGAME']);
        $text = $LNG->format('season_msg_text', $number, $LNG['season_cat_' . $category], $entry['rank'], $this->viewHelpers()->prettyNumber((int) $entry['score']));
        if ($champion) {
            // relics (setting relics_src_season): the winner of a category gets one relic of the configured rarity (epic by default); guarded
            $this->achievementGuard()->run(function () use (&$text, $entry, $universe, $LNG): void {
                $text .= (new \App\Game\Engagement\Relics\RelicDrops($this->db, $this->configs, $this->state))->fromSource('season', (int) $entry['user_id'], $universe, $LNG, $this->state->runtime->timestamp);
            });
        }
        $this->playerMessenger()->sendMessage(
            $entry['user_id'],
            0,
            $LNG['season_sender'],
            MessageType::System->value,
            $LNG->format('season_msg_subject', $number),
            $text,
            $this->state->runtime->timestamp,
            1,
            $universe,
        );
    }

    /**
     * Posts the champions to Discord when the universe has a webhook configured.
     *
     * @param array<string, string> $winners category => name of the winner
     */
    private function announce(int $universe, int $number, array $winners): void
    {
        $hook = (string) ($this->configs->get($universe)->discord_logs_hook ?? '');
        if ($winners === [] || $hook === '' || !\App\Game\Core\Discord::isAllowedWebhook($hook)) {
            return;
        }
        $this->discord()->sendMessage($hook, 'Season ' . $number . ' ended', $winners);
    }
}
