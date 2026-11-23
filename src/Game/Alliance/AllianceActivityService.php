<?php

declare(strict_types=1);

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Member activity grid of an alliance: every member in a coarse "last online" bucket (never an exact time stamp).
 */
class AllianceActivityService
{
    use GameUtilities;

    /** bucket keys, most recent first */
    public const BUCKETS = ['now', 'hour', 'day', 'week', 'older'];

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** The bucket of a member who was last online `$idleSeconds` ago: under 15 min, 1 h, 24 h, 7 d, or older. */
    public static function bucket(int $idleSeconds): string
    {
        return match (true) {
            $idleSeconds < 900 => 'now',
            $idleSeconds < 3600 => 'hour',
            $idleSeconds < 86400 => 'day',
            $idleSeconds < 604800 => 'week',
            default => 'older',
        };
    }

    /**
     * @return array<string, list<array{id: int, username: string}>> bucket key => members (all keys present, most recent first)
     */
    public function grid(int $allianceId): array
    {
        $grid = array_fill_keys(self::BUCKETS, []);
        $rows = $this->db->select(
            'SELECT id, username, onlinetime FROM %%USERS%% WHERE ally_id = :allianceId ORDER BY username ASC;',
            [':allianceId' => $allianceId],
        );
        $now = $this->state->runtime->timestamp;
        foreach ($rows as $row) {
            $grid[self::bucket(max(0, $now - (int) $row['onlinetime']))][] = [
                'id' => (int) $row['id'],
                'username' => $row['username'],
            ];
        }

        return $grid;
    }
}
