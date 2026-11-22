<?php

namespace App\Game\Core\Player;

use App\Game\Enum\MessageType;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;

/**
 * Stores and delivers in-game messages (messages + users_to_messages), forwards messages, pings Discord for
 * admin receivers and triggers the message achievement. Collaborators: Discord, PlayerUtilAchievement,
 * Universe.
 */
class PlayerMessenger
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Forwards an existing message to more users (users_to_messages copies marked forwarded, unread).
     * INSERT IGNORE, so users who already hold a copy are skipped. The forwarder is the current user.
     *
     * @param int|int[] $userId receiver(s)
     * @param int $senderId original sender, 0 = system
     * @param int $messageId
     */
    public function forwardMessage(int|array $userId, int $senderId, int $messageId): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        if (!is_array($userId)) {
            $userId = [$userId];
        }

        $sqlUserMessage = 'INSERT IGNORE INTO %%USERS_TO_MESSAGES%% SET
            `message_id`        = :messageId,
            `user_id`           = :userId,
            `sender_id`         = :senderId,
            `forwarder_id`      = :forwarder_id,
            `message_unread`    = :messageUnread,
            `message_forwarded` = :messageForwarded;';

        foreach ($userId as $receiverId) {
            $db->insert($sqlUserMessage, [
                ':messageId'        => $messageId,
                ':userId'           => $receiverId,
                ':forwarder_id'     => $USER['id'],
                ':senderId'         => $senderId === 0 ? null : $senderId,
                ':messageUnread'    => 1,
                ':messageForwarded' => 1,
            ]);
        }
    }

    /**
     * Stores a message and delivers it to one or more users.
     *
     * Writes one row into messages (owner = first receiver) and a users_to_messages copy per receiver plus a
     * read copy for the sender. Group keys: system/user messages without key get "m_<id>"/"u_<id>", other
     * types get a "m_" prefix on the given key. Side effects: Discord ping for admin receivers of user
     * messages, achievement 36 check per receiver.
     *
     * @param int|int[] $userId receiver(s)
     * @param int $senderId 0 for the system
     * @param string $senderName
     * @param int $messageType MessageType case value
     * @param string $subject
     * @param string $text
     * @param int $time timestamp
     * @param int $unread
     * @param int|null $universe default: current universe
     * @param string|null $groupKey message_group
     */
    public function sendMessage(
        int|array $userId,
        int $senderId,
        string $senderName,
        int $messageType,
        string $subject,
        string $text,
        int $time,
        int $unread = 1,
        ?int $universe = null,
        ?string $groupKey = null,
    ): void {
        if (is_null($universe)) {
            $universe = $this->universe()->current();
        }

        if (!empty($groupKey) && $messageType !== MessageType::User->value && !str_starts_with($groupKey, 'm_')) {
            $groupKey = 'm_' . $groupKey;
        }

        if (!is_array($userId)) {
            $userId = [$userId];
        }

        $db = $this->db;

        $sql = 'INSERT INTO %%MESSAGES%% SET
            `message_group`     = :groupKey,
            `message_owner`     = :userId,
            `message_sender`    = :sender,
            `message_time`      = :time,
            `message_type`      = :type,
            `message_from`      = :from,
            `message_subject`   = :subject,
            `message_text`      = :text,
            `message_unread`    = :unread,
            `message_universe`  = :universe;';

        $db->insert($sql, [
            ':userId'   => $userId[0],
            ':groupKey' => empty($groupKey) ? null : $groupKey,
            ':sender'   => $senderId,
            ':time'     => $time,
            ':type'     => $messageType,
            ':from'     => $senderName,
            ':subject'  => $subject,
            ':text'     => $text,
            ':unread'   => $unread,
            ':universe' => $universe,
        ]);

        $lastMessageId = $db->lastInsertId();
        if (($messageType === MessageType::User->value || $messageType === MessageType::System->value) && empty($groupKey)) {
            if ($messageType == MessageType::User->value) {
                $groupKey = 'u_' . $lastMessageId;
            } else {
                $groupKey = 'm_' . $lastMessageId;
            }
            $sql = 'UPDATE %%MESSAGES%% SET `message_group` = :groupKey WHERE `message_id` = :messageId;';
            $db->update($sql, [
                ':groupKey' => $groupKey,
                ':messageId' => $lastMessageId,
            ]);
        }

        $this->linkMessageToUsers($userId, $lastMessageId, $senderId, $time);

        if ($messageType === MessageType::User->value) {
            $this->notifyAdminsOnDiscord($userId, $universe, $senderId, $senderName, $subject);
        }

        foreach ($userId as $receiverId) {
            $this->playerUtilAchievements()->checkPlayerUtilAchievements36($receiverId, $text);
        }
    }

    /**
     * Creates the per-user copies (users_to_messages) of a message: unread for every receiver and, unless the
     * sender is the system (id 0), a read copy for the sender.
     *
     * @param int[] $receiverIds
     * @param int $messageId
     * @param int $senderId 0 for system messages (stored as NULL)
     * @param int $time creation timestamp
     */
    private function linkMessageToUsers(array $receiverIds, int $messageId, int $senderId, int $time): void
    {
        $db = $this->db;
        $sqlUserMessage = 'INSERT INTO %%USERS_TO_MESSAGES%% SET
            `message_id`     = :messageId,
            `user_id`        = :userId,
            `sender_id`      = :senderId,
            `forwarder_id`   = :forwarder_id,
            `message_unread` = :messageUnread,
            `created_at`     = :created_at;';

        $senderIsReceiver = false;
        foreach (array_unique($receiverIds) as $receiverId) {
            // the sender gets exactly one copy, already read, even when he is among the receivers
            if ($senderId !== 0 && $receiverId == $senderId) {
                $senderIsReceiver = true;
                continue;
            }
            $db->insert($sqlUserMessage, [
                ':messageId'        => $messageId,
                ':userId'           => $receiverId,
                ':forwarder_id'     => null,
                ':senderId'         => $senderId === 0 ? null : $senderId,
                ':messageUnread'    => 1,
                ':created_at'       => $time,
            ]);
        }

        if ($senderId !== 0 || $senderIsReceiver) {
            $db->insert($sqlUserMessage, [
                ':messageId'        => $messageId,
                ':userId'           => $senderId,
                ':forwarder_id'     => null,
                ':senderId'         => $senderId,
                ':messageUnread'    => 0,
                ':created_at'       => $time,
            ]);
        }
    }

    /**
     * Pings the Discord webhook of receivers that are admins (authlevel > 0) and configured one.
     *
     * @param int[] $receiverIds
     * @param int $universe
     * @param int $senderId
     * @param string $senderName
     * @param string $subject
     */
    private function notifyAdminsOnDiscord(array $receiverIds, int $universe, int $senderId, string $senderName, string $subject): void
    {
        $db = $this->db;
        foreach ($receiverIds as $receiverId) {
            $sql = 'SELECT `username`, `discord_id`, `discord_hook` FROM %%USERS%%
            WHERE `universe` = :universe AND `id` = :userID AND `authlevel` > 0;';
            $admin = $db->selectSingle($sql, [
                ':universe' => $universe,
                ':userID' => $receiverId,
            ]);

            if (!empty($admin['discord_hook'])) {
                $title = '';
                if (!empty($admin['discord_id'])) {
                    $title = '<@' . $admin['discord_id'] . '> hat eine neue Ingame-Nachricht erhalten.';
                } else {
                    $title = '<@' . $admin['username'] . '> hat eine neue Ingame-Nachricht erhalten.';
                }

                $content = [
                    'Universum' => $universe,
                    'Absender' => $senderId . ' - ' . $senderName,
                    'Betreff' => $subject,
                ];

                $this->discord()->sendMessage($admin['discord_hook'], $title, $content);
            }
        }
    }
}
