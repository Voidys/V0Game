<?php

namespace App\Game\Statistics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Model\ElementCatalog;

/**
 * The records table of the statistics: highest tech levels and building levels with their holders.
 */
class StatRecords
{
    /**
     * @param list<int>                                $universes ids of all universes
     * @param array<int, ElementCatalog>               $catalogs  universe => game data of that universe
     */
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly array $universes,
        private readonly array $catalogs,
    ) {}

    /**
     * Records of the highest research levels of the (non-hidden) players of a universe, per tech.
     *
     * @return array<mixed>
     */
    private function getHighestUser(string $type, int $uni): array
    {
        $elements = $this->catalogs[$uni];

        $columns = [];
        foreach ($elements->ids($type) as $Techno) {
            $columns[$Techno] = $elements->column($Techno);
        }

        // Highest level per column among the non-hidden users, then every user holding such a level
        // (including hidden ones, as before) - two queries instead of two per column.
        return $this->getHighestRows('USERS', 'id', $columns, $uni);
    }

    /**
     * Records of the highest building levels of the (non-hidden) players' planets of a universe, per building.
     *
     * @return array<mixed>
     */
    private function getHighestPlanet(string $type, int $uni): array
    {
        $elements = $this->catalogs[$uni];

        $columns = [];
        foreach ($elements->ids($type) as $kind) {
            $columns[$kind] = $elements->column($kind);
        }

        return $this->getHighestRows('PLANETS', 'id_owner', $columns, $uni);
    }

    /**
     * Two QueryBuilder queries: the maximum per column among the rows that count (users below the stat level; for planets
     * the standing planets of such users), then every row holding one of the maxima. The column names come from the game's
     * resource table.
     *
     * @param string $table        USERS (rows are users) or PLANETS (rows are planets, owner by id_owner)
     * @param string $idColumn     column of $table that identifies the user
     * @param array<mixed>  $columns      element id => column name
     * @return array<mixed> list (per element with a level above 0) of rows [id, level, 0 => element id]
     */
    private function getHighestRows(string $table, string $idColumn, array $columns, int $uni): array
    {
        $db = $this->db;
        $isPlanet = $table === 'PLANETS';
        $level = $this->configs->get($uni)->stat_level;

        if ($columns === []) {
            return [];
        }

        $maxSelect = [];
        foreach ($columns as $element => $column) {
            $maxSelect[] = "MAX(" . ($isPlanet ? 'p.' : '') . "`$column`) AS `m$element`";
        }
        $maxQuery = $db->qb()->select(...$maxSelect);
        if ($isPlanet) {
            $maxQuery->from($db->table('PLANETS'), 'p')
                ->join('p', $db->table('USERS'), 'u', 'p.id_owner = u.id')
                ->where('p.universe = :universe')
                ->andWhere('p.destruyed = 0')
                ->andWhere('u.authlevel < :level')
                ->andWhere('u.bana = 0');
        } else {
            $maxQuery->from($db->table('USERS'))
                ->where('universe = :universe')
                ->andWhere('authlevel < :level')
                ->andWhere('bana = 0');
        }
        $max = $db->fetchRow($maxQuery->setParameter('universe', $uni)->setParameter('level', $level));

        $wanted = [];
        $holderQuery = $db->qb()->from($db->table($table))->where('universe = :universe')->setParameter('universe', $uni);
        // the same players as for the maxima: no hidden staff, no banned players (they could tie the record)
        if ($isPlanet) {
            $holderQuery->andWhere('destruyed = 0')
                ->andWhere('id_owner IN (SELECT id FROM ' . $db->table('USERS') . ' WHERE authlevel < :level AND bana = 0)');
        } else {
            $holderQuery->andWhere('authlevel < :level')->andWhere('bana = 0');
        }
        $holderQuery->setParameter('level', $level);
        $holderQuery->addSelect('`' . $idColumn . '` AS `id`');
        $conditions = [];
        foreach ($columns as $element => $column) {
            $value = $max['m' . $element] ?? null;
            if ($value !== null && $value > 0) {
                $wanted[$element] = $value;
                $conditions[] = $holderQuery->expr()->eq('`' . $column . '`', (int) $value);
                $holderQuery->addSelect('`' . $column . '` AS `c' . $element . '`');
            }
        }
        if ($wanted === []) {
            return [];
        }

        $rows = $db->fetchAll($holderQuery->andWhere($holderQuery->expr()->or(...$conditions)));

        $result = [];
        foreach ($wanted as $element => $value) {
            $dataFinal = [];
            $seen = [];
            foreach ($rows as $row) {
                if ($row['c' . $element] != $value) {
                    continue;
                }
                if ($isPlanet) {
                    // DISTINCT id_owner
                    if (isset($seen[$row['id']])) {
                        continue;
                    }
                    $seen[$row['id']] = true;
                }
                $dataFinal[] = ['id' => $row['id'], 'level' => $row['c' . $element], 0 => $element];
            }
            $result[] = $dataFinal;
        }

        return $result;
    }

    /**
     * Inserts the record rows of a universe into the records table (in chunks of 1000 rows).
     *
     * @param array<mixed> $records list per element of rows [id, level, 0 => element id]
     */
    private function insertQueries(array $records, int $uni): void
    {
        $db = $this->db;
        $values = [];
        $params = [];
        foreach ($records as $record) {
            foreach ($record as $row) {
                $values[] = '(?, ?, ?, ?)';
                array_push($params, $row['id'], $row['0'], $row['level'], $uni);
            }
        }

        foreach (array_chunk($values, 1000, true) as $offset => $chunk) {
            $sql = "INSERT INTO %%RECORDS%% (userID, elementID, level, universe) VALUES " . implode(', ', $chunk) . ";";
            $db->insert($sql, array_slice($params, array_key_first($chunk) * 4, count($chunk) * 4));
        }
    }

    /**
     * Rebuilds the records table (highest tech and building level per element and universe): clears it and writes the
     * players holding the highest level of each element. Hidden admins (authlevel at or above stat_level) and banned players do not set records.
     */
    public function buildRecords(): void
    {
        $db = $this->db;
        $sql = "DELETE FROM %%RECORDS%%;";
        $db->delete($sql);

        foreach ($this->universes as $uni) {
            $tech = $this->getHighestUser('tech', $uni);
            $buildings = $this->getHighestPlanet('build', $uni);
            //$defenses = $this->getHighestPlanet('defense', $uni);
            //$fleet = $this->getHighestPlanet('fleet', $uni);
            //$missiles = $this->getHighestPlanet('missile', $uni);
            $this->insertQueries($tech, $uni);
            $this->insertQueries($buildings, $uni);
        }
    }
}
