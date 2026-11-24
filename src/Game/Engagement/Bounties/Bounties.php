<?php

declare(strict_types=1);

namespace App\Game\Engagement\Bounties;

use App\Game\BattleEngine\BattleSide;
use App\Game\Core\Database;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\EngagementFeature;

/**
 * Bounties: a player puts resources on another player's head and whoever destroys enough of the target's fleet value
 * collects them. Placing (page /game/bounties) burns a fee, locks the resources and stores the required destroyed
 * value; fights credit the destroyed value to the hunters (onBattle), the tick returns expired bounties to the placer.
 * Rules of who may be targeted live in BountyRules.
 */
final class Bounties extends EngagementFeature
{
    public const OPEN = 'open';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    /** one resource amount a placer may put in, a sanity cap against overflow */
    public const MAX_AMOUNT = 10_000_000_000_000;

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'bounties_enabled', false);
    }

    /** @return int|float|string a bounty setting with its default */
    public function option(int $universe, string $key): int|float|string
    {
        return $this->setting($universe, $key, match ($key) {
            'bounty_fee_percent' => 15,
            'bounty_min_total' => 100000,
            'bounty_max_open' => 3,
            'bounty_cooldown_hours' => 24,
            'bounty_duration_days' => 7,
            'bounty_threshold_percent' => 10,
            'bounty_value_ratio' => 1.0,
            'bounty_min_target_points' => 5000,
            default => 0,
        });
    }

    /**
     * Places a bounty from the planet of the player: one transaction locks the player and the planet row, checks the
     * rules and the resources again, burns the fee, deducts the rest and stores the bounty.
     *
     * @param array<string, mixed> $placer the player row (id, universe, authlevel)
     * @param array{metal: int, crystal: int, deuterium: int} $amounts what the placer puts in
     * @return array{ok: true, id: int}|array{error: string}
     */
    public function place(array $placer, int $planetId, string $targetName, array $amounts, int $now): array
    {
        $universe = (int) $placer['universe'];
        $total = $amounts['metal'] + $amounts['crystal'] + $amounts['deuterium'];
        if ($total < (int) $this->option($universe, 'bounty_min_total') || max($amounts) > self::MAX_AMOUNT || min($amounts) < 0) {
            return ['error' => 'amount'];
        }

        return $this->db->transactional(function () use ($placer, $planetId, $targetName, $amounts, $now, $total, $universe): array {
            $this->db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :id FOR UPDATE;', [':id' => (int) $placer['id']]);
            $planet = $this->db->selectSingle(
                'SELECT `id`, `metal`, `crystal`, `deuterium` FROM %%PLANETS%% WHERE `id` = :id AND `id_owner` = :owner AND `destruyed` = 0 FOR UPDATE;',
                [':id' => $planetId, ':owner' => (int) $placer['id']],
            );
            if (!$planet || $planet['metal'] < $amounts['metal'] || $planet['crystal'] < $amounts['crystal'] || $planet['deuterium'] < $amounts['deuterium']) {
                return ['error' => 'resources'];
            }
            $rules = new BountyRules($this->db, $this->configs, $this->state, $this);
            // the open-bounty limit does not depend on the target, so it is checked first (it must not tell whether a name is valid)
            if ($rules->openLimitReached((int) $placer['id'], $universe)) {
                return ['error' => 'limit'];
            }
            $target = $rules->checkTarget($placer, $targetName, $now);
            if (isset($target['error'])) {
                return $target;
            }
            if ($rules->onCooldown((int) $placer['id'], (int) $target['id'], $universe, $now)) {
                return ['error' => 'cooldown'];
            }

            return $this->store($placer, $target, $planetId, $amounts, $total, $now);
        });
    }

    /**
     * Deducts the resources (with a stock guard), burns the fee and inserts the bounty. Runs inside the lock of place().
     *
     * @param array<string, mixed> $placer
     * @param array<string, mixed> $target
     * @param array{metal: int, crystal: int, deuterium: int} $amounts
     * @return array{ok: true, id: int}|array{error: string}
     */
    private function store(array $placer, array $target, int $planetId, array $amounts, int $total, int $now): array
    {
        $universe = (int) $placer['universe'];
        $this->db->update('UPDATE %%PLANETS%% SET `metal` = `metal` - :m, `crystal` = `crystal` - :c, `deuterium` = `deuterium` - :d
            WHERE `id` = :id AND `metal` >= :m AND `crystal` >= :c AND `deuterium` >= :d;', [
            ':m' => $amounts['metal'], ':c' => $amounts['crystal'], ':d' => $amounts['deuterium'], ':id' => $planetId,
        ]);
        if ($this->db->rowCount() !== 1) {
            return ['error' => 'resources'];
        }
        $keep = 1 - max(0, min(50, (int) $this->option($universe, 'bounty_fee_percent'))) / 100;
        $net = array_map(static fn(int $amount): int => (int) floor($amount * $keep), $amounts);
        $required = max(
            (float) $target['fleet_points'] * 1000 * (int) $this->option($universe, 'bounty_threshold_percent') / 100,
            $total * max(1.0, (float) $this->option($universe, 'bounty_value_ratio')),
        );
        $this->db->insert('INSERT INTO %%ENG_BOUNTIES%% (`universe`, `placer_id`, `target_id`, `metal`, `crystal`, `deuterium`, `fee`, `required_value`, `created_at`, `expires_at`, `status`)
            VALUES (:universe, :placer, :target, :m, :c, :d, :fee, :required, :now, :expires, \'open\');', [
            ':universe' => $universe,
            ':placer' => (int) $placer['id'],
            ':target' => (int) $target['id'],
            ':m' => $net['metal'], ':c' => $net['crystal'], ':d' => $net['deuterium'],
            ':fee' => $total - array_sum($net),
            ':required' => $required,
            ':now' => $now,
            ':expires' => $now + (int) $this->option($universe, 'bounty_duration_days') * 86400,
        ]);

        return ['ok' => true, 'id' => $this->db->lastInsertId()];
    }

    /**
     * Open bounties of the universe, the biggest first, with the credit the viewer already collected on each.
     *
     * @return list<array<string, mixed>>
     */
    public function openList(int $universe, int $now, int $viewerId): array
    {
        return $this->db->select('SELECT b.*, t.`username` AS target_name, COALESCE(pr.`credit`, 0) AS my_credit FROM %%ENG_BOUNTIES%% b
            INNER JOIN %%USERS%% t ON t.`id` = b.`target_id`
            LEFT JOIN %%ENG_BOUNTY_PROGRESS%% pr ON pr.`bounty_id` = b.`id` AND pr.`hunter_id` = :viewer
            WHERE b.`universe` = :universe AND b.`status` = \'open\' AND b.`expires_at` > :now
            ORDER BY (b.`metal` + b.`crystal` + b.`deuterium`) DESC LIMIT 100;', [':universe' => $universe, ':now' => $now, ':viewer' => $viewerId]);
    }

    /**
     * Bounties the player placed, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function placedBy(int $userId): array
    {
        return $this->db->select('SELECT b.*, t.`username` AS target_name FROM %%ENG_BOUNTIES%% b LEFT JOIN %%USERS%% t ON t.`id` = b.`target_id`
            WHERE b.`placer_id` = :id ORDER BY b.`id` DESC LIMIT 20;', [':id' => $userId]);
    }

    /**
     * Credits the destroyed value of the target to the hunters on the other side and pays the first hunter that reaches
     * the required value.
     */
    public function onBattle(BattleOutcome $outcome): void
    {
        $ids = array_merge(array_keys($outcome->attackers), array_keys($outcome->defenders));
        if ($ids === [] || $outcome->attackers === [] || $outcome->defenders === []) {
            return;
        }
        $bounties = $this->db->select('SELECT * FROM %%ENG_BOUNTIES%% WHERE `universe` = :universe AND `status` = \'open\' AND `expires_at` > :now
            AND `target_id` IN (' . implode(',', array_map('intval', $ids)) . ');', [':universe' => $outcome->universe, ':now' => $outcome->time]);
        foreach ($bounties as $bounty) {
            $target = (int) $bounty['target_id'];
            $side = $outcome->isAttacker($target) ? BattleSide::Attacker : BattleSide::Defender;
            $lost = (float) $outcome->losses->userValue($side, $target);
            $hunters = array_filter(
                array_keys($side === BattleSide::Attacker ? $outcome->defenders : $outcome->attackers),
                static fn($id): bool => $outcome->holdsShare((int) $id),
            );
            if ($lost > 0 && $hunters !== []) {
                $this->credit($bounty, $this->shares($outcome, $hunters, $lost), $outcome->time);
            }
        }
    }

    /**
     * Adds the share to every eligible hunter and pays the first one at the required value.
     *
     * @param array<string, mixed> $bounty
     * @param array<int, float> $shares hunter id => credit
     */
    private function credit(array $bounty, array $shares, int $now): void
    {
        $links = new \App\Game\Engagement\Seasons\FightNeutrality($this->db, $now);
        foreach ($shares as $hunter => $share) {
            if ($hunter === (int) $bounty['placer_id'] || $hunter === (int) $bounty['target_id'] || $this->isBot($hunter)
                || $links->pairIsNeutral($hunter, (int) $bounty['target_id']) || $links->pairIsNeutral($hunter, (int) $bounty['placer_id'])) {
                continue;
            }
            $this->db->insert('INSERT INTO %%ENG_BOUNTY_PROGRESS%% (`bounty_id`, `hunter_id`, `credit`, `updated_at`) VALUES (:b, :h, :c, :now)
                ON DUPLICATE KEY UPDATE `credit` = `credit` + :c, `updated_at` = :now;', [':b' => (int) $bounty['id'], ':h' => $hunter, ':c' => $share, ':now' => $now]);
            $credit = (float) $this->db->selectSingle('SELECT `credit` FROM %%ENG_BOUNTY_PROGRESS%% WHERE `bounty_id` = :b AND `hunter_id` = :h;', [':b' => (int) $bounty['id'], ':h' => $hunter], 'credit');
            if ($credit >= (float) $bounty['required_value'] && $this->pay($bounty, $hunter, $now)) {
                return;
            }
        }
    }

    /**
     * Splits the target's lost value among the eligible hunters by the value of the units they brought; equally when the start values are unknown.
     *
     * @param list<int|string> $hunters
     * @return array<int, float> hunter id => credit
     */
    private function shares(BattleOutcome $outcome, array $hunters, float $lost): array
    {
        $weights = [];
        foreach ($hunters as $id) {
            $weights[(int) $id] = (float) ($outcome->startValuePerUser[(int) $id] ?? 0);
        }
        $total = array_sum($weights);
        $shares = [];
        foreach ($weights as $id => $weight) {
            $shares[$id] = $total > 0 ? $lost * $weight / $total : $lost / count($weights);
        }

        return $shares;
    }

    /**
     * Pays the bounty to the hunter: claim and credit are one unit (see settle()), then the notices go out.
     *
     * @param array<string, mixed> $bounty
     */
    private function pay(array $bounty, int $hunter, int $now): bool
    {
        if (!$this->settle($bounty, self::PAID, $hunter, $hunter, $now)) {
            return false;
        }
        (new BountyPayout($this->db, $this->configs, $this->state))->notifyPaid($bounty, $hunter, $now);

        return true;
    }

    /**
     * Claims an open bounty (status UPDATE with a row-count check, so only one caller wins) and credits its resources to the
     * main planet of $receiver, atomically: both are rolled back together when the claim lost, the receiver has no valid main
     * planet (the bounty stays open and is tried again later) or anything fails. Inside an open transaction (a fight, the
     * callers' transactional()) this uses a SAVEPOINT; outside one it opens its own transaction. Deadlocks and lock timeouts
     * are rethrown for the retry.
     *
     * @param array<string, mixed> $bounty
     */
    private function settle(array $bounty, string $status, int $receiver, ?int $hunter, int $now): bool
    {
        if ($this->db->inTransaction()) {
            return $this->settleInSavepoint($bounty, $status, $receiver, $hunter, $now);
        }
        try {
            return $this->db->transactional(function () use ($bounty, $status, $receiver, $hunter, $now): bool {
                if (!$this->claimAndCredit($bounty, $status, $receiver, $hunter, $now)) {
                    throw new \RuntimeException('bounty ' . $bounty['id'] . ' not settled');
                }

                return true;
            });
        } catch (\Throwable $e) {
            if (Database::isRetryable($e)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * The claim UPDATE and the credit (no transaction handling).
     *
     * @param array<string, mixed> $bounty
     */
    private function claimAndCredit(array $bounty, string $status, int $receiver, ?int $hunter, int $now): bool
    {
        $this->db->update('UPDATE %%ENG_BOUNTIES%% SET `status` = :s, `hunter_id` = :h, `closed_at` = :now WHERE `id` = :id AND `status` = \'open\';', [':s' => $status, ':h' => $hunter, ':now' => $now, ':id' => (int) $bounty['id']]);

        return $this->db->rowCount() === 1 && (new BountyPayout($this->db, $this->configs, $this->state))->credit($receiver, $bounty);
    }

    /**
     * settle() inside an open transaction: everything between SAVEPOINT and RELEASE is undone when it fails.
     *
     * @param array<string, mixed> $bounty
     */
    private function settleInSavepoint(array $bounty, string $status, int $receiver, ?int $hunter, int $now): bool
    {
        $this->db->query('SAVEPOINT bounty_settle');
        $failure = null;
        try {
            $done = $this->claimAndCredit($bounty, $status, $receiver, $hunter, $now);
        } catch (\Throwable $e) {
            $failure = $e;
            $done = false;
        }
        if ($failure !== null && Database::isRetryable($failure)) {
            throw $failure;
        }
        if (!$done) {
            $this->db->query('ROLLBACK TO SAVEPOINT bounty_settle');
        }
        $this->db->query('RELEASE SAVEPOINT bounty_settle');
        if ($failure !== null) {
            error_log('bounty ' . $bounty['id'] . ' not settled: ' . $failure->getMessage());
        }

        return $done;
    }

    /** The cronjob step that runs even while the feature is off: returns the expired bounties (after the fee) to the placers, once each. */
    public function refundExpired(int $universe, int $now): void
    {
        $due = $this->db->select('SELECT * FROM %%ENG_BOUNTIES%% WHERE `universe` = :universe AND `status` = \'open\' AND `expires_at` <= :now ORDER BY `id` LIMIT 100;', [':universe' => $universe, ':now' => $now]);
        $payout = new BountyPayout($this->db, $this->configs, $this->state);
        foreach ($due as $bounty) {
            if (!(int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE `id` = :id;', [':id' => (int) $bounty['placer_id']], 'c')) {
                // the placer is gone: nobody to refund, close the row so it does not clog the queue
                $this->db->update('UPDATE %%ENG_BOUNTIES%% SET `status` = \'void\', `closed_at` = :now WHERE `id` = :id AND `status` = \'open\';', [':now' => $now, ':id' => (int) $bounty['id']]);
                error_log('bounty ' . $bounty['id'] . ' closed without refund: placer ' . $bounty['placer_id'] . ' no longer exists');
                continue;
            }
            if ($this->db->transactional(fn(): bool => $this->settle($bounty, self::EXPIRED, (int) $bounty['placer_id'], null, $now))) {
                $payout->notifyExpired($bounty, $now);
            }
        }
    }

    /** Expires the open bounties past their end (see refundExpired(); the cronjob also calls that directly while the feature is off). */
    public function tick(int $universe, int $now): void
    {
        $this->refundExpired($universe, $now);
    }

    /**
     * The placer takes back an own open bounty that nobody made progress on: the stake after the fee returns to his main planet.
     *
     * @return string 'ok' or the error code (gone, progress, planet)
     */
    public function cancel(int $userId, int $bountyId, int $now): string
    {
        return $this->db->transactional(function () use ($userId, $bountyId, $now): string {
            $bounty = $this->db->selectSingle('SELECT * FROM %%ENG_BOUNTIES%% WHERE `id` = :id AND `placer_id` = :p AND `status` = \'open\' FOR UPDATE;', [':id' => $bountyId, ':p' => $userId]);
            if (!$bounty) {
                return 'gone';
            }
            if ((int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ENG_BOUNTY_PROGRESS%% WHERE `bounty_id` = :id AND `credit` > 0;', [':id' => $bountyId], 'c') > 0) {
                return 'progress';
            }

            return $this->settle($bounty, self::CANCELLED, $userId, null, $now) ? 'ok' : 'planet';
        });
    }

    /**
     * A player was deleted: open bounties on him are cancelled and refunded to their placers, the bounties he placed are removed
     * (no refund), progress rows of both go. Runs inside the deletion transaction, whether or not the feature is on.
     */
    public function onPlayerDeleted(int $userId, int $now): void
    {
        $payout = new BountyPayout($this->db, $this->configs, $this->state);
        foreach ($this->db->select('SELECT * FROM %%ENG_BOUNTIES%% WHERE `target_id` = :id AND `status` = \'open\';', [':id' => $userId]) as $bounty) {
            try {
                if ($this->settle($bounty, self::CANCELLED, (int) $bounty['placer_id'], null, $now)) {
                    $payout->notifyCancelled($bounty, $now);
                }
            } catch (\Throwable $e) {
                if (Database::isRetryable($e)) {
                    throw $e;
                }
                error_log('bounty ' . $bounty['id'] . ' of deleted player ' . $userId . ' not refunded: ' . $e->getMessage());
            }
        }
        $this->db->delete('DELETE p FROM %%ENG_BOUNTY_PROGRESS%% p INNER JOIN %%ENG_BOUNTIES%% b ON b.`id` = p.`bounty_id` WHERE b.`placer_id` = :id OR b.`target_id` = :id OR p.`hunter_id` = :id;', [':id' => $userId]);
        $this->db->delete('DELETE FROM %%ENG_BOUNTIES%% WHERE `placer_id` = :id;', [':id' => $userId]);
    }

    /** Bots never collect bounties. */
    private function isBot(int $userId): bool
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BOT_PLAYERS%% WHERE `user_id` = :id;', [':id' => $userId], 'c') > 0;
    }
}
