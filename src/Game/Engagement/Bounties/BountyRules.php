<?php

declare(strict_types=1);

namespace App\Game\Engagement\Bounties;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Who may be the target of a bounty, and how many bounties a placer may have. The checks return an error code (the
 * translation key is bo_err_<code>), a flag or the target row; they run inside the placing transaction.
 */
final class BountyRules
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly Bounties $bounties,
    ) {}

    /**
     * Looks the target up by name and applies the target rules: another human player of the universe, no vacation, no ban,
     * no staff protected from attacks, no noob protection against the placer, no friend (alliance, buddy, pact, linked
     * accounts), enough points.
     *
     * @param array<string, mixed> $placer
     * @return array<string, mixed>|array{error: string} the target row (with total_points, fleet_points) or the error
     */
    public function checkTarget(array $placer, string $name, int $now): array
    {
        $universe = (int) $placer['universe'];
        $target = $this->player('u.`universe` = :universe AND u.`username` = :name', [':universe' => $universe, ':name' => $name]);
        $self = $this->player('u.`id` = :id', [':id' => (int) $placer['id']]);
        if (!$target || !$self) {
            return ['error' => 'target'];
        }
        $config = $this->configs->get($universe);
        if ((int) $target['id'] === (int) $placer['id']) {
            return ['error' => 'self'];
        }
        if ((int) $self['urlaubs_modus'] === 1 || (int) $self['banaday'] > $now) {
            return ['error' => 'placer'];
        }

        // every reason against the target gives the same answer, so the form cannot be used to probe other players
        return $this->targetRefused($placer, $self, $target, $universe, $now) ? ['error' => 'target'] : $target;
    }

    /**
     * Whether the target is excluded: vacation, ban, staff protected from attacks, bots, too few points, noob protection against the
     * placer, or friendly to him (alliance, buddy, pact, linked accounts).
     *
     * @param array<string, mixed> $placer
     * @param array<string, mixed> $self
     * @param array<string, mixed> $target
     */
    private function targetRefused(array $placer, array $self, array $target, int $universe, int $now): bool
    {
        $config = $this->configs->get($universe);
        $isBot = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BOT_PLAYERS%% WHERE `user_id` = :id;', [':id' => (int) $target['id']], 'c') > 0;

        return (int) $target['urlaubs_modus'] === 1 || (int) $target['banaday'] > $now || (int) $target['bana'] === 1
            || ((int) $config->adm_attack === 1 && (int) $target['authattack'] > (int) $self['authlevel'])
            || $isBot
            || (float) $target['total_points'] < (int) $this->bounties->option($universe, 'bounty_min_target_points')
            || $this->protectionRules()->checkNoobProtection($self, $target)['NoobPlayer']
            || (new \App\Game\Engagement\Seasons\FightNeutrality($this->db, $now))->pairIsNeutral((int) $placer['id'], (int) $target['id']);
    }

    /** True when the placer already has the most open bounties he may have. */
    public function openLimitReached(int $placerId, int $universe): bool
    {
        $open = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ENG_BOUNTIES%% WHERE `placer_id` = :p AND `status` = \'open\';', [':p' => $placerId], 'c');

        return $open >= (int) $this->bounties->option($universe, 'bounty_max_open');
    }

    /** True when the placer put a bounty on the same target within the cooldown. */
    public function onCooldown(int $placerId, int $targetId, int $universe, int $now): bool
    {
        $since = $now - (int) $this->bounties->option($universe, 'bounty_cooldown_hours') * 3600;

        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%ENG_BOUNTIES%% WHERE `placer_id` = :p AND `target_id` = :t AND `created_at` > :since;',
            [':p' => $placerId, ':t' => $targetId, ':since' => $since],
            'c',
        ) > 0;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|false the player row with the total and fleet points
     */
    private function player(string $where, array $params): array|false
    {
        return $this->db->selectSingle('SELECT u.*, COALESCE(s.`total_points`, 0) AS total_points, COALESCE(s.`fleet_points`, 0) AS fleet_points FROM %%USERS%% u
            LEFT JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = 1 WHERE ' . $where . ';', $params);
    }
}
