<?php

namespace App\Game\Messages;

use App\Game\Enum\AuthLevel;
use App\Game\Enum\MessageType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Sending and forwarding private messages: which recipients may receive (exist in the universe, accept private
 * messages, no direct-message block in either direction), the forbidden-content check with the automatic account ban,
 * and the actual delivery through PlayerMessenger.
 */
class MessageComposer
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** Most recipients of one private message or forward (rejected before any query). */
    public const MAX_RECIPIENTS = 10;

    /** Longest private message text in characters (the limit the compose form shows). */
    public const MAX_TEXT_LENGTH = 5000;

    /**
     * The distinct valid user ids of the request, in request order (nested arrays, non-numeric and non-positive entries dropped).
     *
     * @return list<int>
     */
    public function requestedIds(mixed $receiver): array
    {
        $ids = [];
        foreach ((array) $receiver as $receiverID) {
            if (!is_int($receiverID) && !(is_string($receiverID) && ctype_digit(trim($receiverID)))) {
                continue;
            }
            $id = (int) $receiverID;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** Whether more recipients than allowed were requested (counted before any lookup). */
    public function tooManyRecipients(mixed $receiver): bool
    {
        return count($this->requestedIds($receiver)) > self::MAX_RECIPIENTS;
    }

    /** Whether the decoded message text exceeds the allowed length. */
    public function textTooLong(string $text): bool
    {
        return mb_strlen(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) > self::MAX_TEXT_LENGTH;
    }

    /**
     * Filters the requested recipients to those the current player may write to, with one query for all of them.
     *
     * @param mixed $receiver requested user ids (array or single id)
     * @return list<int> ids that exist in the universe, accept private messages and are not blocked in either direction
     */
    public function deliverableReceivers(mixed $receiver): array
    {
        $ids = $this->requestedIds($receiver);
        if ($ids === [] || count($ids) > self::MAX_RECIPIENTS) {
            return [];
        }

        $params = [
            ':universe' => $this->universe()->current(),
            ':sender'   => $this->state->USER['id'],
        ];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params[':id' . $index] = $id;
        }

        $sql = 'SELECT u.`id` FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id`
            WHERE u.`id` IN (' . implode(', ', $placeholders) . ') AND u.`universe` = :universe AND us.`settings_blockPM` = 0
              AND NOT EXISTS (
                SELECT 1 FROM %%USERS_BLOCKLIST%% b
                WHERE b.`block_dm` = 1
                  AND ((b.`blocked_user` = :sender AND b.`blocking_user` = u.`id`)
                    OR (b.`blocked_user` = u.`id` AND b.`blocking_user` = :sender))
              );';

        $valid = [];
        foreach ($this->db->select($sql, $params) as $row) {
            $valid[(int) $row['id']] = true;
        }

        return array_values(array_filter($ids, static fn(int $id): bool => isset($valid[$id])));
    }

    /**
     * Names of the requested recipients that were not delivered to (they block messages or do not exist).
     *
     * @param list<int> $delivered ids from deliverableReceivers()
     * @return list<string> usernames (decoded ids without a name are left out)
     */
    public function skippedNames(mixed $receiver, array $delivered): array
    {
        $skipped = array_values(array_diff($this->requestedIds($receiver), $delivered));
        if ($skipped === []) {
            return [];
        }

        $params = [':universe' => $this->universe()->current()];
        $placeholders = [];
        foreach ($skipped as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params[':id' . $index] = $id;
        }
        $sql = 'SELECT `username` FROM %%USERS%% WHERE `id` IN (' . implode(', ', $placeholders) . ') AND `universe` = :universe;';

        return array_column($this->db->select($sql, $params), 'username');
    }

    /** Whether the message is in the current player's (not deleted) mailbox, i.e. may be forwarded by them. */
    public function ownsMessage(mixed $messageID): bool
    {
        $USER = $this->state->USER;
        $sql = 'SELECT COUNT(*) as state FROM %%USERS_TO_MESSAGES%%
            WHERE `user_id` = :userId
              AND `message_id` = :messageId
              AND `deleted_at` IS NULL;';
        $ownsMessage = $this->db->selectSingle($sql, [
            ':userId'    => $USER['id'],
            ':messageId' => $messageID,
        ], 'state');

        return !empty($ownsMessage);
    }

    /**
     * Forwards one of the player's messages to the receivers (see PlayerMessenger::forwardMessage).
     *
     * @param array<mixed> $receiverList
     */
    public function forward(array $receiverList, mixed $messageID): void
    {
        $USER = $this->state->USER;
        $this->playerMessenger()->forwardMessage($receiverList, $USER['id'], $messageID);
    }

    /**
     * Delivers a private message from the current player, unless its text or subject matches a forbidden pattern
     * (table messages_forbidden, applied to normal players only): then nothing is sent, the account is banned
     * ("Forbidden message content") and a Discord notice goes to the tickets hook.
     *
     * @param list<int> $receiverList deliverable recipient ids
     * @param mixed            $requested    the recipients as requested (only the last one is named in the Discord notice)
     * @return bool true when the message was blocked and the sender banned
     */
    public function send(array $receiverList, mixed $requested, string $subject, string $text, string $groupKey): bool
    {
        $USER = $this->state->USER;
        $senderName	= $USER['username'] . ' [' . $USER['galaxy'] . ':' . $USER['system'] . ':' . $USER['planet'] . ']';
        $db = $this->db;
        $config = $this->configs->get();
        $sql = "SELECT `text` FROM %%MESSAGES_FORBIDDEN%%";
        $texts = $db->select($sql);
        $banned = false;
        if (!empty($texts) && $USER['authlevel'] == AuthLevel::User->value) {
            // the last requested recipient, as the loop variable of the former inline code
            $requestedList = (array) $requested;
            $receiverID = end($requestedList);
            foreach ($texts as $textRow) {
                $regex = '/' . $textRow['text'] . '/i';
                if (preg_match($regex, $text) || preg_match($regex, $subject)) {
                    $banned = true;
                    $accountId = $this->playerLookup()->get_account_id($USER['id']);
                    $this->accountUtil()->banAccount($accountId, 'Forbidden message content');
                    $this->discord()->sendMessage($config->discord_tickets_hook, 'Automatic user message bann', [
                        'AccountID' => $accountId,
                        'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                        'Forbidden Text' => $textRow['text'],
                        'Subject' => $subject,
                        'Message' => $text,
                        'ReceiverID' => $receiverID,
                    ]);
                    break;
                }
            }
        }

        if (!$banned) {
            $this->playerMessenger()->sendMessage($receiverList, $USER['id'], $senderName, MessageType::User->value, $subject, $text, $this->state->runtime->timestamp, 1, null, $groupKey);
        }

        return $banned;
    }
}
