<?php

declare(strict_types=1);

namespace App\Game\Engagement\Bounties;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Enum\MessageType;

/**
 * Pays a bounty out (to the hunter, or back to the placer on expiry) and sends the notices. Credits go to the main
 * planet of the receiver with one relative UPDATE; the callers claim the bounty row first (status UPDATE with a row
 * count check), so a bounty is credited exactly once.
 */
final class BountyPayout
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Credits the bounty to the main planet of a player (a planet he owns and that still exists).
     *
     * @param array<string, mixed> $bounty row with metal, crystal, deuterium
     * @return bool false when the player has no valid main planet (nothing credited)
     */
    public function credit(int $userId, array $bounty): bool
    {
        $this->db->update('UPDATE %%PLANETS%% p INNER JOIN %%USERS%% u ON u.`id_planet` = p.`id` AND u.`id` = :user AND p.`id_owner` = u.`id` AND p.`destruyed` = 0
            SET p.`metal` = p.`metal` + :m, p.`crystal` = p.`crystal` + :c, p.`deuterium` = p.`deuterium` + :d;', [
            ':user' => $userId,
            ':m' => (int) $bounty['metal'],
            ':c' => (int) $bounty['crystal'],
            ':d' => (int) $bounty['deuterium'],
        ]);

        return $this->db->rowCount() === 1;
    }

    /**
     * Tells the placer that the bounty was cancelled (by himself or because the target left the game) and the stake returned.
     *
     * @param array<string, mixed> $bounty
     */
    public function notifyCancelled(array $bounty, int $now): void
    {
        $names = $this->names([(int) $bounty['target_id']]);
        $lang = $this->playerLookup()->getLanguage(null, (int) $bounty['placer_id']);
        $total = (int) $bounty['metal'] + (int) $bounty['crystal'] + (int) $bounty['deuterium'];
        $this->send((int) $bounty['placer_id'], $bounty, $lang['bo_msg_title'], $lang->formatHtml('bo_msg_cancelled', $names[(int) $bounty['target_id']] ?? '?', $this->viewHelpers()->prettyNumber($total)), $now);
    }

    /**
     * Tells the hunter (reward), the placer and the target that a bounty was collected.
     *
     * @param array<string, mixed> $bounty
     */
    public function notifyPaid(array $bounty, int $hunterId, int $now): void
    {
        $names = $this->names([(int) $bounty['placer_id'], (int) $bounty['target_id'], $hunterId]);
        $total = (int) $bounty['metal'] + (int) $bounty['crystal'] + (int) $bounty['deuterium'];
        $hunter = $names[$hunterId] ?? '?';
        $target = $names[(int) $bounty['target_id']] ?? '?';
        $placer = $names[(int) $bounty['placer_id']] ?? '?';
        $reward = $this->viewHelpers()->prettyNumber($total);
        // the texts use positional placeholders; every text gets exactly the arguments it counts
        $notices = [
            [$hunterId, 'bo_msg_hunter', [$hunter, $target, $placer, $reward]],
            [(int) $bounty['placer_id'], 'bo_msg_placer', [$hunter, $target]],
            [(int) $bounty['target_id'], 'bo_msg_target', [$hunter, $target, $placer]],
        ];
        foreach ($notices as [$userId, $key, $args]) {
            $lang = $this->playerLookup()->getLanguage(null, $userId);
            $this->send($userId, $bounty, $lang['bo_msg_title'], $lang->formatHtml($key, ...$args), $now);
        }
    }

    /**
     * Tells the placer that the bounty ran out and the remainder was returned.
     *
     * @param array<string, mixed> $bounty
     */
    public function notifyExpired(array $bounty, int $now): void
    {
        $names = $this->names([(int) $bounty['target_id']]);
        $lang = $this->playerLookup()->getLanguage(null, (int) $bounty['placer_id']);
        $total = (int) $bounty['metal'] + (int) $bounty['crystal'] + (int) $bounty['deuterium'];
        $this->send((int) $bounty['placer_id'], $bounty, $lang['bo_msg_title'], $lang->formatHtml('bo_msg_expired', $names[(int) $bounty['target_id']] ?? '?', $this->viewHelpers()->prettyNumber($total)), $now);
    }

    /** @param array<string, mixed> $bounty */
    private function send(int $userId, array $bounty, string $subject, string $text, int $now): void
    {
        $this->playerMessenger()->sendMessage($userId, 0, $this->playerLookup()->getLanguage(null, $userId)['bo_sender'], MessageType::Game->value, $subject, $text, $now, 1, (int) $bounty['universe']);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> user id => name
     */
    private function names(array $ids): array
    {
        $names = [];
        foreach ($this->db->select('SELECT `id`, `username` FROM %%USERS%% WHERE `id` IN (' . implode(',', array_map('intval', $ids)) . ');') as $row) {
            $names[(int) $row['id']] = (string) $row['username'];
        }

        return $names;
    }
}
