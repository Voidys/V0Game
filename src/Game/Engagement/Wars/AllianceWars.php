<?php

declare(strict_types=1);

namespace App\Game\Engagement\Wars;

use App\Game\Alliance\AllianceRepository;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\EngagementFeature;
use App\Game\Enum\DiploType;
use App\Game\Enum\MessageType;

/**
 * Scoreboard on top of the alliance diplomacy: while two alliances have an accepted war relation (DiploType::War) a
 * row in eng_wars tracks the destroyed value, the loot and the fights between them (scoring: WarScoring). The tick
 * opens rows for new war relations, closes rows whose relation is gone, and ends wars that reached war_max_days (then
 * the war relation is deleted like an accepted peace does). The side with the higher score wins; the leaders and
 * diplomats of both alliances get a message, and the alliance home page shows active and past wars.
 * There is no alliance history table in the game, so past wars are listed on the alliance page.
 */
final class AllianceWars extends EngagementFeature
{
    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'alliance_wars_enabled', false);
    }

    /** Adds the fight to the wars between the alliances of the two sides. */
    public function onBattle(BattleOutcome $outcome): void
    {
        (new WarScoring($this->db, $this->configs, $this->state, $this))->apply($outcome);
    }

    /** Days after which a war ends (0 = unlimited). */
    public function maxDays(int $universe): int
    {
        return max(0, (int) $this->setting($universe, 'war_max_days', 0));
    }

    /** Minimal lost value of a fight to count for the score. */
    public function minFightValue(int $universe): int
    {
        return max(0, (int) $this->setting($universe, 'war_min_fight_value', 20000));
    }

    /**
     * The open war row of two alliances while they have an accepted war relation (null without one); the row is created when missing.
     *
     * @return array<string, mixed>|null
     */
    public function openWar(int $universe, int $allyA, int $allyB, int $now): ?array
    {
        $key = $universe . ':' . min($allyA, $allyB) . ':' . max($allyA, $allyB);
        $relation = $this->db->selectSingle(
            'SELECT `owner_1`, `owner_2` FROM %%DIPLO%% WHERE `universe` = :u AND `level` = :war AND `accept` = 1
            AND ((`owner_1` = :a AND `owner_2` = :b) OR (`owner_1` = :b AND `owner_2` = :a));',
            [':u' => $universe, ':war' => DiploType::War->value, ':a' => $allyA, ':b' => $allyB],
        );

        if (!$relation) {
            return null;
        }
        $war = $this->db->selectSingle('SELECT * FROM %%ENG_WARS%% WHERE `open_pair` = :key;', [':key' => $key]);

        return $war ?: $this->start($universe, (int) $relation['owner_1'], (int) $relation['owner_2'], $key, $now);
    }

    /**
     * Inserts the row of a new war (the declaring alliance is side a); a parallel insert of the same pair loses against
     * the unique open_pair key.
     *
     * @return array<string, mixed>|null
     */
    private function start(int $universe, int $allyA, int $allyB, string $key, int $now): ?array
    {
        $names = [];
        foreach ($this->db->select('SELECT `id`, `ally_tag`, `ally_name` FROM %%ALLIANCE%% WHERE `id` IN (:a, :b);', [':a' => $allyA, ':b' => $allyB]) as $row) {
            $names[(int) $row['id']] = '[' . $row['ally_tag'] . '] ' . $row['ally_name'];
        }
        if (count($names) < 2) {
            return null;
        }
        $days = $this->maxDays($universe);
        $this->db->insert('INSERT IGNORE INTO %%ENG_WARS%% (`universe`, `ally_a`, `ally_b`, `name_a`, `name_b`, `started_at`, `ends_at`, `open_pair`)
            VALUES (:u, :a, :b, :na, :nb, :now, :ends, :key);', [
            ':u' => $universe, ':a' => $allyA, ':b' => $allyB, ':na' => mb_substr($names[$allyA], 0, 96), ':nb' => mb_substr($names[$allyB], 0, 96),
            ':now' => $now, ':ends' => $days > 0 ? $now + $days * 86400 : null, ':key' => $key,
        ]);

        return $this->db->selectSingle('SELECT * FROM %%ENG_WARS%% WHERE `open_pair` = :key;', [':key' => $key]) ?: null;
    }

    /** Opens rows for new war relations, closes the rows of ended relations and ends the wars that ran out. */
    public function tick(int $universe, int $now): void
    {
        foreach ($this->db->select('SELECT `owner_1`, `owner_2` FROM %%DIPLO%% WHERE `universe` = :u AND `level` = :war AND `accept` = 1;', [':u' => $universe, ':war' => DiploType::War->value]) as $relation) {
            $this->openWar($universe, (int) $relation['owner_1'], (int) $relation['owner_2'], $now);
        }
        foreach ($this->db->select('SELECT * FROM %%ENG_WARS%% WHERE `universe` = :u AND `ended_at` IS NULL;', [':u' => $universe]) as $war) {
            $relation = $this->hasRelation($war);
            // the end is fixed when the war starts: changing war_max_days only affects later wars
            $expired = $war['ends_at'] !== null && $now >= (int) $war['ends_at'];
            if (!$relation || $expired) {
                $this->end($war, $now, $relation);
            }
        }
    }

    /**
     * @param array<string, mixed> $war
     * @return bool whether the accepted war relation of the two alliances still exists
     */
    private function hasRelation(array $war): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%DIPLO%% WHERE `level` = :war AND `accept` = 1 AND ((`owner_1` = :a AND `owner_2` = :b) OR (`owner_1` = :b AND `owner_2` = :a));',
            [':war' => DiploType::War->value, ':a' => (int) $war['ally_a'], ':b' => (int) $war['ally_b']],
            'c',
        ) > 0;
    }

    /**
     * Closes a war once (UPDATE with a row-count check), then messages both alliances. A war that ran out while its relation
     * still exists also ends the relation: the war and open peace-offer rows of the pair are deleted, as accepting a peace does.
     *
     * @param array<string, mixed> $war
     */
    private function end(array $war, int $now, bool $relationStillThere): void
    {
        $a = (float) $war['score_a'];
        $b = (float) $war['score_b'];
        $result = $a > $b ? 'a' : ($b > $a ? 'b' : 'draw');
        $this->db->update('UPDATE %%ENG_WARS%% SET `ended_at` = :now, `result` = :r, `open_pair` = NULL WHERE `id` = :id AND `ended_at` IS NULL;', [':now' => $now, ':r' => $result, ':id' => (int) $war['id']]);
        if ($this->db->rowCount() !== 1) {
            return;
        }
        if ($relationStillThere) {
            $this->db->delete('DELETE FROM %%DIPLO%% WHERE `level` IN (:war, :peace) AND `owner_1` IN (:a, :b) AND `owner_2` IN (:a, :b);', [
                ':war' => DiploType::War->value, ':peace' => DiploType::Peace->value, ':a' => (int) $war['ally_a'], ':b' => (int) $war['ally_b'],
            ]);
        }
        $this->notify($war, $result, $now);
    }

    /**
     * Result message to the leaders and diplomats of both alliances, in their language.
     *
     * @param array<string, mixed> $war
     */
    private function notify(array $war, string $result, int $now): void
    {
        $winner = match ($result) {
            'a' => $war['name_a'],
            'b' => $war['name_b'],
            default => '',
        };
        $receivers = (new AllianceRepository($this->db, $this->configs, $this->state))->messageReceivers((int) $war['ally_b'], (int) $war['ally_a'], true);
        foreach ($receivers as $receiver) {
            $lang = $this->playerLookup()->getLanguage($receiver['lang']);
            $text = $lang->formatHtml($result === 'draw' ? 'wr_msg_draw' : 'wr_msg_win', $war['name_a'], $war['name_b'], $winner, $this->viewHelpers()->prettyNumber((float) $war['score_a']), $this->viewHelpers()->prettyNumber((float) $war['score_b']), (int) $war['fights']);
            $this->playerMessenger()->sendMessage((int) $receiver['id'], 0, $lang['wr_sender'], MessageType::Alliance->value, $lang['wr_msg_title'], $text, $now, 1, (int) $war['universe']);
        }
    }

    /**
     * Wars of an alliance for its home page: the active wars with the live score and the last 5 finished ones.
     *
     * @return array{active: list<array<string, mixed>>, past: list<array<string, mixed>>}|null null while the feature is off
     */
    public function summaryFor(int $allianceId, int $universe): ?array
    {
        if (!$this->enabled($universe) || $allianceId <= 0) {
            return null;
        }
        $rows = $this->db->select('SELECT * FROM %%ENG_WARS%% WHERE `universe` = :u AND (`ally_a` = :id OR `ally_b` = :id) AND (`ended_at` IS NULL OR `id` IN (
            SELECT `id` FROM (SELECT `id` FROM %%ENG_WARS%% WHERE `universe` = :u AND (`ally_a` = :id OR `ally_b` = :id) AND `ended_at` IS NOT NULL ORDER BY `ended_at` DESC LIMIT 5) recent))
            ORDER BY `ended_at` IS NULL DESC, `ended_at` DESC, `started_at` DESC;', [':u' => $universe, ':id' => $allianceId]);
        $summary = ['active' => [], 'past' => []];
        foreach ($rows as $war) {
            $own = (int) $war['ally_a'] === $allianceId;
            $summary[$war['ended_at'] === null ? 'active' : 'past'][] = [
                'enemy' => $own ? $war['name_b'] : $war['name_a'],
                'own_score' => (int) ($own ? $war['score_a'] : $war['score_b']),
                'enemy_score' => (int) ($own ? $war['score_b'] : $war['score_a']),
                'own_loot' => (int) ($own ? $war['loot_a'] : $war['loot_b']),
                'enemy_loot' => (int) ($own ? $war['loot_b'] : $war['loot_a']),
                'fights' => (int) $war['fights'],
                'started' => (int) $war['started_at'],
                'ended' => $war['ended_at'] === null ? null : (int) $war['ended_at'],
                'outcome' => $war['result'] === 'draw' || $war['result'] === null ? (string) $war['result'] : (($war['result'] === 'a') === $own ? 'won' : 'lost'),
            ];
        }

        return $summary;
    }
}
