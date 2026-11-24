<?php

namespace App\Game\Alliance;

use App\Game\Enum\DiploType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Language;
use App\Game\Core\Singleton;

/**
 * Diplomacy between alliances (table diplo): listing, requesting, accepting, rejecting and ending treaties
 * (main/wing, alliance/trade/non-aggression pacts, war, secret pact, peace), including the messages to both sides.
 *
 * Scoping rules (security fixes GB-10/12/30/31/34 stay in force): every lookup is restricted to rows where the
 * acting alliance is one of the two parties (see targetAlliance()), only the recipient of an open request may
 * accept it, and deleting only ever touches rows of the own alliance.
 */
class AllianceDiplomacyService
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
        private readonly AllianceMessenger $messenger,
    ) {}

    /**
     * Treaties of the alliance as shown on the home and info pages, grouped by level (and 5 = incoming, 6 = outgoing
     * open requests). Level "wing" is turned into "main" when seen from the second party.
     *
     * @param array<string, mixed> $alliance
     * @return array<int, array<int|string, array>>
     */
    public function relations(array $alliance): array
    {
        $Return = [];

        $sql = "SELECT d.level, d.accept, d.accept_text, d.id, a.id as ally_id, a.ally_name, a.ally_tag, d.owner_1,"
            . " d.owner_2 FROM %%DIPLO%% as d INNER JOIN %%ALLIANCE%% as a ON IF(:allianceId = d.owner_1,"
            . " a.id = d.owner_2, a.id = d.owner_1) WHERE :allianceId = d.owner_1 OR :allianceId = d.owner_2;";
        $DiploResult = $this->db->select($sql, [
            ':allianceId' => $alliance['id'],
        ]);

        foreach ($DiploResult as $CurDiplo) {
            if ($CurDiplo['level'] == 1 && $CurDiplo['owner_2'] == $alliance['id']) {
                $CurDiplo['level'] = 0;
            }

            if ($CurDiplo['accept'] == 0 && $CurDiplo['owner_2'] == $alliance['id']) {
                $Return[5][$CurDiplo['id']] = [
                    $CurDiplo['ally_name'],
                    $CurDiplo['ally_id'],
                    $CurDiplo['level'],
                    $CurDiplo['accept_text'],
                    $CurDiplo['ally_tag'],
                ];
            } elseif ($CurDiplo['accept'] == 0 && $CurDiplo['owner_1'] == $alliance['id']) {
                $Return[6][$CurDiplo['id']] = [
                    $CurDiplo['ally_name'],
                    $CurDiplo['ally_id'],
                    $CurDiplo['level'],
                    $CurDiplo['accept_text'],
                    $CurDiplo['ally_tag'],
                ];
            } else {
                $Return[$CurDiplo['level']][$CurDiplo['id']] = [
                    $CurDiplo['ally_name'],
                    $CurDiplo['ally_id'],
                    $CurDiplo['owner_1'],
                    $CurDiplo['ally_tag'],
                ];
            }
        }

        return $Return;
    }

    /**
     * Data of the diplomacy admin page: [0] = accepted treaties, [1] = requests received, [2] = requests sent;
     * each grouped by level, id => name of the other alliance.
     *
     * @param array<string, mixed> $alliance
     * @return array<int, array<int, array<int|string, string>>>
     */
    public function overview(array $alliance): array
    {
        $emptyStatus = [
            DiploType::Main->value => [],
            DiploType::Wing->value => [],
            DiploType::AlliancePact->value => [],
            DiploType::TradePact->value => [],
            DiploType::NonAgressionPact->value => [],
            DiploType::War->value => [],
            DiploType::SecretAlliancePact->value => [],
            DiploType::Peace->value => [],
        ];
        $diplomaticList = [
            0 => [
                DiploType::Main->value => [],
                DiploType::Wing->value => [],
                DiploType::AlliancePact->value => [],
                DiploType::TradePact->value => [],
                DiploType::NonAgressionPact->value => [],
                DiploType::War->value => [],
                DiploType::SecretAlliancePact->value => [],
            ],
            1 => $emptyStatus,
            2 => $emptyStatus,
        ];

        $sql = "SELECT d.id, d.level, d.accept, d.owner_1, d.owner_2, a.ally_name
            FROM %%DIPLO%% d
            INNER JOIN %%ALLIANCE%% a ON IF(:allianceId = d.owner_1, a.id = d.owner_2, a.id = d.owner_1)
            WHERE owner_1 = :allianceId OR owner_2 = :allianceId;";
        $diplomaticResult = $this->db->select($sql, [
            ':allianceId' => $alliance['id'],
        ]);

        foreach ($diplomaticResult as $diplomaticRow) {
            if ($diplomaticRow['level'] == DiploType::Wing->value && $diplomaticRow['owner_2'] == $alliance['id']) {
                $diplomaticRow['level'] = DiploType::Main->value;
            }
            $own = $diplomaticRow['owner_1'] == $alliance['id'];
            if ($diplomaticRow['accept'] == 1) {
                $diplomaticList[0][$diplomaticRow['level']][$diplomaticRow['id']] = $diplomaticRow['ally_name'];
            } elseif ($own) {
                $diplomaticList[2][$diplomaticRow['level']][$diplomaticRow['id']] = $diplomaticRow['ally_name'];
            } else {
                $diplomaticList[1][$diplomaticRow['level']][$diplomaticRow['id']] = $diplomaticRow['ally_name'];
            }
        }

        return $diplomaticList;
    }

    /**
     * The other party of a treaty, with the treaty level (`diplo`) and acceptance, scoped to the acting alliance
     * and the universe.
     *
     * @param int  $id           treaty id (diplo.id), or the other alliance's id when $byAllianceId is set
     * @param bool $byAllianceId look the alliance up by its own id (create form/request); a LEFT JOIN yields
     *                           `diplo` = null when there is no treaty yet
     * @return array<string, mixed>|false|null id, ally_name, ally_owner, ally_tag, diplo, accept
     */
    public function targetAlliance(int $id, bool $byAllianceId = false): array|false|null
    {
        $USER = $this->state->USER;
        $db = $this->db;

        if ($byAllianceId) {
            $sql = "SELECT a.id, a.ally_name, a.ally_owner, a.ally_tag, d.level as diplo, d.accept
                FROM %%ALLIANCE%% a
                LEFT JOIN %%DIPLO%% d ON (d.owner_1 = :id AND d.owner_2 = :allianceId) OR (d.owner_2 = :id AND d.owner_1 = :allianceId)
                WHERE a.ally_universe = :universe AND a.id = :id;";

            return $db->selectSingle($sql, [
                ':allianceId' => $USER['ally_id'],
                ':id' => $id,
                ':universe' => $this->universe()->current(),
            ]);
        }

        $sql = "SELECT a.id, a.ally_name, a.ally_owner, a.ally_tag, d.level as diplo, d.accept
            FROM %%DIPLO%% d
            JOIN %%ALLIANCE%% a ON a.id = d.owner_1 OR a.id = d.owner_2
            WHERE a.ally_universe = :universe AND d.id = :id AND a.id != :allianceId
            AND (d.owner_1 = :allianceId OR d.owner_2 = :allianceId);";

        return $db->selectSingle($sql, [
            ':allianceId' => $USER['ally_id'],
            ':id' => $id,
            ':universe' => $this->universe()->current(),
        ]);
    }

    /**
     * Data for the "new treaty" dialog: the selectable alliances and levels.
     *
     * @param int|string $diploMode DiploType::Peace->value offers peace to the one alliance `$id` we are at war with
     * @return array{diploLevel: array, AllyList: array, IdList: array}
     */
    public function createFormData(int|string $diploMode, int $id): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

        $AllyList = [];
        $IdList = [];

        if ($diploMode == DiploType::Peace->value) {
            $diplomaticAlly = $this->targetAlliance($id, $id == $USER['ally_id']);
            $IdList[] = $diplomaticAlly['id'];
            $AllyList[] = $diplomaticAlly['ally_name'];

            $diplo_level = [
                DiploType::Peace->value => $LNG['al_diplo_level'][DiploType::Peace->value],
            ];
        } else {
            $sql = "SELECT `ally_tag`, `ally_name`, `id` FROM %%ALLIANCE%% WHERE `id` != :allianceId AND `ally_universe` = :universe ORDER BY `ally_tag` ASC;";
            $diplomaticAlly = $this->db->select($sql, [
                ':allianceId' => $USER['ally_id'],
                ':universe' => $this->universe()->current(),
            ]);
            foreach ($diplomaticAlly as $i) {
                $IdList[] = $i['id'];
                $AllyList[] = $i['ally_name'];
            }

            $diplo_level = $LNG['al_diplo_level'];
            array_shift($diplo_level);
        }

        return ['diploLevel' => $diplo_level, 'AllyList' => $AllyList, 'IdList' => $IdList];
    }

    /**
     * Accepts a treaty request (or, for a peace offer, ends the war: war and peace rows between the two alliances are
     * deleted) and informs both alliances.
     *
     * @param array<string, mixed> $alliance acting alliance
     * @throws AllianceException home when the treaty does not involve the alliance, redirect to the diplomacy page
     *         when the alliance is not the recipient of an open request
     */
    public function accept(array $alliance, int $id): void
    {
        $db = $this->db;
        $targetAlliance = $this->targetAlliance($id);

        if (empty($targetAlliance)) {
            throw AllianceException::home();
        }

        // Only the recipient of an open request may accept it.
        $sql = "SELECT COUNT(*) as c FROM %%DIPLO%% WHERE id = :id AND owner_2 = :allianceId AND accept = 0;";
        $canAccept = $db->selectSingle($sql, [
            ':id' => $id,
            ':allianceId' => $alliance['id'],
        ], 'c');
        if (!$canAccept) {
            throw AllianceException::redirect('game_alliance_admin_diplomacy');
        }

        if ($targetAlliance['diplo'] == DiploType::Peace->value) {
            $sql = "DELETE FROM %%DIPLO%% WHERE `level` IN (:war,:piece)
                AND `owner_1` IN (:allianceId1,:allianceId2) AND `owner_2` IN (:allianceId1,:allianceId2)";
            $db->delete($sql, [
                ':war'          => DiploType::War->value,
                ':piece'        => DiploType::Peace->value,
                ':allianceId1'  => $alliance['id'],
                ':allianceId2'  => $targetAlliance['id'],
            ]);
        } else {
            // a treaty proposed with a duration runs that long from the acceptance on (assignments run left to right)
            $sql = "UPDATE %%DIPLO%% SET `expires_at` = IF(`expires_at` IS NULL, NULL, :request_time + (`expires_at` - `request_time`)),
                `accept` = 1, `request_time` = :request_time
                WHERE `id` = :id AND `owner_2` = :allianceId";
            $db->update($sql, [
                ':allianceId'   => $alliance['id'],
                ':id'           => $id,
                ':request_time' => $this->state->runtime->timestamp,
            ]);
        }

        $isWar = $targetAlliance['diplo'] == DiploType::War->value;
        if (!$isWar) {
            $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkDiplomacyAchievements((int) $alliance['id']));
            $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkDiplomacyAchievements((int) $targetAlliance['id']));
        }
        $this->messenger->sendDiplomatic(
            $this->bothSides($targetAlliance, $alliance),
            $alliance['ally_tag'],
            fn(array|\ArrayAccess $lang): array => [
                $isWar ? $lang['al_diplo_war_end'] : $lang['al_diplo_accept_yes'],
                $this->treatyText($isWar ? 'al_diplo_war_end_mes' : 'al_diplo_accept_yes_mes', $lang, (int) $targetAlliance['diplo'], $alliance, $targetAlliance),
            ],
        );
    }

    /**
     * Rejects a treaty request: informs the other alliance's leaders/diplomats and deletes the treaty row
     * (only rows of the own alliance).
     *
     * @param array<string, mixed> $alliance
     * @throws AllianceException home when the treaty does not involve the alliance
     */
    public function reject(array $alliance, int $id): void
    {
        $targetAlliance = $this->targetAlliance($id);

        if (empty($targetAlliance)) {
            throw AllianceException::home();
        }

        // only an open request may be rejected, a war (accepted at once) cannot be ended that way
        if ($targetAlliance['accept'] == 1 || $targetAlliance['diplo'] == DiploType::War->value) {
            throw AllianceException::home();
        }

        $this->messenger->sendDiplomatic(
            $this->repository->messageReceivers($targetAlliance['id'], $this->state->USER['ally_id'], true),
            $alliance['ally_tag'],
            fn(array|\ArrayAccess $lang): array => [
                $lang['al_diplo_accept_no'],
                $this->treatyText('al_diplo_accept_no_mes', $lang, (int) $targetAlliance['diplo'], $alliance, $targetAlliance),
            ],
        );

        $this->deleteRow($alliance, $id);
    }

    /**
     * Ends an accepted treaty (both alliances are informed) or withdraws an open request (the other alliance's
     * leaders/diplomats are informed), then deletes the row (only rows of the own alliance).
     *
     * @param array<string, mixed> $alliance
     * @throws AllianceException home when the treaty does not involve the alliance
     */
    public function delete(array $alliance, int $id): void
    {
        $targetAlliance = $this->targetAlliance($id);

        if (empty($targetAlliance)) {
            throw AllianceException::home();
        }

        $level = (int) $targetAlliance['diplo'];
        // a war ends only through an accepted peace offer, never by deleting the row (also not by the declaring side)
        if ($level === DiploType::War->value) {
            throw AllianceException::home();
        }
        if ($targetAlliance['accept'] == 1) {
            $this->messenger->sendDiplomatic(
                $this->bothSides($targetAlliance, $alliance),
                $alliance['ally_tag'],
                fn(array|\ArrayAccess $lang): array => [
                    $lang['al_diplo_delete'],
                    $this->treatyText('al_diplo_delete_mes', $lang, $level, $alliance, $targetAlliance),
                ],
            );
        } else {
            $this->messenger->sendDiplomatic(
                $this->repository->messageReceivers($targetAlliance['id'], $this->state->USER['ally_id'], true),
                $alliance['ally_tag'],
                fn(array|\ArrayAccess $lang): array => [
                    $lang['al_diplo_withdraw'],
                    $this->treatyText('al_diplo_withdraw_mes', $lang, $level, $alliance, $targetAlliance),
                ],
            );
        }

        $this->deleteRow($alliance, $id);
    }

    /**
     * Requests a treaty with another alliance (war is declared at once, everything else needs acceptance) and
     * informs the receivers.
     *
     * Rules: the level must be a valid treaty level; the target must exist in this universe, differ from the own
     * alliance and not already have a treaty with it, except that peace may be offered during a war.
     *
     * @param array<string, mixed> $alliance acting alliance
     * @param int|string $id    id of the other alliance
     * @param int|string $level treaty level (DIPLO_*)
     * @param string $text      free text sent with the request
     * @param int $expiresDays  optional duration of the treaty in days (1..365, 0 = unlimited; not for war and peace)
     * @throws AllianceException home on an invalid level; json error for unknown/duplicate/own alliance
     */
    public function create(array $alliance, int|string $id, int|string $level, string $text, int $expiresDays = 0): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

        $targetAlliance = $this->targetAlliance((int) $id, true);

        if (!in_array((int) $level, [DiploType::Wing->value, DiploType::AlliancePact->value, DiploType::TradePact->value, DiploType::NonAgressionPact->value, DiploType::War->value, DiploType::SecretAlliancePact->value, DiploType::Peace->value], true)) {
            throw AllianceException::home();
        }
        $level = (int) $level;

        if (empty($targetAlliance)) {
            throw AllianceException::json([
                'error' => true,
                'message' => $LNG->format('al_diplo_no_alliance', $id),
            ]);
        }

        if (!empty($targetAlliance['diplo']) && ($targetAlliance['diplo'] != DiploType::War->value || $level != DiploType::Peace->value)) {
            throw AllianceException::json([
                'error' => true,
                'message' => $LNG->format('al_diplo_exists', $targetAlliance['ally_name']),
            ]);
        }
        if ($targetAlliance['id'] == $alliance['id']) {
            throw AllianceException::json([
                'error' => true,
                'message' => $LNG['al_diplo_same_alliance'],
            ]);
        }

        $this->assertNoOpenPeaceOffer($level, $alliance, $targetAlliance);

        if ($level == DiploType::War->value) {
            $this->messenger->sendDiplomatic(
                $this->bothSides($targetAlliance, $alliance),
                $alliance['ally_tag'],
                fn(array|\ArrayAccess $lang): array => [
                    $lang['al_diplo_war'],
                    $lang->formatHtml(
                        'al_diplo_war_mes',
                        "[" . $alliance['ally_tag'] . "] " . $alliance['ally_name'],
                        "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                        $lang['al_diplo_level'][$level],
                        $text,
                    ),
                ],
            );
        } else {
            $this->messenger->sendDiplomatic(
                $this->repository->messageReceivers($targetAlliance['id'], $USER['ally_id'], true),
                $alliance['ally_tag'],
                fn(array|\ArrayAccess $lang): array => [
                    $lang['al_diplo_ask'],
                    $lang->formatHtml(
                        'al_diplo_ask_mes',
                        $lang['al_diplo_level'][$level],
                        "[" . $alliance['ally_tag'] . "] " . $alliance['ally_name'],
                        "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                        $text,
                    ),
                ],
            );
        }

        $expiresDays = in_array($level, [DiploType::War->value, DiploType::Peace->value], true) ? 0 : max(0, min(365, $expiresDays));
        $sql = "INSERT INTO %%DIPLO%% SET owner_1 = :allianceId, owner_2 = :allianceTargetID, level	= :level,"
            . " accept = :accept, accept_text = :text, universe = :universe, request_time = :request_time, expires_at = :expires";
        $this->db->insert($sql, [
            ':allianceId' => $USER['ally_id'],
            ':allianceTargetID' => $targetAlliance['id'],
            ':level' => $level,
            ':accept' => $level == DiploType::War->value ? 1 : 0,
            ':text' => $text,
            ':universe' => $this->universe()->current(),
            ':request_time' => $this->state->runtime->timestamp,
            ':expires' => $expiresDays > 0 ? $this->state->runtime->timestamp + $expiresDays * 86400 : null,
        ]);
    }

    /**
     * End dates of the alliance's treaties for the diplomacy page.
     *
     * @param array<string, mixed> $alliance
     * @return array<int, int> treaty id => expiry time stamp (only treaties with an end date)
     */
    public function expiries(array $alliance): array
    {
        $rows = $this->db->select(
            'SELECT id, expires_at FROM %%DIPLO%% WHERE expires_at IS NOT NULL AND (owner_1 = :id OR owner_2 = :id);',
            [':id' => $alliance['id']],
        );

        return array_column($rows, 'expires_at', 'id');
    }

    /**
     * Ends every treaty whose end date has passed (the daily cleanup): the row is deleted and, when the treaty was
     * in force, all members of both alliances get a system message in their language. Expired open requests vanish silently.
     * Does not depend on a logged-in player.
     *
     * @return int number of treaties removed
     */
    public function expireTreaties(): int
    {
        $rows = $this->db->select(
            'SELECT d.id, d.owner_1, d.owner_2, d.level, d.accept, a1.ally_tag AS tag_1, a1.ally_name AS name_1,
                a2.ally_tag AS tag_2, a2.ally_name AS name_2
            FROM %%DIPLO%% d
            INNER JOIN %%ALLIANCE%% a1 ON a1.id = d.owner_1
            INNER JOIN %%ALLIANCE%% a2 ON a2.id = d.owner_2
            WHERE d.expires_at IS NOT NULL AND d.expires_at <= :now AND d.level NOT IN (:war, :peace);',
            [':now' => $this->state->runtime->timestamp, ':war' => DiploType::War->value, ':peace' => DiploType::Peace->value],
        );

        foreach ($rows as $row) {
            $this->db->delete('DELETE FROM %%DIPLO%% WHERE id = :id;', [':id' => $row['id']]);
            if ($row['accept'] == 1) {
                $this->messenger->treatyExpired(
                    $this->repository->messageReceivers((int) $row['owner_2'], (int) $row['owner_1']),
                    (int) $row['level'],
                    '[' . $row['tag_1'] . '] ' . $row['name_1'],
                    '[' . $row['tag_2'] . '] ' . $row['name_2'],
                );
            }
        }

        return count($rows);
    }

    /**
     * One open peace offer between two alliances at a time (the lookup in create() only sees one joined row).
     *
     * @param array<string, mixed> $alliance
     * @param array<string, mixed> $targetAlliance
     * @throws AllianceException json error when a peace offer is already open
     */
    private function assertNoOpenPeaceOffer(int $level, array $alliance, array $targetAlliance): void
    {
        if ($level != DiploType::Peace->value) {
            return;
        }

        $sql = "SELECT COUNT(*) as c FROM %%DIPLO%% WHERE `level` = :peace
            AND `owner_1` IN (:id1, :id2) AND `owner_2` IN (:id1, :id2);";
        $openPeace = $this->db->selectSingle($sql, [
            ':peace' => DiploType::Peace->value,
            ':id1' => $alliance['id'],
            ':id2' => $targetAlliance['id'],
        ], 'c');
        if ($openPeace) {
            throw AllianceException::json([
                'error' => true,
                'message' => $this->state->LNG->format('al_diplo_exists', $targetAlliance['ally_name']),
            ]);
        }
    }

    /**
     * Receivers on both sides of a treaty: members of the two alliances, each user once.
     *
     * @param array<string, mixed> $alliance
     * @param array<mixed> $targetAlliance
     * @return list<array{id: int|string, lang: string}>
     */
    private function bothSides(array $targetAlliance, array $alliance): array
    {
        $ownAllyId = $this->state->USER['ally_id'];
        $merged = array_merge(
            $this->repository->messageReceivers($targetAlliance['id'], $ownAllyId),
            $this->repository->messageReceivers($alliance['id'], $ownAllyId),
        );

        $receivers = [];
        foreach ($merged as $receiver) {
            $receivers[$receiver['id']] = $receiver;
        }

        return array_values($receivers);
    }

    /**
     * the standard treaty message: level name, own alliance and other alliance as "[tag] name"
     *
     * @param array<string, mixed> $alliance
     * @param array<mixed> $targetAlliance
     */
    private function treatyText(string $key, Language $lang, int $level, array $alliance, array $targetAlliance): string
    {
        return $lang->formatHtml(
            $key,
            $lang['al_diplo_level'][$level],
            "[" . $alliance['ally_tag'] . "] " . $alliance['ally_name'],
            "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
        );
    }

    /**
     * deletes a treaty row, only when the alliance is one of its two parties
     *
     * @param array<string, mixed> $alliance
     */
    private function deleteRow(array $alliance, int $id): void
    {
        $sql = "DELETE FROM %%DIPLO%% WHERE `id` = :id AND (`owner_1` = :allianceId OR `owner_2` = :allianceId);";
        $this->db->delete($sql, [
            ':allianceId' => $alliance['id'],
            ':id' => $id,
        ]);
    }
}
