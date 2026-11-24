<?php

declare(strict_types=1);

namespace App\Game\Engagement\Mothership;

use App\Game\BattleEngine\BattleFleet;
use App\Game\Enum\MessageType;

/**
 * The fight side of the pirate mothership, called by the pirate base attack mission (inside its event transaction, so
 * nothing here opens a transaction of its own).
 *
 * Damage formula: the battle result only knows the losses per side. The damage of a fight is the metal + crystal value of
 * the mothership ships destroyed in it (defender loss). It is split between the attacking users by their share of the attacking
 * side's attack power at the start of the fight: for every fleet sum(ships * attack value) * (1 + 0.1 * weapons tech) of its
 * units, added per user. The damage per user accumulates in eng_mothership_damage. When the last ship dies, the loot is split
 * by the accumulated damage and paid to every participant's main planet, once (the status flip is the guard).
 */
final class MothershipBattle
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * The living mothership of a pirate base row, locked for the fight.
     *
     * @return array<string, mixed>|null null when the base is no living mothership
     */
    public function alive(int $baseId): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT * FROM %%ENG_MOTHERSHIPS%% WHERE `base_id` = :base AND `status` = 0 FOR UPDATE;',
            [':base' => $baseId],
        );

        return $row ?: null;
    }

    /** Different players needed in the combat group, as frozen when the mothership spawned. */
    public function minPlayers(array $mothership): int
    {
        return max(1, (int) $mothership['min_players']);
    }

    /**
     * Attack power per user at the start of the fight (see the class comment).
     *
     * @param array<int|string, BattleFleet> $fleets the attacking fleets before the fight
     * @return array<int, float> user id => power
     */
    public function attackPower(array $fleets): array
    {
        $catalog = $this->elements();
        $power = [];
        foreach ($fleets as $fleet) {
            $userId = (int) $fleet->userId();
            if ($userId <= 0) {
                continue;
            }
            $sum = 0.0;
            foreach ($fleet->units as $unitId => $count) {
                if ($catalog->has((int) $unitId)) {
                    $sum += (float) $count * (float) $catalog->combat((int) $unitId)->attack;
                }
            }
            $power[$userId] = ($power[$userId] ?? 0.0) + $sum * $fleet->techs->attackMultiplier();
        }

        return $power;
    }

    /** Share of the group's attack power a player needs to count as a participant (for min_players and the loot). */
    public const MIN_SHARE = 0.05;

    /**
     * Users whose attack power is at least MIN_SHARE of the group total; players that are neutral to each other (same alliance, buddies,
     * pact, same IP) count as one, the strongest of them.
     *
     * @param array<int, float> $power user id => attack power
     * @return list<int>
     */
    public function qualifying(array $power): array
    {
        $total = array_sum($power);
        arsort($power);
        $neutrality = new \App\Game\Engagement\Seasons\FightNeutrality($this->db, $this->state->runtime->timestamp);
        $users = [];
        foreach ($power as $userId => $userPower) {
            if ($total <= 0 || $userPower / $total < self::MIN_SHARE) {
                continue;
            }
            // alts and allies (neutral to each other) count as one player: only the strongest of them qualifies
            foreach ($users as $kept) {
                if ($neutrality->pairIsNeutral((int) $userId, $kept)) {
                    continue 2;
                }
            }
            $users[] = (int) $userId;
        }

        return $users;
    }

    /**
     * Adds the damage of one fight to the attackers by their power share; bots earn nothing. Then pays out when the mothership is dead.
     *
     * @param array<string, mixed> $mothership eng_motherships row (locked)
     * @param array<int, float> $power user id => attack power at the start of the fight
     * @param float $damage metal + crystal value of the destroyed mothership ships
     * @param array<int, int|float> $unitsLeft ship id => amount of the mothership after the fight
     */
    public function afterFight(array $mothership, array $power, float $damage, array $unitsLeft, int $now): void
    {
        $total = array_sum($power);
        $qualified = $this->qualifying($power);
        if ($damage > 0 && $total > 0) {
            foreach ($power as $userId => $userPower) {
                if (!in_array((int) $userId, $qualified, true) || $this->isBot((int) $userId)) {
                    continue;
                }
                $this->db->insert(
                    'INSERT INTO %%ENG_MOTHERSHIP_DAMAGE%% SET `mothership_id` = :id, `user_id` = :user, `damage` = :damage, `fights` = 1
                    ON DUPLICATE KEY UPDATE `damage` = `damage` + VALUES(`damage`), `fights` = `fights` + 1;',
                    [':id' => $mothership['id'], ':user' => $userId, ':damage' => $damage * $userPower / $total],
                );
            }
        }
        if (array_sum(array_filter($unitsLeft)) <= 0) {
            $this->payOut($mothership, $now);
        }
    }

    /**
     * The mothership is dead: flips the status (the guard against paying twice), splits the loot by damage, credits each
     * participant's main planet and messages them. Posts to the log webhook if one is set.
     *
     * @param array<string, mixed> $mothership
     */
    private function payOut(array $mothership, int $now): void
    {
        $this->db->update('UPDATE %%ENG_MOTHERSHIPS%% SET `status` = 1, `ended_at` = :now WHERE `id` = :id AND `status` = 0;', [':now' => $now, ':id' => $mothership['id']]);
        if ($this->db->rowCount() !== 1) {
            return; // somebody else already finished it
        }
        $this->db->delete('DELETE FROM %%PIRATE_BASES%% WHERE `id` = :id AND `kind` = 2;', [':id' => $mothership['base_id']]);

        $rows = $this->db->select(
            'SELECT d.`user_id`, d.`damage`, u.`id_planet` FROM %%ENG_MOTHERSHIP_DAMAGE%% d
            INNER JOIN %%USERS%% u ON u.`id` = d.`user_id` WHERE d.`mothership_id` = :id AND d.`damage` > 0 AND u.`banaday` <= :now;',
            [':id' => $mothership['id'], ':now' => $now],
        );
        $totalDamage = array_sum(array_map('floatval', array_column($rows, 'damage')));
        foreach ($rows as $row) {
            $share = $totalDamage > 0 ? (float) $row['damage'] / $totalDamage : 0.0;
            $paid = [
                floor((float) $mothership['loot_metal'] * $share),
                floor((float) $mothership['loot_crystal'] * $share),
                floor((float) $mothership['loot_deuterium'] * $share),
            ];
            $this->db->update('UPDATE %%PLANETS%% SET `metal` = `metal` + :m, `crystal` = `crystal` + :c, `deuterium` = `deuterium` + :d WHERE `id` = :id;', [
                ':m' => $paid[0], ':c' => $paid[1], ':d' => $paid[2], ':id' => $row['id_planet'],
            ]);
            $this->db->update('UPDATE %%ENG_MOTHERSHIP_DAMAGE%% SET `paid_metal` = :m, `paid_crystal` = :c, `paid_deuterium` = :d WHERE `mothership_id` = :id AND `user_id` = :user;', [
                ':m' => $paid[0], ':c' => $paid[1], ':d' => $paid[2], ':id' => $mothership['id'], ':user' => $row['user_id'],
            ]);
            $this->tell((int) $row['user_id'], 'ms_msg_title', 'ms_msg_dead', $paid, $now, (int) $mothership['universe'], round(100 * $share), 'mothership');
        }
        $this->discord()->sendLog('Pirate mothership destroyed', [
            'universe' => $mothership['universe'], 'participants' => count($rows), 'loot' => round((float) $mothership['loot_metal'] + (float) $mothership['loot_crystal'] + (float) $mothership['loot_deuterium']),
        ]);
    }

    /**
     * Tells the participants who fought it that the mothership left (expiry, no loot).
     *
     * @param array<string, mixed> $mothership
     */
    public function notifyLeft(array $mothership, int $now): void
    {
        $users = $this->db->select('SELECT `user_id` FROM %%ENG_MOTHERSHIP_DAMAGE%% WHERE `mothership_id` = :id AND `damage` > 0;', [':id' => $mothership['id']]);
        foreach ($users as $row) {
            $this->tell((int) $row['user_id'], 'ms_msg_title', 'ms_msg_left', [0, 0, 0], $now, (int) $mothership['universe'], 0);
        }
    }

    /**
     * Message to one participant in his language.
     *
     * @param array<int, float|int> $paid metal, crystal, deuterium
     */
    private function tell(int $userId, string $titleKey, string $textKey, array $paid, int $now, int $universe, float|int $percent, string $relicSource = ''): void
    {
        $lang = $this->playerLookup()->getLanguage(null, $userId);
        $view = $this->viewHelpers();
        $text = $lang->formatHtml($textKey, $percent, $view->prettyNumber($paid[0]), $view->prettyNumber($paid[1]), $view->prettyNumber($paid[2]));
        if ($relicSource !== '') {
            // relics (setting relics_src_mothership): a relic of at least the configured rarity for the participant; guarded, a failure never blocks the payout
            $this->achievementGuard()->run(function () use (&$text, $relicSource, $userId, $universe, $lang, $now): void {
                $text .= (new \App\Game\Engagement\Relics\RelicDrops($this->db, $this->configs, $this->state))->fromSource($relicSource, $userId, $universe, $lang, $now);
            });
        }
        $this->playerMessenger()->sendMessage($userId, 0, $lang['ms_sender'], MessageType::Game->value, $lang[$titleKey], $text, $now, 1, $universe);
    }

    /** True when the user is a bot account (bots earn no rewards). */
    private function isBot(int $userId): bool
    {
        return (bool) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%BOT_PLAYERS%% WHERE `user_id` = :id;', [':id' => $userId], 'n');
    }
}
