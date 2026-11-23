<?php

declare(strict_types=1);

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * The shared target list of an alliance (table alliance_targets): members note coordinates worth attacking, claim a
 * target for a number of hours so nobody else hits the same farm, and the managers (right TARGETS) clean up.
 *
 * Rules: every statement is scoped to the alliance; a live claim (claimed_until in the future) can only be taken
 * over by nobody but its holder (who may renew it) and only be lifted by the holder or a manager; a target can be
 * deleted by a manager or by the member who added it; an expired claim counts as no claim.
 */
class AllianceTargetService
{
    use GameUtilities;

    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXISTS = 'exists';
    public const FULL = 'full';
    public const MISSING = 'missing';
    public const TAKEN = 'taken';
    public const DENIED = 'denied';

    public const MAX_TARGETS = 200;
    public const MAX_CLAIM_HOURS = 72;
    public const NOTE_LENGTH = 255;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * The targets of the alliance with the owner of the planet (when it exists), an inactive marker (owner offline
     * longer than INACTIVE, 2 = longer than INACTIVE_LONG) and the active claim.
     *
     * @return list<array<string, mixed>> id, galaxy, system, planet, planet_type, note, added_by, adder, created_at,
     *         owner_id, owner_name, planet_name, inactive (0|1|2), claimed_by, claimer, claimed_until (null without live claim)
     */
    public function list(int $allianceId): array
    {
        $now = $this->state->runtime->timestamp;
        $sql = 'SELECT t.id, t.galaxy, t.`system`, t.planet, t.planet_type, t.note, t.added_by, t.created_at,
                t.claimed_by, t.claimed_until, a.username AS adder, c.username AS claimer,
                p.name AS planet_name, u.id AS owner_id, u.username AS owner_name, u.onlinetime AS owner_online
            FROM %%ALLIANCE_TARGETS%% t
            LEFT JOIN %%USERS%% a ON a.id = t.added_by
            LEFT JOIN %%USERS%% c ON c.id = t.claimed_by
            LEFT JOIN %%PLANETS%% p ON p.universe = :universe AND p.galaxy = t.galaxy AND p.`system` = t.`system`
                AND p.planet = t.planet AND p.planet_type = t.planet_type
            LEFT JOIN %%USERS%% u ON u.id = p.id_owner
            WHERE t.alliance_id = :allianceId
            ORDER BY t.galaxy, t.`system`, t.planet, t.planet_type;';
        $rows = $this->db->select($sql, [
            ':universe' => $this->universe()->current(),
            ':allianceId' => $allianceId,
        ]);

        foreach ($rows as &$row) {
            $live = $row['claimed_by'] !== null && (int) $row['claimed_until'] > $now;
            if (!$live) {
                $row['claimed_by'] = null;
                $row['claimer'] = null;
                $row['claimed_until'] = null;
            }
            $row['inactive'] = match (true) {
                $row['owner_online'] === null => 0,
                $now - (int) $row['owner_online'] > INACTIVE_LONG => 2,
                $now - (int) $row['owner_online'] > INACTIVE => 1,
                default => 0,
            };
        }
        unset($row);

        return $rows;
    }

    /**
     * Adds a target with a note.
     *
     * @return string OK, INVALID (coordinates outside the universe, type not planet/debris/moon), EXISTS or FULL
     */
    public function add(int $allianceId, int $userId, int $galaxy, int $system, int $planet, int $type, string $note): string
    {
        $config = $this->configs->get();
        if (
            $galaxy < 1 || $galaxy > $config->max_galaxy
            || $system < 1 || $system > $config->max_system
            || $planet < 1 || $planet > $config->max_planets + 3
            || !in_array($type, [1, 2, 3], true)
        ) {
            return self::INVALID;
        }

        $count = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%ALLIANCE_TARGETS%% WHERE alliance_id = :id;',
            [':id' => $allianceId],
            'c',
        );
        if ($count >= self::MAX_TARGETS) {
            return self::FULL;
        }

        $exists = $this->db->selectSingle(
            'SELECT id FROM %%ALLIANCE_TARGETS%% WHERE alliance_id = :id AND galaxy = :g AND `system` = :s AND planet = :p AND planet_type = :t;',
            [':id' => $allianceId, ':g' => $galaxy, ':s' => $system, ':p' => $planet, ':t' => $type],
            'id',
        );
        if ($exists) {
            return self::EXISTS;
        }

        $this->db->insert(
            'INSERT INTO %%ALLIANCE_TARGETS%% SET alliance_id = :id, galaxy = :g, `system` = :s, planet = :p, planet_type = :t,
                note = :note, added_by = :user, created_at = :now;',
            [
                ':id' => $allianceId, ':g' => $galaxy, ':s' => $system, ':p' => $planet, ':t' => $type,
                ':note' => mb_substr($note, 0, self::NOTE_LENGTH), ':user' => $userId,
                ':now' => $this->state->runtime->timestamp,
            ],
        );

        return self::OK;
    }

    /**
     * Claims a target for 1..MAX_CLAIM_HOURS hours. The holder of a live claim may renew it, everybody else is refused.
     *
     * @return string OK, MISSING (not a target of the alliance) or TAKEN (somebody else's live claim)
     */
    public function claim(int $allianceId, int $targetId, int $userId, int $hours): string
    {
        $row = $this->find($allianceId, $targetId);
        if ($row === null) {
            return self::MISSING;
        }

        $now = $this->state->runtime->timestamp;
        if ($this->isLive($row, $now) && (int) $row['claimed_by'] !== $userId) {
            return self::TAKEN;
        }

        $hours = max(1, min(self::MAX_CLAIM_HOURS, $hours));
        $this->db->update(
            'UPDATE %%ALLIANCE_TARGETS%% SET claimed_by = :user, claimed_until = :until WHERE id = :id AND alliance_id = :alliance;',
            [':user' => $userId, ':until' => $now + $hours * 3600, ':id' => $targetId, ':alliance' => $allianceId],
        );

        return self::OK;
    }

    /**
     * Lifts a claim: the holder may, and so may a manager. An expired or missing claim is simply cleared.
     *
     * @return string OK, MISSING or DENIED (live claim of somebody else, caller is no manager)
     */
    public function unclaim(int $allianceId, int $targetId, int $userId, bool $manager): string
    {
        $row = $this->find($allianceId, $targetId);
        if ($row === null) {
            return self::MISSING;
        }

        if ($this->isLive($row, $this->state->runtime->timestamp) && (int) $row['claimed_by'] !== $userId && !$manager) {
            return self::DENIED;
        }

        $this->db->update(
            'UPDATE %%ALLIANCE_TARGETS%% SET claimed_by = NULL, claimed_until = NULL WHERE id = :id AND alliance_id = :alliance;',
            [':id' => $targetId, ':alliance' => $allianceId],
        );

        return self::OK;
    }

    /**
     * Deletes a target: managers (right TARGETS) any, a member only the ones he added.
     *
     * @return string OK, MISSING or DENIED
     */
    public function delete(int $allianceId, int $targetId, int $userId, bool $manager): string
    {
        $row = $this->find($allianceId, $targetId);
        if ($row === null) {
            return self::MISSING;
        }

        if (!$manager && (int) $row['added_by'] !== $userId) {
            return self::DENIED;
        }

        $this->db->delete(
            'DELETE FROM %%ALLIANCE_TARGETS%% WHERE id = :id AND alliance_id = :alliance;',
            [':id' => $targetId, ':alliance' => $allianceId],
        );

        return self::OK;
    }

    /** @return array<string, mixed>|null */
    private function find(int $allianceId, int $targetId): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT * FROM %%ALLIANCE_TARGETS%% WHERE id = :id AND alliance_id = :alliance;',
            [':id' => $targetId, ':alliance' => $allianceId],
        );

        return $row ?: null;
    }

    /** @param array<string, mixed> $row */
    private function isLive(array $row, int $now): bool
    {
        return $row['claimed_by'] !== null && (int) $row['claimed_until'] > $now;
    }
}
