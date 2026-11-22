<?php

namespace App\Game\Statistics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;

/**
 * Ranking pass of the statistics after the points are written: ranks per category, honor ranks and honor titles
 * (table statpoints), for every universe.
 */
class StatRanker
{
    private const STAT_TYPE_USER = 1;
    private const STAT_TYPE_ALLIANCE = 2;

    /** @param list<int> $universes ids of all universes */
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly array $universes,
    ) {}

    /**
     * Ranks users and alliances per universe by tech, build, defense, fleet, total and units points (ties by owner id).
     * With stat = 2 admins are hidden from the user ranking: they sort below players with the same points (their own
     * rank is still written). Writes the `*_rank` columns of statpoints.
     */
    public function setNewRanks(): void
    {
        $db = $this->db;
        foreach ($this->universes as $uni) {
            foreach (['tech', 'build', 'defs', 'fleet', 'total', 'units'] as $type) {
                // User ranking, invisible admins at bottom of same points
                if ($this->configs->get((int) $uni)->stat == 2) {
                    $sql = 'CREATE TEMPORARY TABLE temp_ranking AS
                        SELECT s.id_owner, s.' . $type . '_points, 0 AS ' . $type . '_rank
                        FROM %%STATPOINTS%% s
                        JOIN %%USERS%% u ON u.id = s.id_owner
                        WHERE s.universe = ' . $uni . ' AND s.stat_type = 1
                        ORDER BY s.' . $type . '_points DESC, u.authlevel ASC, s.id_owner ASC;';
                    $db->nativeQuery($sql);

                    $db->nativeQuery('SELECT @i := 0;');

                    $sql = 'UPDATE temp_ranking
                        SET ' . $type . '_rank = (SELECT @i := @i + 1);';
                    $db->update($sql);

                    $sql = 'UPDATE %%STATPOINTS%% s
                        JOIN temp_ranking tr ON s.id_owner = tr.id_owner
                        SET s.' . $type . '_rank = tr.' . $type . '_rank
                        WHERE s.universe = :uni AND s.stat_type = :type;';
                    $db->update($sql, [
                        ':uni' => $uni,
                        ':type' => 1,
                    ]);

                    $sql = 'DROP TEMPORARY TABLE temp_ranking;';
                    $db->nativeQuery($sql);
                } else { // User ranking, visible admins in normal order
                    $db->nativeQuery('SELECT @i := 0;');

                    $sql = 'UPDATE %%STATPOINTS%%
                        SET ' . $type . '_rank = (SELECT @i := @i + 1)
                        WHERE universe = :uni AND stat_type = :type
                        ORDER BY ' . $type . '_points DESC, id_owner ASC;';
                    $db->update($sql, [
                        ':uni' => $uni,
                        ':type' => 1,
                    ]);
                }

                // Alliance ranking
                $db->nativeQuery('SELECT @i := 0;');

                $sql = 'UPDATE %%STATPOINTS%%
                    SET ' . $type . '_rank = (SELECT @i := @i + 1)
                    WHERE universe = :uni AND stat_type = :type
                    ORDER BY ' . $type . '_points DESC, id_owner ASC;';
                $db->update($sql, [
                    ':uni' => $uni,
                    ':type' => 2,
                ]);
            }
        }
    }

    /**
     * Ranks users and alliances per universe by honor points (ties: more honorable fights, fewer dishonorable fights,
     * owner id; hidden admins below equal players when stat = 2). Writes `honor_rank`.
     */
    public function setHonorRanks(): void
    {
        $db = $this->db;

        foreach ($this->universes as $uni) {
            // User ranking, invisible admins at bottom of same points
            if ($this->configs->get((int) $uni)->stat == 2) {
                $sql = 'CREATE TEMPORARY TABLE temp_honor_ranking AS
                    SELECT s.id_owner, 0 AS honor_rank
                    FROM %%STATPOINTS%% s
                    JOIN %%USERS%% u ON u.id = s.id_owner
                    WHERE s.universe = ' . $uni . ' AND s.stat_type = 1
                    ORDER BY s.honor_points DESC, s.honorable_fights DESC,
                             s.dishonorable_fights ASC, u.authlevel ASC, s.id_owner ASC;';
                $db->nativeQuery($sql);

                $db->nativeQuery('SELECT @i := 0;');

                $db->update('UPDATE temp_honor_ranking SET honor_rank = (SELECT @i := @i + 1);');

                $db->update('UPDATE %%STATPOINTS%% s
                    JOIN temp_honor_ranking tr ON s.id_owner = tr.id_owner
                    SET s.honor_rank = tr.honor_rank
                    WHERE s.universe = :uni AND s.stat_type = :type;', [
                    ':uni' => $uni,
                    ':type' => self::STAT_TYPE_USER,
                ]);

                $db->nativeQuery('DROP TEMPORARY TABLE temp_honor_ranking;');
            } else {
                $db->nativeQuery('SELECT @i := 0;');

                $db->update('UPDATE %%STATPOINTS%%
                    SET honor_rank = (SELECT @i := @i + 1)
                    WHERE universe = :uni AND stat_type = :type
                    ORDER BY honor_points DESC, honorable_fights DESC,
                             dishonorable_fights ASC, id_owner ASC;', [
                    ':uni' => $uni,
                    ':type' => self::STAT_TYPE_USER,
                ]);
            }

            // Alliance ranking
            $db->nativeQuery('SELECT @i := 0;');

            $db->update('UPDATE %%STATPOINTS%%
                SET honor_rank = (SELECT @i := @i + 1)
                WHERE universe = :uni AND stat_type = :type
                ORDER BY honor_points DESC, honorable_fights DESC,
                         dishonorable_fights ASC, id_owner ASC;', [
                ':uni' => $uni,
                ':type' => self::STAT_TYPE_ALLIANCE,
            ]);
        }
    }

    /**
     * Assigns the honor titles of the users of each universe: top1/top10/top20/top30 by honor rank (within the best
     * 10/20/30 percent of the players, and above 50000/15000/2500/500 honor points) and the mirrored low1..low30
     * (at most -50000/-15000/-2500/-500 points); everybody else is "neutral". Writes `honor_title`.
     */
    public function setHonorTitles(): void
    {
        $db = $this->db;

        foreach ($this->universes as $uni) {
            $userAmount = (int) $db->selectSingle(
                'SELECT COUNT(*) AS count FROM %%USERS%% WHERE universe = :universe;',
                [':universe' => $uni],
                'count',
            );

            $totalRanked = (int) $db->selectSingle(
                'SELECT COUNT(*) AS count FROM %%STATPOINTS%%
                 WHERE universe = :universe AND stat_type = :type;',
                [':universe' => $uni, ':type' => self::STAT_TYPE_USER],
                'count',
            );

            if ($totalRanked === 0) {
                continue;
            }

            $limTop10 = (int) ceil(($userAmount - 1) * 0.10);
            $limTop20 = (int) ceil(($userAmount - 1) * 0.20);
            $limTop30 = (int) ceil(($userAmount - 1) * 0.30);

            $db->update(
                "UPDATE %%STATPOINTS%% SET honor_title = 'neutral'
                 WHERE universe = :universe AND stat_type = :type;",
                [':universe' => $uni, ':type' => self::STAT_TYPE_USER],
            );

            $tiers = [
                ['top1',  'honor_rank = :bound',  1,                            'honor_points >= :floor',  50000],
                ['top10', 'honor_rank < :bound',  $limTop10,                    'honor_points >= :floor',  15000],
                ['top20', 'honor_rank < :bound',  $limTop20,                    'honor_points >= :floor',   2500],
                ['top30', 'honor_rank < :bound',  $limTop30,                    'honor_points >= :floor',    500],
                ['low1',  'honor_rank = :bound',  $totalRanked,                 'honor_points <= :floor', -50000],
                ['low10', 'honor_rank > :bound',  $totalRanked - $limTop10 + 1, 'honor_points <= :floor', -15000],
                ['low20', 'honor_rank > :bound',  $totalRanked - $limTop20 + 1, 'honor_points <= :floor',  -2500],
                ['low30', 'honor_rank > :bound',  $totalRanked - $limTop30 + 1, 'honor_points <= :floor',   -500],
            ];

            foreach ($tiers as [$title, $rankPredicate, $bound, $pointsPredicate, $floor]) {
                if ($bound <= 0) {
                    continue;
                }

                $db->update(
                    "UPDATE %%STATPOINTS%%
                     SET honor_title = :title
                     WHERE universe = :universe AND stat_type = :type
                       AND honor_title = 'neutral'
                       AND {$rankPredicate} AND {$pointsPredicate};",
                    [
                        ':title'    => $title,
                        ':universe' => $uni,
                        ':type'     => self::STAT_TYPE_USER,
                        ':bound'    => $bound,
                        ':floor'    => $floor,
                    ],
                );
            }
        }
    }
}
