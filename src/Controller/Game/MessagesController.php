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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;
use App\Game\Core\MarketManager;
use App\Game\Messages\MessageComposer;
use App\Game\Messages\MessageListing;
use App\Game\Messages\MessageMailbox;

/**
 * Message center: category list, paged message list (grouped, favourites, search), mark/delete actions, and the write and forward popups.
 */
#[\App\Controller\RequireModule('MODULE_MESSAGES')]
class MessagesController extends AbstractGameController
{
    private ?CacheInterface $spyReportCache = null;

    /** render cache of the spy reports in the message list */
    #[Required]
    public function setSpyReportCache(CacheInterface $cache): void
    {
        $this->spyReportCache = $cache;
    }

    private function mailbox(): MessageMailbox
    {
        return new MessageMailbox($this->db, $this->configs, $this->state);
    }

    private function composer(): MessageComposer
    {
        return new MessageComposer($this->db, $this->configs, $this->state);
    }

    private function sendData($Code, $Message): Response
    {
        return $this->jsonResponse([
            'code' => $Code,
            'mess' => $Message,
        ]);
    }

    /**
     * Toggles the favourite flag of a message (XHR, request: favMessID); answers JSON.
     */
    #[GameRoute('game', 'game/messages/favorite', 'messages', 'favorite', ['POST'])]
    public function favMessage(): Response
    {
        $LNG = $this->state->LNG;

        $this->setWindow('ajax');

        $favMessID = $this->input()->int('favMessID');

        if (empty($favMessID)) {
            return $this->sendData(0, $LNG['error']);
        }

        return $this->sendData($this->mailbox()->toggleFavorite($favMessID), 'OK');
    }

    /**
     * Deletes a message of the player (XHR, request: delMessID); answers JSON.
     */
    #[GameRoute('game', 'game/messages/delete', 'messages', 'delete', ['POST'])]
    public function deleteMessage(): Response
    {
        $LNG = $this->state->LNG;

        $this->setWindow('ajax');

        $delMessID  = $this->input()->int('delMessID');

        if (empty($delMessID)) {
            return $this->sendData(0, $LNG['error']);
        }

        $this->mailbox()->delete($delMessID);

        return $this->sendData($delMessID, $LNG['mg_deleted']);
    }

    /**
     * Bulk action on messages (form post): messcat (category), site (page), messageID[] and action, applied by the mailbox service. Without an action
     * nothing happens; redirects back to the same category page.
     */
    #[GameRoute('game', 'game/messages/action', 'messages', 'action', ['POST'])]
    public function action(): Response
    {
        $MessCategory  	= $this->input()->int('messcat', MessageType::All->value);
        $page		 	= $this->input()->int('site', 1);
        $messageIDs		= $this->input()->intMap('messageID');
        $action	        = $this->input()->string('action');

        if ($action != "") {
            $this->mailbox()->applyAction($action, $MessCategory, $messageIDs);
        }

        return $this->redirectToRoute('game_messages_show', ['category' => $MessCategory, 'site' => $page]);
    }

    /** Receiver search of the write dialog (JSON list of id and username). */
    #[GameRoute('game', 'game/messages/users', 'messages', 'loadUser')]
    public function loadUser(): Response
    {
        $searchtext		= $this->input()->string('searchtext', '', true);

        return $this->jsonResponse($this->mailbox()->searchRecipients($searchtext));
    }

    /**
     * Forwards an own message to other players (XHR, request: to[] receivers, id message); validates ownership and receivers, answers JSON.
     */
    #[GameRoute('game', 'game/messages/forward', 'messages', 'sendForward', ['POST'])]
    public function sendForward(): Response
    {
        $LNG = $this->state->LNG;
        $receiver   = $this->input()->stringList('to');
        $messageID  = $this->input()->int('id');
        $composer = $this->composer();

        if (empty($receiver) || empty($messageID)) {
            return $this->jsonResponse($LNG['mg_error']);
        }

        if ($composer->tooManyRecipients($receiver)) {
            return $this->jsonResponse(sprintf($LNG['mg_too_many_recipients'], MessageComposer::MAX_RECIPIENTS));
        }

        if (!$composer->ownsMessage($messageID)) {
            return $this->jsonResponse($LNG['mg_error']);
        }

        if (!$this->rateLimits->allowPrivateMessage((int) $this->state->USER['id'])) {
            return $this->jsonResponse($LNG['mg_rate_limited']);
        }

        $receiverList = $composer->deliverableReceivers($receiver);

        if (empty($receiverList)) {
            return $this->jsonResponse($LNG['mg_error']);
        }

        $composer->forward($receiverList, $messageID);

        return $this->jsonResponse($LNG['mg_message_send']);
    }

    /**
     * Sends a new message (XHR, request: to[] receivers, subject, text, groupid). Needs receivers and text; receivers who block messages are dropped (all
     * blocked: error). Answers JSON with the result text.
     */
    #[GameRoute('game', 'game/messages/send', 'messages', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;
        $receiver   = $this->input()->stringList('to');
        $subject    = $this->input()->string('subject', $LNG['mg_no_subject'], true);
        $text       = $this->input()->string('text', '', true);
        $groupKey   = $this->input()->string('groupid', "", true);

        // the subject field allows 40 typed characters: cut the decoded text, so no entity is split and the column (255) holds it
        $subject    = htmlspecialchars(mb_substr(html_entity_decode((string) $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 40), ENT_QUOTES, 'UTF-8');
        $text		= makebr($text);

        if (empty($receiver) || empty($text)) {
            return $this->jsonResponse($LNG['mg_error']);
        }

        $composer = $this->composer();
        if ($composer->tooManyRecipients($receiver)) {
            return $this->jsonResponse(sprintf($LNG['mg_too_many_recipients'], MessageComposer::MAX_RECIPIENTS));
        }
        if ($composer->textTooLong((string) $text)) {
            return $this->jsonResponse(sprintf($LNG['mg_text_too_long'], MessageComposer::MAX_TEXT_LENGTH));
        }
        if (!$this->rateLimits->allowPrivateMessage((int) $this->state->USER['id'])) {
            return $this->jsonResponse($LNG['mg_rate_limited']);
        }

        $receiverList = $composer->deliverableReceivers($receiver);

        if (empty($receiverList)) {
            return $this->jsonResponse($LNG['mg_blocked']);
        }

        $composer->send($receiverList, $receiver, $subject, $text, $groupKey);

        $skipped = $composer->skippedNames($receiver, $receiverList);
        if ($skipped !== []) {
            return $this->jsonResponse($LNG['mg_message_send'] . ' ' . sprintf($LNG['mg_not_delivered'], implode(', ', $skipped)));
        }

        return $this->jsonResponse($LNG['mg_message_send']);
    }

    /**
     * Compose form prefilled with the text of message `id` (to forward it).
     */
    #[GameRoute('game', 'game/messages/forward', 'messages', 'forward')]
    public function forward(): Response
    {
        $this->setWindow('popup');

        $messageID  = $this->input()->int('id');

        $this->addScript('message.js');

        return $this->render('game/page.messages.write.html.twig', [
            'messageID' => $messageID,
            'action' => 'forward',
        ]);
    }

    /**
     * Compose popup. Request: receivers[] (player ids), subject, message, groupid. Looks up the receivers' home coordinates and names and whether they block PMs.
     */
    #[GameRoute('game', 'game/messages/write', 'messages', 'write')]
    public function write(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $this->setWindow('popup');

        $db = $this->db;

        $receivers      = $this->input()->stringList('receivers', true);
        $Subject        = $this->input()->string('subject', $LNG['mg_no_subject'], true);
        $Message        = $this->input()->string('message', "", true);
        $messageGroup   = $this->input()->string('groupid', "", true);

        $receiverIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $receivers)))), 0, \App\Game\Messages\MessageComposer::MAX_RECIPIENTS);

        $receiverList = [];
        if ($receiverIds !== []) {
            $sql = "SELECT p.`galaxy`, p.`system`, p.`planet`, u.`id`, u.`username`, u.`id_planet`, us.`settings_blockPM`
                FROM %%USERS%% as u
                INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id`
                INNER JOIN %%PLANETS%% as p ON p.`id` = u.`id_planet`
                WHERE u.`id` IN (" . implode(',', $receiverIds) . ") AND u.`universe` = :universe;";

            foreach ($db->select($sql, [':universe' => $this->universe()->current()]) as $receiverRecord) {
                $receiverList[$receiverRecord['id']] = $receiverRecord;
            }
        }

        $this->addScript('message.js');

        return $this->render('game/page.messages.write.html.twig', [
            'subject'       => $Subject,
            'receiverList'  => $receiverList,
            'message'       => $Message,
            'messageGroup'  => $messageGroup,
            'action' => 'write',
        ]);
    }

    /**
     * Message center. Request: category (-1 = category overview), site (page), fav (1 = favourites only), searchtext. The list comes from MessageListing;
     * the grouping (cookie msgGrouping), the player's simulator display settings and the market reference ratios are passed to the template.
     */
    #[GameRoute('game', 'game/messages', 'messages')]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $MessCategory = $this->input()->int('category', -1);
        $page = $this->input()->int('site', 1);
        $fav = $this->input()->int('fav');
        $searchtext = $this->input()->string('searchtext', '', true);

        $grp = isset($this->state->requestCookies['msgGrouping']) ? (int) $this->state->requestCookies['msgGrouping'] : 0;

        $fleetIntoDebris = $this->configs->get()->fleet_debris_percentage;
        $defIntoDebris = $this->configs->get()->def_debris_percentage;
        $uniType = $this->configs->get()->uni_type;
        $maxGalaxy = $this->configs->get()->max_galaxy;

        $stbSettings2 = $this->playerLookup()->player_stb_settings($USER);

        $combustion_tech = $USER["combustion_tech"];
        $impulse_motor_tech = $USER["impulse_motor_tech"];

        $list = (new MessageListing($this->db, $this->configs, $this->state, $this->spyReportCache))->listPage($MessCategory, $page, $fav, $searchtext, $grp);

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $refrates = $pMarket->getReferenceRatios();
        $this->addScript('message.js');

        return $this->render('game/page.messages.default.html.twig', [
            'MessID'		                => $MessCategory,
            'MessageList'	                => $list['MessageList'],
            'CategoryList'	                => $list['CategoryList'],
            'page'			                => $list['page'],
            'maxPage'		                => $list['maxPage'],
            'fav'			                => $fav,
            'grp'			                => $grp,
            'searchtext'	                => $searchtext,
            'stb_big_time'                  => $stbSettings2['stb_big_time'],
            'stb_med_time'                  => $stbSettings2['stb_med_time'],
            'stb_small_time'                => $stbSettings2['stb_small_time'],
            'stb_big_ress'                  => $stbSettings2['stb_big_ress'],
            'stb_med_ress'                  => $stbSettings2['stb_med_ress'],
            'stb_small_ress'                => $stbSettings2['stb_small_ress'],
            'refratesMetal'                 => $refrates['metal'],
            'refratesCrystal'               => $refrates['crystal'],
            'refratesDeuterium'             => $refrates['deuterium'],
            'impulse_motor_tech'            => $impulse_motor_tech,
            'combustion_tech'               => $combustion_tech,
            'fleetIntoDebris'               => $fleetIntoDebris,
            'defIntoDebris'                 => $defIntoDebris,
            'uniType'                       => $uniType,
            'maxGalaxy'                     => $maxGalaxy,
            'spyReportsCollapsed'           => $USER['spyReportsCollapsed'],
        ]);
    }
}
