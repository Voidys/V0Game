<?php

declare(strict_types=1);

namespace App\Game\Engagement\Relics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;
use App\Game\Enum\PlanetType;

/**
 * The relic inventory of a player: equip, unequip, lock, dismantle, reforge, award (drops) and admin grant. Every change
 * runs in one transaction. Lock order everywhere: user row, planet rows (by id), relic row, wallet row; the user row lock
 * serialises all actions of one player, and the state of the relic is checked again after its row lock was taken, so a
 * dismantle racing an equip or a reforge can never act twice. Before a planet's relics change, the planet's production
 * is settled up to now (ResourceUpdate::CalcResource, saved), so a relic never boosts time that already passed, and the
 * planet's eco hash is cleared so the next update rebuilds production, storage and energy.
 * Results are short codes: 'ok' or one of gone, planet, moon, cooldown, same, locked, sub, dust, resources.
 */
final class RelicInventory
{
    /** largest dust balance (the column is an unsigned int) */
    private const MAX_DUST = 4_294_967_295;

    /** @var (\Closure(int, int): int)|null random source for the roller (tests) */
    public ?\Closure $rand = null;

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    private function catalog(): RelicCatalog
    {
        return new RelicCatalog($this->db, $this->configs, $this->state);
    }

    /** @return int setting of the relics category */
    private function setting(int $universe, string $key, int $default): int
    {
        return (int) ($this->configs->get($universe)->$key ?? $default);
    }

    /**
     * Runs $work in a transaction, or directly when the caller already holds one (transactions do not nest). The bonus
     * memo is flushed afterwards either way.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function atomically(callable $work): mixed
    {
        try {
            return $this->db->inTransaction() ? $work() : $this->db->transactional(static fn(): mixed => $work());
        } finally {
            RelicBonus::flush();
        }
    }

    // --- reading -----------------------------------------------------------------------------------------------------

    /**
     * Every relic of the player (equipped ones with the name of the planet), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function relicsOf(int $userId): array
    {
        return $this->db->select(
            'SELECT r.*, p.`name` AS planet_name, p.`galaxy` AS planet_galaxy, p.`system` AS planet_system, p.`planet` AS planet_pos
            FROM %%ENG_RELICS%% r LEFT JOIN %%PLANETS%% p ON p.`id` = r.`planet_id` WHERE r.`user_id` = :user ORDER BY r.`id` DESC;',
            [':user' => $userId],
        );
    }

    /** Relic dust of the player. */
    public function dustOf(int $userId): int
    {
        return (int) $this->db->selectSingle('SELECT `dust` FROM %%ENG_RELIC_WALLET%% WHERE `user_id` = :user;', [':user' => $userId], 'dust');
    }

    /** Number of relics the player owns. */
    public function countOf(int $userId): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%ENG_RELICS%% WHERE `user_id` = :user;', [':user' => $userId], 'n');
    }

    /**
     * Dust and resource price of a reforge: the rarity's dust cost, and the universe's base resources times the rarity
     * factor times the economy speed (resource_multiplier / 2500, so the price keeps its weight at higher game speeds).
     *
     * @param array<string, mixed> $config effective catalog
     * @return array{dust: int, metal: int, crystal: int, deuterium: int}
     */
    public function reforgeCost(array $config, int $rarity, int $universe): array
    {
        $row = $config['rarities'][$rarity] ?? $config['rarities'][0];
        $scale = max(0.0, (float) ($this->configs->get($universe)->resource_multiplier ?? 2500)) / 2500;
        $price = static fn(string $key): int => (int) round($config['reforge_base'][$key] * $row['reforge_factor'] * $scale);

        return ['dust' => (int) $row['reforge_dust'], 'metal' => $price('metal'), 'crystal' => $price('crystal'), 'deuterium' => $price('deuterium')];
    }

    // --- player actions ----------------------------------------------------------------------------------------------

    /**
     * Equips a relic on a planet of the player (the relic's own slot; a relic in that slot goes back to storage; a relic
     * on another planet moves). Refused for moons, foreign planets/relics and while the relic's move cooldown runs.
     */
    public function equip(int $userId, int $universe, int $relicId, int $planetId, int $now): string
    {
        return $this->atomically(function () use ($userId, $universe, $relicId, $planetId, $now): string {
            $peek = $this->peek($relicId, $userId);
            if ($peek === null) {
                return 'gone';
            }
            $this->settle($userId, [$planetId, (int) $peek['planet_id']]);
            $relic = $this->lockRelic($relicId, $userId, $peek);
            if ($relic === null) {
                return 'gone';
            }
            $planet = $this->db->selectSingle(
                'SELECT `id`, `planet_type` FROM %%PLANETS%% WHERE `id` = :id AND `id_owner` = :user AND `destruyed` = 0;',
                [':id' => $planetId, ':user' => $userId],
            );
            if (empty($planet)) {
                return 'planet';
            }
            if ((int) $planet['planet_type'] !== PlanetType::Planet->value) {
                return 'moon';
            }
            if ((int) $relic['planet_id'] === $planetId) {
                return 'same';
            }
            if (($this->catalog()->config($universe)['slots'][(int) $relic['slot']] ?? []) === []) {
                return 'slot';
            }
            $cooldown = $this->setting($universe, 'relics_move_cooldown_hours', 6) * 3600;
            if ($now < (int) $relic['moved_at'] + $cooldown) {
                return 'cooldown';
            }

            $occupant = $this->db->selectSingle(
                'SELECT `id` FROM %%ENG_RELICS%% WHERE `planet_id` = :planet AND `slot` = :slot FOR UPDATE;',
                [':planet' => $planetId, ':slot' => $relic['slot']],
                'id',
            );
            if (!empty($occupant)) {
                $this->db->update('UPDATE %%ENG_RELICS%% SET `planet_id` = NULL, `moved_at` = :now WHERE `id` = :id;', [':now' => $now, ':id' => $occupant]);
            }
            $this->db->update(
                'UPDATE %%ENG_RELICS%% SET `planet_id` = :planet, `moved_at` = :now WHERE `id` = :id;',
                [':planet' => $planetId, ':now' => $now, ':id' => $relicId],
            );
            $this->resetHash([$planetId, (int) $relic['planet_id']]);
            $this->log($universe, $userId, $relicId, 'equip', 'planet ' . $planetId, $now);

            return 'ok';
        });
    }

    /** Takes a relic off its planet back to storage (the move cooldown starts; unequipping itself is always allowed). */
    public function unequip(int $userId, int $universe, int $relicId, int $now): string
    {
        return $this->atomically(function () use ($userId, $universe, $relicId, $now): string {
            $peek = $this->peek($relicId, $userId);
            if ($peek === null) {
                return 'gone';
            }
            $this->settle($userId, [(int) $peek['planet_id']]);
            $relic = $this->lockRelic($relicId, $userId, $peek);
            if ($relic === null || $relic['planet_id'] === null) {
                return 'gone';
            }
            $this->db->update('UPDATE %%ENG_RELICS%% SET `planet_id` = NULL, `moved_at` = :now WHERE `id` = :id;', [':now' => $now, ':id' => $relicId]);
            $this->resetHash([(int) $relic['planet_id']]);
            $this->log($universe, $userId, $relicId, 'unequip', 'planet ' . $relic['planet_id'], $now);

            return 'ok';
        });
    }

    /** Toggles the lock of a relic (a locked relic cannot be dismantled). */
    public function toggleLock(int $userId, int $relicId): string
    {
        return $this->atomically(function () use ($userId, $relicId): string {
            $relic = $this->db->selectSingle('SELECT * FROM %%ENG_RELICS%% WHERE `id` = :id AND `user_id` = :user FOR UPDATE;', [':id' => $relicId, ':user' => $userId]);
            if (empty($relic)) {
                return 'gone';
            }
            $this->db->update('UPDATE %%ENG_RELICS%% SET `locked` = :locked WHERE `id` = :id;', [':locked' => (int) $relic['locked'] === 1 ? 0 : 1, ':id' => $relicId]);

            return 'ok';
        });
    }

    /** Dismantles an unlocked relic into dust (the rarity's dust value of the catalog). */
    public function dismantle(int $userId, int $universe, int $relicId, int $now): string
    {
        return $this->atomically(function () use ($userId, $universe, $relicId, $now): string {
            $peek = $this->peek($relicId, $userId);
            if ($peek === null) {
                return 'gone';
            }
            $this->settle($userId, [(int) $peek['planet_id']]);
            $relic = $this->lockRelic($relicId, $userId, $peek);
            if ($relic === null) {
                return 'gone';
            }
            if ((int) $relic['locked'] === 1) {
                return 'locked';
            }
            $dust = (int) $this->catalog()->config($universe)['rarities'][(int) $relic['rarity']]['dust'];
            $this->db->delete('DELETE FROM %%ENG_RELICS%% WHERE `id` = :id;', [':id' => $relicId]);
            $this->addDust($userId, $dust);
            $this->resetHash([(int) $relic['planet_id']]);
            $this->log($universe, $userId, $relicId, 'dismantle', '+' . $dust . ' dust', $now);

            return 'ok';
        });
    }

    /**
     * Rerolls the value of one substat (index in the relic's list) within the rarity band; the stat stays. Costs the
     * rarity's dust and resources of the current planet; changes nothing else. Refused when dust or resources are short.
     */
    public function reforge(int $userId, int $universe, int $relicId, int $subIndex, int $planetId, int $now): string
    {
        return $this->atomically(function () use ($userId, $universe, $relicId, $subIndex, $planetId, $now): string {
            $peek = $this->peek($relicId, $userId);
            if ($peek === null) {
                return 'gone';
            }
            $this->settle($userId, [$planetId, (int) $peek['planet_id']]);
            $relic = $this->lockRelic($relicId, $userId, $peek);
            if ($relic === null) {
                return 'gone';
            }
            $subs = (array) json_decode((string) $relic['subs'], true);
            if (!isset($subs[$subIndex]['stat'])) {
                return 'sub';
            }
            $config = $this->catalog()->config($universe);
            $subConfig = $config['subs'][$subs[$subIndex]['stat']] ?? null;
            if ($subConfig === null || $subConfig['max'] <= 0 || !in_array((int) $relic['slot'], $subConfig['slots'], true)) {
                return 'sub'; // the catalog does not allow this substat any more: nothing is paid
            }
            $cost = $this->reforgeCost($config, (int) $relic['rarity'], $universe);
            $paid = $this->pay($userId, $planetId, $cost);
            if ($paid !== 'ok') {
                return $paid;
            }
            $roller = new RelicRoller($config, $this->rand);
            $old = (float) $subs[$subIndex]['value'];
            for ($try = 0, $new = $old; $try < 5 && $new === $old; $try++) {
                $new = $roller->rerollSub((string) $subs[$subIndex]['stat'], (int) $relic['rarity']);
            }
            $subs[$subIndex]['value'] = $new;
            $this->db->update('UPDATE %%ENG_RELICS%% SET `subs` = :subs WHERE `id` = :id;', [':subs' => json_encode($subs), ':id' => $relicId]);
            $this->resetHash([(int) $relic['planet_id']]);
            $this->log($universe, $userId, $relicId, 'reforge', $subs[$subIndex]['stat'] . ' ' . $old . ' -> ' . $new, $now);

            return 'ok';
        });
    }

    /**
     * Takes the reforge price: dust from the wallet and resources from the planet, each with a conditional UPDATE so
     * neither can go negative.
     *
     * @param array{dust: int, metal: int, crystal: int, deuterium: int} $cost
     */
    private function pay(int $userId, int $planetId, array $cost): string
    {
        $planet = $this->db->selectSingle(
            'SELECT `id` FROM %%PLANETS%% WHERE `id` = :id AND `id_owner` = :user AND `destruyed` = 0 FOR UPDATE;',
            [':id' => $planetId, ':user' => $userId],
        );
        if (empty($planet)) {
            return 'planet';
        }
        $this->ensureWallet($userId);
        $dust = (int) $this->db->selectSingle('SELECT `dust` FROM %%ENG_RELIC_WALLET%% WHERE `user_id` = :user FOR UPDATE;', [':user' => $userId], 'dust');
        if ($dust < $cost['dust']) {
            return 'dust';
        }
        if ($cost['metal'] + $cost['crystal'] + $cost['deuterium'] > 0) {
            $this->db->update(
                'UPDATE %%PLANETS%% SET `metal` = `metal` - :m, `crystal` = `crystal` - :c, `deuterium` = `deuterium` - :d
                WHERE `id` = :id AND `metal` >= :m AND `crystal` >= :c AND `deuterium` >= :d;',
                [':m' => $cost['metal'], ':c' => $cost['crystal'], ':d' => $cost['deuterium'], ':id' => $planetId],
            );
            if ($this->db->rowCount() !== 1) {
                return 'resources';
            }
        }
        $this->db->update('UPDATE %%ENG_RELIC_WALLET%% SET `dust` = `dust` - :dust WHERE `user_id` = :user AND `dust` >= :dust;', [':dust' => $cost['dust'], ':user' => $userId]);
        if ($cost['dust'] > 0 && $this->db->rowCount() !== 1) {
            return 'dust';
        }

        return 'ok';
    }

    // --- finding relics ----------------------------------------------------------------------------------------------

    /**
     * A drop: rolls a relic for the player (rarity by the weights, at least $minRarity; the pity counter lifts the
     * minimum to rare every relics_pity-th relic without a rare or better; $rarity fixes the rarity) and stores it. A full
     * inventory turns the relic into dust at once. Bots get nothing.
     *
     * @return array{status: string, rarity: int, dust: int, relic: array<string, mixed>|null} status none (bot / unusable catalog), relic or dust
     */
    public function award(int $userId, int $universe, string $source, int $now, int $minRarity = 0, ?int $rarity = null): array
    {
        $none = ['status' => 'none', 'rarity' => 0, 'dust' => 0, 'relic' => null];
        if ($this->isBot($userId)) {
            return $none;
        }

        return $this->atomically(function () use ($userId, $universe, $source, $now, $minRarity, $rarity, $none): array {
            $this->ensureWallet($userId);
            $wallet = $this->db->selectSingle('SELECT `dust`, `since_rare` FROM %%ENG_RELIC_WALLET%% WHERE `user_id` = :user FOR UPDATE;', [':user' => $userId]);
            $config = $this->catalog()->config($universe);
            $pity = $this->setting($universe, 'relics_pity', 40);
            if ($pity > 0 && (int) $wallet['since_rare'] + 1 >= $pity) {
                $minRarity = self::pityMinimum($config, $minRarity);
            }
            $roller = new RelicRoller($config, $this->rand);
            $rarity ??= $roller->rarity($minRarity);
            $roll = $roller->roll($rarity);
            if ($roll === null) {
                return $none;
            }
            $this->db->update(
                'UPDATE %%ENG_RELIC_WALLET%% SET `since_rare` = :since WHERE `user_id` = :user;',
                [':since' => $rarity >= 2 ? 0 : (int) $wallet['since_rare'] + 1, ':user' => $userId],
            );
            if ($this->countOf($userId) >= max(1, $this->setting($universe, 'relics_inventory_max', 60))) {
                $dust = (int) $config['rarities'][$rarity]['dust'];
                $this->addDust($userId, $dust);
                $this->log($universe, $userId, 0, 'full', 'rarity ' . $rarity . ' -> +' . $dust . ' dust (' . $source . ')', $now);

                return ['status' => 'dust', 'rarity' => $rarity, 'dust' => $dust, 'relic' => null];
            }
            $relic = $this->insert($userId, $universe, $roll, $source, $now);

            return ['status' => 'relic', 'rarity' => $rarity, 'dust' => 0, 'relic' => $relic];
        });
    }

    /**
     * The minimum rarity when the bad luck protection fires: the lowest rarity of rare or better that can drop (weight above
     * 0), at least $minRarity; without such a rarity the protection does nothing and $minRarity stays.
     *
     * @param array<string, mixed> $config effective catalog
     */
    public static function pityMinimum(array $config, int $minRarity): int
    {
        foreach (array_keys(RelicCatalog::RARITIES) as $candidate) {
            if ($candidate >= 2 && $config['rarities'][$candidate]['weight'] > 0) {
                return max($minRarity, $candidate);
            }
        }

        return $minRarity;
    }

    /**
     * Admin grant of a rolled relic with the chosen slot, set and rarity (the inventory limit and the pity counter do
     * not apply). Null when slot, set or rarity are not in the catalog.
     *
     * @return array<string, mixed>|null the new relic row
     */
    public function grant(int $userId, int $universe, int $slot, string $set, int $rarity, int $now, string $source = 'admin'): ?array
    {
        $roll = (new RelicRoller($this->catalog()->config($universe), $this->rand))->roll($rarity, $slot, $set);
        if ($roll === null) {
            return null;
        }

        return $this->atomically(fn(): array => $this->insert($userId, $universe, $roll, $source, $now));
    }

    /**
     * @param array{slot: int, set: string, rarity: int, main_stat: string, main_value: float, subs: list<array{stat: string, value: float}>} $roll
     * @return array<string, mixed>
     */
    private function insert(int $userId, int $universe, array $roll, string $source, int $now): array
    {
        $this->db->insert(
            'INSERT INTO %%ENG_RELICS%% SET `user_id` = :user, `universe` = :uni, `set_code` = :set, `slot` = :slot, `rarity` = :rarity,
                `main_stat` = :stat, `main_value` = :value, `subs` = :subs, `planet_id` = NULL, `locked` = 0, `moved_at` = 0, `found_at` = :now, `source` = :source;',
            [
                ':user' => $userId, ':uni' => $universe, ':set' => $roll['set'], ':slot' => $roll['slot'], ':rarity' => $roll['rarity'],
                ':stat' => $roll['main_stat'], ':value' => $roll['main_value'], ':subs' => json_encode($roll['subs']), ':now' => $now, ':source' => $source,
            ],
        );
        $id = $this->db->lastInsertId();
        $this->log($universe, $userId, $id, 'found', $source, $now);

        return ['id' => $id, 'user_id' => $userId, 'set_code' => $roll['set'], 'slot' => $roll['slot'], 'rarity' => $roll['rarity'], 'main_stat' => $roll['main_stat'],
            'main_value' => $roll['main_value'], 'subs' => json_encode($roll['subs']), 'planet_id' => null, 'locked' => 0, 'moved_at' => 0, 'found_at' => $now, 'source' => $source];
    }

    // --- admin / cleanup ---------------------------------------------------------------------------------------------

    /** Admin: deletes one relic of the player (the planet is settled first when it was equipped). */
    public function remove(int $userId, int $universe, int $relicId, int $now): string
    {
        return $this->atomically(function () use ($userId, $universe, $relicId, $now): string {
            $peek = $this->peek($relicId, $userId);
            if ($peek === null) {
                return 'gone';
            }
            $this->settle($userId, [(int) $peek['planet_id']]);
            $relic = $this->lockRelic($relicId, $userId, $peek);
            if ($relic === null) {
                return 'gone';
            }
            $this->db->delete('DELETE FROM %%ENG_RELICS%% WHERE `id` = :id;', [':id' => $relicId]);
            $this->resetHash([(int) $relic['planet_id']]);
            $this->log($universe, $userId, $relicId, 'removed', 'admin', $now);

            return 'ok';
        });
    }

    /** Admin: sets the dust balance of the player. */
    public function setDust(int $userId, int $universe, int $dust, int $now): void
    {
        $this->atomically(function () use ($userId, $universe, $dust, $now): void {
            $this->ensureWallet($userId);
            $dust = max(0, min(self::MAX_DUST, $dust));
            $this->db->update('UPDATE %%ENG_RELIC_WALLET%% SET `dust` = :dust WHERE `user_id` = :user;', [':dust' => $dust, ':user' => $userId]);
            $this->log($universe, $userId, 0, 'dust', 'admin set ' . $dust, $now);
        });
    }

    /** A planet is gone (deleted, abandoned): its relics return to storage; the move cooldown is not touched. */
    public function releasePlanet(int $planetId): void
    {
        $this->db->update('UPDATE %%ENG_RELICS%% SET `planet_id` = NULL WHERE `planet_id` = :planet;', [':planet' => $planetId]);
        RelicBonus::flush();
    }

    /** Every planet of the player is gone (reshuffle): all relics return to storage. */
    public function releaseUser(int $userId): void
    {
        $this->db->update('UPDATE %%ENG_RELICS%% SET `planet_id` = NULL WHERE `user_id` = :user;', [':user' => $userId]);
        RelicBonus::flush();
    }

    /** The player is deleted: relics, wallet and log go with him. */
    public function deleteUser(int $userId): void
    {
        foreach (['ENG_RELICS', 'ENG_RELIC_WALLET', 'ENG_RELIC_LOG'] as $table) {
            $this->db->delete('DELETE FROM %%' . $table . '%% WHERE `user_id` = :user;', [':user' => $userId]);
        }
        RelicBonus::flush();
    }

    /**
     * Stores the expedition finds factor of the start planet's relics for a fleet that was just dispatched (the factor at
     * launch counts, so relics cannot be swapped in just before the fleet finds something). No row without a bonus.
     */
    public function snapshotFleet(int $fleetId, int $userId, int $universe, int $planetId): void
    {
        if (!$this->catalog()->enabled($universe)) {
            return;
        }
        $factor = (new RelicBonus($this->db, $this->configs, $this->state))->gainFactor($userId, $universe, $planetId, RelicCatalog::EXPEDITION);
        if ($factor !== 1.0) {
            $this->db->replace('REPLACE INTO %%ENG_RELIC_FLEET%% SET `fleet_id` = :fleet, `factor` = :factor;', [':fleet' => $fleetId, ':factor' => $factor]);
        }
    }

    /** The snapshot of a fleet (see snapshotFleet()); 1.0 when there is none or the relics are off. */
    public function fleetFactor(int $fleetId, int $universe): float
    {
        if (!$this->catalog()->enabled($universe)) {
            return 1.0;
        }
        $factor = $this->db->selectSingle('SELECT `factor` FROM %%ENG_RELIC_FLEET%% WHERE `fleet_id` = :fleet;', [':fleet' => $fleetId], 'factor');

        return is_numeric($factor) ? max(1.0, min(1.0 + RelicCatalog::MAX_VALUE / 100, (float) $factor)) : 1.0;
    }

    /** Housekeeping: log lines older than relics_log_days (default 90) of the universe and snapshots of fleets that are gone. */
    public function cleanup(int $universe, int $now): void
    {
        $days = max(1, $this->setting($universe, 'relics_log_days', 90));
        $this->db->delete('DELETE FROM %%ENG_RELIC_LOG%% WHERE `universe` = :uni AND `created_at` < :before;', [':uni' => $universe, ':before' => $now - $days * 86400]);
        $this->db->delete('DELETE r FROM %%ENG_RELIC_FLEET%% r LEFT JOIN %%FLEETS%% f ON f.`id` = r.`fleet_id` WHERE f.`id` IS NULL;');
    }

    // --- helpers -----------------------------------------------------------------------------------------------------

    /**
     * The relic row of the player without a lock (to learn its planet before the planets are locked).
     *
     * @return array<string, mixed>|null
     */
    private function peek(int $relicId, int $userId): ?array
    {
        $row = $this->db->selectSingle('SELECT `planet_id`, `user_id` FROM %%ENG_RELICS%% WHERE `id` = :id;', [':id' => $relicId]);

        return !empty($row) && (int) $row['user_id'] === $userId ? $row : null;
    }

    /**
     * The relic row under lock, checked again: still the player's and still on the planet it was on at peek().
     *
     * @param array<string, mixed> $peek
     * @return array<string, mixed>|null
     */
    private function lockRelic(int $relicId, int $userId, array $peek): ?array
    {
        $row = $this->db->selectSingle('SELECT * FROM %%ENG_RELICS%% WHERE `id` = :id AND `user_id` = :user FOR UPDATE;', [':id' => $relicId, ':user' => $userId]);
        if (empty($row) || (string) ($row['planet_id'] ?? '') !== (string) ($peek['planet_id'] ?? '')) {
            return null;
        }

        return $row;
    }

    /**
     * Brings the player's planets up to now before their relics change: locks the user row, then the planets in id
     * order, and runs ResourceUpdate::CalcResource (saved) on each.
     *
     * @param list<int|null> $planetIds
     */
    private function settle(int $userId, array $planetIds): void
    {
        $user = $this->db->selectSingle(
            'SELECT * FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id` WHERE u.`id` = :id FOR UPDATE;',
            [':id' => $userId],
        );
        if (empty($user)) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $planetIds))));
        sort($ids);
        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        foreach ($ids as $id) {
            $planet = $this->db->selectSingle(
                'SELECT * FROM %%PLANETS%% WHERE `id` = :id AND `id_owner` = :user AND `destruyed` = 0 FOR UPDATE;',
                [':id' => $id, ':user' => $userId],
            );
            if (!empty($planet)) {
                [$user] = $eco->CalcResource($user, $planet, true);
            }
        }
    }

    /**
     * Clears the eco hash of planets so the next update rebuilds production, storage and energy with the new relics.
     *
     * @param list<int|null> $planetIds
     */
    private function resetHash(array $planetIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $planetIds))));
        if ($ids !== []) {
            $this->db->update('UPDATE %%PLANETS%% SET `eco_hash` = \'\' WHERE `id` IN (' . implode(',', $ids) . ');');
        }
    }

    private function ensureWallet(int $userId): void
    {
        $this->db->insert('INSERT IGNORE INTO %%ENG_RELIC_WALLET%% SET `user_id` = :user;', [':user' => $userId]);
    }

    private function addDust(int $userId, int $dust): void
    {
        $this->ensureWallet($userId);
        $this->db->update('UPDATE %%ENG_RELIC_WALLET%% SET `dust` = LEAST(`dust` + :dust, ' . self::MAX_DUST . ') WHERE `user_id` = :user;', [':dust' => $dust, ':user' => $userId]);
    }

    private function log(int $universe, int $userId, int $relicId, string $action, string $detail, int $now): void
    {
        $this->db->insert(
            'INSERT INTO %%ENG_RELIC_LOG%% SET `universe` = :uni, `user_id` = :user, `relic_id` = :relic, `action` = :action, `detail` = :detail, `created_at` = :now;',
            [':uni' => $universe, ':user' => $userId, ':relic' => $relicId, ':action' => $action, ':detail' => mb_substr($detail, 0, 255), ':now' => $now],
        );
    }

    /** True when the user is a bot account (bots earn no relics). */
    private function isBot(int $userId): bool
    {
        return (bool) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%BOT_PLAYERS%% WHERE `user_id` = :id;', [':id' => $userId], 'n');
    }
}
