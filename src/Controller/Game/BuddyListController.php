<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Routing\GameRoute;
use App\Game\Enum\MessageType;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Language;

/**
 * Buddy list: friends, friend requests (send, accept, reject, delete).
 */
#[\App\Controller\RequireModule('MODULE_BUDDYLIST')]
class BuddyListController extends AbstractGameController
{
    private const MAX_OPEN_REQUESTS = 10;
    private const MAX_REQUESTS_PER_WINDOW = 5;
    private const REQUEST_WINDOW = 600;

    /**
     * Request form for a buddy (request: id of the player); refuses yourself, existing buddies and existing requests.
     */
    #[GameRoute('game', 'game/buddies/request', 'buddyList', 'request')]
    public function request(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $this->setWindow('popup');

        $id	= $this->input()->int('id');

        if ($id == $USER['id']) {
            return $this->messageResponse($LNG['bu_cannot_request_yourself']);
        }

        $db = $this->db;

        $sql = "SELECT COUNT(*) as count FROM %%BUDDY%% WHERE (sender = :userID AND owner = :friendID) OR (owner = :userID AND sender = :friendID);";
        $exists = $db->selectSingle($sql, [
            ':userID'	=> $USER['id'],
            ':friendID'  => $id,
        ], 'count');

        if ($exists != 0) {
            return $this->messageResponse($LNG['bu_request_exists']);
        }

        $sql = "SELECT username, galaxy, system, planet FROM %%USERS%% WHERE id = :friendID AND universe = :universe;";
        $userData = $db->selectSingle($sql, [
            ':friendID'  => $id,
            ':universe'  => $this->universe()->current(),
        ]);

        if (empty($userData)) {
            return $this->messageResponse($LNG['bu_player_not_exist']);
        }

        return $this->render('game/page.buddyList.request.html.twig', [
            'username'	=> $userData['username'],
            'galaxy'	=> $userData['galaxy'],
            'system'	=> $userData['system'],
            'planet'	=> $userData['planet'],
            'id'		=> $id,
        ]);
    }

    /**
     * Spam guard of the requests: at most MAX_OPEN_REQUESTS unanswered requests and MAX_REQUESTS_PER_WINDOW new ones per
     * REQUEST_WINDOW seconds (kept in the session), so withdrawing and re-sending cannot flood a player with messages.
     * Counts the attempt when it is allowed.
     */
    private function allowRequest(): bool
    {
        $sql = "SELECT COUNT(*) as count FROM %%BUDDY%% b INNER JOIN %%BUDDY_REQUEST%% r ON r.id = b.id WHERE b.sender = :userID;";
        $open = $this->db->selectSingle($sql, [':userID' => $this->state->USER['id']], 'count');
        if ($open >= self::MAX_OPEN_REQUESTS) {
            return false;
        }

        $session = $this->sessions()->symfonySession();
        $now = $this->state->runtime->timestamp;
        $times = array_values(array_filter(
            (array) $session->get('buddy_request_times', []),
            static fn($time): bool => is_int($time) && $time > $now - self::REQUEST_WINDOW,
        ));
        if (count($times) >= self::MAX_REQUESTS_PER_WINDOW) {
            return false;
        }

        $times[] = $now;
        $session->set('buddy_request_times', $times);

        return true;
    }

    /**
     * Stores a buddy request (request: id, text): refused for yourself, a player who blocks direct messages (either direction), an unknown player or an existing
     * request. Inserts BUDDY and BUDDY_REQUEST (the text) and sends the target a system message in his own language.
     */
    #[GameRoute('game', 'game/buddies/send', 'buddyList', 'send', ['POST'])]
    public function send(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $this->setWindow('popup');
        // the result message closes its dialog after two seconds (data-auto-close, scripts/app/actions.js)
        $this->assign(['autoCloseMs' => 2000]);

        $id		= $this->input()->int('id');
        $text	= $this->input()->string('text', '', UTF8_SUPPORT);

        if ($id == $USER['id']) {
            return $this->messageResponse($LNG['bu_cannot_request_yourself']);
        }
        $blocked    = $this->playerLookup()->get_block_status($USER['id'], $id);
        $blockedInverted = $this->playerLookup()->get_block_status($id, $USER['id']);
        if ($blocked['block_dm'] == 1 || $blockedInverted['block_dm'] == 1) {
            return $this->messageResponse($LNG['mg_blocked']);
        }
        $db = $this->db;

        $sql = "SELECT COUNT(*) as count FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
            WHERE u.id = :friendID AND u.universe = :universe AND us.settings_blockPM = 0;";
        $targetExists = $db->selectSingle($sql, [
            ':friendID'  => $id,
            ':universe'  => $this->universe()->current(),
        ], 'count');

        if (!$targetExists) {
            // unknown player, or one who switched private messages (and with them requests) off
            return $this->messageResponse($LNG['bu_player_not_exist']);
        }

        if (!$this->allowRequest()) {
            return $this->messageResponse($LNG['bu_request_limit']);
        }

        $sql = "SELECT COUNT(*) as count FROM %%BUDDY%% WHERE (sender = :userID AND owner = :friendID) OR (owner = :userID AND sender = :friendID);";
        $exists = $db->selectSingle($sql, [
            ':userID'	=> $USER['id'],
            ':friendID'  => $id,
        ], 'count');

        if ($exists != 0) {
            return $this->messageResponse($LNG['bu_request_exists']);
        }

        $sql = "INSERT INTO %%BUDDY%% SET sender = :userID,	owner = :friendID, universe = :universe;";
        $db->insert($sql, [
            ':userID'	=> $USER['id'],
            ':friendID'  => $id,
            ':universe' => $this->universe()->current(),
        ]);

        $buddyID = $db->lastInsertId();

        $sql = "INSERT INTO %%BUDDY_REQUEST%% SET id = :buddyID, text = :text;";
        $db->insert($sql, [
            ':buddyID'  => $buddyID,
            ':text' => $text,
        ]);

        $sql = "SELECT u.username, us.lang  FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id WHERE u.id = :friendID;";
        $row = $db->selectSingle($sql, [
            ':friendID'  => $id,
        ]);

        $Friend_LNG = $LNG;

        if ($USER['lang'] != $row['lang']) {
            $Friend_LNG = new Language($this->configs, $row['lang']);
            $Friend_LNG->includeData(['INGAME']);
        }

        $this->playerMessenger()->sendMessage($id, $USER['id'], $USER['username'], MessageType::System->value, $Friend_LNG['bu_new_request_title'], $Friend_LNG->formatHtml('bu_new_request_body', $row['username'], $USER['username']), $this->state->runtime->timestamp);

        return $this->messageResponse($LNG['bu_request_send']);
    }

    /**
     * Removes a buddy or withdraws/rejects a pending request (request: id of the BUDDY row; only its two parties may). For a pending request the other
     * player gets a system message. Deletes the BUDDY and BUDDY_REQUEST rows; redirects to the list.
     */
    #[GameRoute('game', 'game/buddies/{id}/delete', 'buddyList', 'delete', ['POST'], ['id' => '\d+'])]
    public function delete(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $id	= $this->input()->int('id');
        $db = $this->db;

        $sql = "SELECT COUNT(*) as count FROM %%BUDDY%% WHERE id = :id AND (sender = :userID OR owner = :userID);";
        $isAllowed = $db->selectSingle($sql, [
            ':id'  => $id,
            ':userID' => $USER['id'],
        ], 'count');

        if ($isAllowed) {
            $sql = "SELECT COUNT(*) as count FROM %%BUDDY_REQUEST%% WHERE id = :id;";
            $isRequest = $db->selectSingle($sql, [
                ':id'  => $id,
            ], 'count');

            // only the recipient rejecting a request is told; the sender withdrawing his own request sends nothing
            $sql = "SELECT COUNT(*) as count FROM %%BUDDY%% WHERE id = :id AND owner = :userID;";
            $isRecipient = $db->selectSingle($sql, [':id' => $id, ':userID' => $USER['id']], 'count');

            if ($isRequest && $isRecipient) {
                $sql = "SELECT u.username, u.id, us.lang FROM %%BUDDY%% b INNER JOIN %%USERS%% u ON u.id = IF(b.sender = :userID,b.owner,b.sender) INNER JOIN  %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE b.id = :id;";
                $requestData = $db->selectSingle($sql, [
                    ':id'       => $id,
                    'userID'    => $USER['id'],
                ]);

                $Enemy_LNG = $LNG;

                if ($USER['lang'] != $requestData['lang']) {
                    $Enemy_LNG = new Language($this->configs, $requestData['lang']);
                    $Enemy_LNG->includeData(['INGAME']);
                }

                $this->playerMessenger()->sendMessage($requestData['id'], $USER['id'], $USER['username'], MessageType::System->value, $Enemy_LNG['bu_rejected_request_title'], $Enemy_LNG->formatHtml('bu_rejected_request_body', $requestData['username'], $USER['username']), $this->state->runtime->timestamp);
            }

            $sql = "DELETE b.*, r.* FROM %%BUDDY%% b LEFT JOIN %%BUDDY_REQUEST%% r USING (id) WHERE b.id = :id;";
            $db->delete($sql, [
                ':id'       => $id,
            ]);
        }
        return $this->redirectToRoute('game_buddy_list_show');
    }

    /**
     * Accepts a pending request (request: id of the BUDDY row; only its recipient may): deletes the BUDDY_REQUEST row, which turns the BUDDY row into a
     * friendship, and sends the requester a system message; redirects to the list.
     */
    #[GameRoute('game', 'game/buddies/{id}/accept', 'buddyList', 'accept', ['POST'], ['id' => '\d+'])]
    public function accept(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $id	= $this->input()->int('id');
        $db = $this->db;

        // Only the recipient of a still pending request may accept it
        $sql = "SELECT COUNT(*) as count FROM %%BUDDY%% b INNER JOIN %%BUDDY_REQUEST%% r ON r.id = b.id"
            . " WHERE b.id = :id AND b.owner = :userID;";
        $isAllowed = $db->selectSingle($sql, [
            ':id'       => $id,
            ':userID'   => $USER['id'],
        ], 'count');

        if (!$isAllowed) {
            return $this->redirectToRoute('game_buddy_list_show');
        }

        $sql = "DELETE FROM %%BUDDY_REQUEST%% WHERE id = :id;";
        $db->delete($sql, [
            ':id'       => $id,
        ]);

        $sql = "SELECT sender, u.username, us.lang FROM %%BUDDY%% b INNER JOIN %%USERS%% u ON sender = u.id INNER JOIN  %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE b.id = :id;";
        $sender = $db->selectSingle($sql, [
            ':id'       => $id,
        ]);

        $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkBuddyAchievements((int) $USER['id']));
        $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkBuddyAchievements((int) $sender['sender']));

        $Friend_LNG = $LNG;

        if ($USER['lang'] != $sender['lang']) {
            $Friend_LNG = new Language($this->configs, $sender['lang']);
            $Friend_LNG->includeData(['INGAME']);
        }

        $this->playerMessenger()->sendMessage($sender['sender'], $USER['id'], $USER['username'], MessageType::System->value, $Friend_LNG['bu_accepted_request_title'], $Friend_LNG->formatHtml('bu_accepted_request_body', $sender['username'], $USER['username']), $this->state->runtime->timestamp);

        return $this->redirectToRoute('game_buddy_list_show');
    }

    /**
     * Buddy list: friends with online state and alliance, incoming and outgoing requests.
     */
    #[GameRoute('game', 'game/buddies', 'buddyList')]
    public function show(): Response
    {
        $USER = $this->state->USER;

        $db = $this->db;
        $sql = "SELECT a.sender, a.id as buddyid, b.id, b.username, b.onlinetime, b.galaxy, b.system, b.planet, b.ally_id, c.ally_name, d.text
		FROM (%%BUDDY%% as a, %%USERS%% as b) LEFT JOIN %%ALLIANCE%% as c ON c.id = b.ally_id LEFT JOIN %%BUDDY_REQUEST%% as d ON a.id = d.id
		WHERE (a.sender = :userID AND a.owner = b.id) OR (a.owner = :userID AND a.sender = b.id);";
        $BuddyListResult = $db->select($sql, [
            'userID'    => $USER['id'],
        ]);

        $myRequestList		= [];
        $otherRequestList	= [];
        $myBuddyList		= [];

        foreach ($BuddyListResult as $BuddyList) {
            if (isset($BuddyList['text'])) {
                if ($BuddyList['sender'] == $USER['id']) {
                    $myRequestList[$BuddyList['buddyid']]		= $BuddyList;
                } else {
                    $otherRequestList[$BuddyList['buddyid']]	= $BuddyList;
                }
            } else {
                $BuddyList['onlinetime']			= floor(($this->state->runtime->timestamp - $BuddyList['onlinetime']) / 60);
                $myBuddyList[$BuddyList['buddyid']]	= $BuddyList;
            }
        }

        return $this->render('game/page.buddyList.default.html.twig', [
            'myBuddyList'		=> $myBuddyList,
            'myRequestList'			=> $myRequestList,
            'otherRequestList'	=> $otherRequestList,
        ]);
    }
}
