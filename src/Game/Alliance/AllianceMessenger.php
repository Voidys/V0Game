<?php

namespace App\Game\Alliance;

use App\Game\Enum\MessageType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * All in-game messages the alliance system sends: application and leave notices to the managers, circular messages
 * to members, and the per-language diplomacy messages to the two alliances involved. Every message is of type
 * MessageType::Alliance->value and written in the receiver's own language.
 */
class AllianceMessenger
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
    ) {}

    /** Tells the managers (leader and MANAGEAPPLY ranks) of the alliance that the acting user applied. */
    public function applicationReceived(int|string $allianceId): void
    {
        $USER = $this->state->USER;
        foreach ($this->repository->messageReceivers($allianceId, $USER['ally_id'], false, true) as $receiver) {
            $lang = $this->playerLookup()->getLanguage($receiver['lang']);

            $applyMessage = $lang->formatHtml(
                'al_new_apply',
                $USER['id'],
                $USER['username'],
                $USER['username'],
            );

            $this->playerMessenger()->sendMessage(
                $receiver['id'],
                0,
                $lang['al_alliance'],
                MessageType::Alliance->value,
                $lang['al_request'],
                $applyMessage,
                $this->state->runtime->timestamp,
            );
        }
    }

    /** Tells the managers of the alliance (except the acting user) that the acting user left. */
    public function memberLeft(int|string $allianceId): void
    {
        $USER = $this->state->USER;
        foreach ($this->repository->messageReceivers($allianceId, $USER['ally_id'], false, true) as $receiver) {
            if ($receiver['id'] == $USER['id']) {
                continue;
            }
            $lang = $this->playerLookup()->getLanguage($receiver['lang']);

            $applyMessage = $lang->formatHtml(
                'al_leaving_msg',
                $USER['id'],
                $USER['username'],
                $USER['username'],
            );

            $this->playerMessenger()->sendMessage(
                $receiver['id'],
                0,
                $lang['al_alliance'],
                MessageType::Alliance->value,
                $lang['al_leaving'],
                $applyMessage,
                $this->state->runtime->timestamp,
            );
        }
    }

    /** Tells a kicked member that he was removed from the alliance. */
    public function memberKicked(int|string $userId, string $label): void
    {
        $lang = $this->playerLookup()->getLanguage(null, $userId);
        $this->sendSystem($userId, $lang, $lang['al_kicked'], $lang->formatHtml('al_kicked_msg', $label));
    }

    /**
     * Tells everybody concerned that the alliance is being disbanded: the other members, the open applicants and the
     * leaders/diplomats of the alliances it had a treaty with. Call before the alliance rows are deleted.
     *
     * @param array<string, mixed> $alliance
     */
    public function allianceDisbanded(array $alliance): void
    {
        $db = $this->db;
        $label = '[' . $alliance['ally_tag'] . '] ' . $alliance['ally_name'];
        $actingId = $this->state->USER['id'];
        $allianceId = (int) $alliance['id'];

        $members = $db->select(
            'SELECT u.id, us.lang FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id
            WHERE u.ally_id = :id AND u.id != :actor;',
            [':id' => $allianceId, ':actor' => $actingId],
        );
        $this->sendDisbanded($members, 'al_disbanded_msg', $label);

        $applicants = $db->select(
            'SELECT u.id, us.lang FROM %%ALLIANCE_REQUEST%% r INNER JOIN %%USERS%% u ON u.id = r.userID
            INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id WHERE r.allianceID = :id;',
            [':id' => $allianceId],
        );
        $this->sendDisbanded($applicants, 'al_disbanded_applicant_msg', $label);

        $partners = $db->select(
            'SELECT IF(owner_1 = :id, owner_2, owner_1) AS partner FROM %%DIPLO%% WHERE owner_1 = :id OR owner_2 = :id;',
            [':id' => $allianceId],
        );
        $receivers = [];
        foreach ($partners as $partner) {
            foreach ($this->repository->messageReceivers((int) $partner['partner'], 0, true) as $receiver) {
                $receivers[$receiver['id']] = $receiver;
            }
        }
        $this->sendDisbanded(array_values($receivers), 'al_disbanded_partner_msg', $label);
    }

    /**
     * @param list<array{id: int|string, lang: string}> $receivers
     */
    private function sendDisbanded(array $receivers, string $textKey, string $label): void
    {
        foreach ($receivers as $receiver) {
            $lang = $this->playerLookup()->getLanguage($receiver['lang']);
            $this->sendSystem($receiver['id'], $lang, $lang['al_disbanded'], $lang->formatHtml($textKey, $label));
        }
    }

    /** One alliance-type system message (sender: the alliance system) to a user, in his language. */
    private function sendSystem(int|string $userId, \ArrayAccess|array $lang, string $subject, string $text): void
    {
        $this->playerMessenger()->sendMessage(
            $userId,
            0,
            $lang['al_alliance'],
            MessageType::Alliance->value,
            $subject,
            $text,
            $this->state->runtime->timestamp,
        );
    }

    /**
     * Circular message of the acting user to all members of the alliance or to one rank.
     *
     * Side effect: one message (sender = acting user) to every receiver.
     *
     * @param array<string, mixed> $alliance
     * @return string the confirmation text listing the receivers' names (one per line)
     * @throws AllianceException json error when the text is empty
     */
    public function sendCircular(array $alliance, int|string $rankId, string $subject, string $text): string
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        if (empty($text)) {
            throw AllianceException::json(['message' => $LNG['mg_empty_text'], 'error' => true]);
        }

        $db = $this->db;
        if ($rankId == 0) {
            $sql = 'SELECT id, username FROM %%USERS%% WHERE ally_id = :AllianceID;';
            $sendUsersResult = $db->select($sql, [
                ':AllianceID' => $alliance['id'],
            ]);
        } else {
            $sql = 'SELECT id, username FROM %%USERS%% WHERE ally_id = :AllianceID AND ally_rank_id = :RankID;';
            $sendUsersResult = $db->select($sql, [
                ':AllianceID' => $alliance['id'],
                ':RankID' => $rankId,
            ]);
        }

        $sendList = $LNG['al_circular_sended'];
        $title = $LNG['al_circular_alliance'] . $alliance['ally_tag'];
        $text = $LNG->formatHtml('al_circular_front_text', $USER['username']) . "\r\n" . $text;

        $receivers = [];
        foreach ($sendUsersResult as $sendUsersRow) {
            $receivers[] = $sendUsersRow['id'];
            $sendList .= "\n" . $sendUsersRow['username'];
        }
        $this->playerMessenger()->sendMessage(
            $receivers,
            $USER['id'],
            $title,
            MessageType::Alliance->value,
            $subject,
            makebr($text),
            $this->state->runtime->timestamp,
        );

        return $sendList;
    }

    /**
     * Sends a diplomacy message to the receivers, grouped by language so that every group gets one message in its
     * own language (sender = acting user, sender name "<al_circular_alliance><own tag>").
     *
     * @param list<array{id: int|string, lang: string}> $receivers
     * @param string $ownTag tag of the acting alliance
     * @param callable(array<string, mixed>|\ArrayAccess): array{0: string, 1: string} $compose receives the language data and
     *        returns [subject, text]
     */
    public function sendDiplomatic(array $receivers, string $ownTag, callable $compose): void
    {
        $langReceivers = [];
        foreach ($receivers as $receiver) {
            if (!isset($langReceivers[$receiver['lang']])) {
                $langReceivers[$receiver['lang']] = [
                    'receivers' => [],
                    'lang' => $this->playerLookup()->getLanguage($receiver['lang']),
                ];
            }
            $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
        }
        foreach ($langReceivers as $group) {
            [$subject, $text] = $compose($group['lang']);
            $this->playerMessenger()->sendMessage(
                $group['receivers'],
                $this->state->USER['id'],
                $group['lang']['al_circular_alliance'] . $ownTag,
                MessageType::Alliance->value,
                $subject,
                $text,
                $this->state->runtime->timestamp,
            );
        }
    }
}
