<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Game\Alliance\AllianceException;
use App\Game\Alliance\AllianceRights;
use App\Game\Alliance\AllianceServices;
use App\Game\Alliance\AllianceText;
use App\Game\Core\EndOfRequest;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alliance pages: overview, application, creation, member list, circular messages and the admin area (texts, ranks, members, diplomacy).
 *
 * Paths: /game/alliance (home), /game/alliance/{id} (info), .../apply/{id}, .../search, .../create, .../members, .../circular,
 * and the admin area below /game/alliance/admin (applications, ranks, members, diplomacy). Every state change is a POST route.
 *
 * HTTP adapter only: reads the request, checks the rank rights of the page and renders; the rules and queries live
 * in the services of App\Game\Alliance (see AllianceServices). The services end an action early by throwing an
 * AllianceException (route names as targets), which exitWith() turns into the redirect / message page / JSON answer.
 */
#[\App\Controller\RequireModule('MODULE_ALLIANCE')]
class AllianceController extends AbstractGameController
{
    private $allianceData;
    /** @var array<mixed> */
    private array $rights = [];
    private $hasAlliance = false;
    private $hasApply = false;
    private ?AllianceServices $allianceServices = null;
    public $availableRanks = AllianceRights::AVAILABLE;

    /**
     * Loads the alliance state of the player: rights, whether he is in an alliance, has an open application, and the alliance data.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->rights = AllianceRights::all(false);
        $this->hasAlliance = $this->state->USER['ally_id'] != 0;
        $this->hasApply = $this->alliance()->repository()->hasOpenApplication($this->state->USER['id']);
        if ($this->hasAlliance && !$this->hasApply) {
            if ($failure = $this->setAllianceData($this->state->USER['ally_id'])) {
                throw EndOfRequest::withResponse($failure);
            }
        }
    }

    private function alliance(): AllianceServices
    {
        return $this->allianceServices ??= new AllianceServices($this->db, $this->configs, $this->state);
    }

    /** The answer the thrown alliance exception asks for (the route names it carries become URLs). */
    private function exitWith(AllianceException $exit): Response
    {
        return match ($exit->kind) {
            AllianceException::HOME => $this->redirectToHome(),
            AllianceException::REDIRECT => $this->redirectToRoute($exit->payload),
            AllianceException::MESSAGE => $this->messageResponse($exit->payload, array_map(
                fn(array $button): array => ['url' => $this->router->generate($button['url'])] + $button,
                $exit->buttons,
            )),
            AllianceException::JSON => $this->jsonResponse($exit->payload),
        };
    }

    /**
     * Loads the alliance shown by this request into $this->allianceData (message page if it doesn't exist) and,
     * when it's the player's own alliance, the player's rank rights; assigns `rights` and `AllianceOwner` for the templates.
     */
    private function setAllianceData(int $allianceId): ?Response
    {
        $USER = $this->state->USER;

        $this->allianceData = $this->alliance()->repository()->find($allianceId);

        if (!$this->allianceData) {
            return $this->messageResponse($this->state->LNG['al_not_exists']);
        }

        if ($USER['ally_id'] == $allianceId) {
            $this->rights = $this->alliance()->repository()->rightsOf($USER, $this->allianceData);

            $this->assign([
                'rights' => $this->rights,
                'AllianceOwner' => $this->allianceData['ally_owner'] == $USER['id'],
            ]);
        }

        return null;
    }

    /**
     * Public info page of an alliance (path: id).
     */
    #[GameRoute('game', 'game/alliance/{id}', 'alliance', 'info', requirements: ['id' => '\d+'])]
    public function info(): Response
    {
        if ($failure = $this->setAllianceData($this->input()->int('id'))) {
            return $failure;
        }

        return $this->render('game/page.alliance.info.html.twig', $this->alliance()->pages()->info($this->allianceData, $this->hasAlliance, $this->hasApply));
    }

    /**
     * Alliance entry page: members see their alliance home, players with an open application a wait screen, everybody else the create/search choice.
     */
    #[GameRoute('game', 'game/alliance', 'alliance')]
    public function show(): Response
    {
        if ($this->hasAlliance) {
            return $this->homeAlliance();
        } elseif ($this->hasApply) {
            return $this->applyWaitScreen();
        } else {
            return $this->render('game/page.alliance.createSelection.html.twig');
        }
    }

    private function redirectToHome(): Response
    {
        return $this->redirectToRoute('game_alliance_show');
    }

    /**
     * Page shown while the player's application is pending: the waiting text with a link to the alliance's info page
     * (tag and name escaped; a missing tag shows as 0 like before).
     */
    private function applyWaitScreen(): Response
    {
        $allianceResult = $this->alliance()->applications()->pendingTarget();

        if (empty($allianceResult['ally_tag'])) {
            $allianceResult['ally_tag'] = 0;
        }

        $link = new \Twig\Markup(
            '<a href="' . $this->router->generate('game_alliance_info', ['id' => (int) $allianceResult['id']]) . '">['
            . \App\Twig\GameEscaping::escape($allianceResult['ally_tag']) . '] ' . \App\Twig\GameEscaping::escape($allianceResult['ally_name']) . '</a>',
            'UTF-8',
        );

        return $this->render('game/page.alliance.applyWait.html.twig', [
            'request_text' => AllianceText::html($this->state->LNG, 'al_request_wait_message', $link),
        ]);
    }

    /**
     * Alliance search (request: searchtext); players with an open application are sent back to the wait screen.
     */
    #[GameRoute('game', 'game/alliance/search', 'alliance', 'search')]
    public function search(): Response
    {
        if ($this->hasApply) {
            return $this->redirectToHome();
        }

        $searchText = $this->input()->string('searchtext', '', UTF8_SUPPORT);

        return $this->render('game/page.alliance.search.html.twig', [
            'searchText' => $searchText,
            'searchList' => $this->alliance()->applications()->search($searchText),
        ]);
    }

    /**
     * Application form for alliance `id`. Players who are in an alliance or already applied are redirected home.
     */
    #[GameRoute('game', 'game/alliance/apply/{id}', 'alliance', 'apply', requirements: ['id' => '\d+'])]
    public function apply(): Response
    {
        if ($this->hasApply || $this->state->USER['ally_id'] != 0) {
            return $this->redirectToHome();
        }

        return $this->applicationForm($this->input()->int('id'));
    }

    /**
     * Submits the application (request: text, path: id): stored through the application service, then a confirmation message.
     * An empty text shows the form again.
     */
    #[GameRoute('game', 'game/alliance/apply/{id}', 'alliance', 'applySend', ['POST'], ['id' => '\d+'])]
    public function applySend(): Response
    {
        $LNG = $this->state->LNG;
        if ($this->hasApply || $this->state->USER['ally_id'] != 0) {
            return $this->redirectToHome();
        }

        $text = $this->input()->string('text', '', true);
        $allianceId = $this->input()->int('id');
        if (empty($text)) {
            return $this->applicationForm($allianceId);
        }

        try {
            $applications = $this->alliance()->applications();
            $applications->applicationTarget($allianceId);

            if (!$this->rateLimits->allowAllianceApplication((int) $this->state->USER['id'])) {
                throw AllianceException::message($LNG['al_apply_rate_limited'], $LNG['sys_forward'], 'game_alliance_show');
            }
            $applications->submit($allianceId, $text);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->messageResponse(AllianceText::html($LNG, 'al_request_confirmation_message'), [[
            'label' => $LNG['al_ok'],
            'url' => $this->router->generate('game_overview_show'),
        ]]);
    }

    /** the application form of alliance `$allianceId` (message page when the alliance does not take applications) */
    private function applicationForm(int $allianceId): Response
    {
        $LNG = $this->state->LNG;

        try {
            $allianceResult = $this->alliance()->applications()->applicationTarget($allianceId);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->render('game/page.alliance.apply.html.twig', [
            'allyid' => $allianceId,
            'applytext' => $allianceResult['ally_request'],
            'al_write_request' => AllianceText::html($LNG, 'al_write_request', $allianceResult['ally_tag']),
        ]);
    }

    /**
     * Withdraws the player's open application.
     */
    #[GameRoute('game', 'game/alliance/cancel-apply', 'alliance', 'cancelApply', ['POST'])]
    public function cancelApply(): Response
    {
        $LNG = $this->state->LNG;
        if (!$this->hasApply) {
            return $this->redirectToHome();
        }

        $allyTag = $this->alliance()->applications()->cancel();

        return $this->messageResponse(AllianceText::html($LNG, 'al_request_deleted', $allyTag), [[
            'label' => $LNG['sys_forward'],
            'url' => $this->router->generate('game_alliance_show'),
        ]]);
    }

    /**
     * Founding form. Players who may not found (already in one, ...) get the reason as message.
     */
    #[GameRoute('game', 'game/alliance/create', 'alliance', 'create')]
    public function create(): Response
    {
        if ($failure = $this->mayFound()) {
            return $failure;
        }

        return $this->render('game/page.alliance.create.html.twig');
    }

    /**
     * Founds the alliance (request: atag, aname; AllianceMembership::found), the player becomes founder, then a message is shown.
     */
    #[GameRoute('game', 'game/alliance/create', 'alliance', 'createSend', ['POST'])]
    public function createSend(): Response
    {
        if ($failure = $this->mayFound()) {
            return $failure;
        }

        $LNG = $this->state->LNG;
        $allianceTag = $this->input()->string('atag', '', UTF8_SUPPORT);
        $allianceName = $this->input()->string('aname', '', UTF8_SUPPORT);

        try {
            $label = $this->alliance()->membership()->found($allianceTag, $allianceName);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->messageResponse(AllianceText::html($LNG, 'al_created', $label), [[
            'label' => $LNG['sys_forward'],
            'url' => $this->router->generate('game_alliance_show'),
        ]]);
    }

    /** the answer when the player may not found an alliance (open application, already a member, ...), null when he may */
    private function mayFound(): ?Response
    {
        if ($this->hasApply) {
            return $this->redirectToHome();
        }

        try {
            $this->alliance()->membership()->assertMayFound();
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return null;
    }

    private function homeAlliance(): Response
    {
        // names and online state of the members only with the rights of the member list (same gate as there)
        $activity = $this->rights['ONLINESTATE'] && $this->rights['MEMBERLIST']
            ? $this->alliance()->memberLists()->activitySummary($this->allianceData)
            : null;

        return $this->render('game/page.alliance.home.html.twig', $this->alliance()->pages()->home($this->allianceData) + ['activity' => $activity]);
    }

    /**
     * Member list of the own alliance with the main/wing alliances; needs the MEMBERLIST right.
     */
    #[GameRoute('game', 'game/alliance/members', 'alliance', 'memberList')]
    public function memberList(): Response
    {
        if (!$this->rights['MEMBERLIST']) {
            return $this->redirectToHome();
        }

        $lists = $this->alliance()->memberLists();
        $memberList = $lists->members($this->allianceData);

        return $this->render('game/page.alliance.memberList.html.twig', [
            'memberList' => $memberList,
            'mainList' => $lists->mainAlliances($this->allianceData),
            'wingList' => $lists->wingAlliances($this->allianceData),
            'al_users_list' => $this->state->LNG->format('al_users_list', count($memberList)),
            'selfId' => $this->state->USER['id'],
            'timezone' => $this->state->USER['timezone'],
            'ShortStatus' => $lists->shortStatus(),
        ]);
    }

    /** leave the alliance (not for the leader) */
    #[GameRoute('game', 'game/alliance/leave', 'alliance', 'close', ['POST'])]
    public function close(): Response
    {
        $LNG = $this->state->LNG;

        if (!$this->hasAlliance || !$this->allianceData) {
            return $this->redirectToHome();
        }

        try {
            $this->alliance()->membership()->leave($this->allianceData);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->messageResponse(AllianceText::html($LNG, 'al_leave_sucess', $this->allianceData['ally_name']), [[
            'label' => $LNG['sys_back'],
            'url' => $this->router->generate('game_alliance_show'),
        ]]);
    }

    /**
     * Circular message dialog with the rank selection. Needs the ROUNDMAIL right.
     */
    #[GameRoute('game', 'game/alliance/circular', 'alliance', 'circular')]
    public function circular(): Response
    {
        $LNG = $this->state->LNG;
        if (!$this->rights['ROUNDMAIL']) {
            return $this->redirectToHome();
        }

        $this->setWindow('popup');

        return $this->render('game/page.alliance.circular.html.twig', [
            'RangeList' => [$LNG['al_all_players']] + $this->alliance()->repository()->rankNames($this->allianceData['id']),
        ]);
    }

    /**
     * Sends the circular message (request: rankID, 0 = all members, otherwise one rank; subject, text) through the
     * alliance messenger and answers JSON {message, error}.
     */
    #[GameRoute('game', 'game/alliance/circular', 'alliance', 'circularSend', ['POST'])]
    public function circularSend(): Response
    {
        $LNG = $this->state->LNG;
        if (!$this->rights['ROUNDMAIL']) {
            return $this->redirectToHome();
        }

        $rankId = $this->input()->int('rankID');
        $subject = $this->input()->string('subject', '', true);
        $text = $this->input()->string('text', $LNG['mg_no_subject'], true);

        try {
            $sendList = $this->alliance()->messenger()->sendCircular($this->allianceData, $rankId, $subject, $text);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->jsonResponse(['message' => $sendList, 'error' => false]);
    }

    /**
     * Alliance admin overview (needs the ADMIN right): settings form with the text selected by textMode (external, internal or application text).
     */
    #[GameRoute('game', 'game/alliance/admin', 'alliance', 'admin')]
    public function admin(): Response
    {
        if (!$this->hasAlliance || !$this->rights['ADMIN']) {
            return $this->redirectToHome();
        }

        $textMode = $this->input()->string('textMode', 'external');

        return $this->adminOverviewPage($textMode, $this->alliance()->settings()->textFor($this->allianceData, $textMode));
    }

    /**
     * Saves the alliance settings (needs the ADMIN right). Request: owner_range (founder title), web, image, request_notallow, ally_max_members,
     * request_min_points, stats, diplo, ally_tag, ally_name and the text selected by textMode (external, internal or application text).
     */
    #[GameRoute('game', 'game/alliance/admin', 'alliance', 'adminSend', ['POST'])]
    public function adminSend(): Response
    {
        if (!$this->hasAlliance || !$this->rights['ADMIN']) {
            return $this->redirectToHome();
        }

        $textMode = $this->input()->string('textMode', 'external');
        $input = [
            'owner_range' => $this->input()->string('owner_range', '', true),
            'web' => $this->input()->string('web'),
            'image' => $this->input()->string('image'),
            'request_notallow' => $this->input()->int('request_notallow'),
            'ally_max_members' => $this->input()->string('ally_max_members'),
            'request_min_points' => $this->input()->int('request_min_points'),
            'stats' => $this->input()->int('stats'),
            'diplo' => $this->input()->int('diplo'),
            'chat_slowmode' => $this->isLeader() && $this->input()->has('chat_slowmode') ? $this->input()->int('chat_slowmode') : null,
            'ally_tag' => $this->input()->string('ally_tag', (string) $this->allianceData['ally_tag'], UTF8_SUPPORT),
            'ally_name' => $this->input()->string('ally_name', (string) $this->allianceData['ally_name'], UTF8_SUPPORT),
            'text' => $this->input()->string('text', '', true),
            'textMode' => $textMode,
        ];
        try {
            $this->allianceData = $this->alliance()->settings()->save($this->allianceData, $input);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->adminOverviewPage($textMode, $input['text']);
    }

    /** the founder of the alliance (the only one who may change the chat slow mode) */
    private function isLeader(): bool
    {
        return $this->allianceData && $this->allianceData['ally_owner'] == $this->state->USER['id'];
    }

    /** the settings form of the admin overview with `$text` in the text area of `$textMode` */
    private function adminOverviewPage(string $textMode, mixed $text): Response
    {
        $LNG = $this->state->LNG;

        return $this->render('game/page.alliance.admin.overview.html.twig', [
            'RequestSelector' => [0 => $LNG['al_requests_allowed'], 1 => $LNG['al_requests_not_allowed']],
            'YesNoSelector' => [1 => $LNG['al_go_out_yes'], 0 => $LNG['al_go_out_no']],
            'textMode' => $textMode,
            'text' => $text,
            'ally_tag' => $this->allianceData['ally_tag'],
            'ally_name' => $this->allianceData['ally_name'],
            'ally_web' => AllianceText::httpUrlOrEmpty((string) $this->allianceData['ally_web']),
            'ally_image' => AllianceText::httpUrlOrEmpty((string) $this->allianceData['ally_image']),
            'ally_request_notallow' => $this->allianceData['ally_request_notallow'],
            'ally_members' => $this->allianceData['ally_members'],
            'ally_max_members' => $this->allianceData['ally_max_members'],
            'ally_request_min_points' => $this->allianceData['ally_request_min_points'],
            'ally_owner_range' => $this->allianceData['ally_owner_range'],
            'ally_stats_data' => $this->allianceData['ally_stats'],
            'ally_diplo_data' => $this->allianceData['ally_diplo'],
            'isLeader' => $this->isLeader(),
            'chatSlowmodes' => $this->slowmodeChoices(),
            'ally_chat_slowmode' => (int) ($this->allianceData['chat_slowmode'] ?? 0),
        ]);
    }

    /** @return array<int, string> the slow mode choices (seconds => label) */
    private function slowmodeChoices(): array
    {
        $LNG = $this->state->LNG;

        return [
            0 => $LNG['al_chat_slowmode_off'],
            10 => $LNG['al_chat_slowmode_10'],
            30 => $LNG['al_chat_slowmode_30'],
            60 => $LNG['al_chat_slowmode_60'],
            120 => $LNG['al_chat_slowmode_120'],
            300 => $LNG['al_chat_slowmode_300'],
        ];
    }

    /** disband the alliance (leader only, silently ignored otherwise) */
    #[GameRoute('game', 'game/alliance/admin/disband', 'alliance', 'adminClose', ['POST'])]
    public function adminClose(): Response
    {
        if ($this->allianceData) {
            $this->alliance()->membership()->disband($this->allianceData);
        }

        return $this->redirectToHome();
    }

    /**
     * Form to hand the leadership to another member; only the founder may.
     */
    #[GameRoute('game', 'game/alliance/admin/transfer', 'alliance', 'adminTransfer')]
    public function adminTransfer(): Response
    {
        if (!$this->allianceData || $this->allianceData['ally_owner'] != $this->state->USER['id']) {
            return $this->redirectToHome();
        }

        return $this->transferForm();
    }

    /**
     * Transfers the leadership to another member (request: newleader); only the founder may. Without a choice the form is shown again.
     */
    #[GameRoute('game', 'game/alliance/admin/transfer', 'alliance', 'adminTransferSend', ['POST'])]
    public function adminTransferSend(): Response
    {
        if (!$this->allianceData || $this->allianceData['ally_owner'] != $this->state->USER['id']) {
            return $this->redirectToHome();
        }

        $newLeader = $this->input()->int('newleader');
        if (empty($newLeader)) {
            return $this->transferForm();
        }

        try {
            $this->alliance()->membership()->transfer($this->allianceData, $newLeader);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->redirectToHome();
    }

    private function transferForm(): Response
    {
        return $this->render('game/page.alliance.admin.transfer.html.twig', [
            'transferUserList' => $this->alliance()->membership()->transferCandidates($this->allianceData),
        ]);
    }

    /** the application pages need both rights; the redirect home when one is missing */
    private function requireApplicationRights(): ?Response
    {
        if (!$this->rights['SEEAPPLY'] || !$this->rights['MANAGEAPPLY']) {
            return $this->redirectToHome();
        }

        return null;
    }

    /**
     * List of open applications (needs the SEEAPPLY and MANAGEAPPLY rights).
     */
    #[GameRoute('game', 'game/alliance/admin/applications', 'alliance', 'adminManageApply')]
    public function adminManageApply(): Response
    {
        if ($denied = $this->requireApplicationRights()) {
            return $denied;
        }

        return $this->render('game/page.alliance.admin.manageApply.html.twig', [
            'applyList' => $this->alliance()->applications()->listFor($this->allianceData),
        ]);
    }

    /**
     * One application (path: id) with the applicant's message and points.
     */
    #[GameRoute('game', 'game/alliance/admin/applications/{id}', 'alliance', 'adminDetailApply', requirements: ['id' => '\d+'])]
    public function adminDetailApply(): Response
    {
        $LNG = $this->state->LNG;
        if ($denied = $this->requireApplicationRights()) {
            return $denied;
        }

        $applications = $this->alliance()->applications();
        $applyDetail = $applications->find($this->allianceData, $this->input()->int('id'));

        if (empty($applyDetail)) {
            return $this->messageResponse($LNG['al_apply_not_exists'], [[
                'label' => $LNG['sys_back'],
                'url' => $this->router->generate('game_alliance_admin_manage_apply'),
            ]]);
        }

        $playerCard = (new PlayerCardController())->withGameServicesOf($this);
        $playerCard->initialize();

        return $this->render('game/page.alliance.admin.detailApply.html.twig', $applications->detailVariables($applyDetail, $playerCard));
    }

    /**
     * Accepts or rejects an application (path: id; request: answer, text) and notifies the applicant; redirects to the application list.
     */
    #[GameRoute('game', 'game/alliance/admin/applications/{id}/answer', 'alliance', 'adminAnswerApply', ['POST'], ['id' => '\d+'])]
    public function adminAnswerApply(): Response
    {
        if ($denied = $this->requireApplicationRights()) {
            return $denied;
        }

        $text = makebr($this->input()->string('text', '', true));
        $answer = $this->input()->string('answer');
        $applyID = $this->input()->int('id');

        try {
            $result = $this->alliance()->applications()->answer($this->allianceData, $applyID, $answer, $text);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        if ($result !== null) {
            // confirmation for the manager, in his own language
            $LNG = $this->state->LNG;

            return $this->messageResponse($result == 'yes' ? $LNG['al_apply_accepted'] : $LNG['al_apply_declined'], [[
                'label' => $LNG['sys_back'],
                'url' => $this->router->generate('game_alliance_admin_manage_apply'),
            ]]);
        }

        return $this->redirectToRoute('game_alliance_admin_manage_apply');
    }

    /**
     * Rank and rights table (needs the RANKS right).
     */
    #[GameRoute('game', 'game/alliance/admin/ranks', 'alliance', 'adminPermissions')]
    public function adminPermissions(): Response
    {
        if (!$this->rights['RANKS']) {
            return $this->redirectToHome();
        }

        return $this->render('game/page.alliance.admin.permissions.html.twig', $this->alliance()->ranks()->permissionPage($this->allianceData, $this->rights) + [
            'ownRights' => $this->rights,
            'ownRankId' => $this->state->USER['ally_rank_id'],
        ]);
    }

    /**
     * Saves the rank table (needs the RANKS right). Request: rank[] (rights per rank), newrank[] (ranks to create), deleteRank; redirects back to the table.
     */
    #[GameRoute('game', 'game/alliance/admin/ranks', 'alliance', 'adminPermissionsSend', ['POST'])]
    public function adminPermissionsSend(): Response
    {
        if (!$this->rights['RANKS']) {
            return $this->redirectToHome();
        }

        $newRank = $this->input()->stringMap('newrank', true);
        $delete = $this->input()->int('deleteRank');
        $rankData = $this->input()->nested('rank');
        $ranks = $this->alliance()->ranks();

        try {
            if (!empty($newRank['rankName'])) {
                $ranks->create($this->allianceData, $this->rights, $newRank);
            } elseif (!empty($delete)) {
                $ranks->delete($this->allianceData, $this->rights, $delete);
            } else {
                $ranks->update($this->allianceData, $this->rights, $rankData);
            }
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->redirectToRoute('game_alliance_admin_permissions');
    }

    /**
     * Member administration list (needs MANAGEUSERS) with the rank choices the acting member may hand out; kick buttons need the KICK right.
     */
    #[GameRoute('game', 'game/alliance/admin/members', 'alliance', 'adminMembers')]
    public function adminMembers(): Response
    {
        $LNG = $this->state->LNG;
        if (!$this->rights['MANAGEUSERS']) {
            return $this->redirectToHome();
        }

        $lists = $this->alliance()->memberLists();
        $choices = $this->alliance()->ranks()->memberChoices($this->allianceData, $this->rights);
        $memberList = $lists->adminMembers($this->allianceData);

        return $this->render('game/page.alliance.admin.members.html.twig', [
            'memberList' => $memberList,
            'rankList' => $choices['rankList'],
            'rankSelectList' => $choices['rankSelectList'],
            'founder' => empty($this->allianceData['ally_owner_range'])
                ? $LNG['al_founder_rank_text'] : $this->allianceData['ally_owner_range'],
            'al_users_list' => $LNG->format('al_users_list', count($memberList)),
            'canKick' => $this->rights['KICK'],
            'ShortStatus' => $lists->shortStatus(),
        ]);
    }

    /**
     * Assigns ranks to members (needs MANAGEUSERS; request rank[] member id => rank id); answers JSON with the saved message.
     */
    #[GameRoute('game', 'game/alliance/admin/members/rank', 'alliance', 'adminRank', ['POST'])]
    public function adminRank(): Response
    {
        if (!$this->rights['MANAGEUSERS']) {
            return $this->redirectToHome();
        }

        $this->alliance()->ranks()->assign($this->allianceData, $this->rights, $this->input()->intMap('rank'));

        return $this->jsonResponse($this->state->LNG['al_saved']);
    }

    /**
     * Removes member `id` from the alliance (needs the KICK right; the membership service refuses members the acting rank may not kick); redirects to the member list.
     */
    #[GameRoute('game', 'game/alliance/admin/members/{id}/kick', 'alliance', 'adminKick', ['POST'], ['id' => '\d+'])]
    public function adminKick(): Response
    {
        if (!$this->rights['KICK']) {
            return $this->redirectToHome();
        }

        try {
            $this->alliance()->membership()->kick($this->allianceData, $this->rights, $this->input()->int('id'));
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->redirectToRoute('game_alliance_admin_members');
    }

    /** the redirect home when the player lacks the diplomatic right, null otherwise */
    private function requireDiplomaticRight(): ?Response
    {
        if (!$this->rights['DIPLOMATIC']) {
            return $this->redirectToHome();
        }

        return null;
    }

    /**
     * Diplomacy overview (needs the diplomatic right): relations and open proposals.
     */
    #[GameRoute('game', 'game/alliance/admin/diplomacy', 'alliance', 'adminDiplomacy')]
    public function adminDiplomacy(): Response
    {
        if ($denied = $this->requireDiplomaticRight()) {
            return $denied;
        }

        return $this->render('game/page.alliance.admin.diplomacy.default.html.twig', [
            'diploList' => $this->alliance()->diplomacy()->overview($this->allianceData),
            'diploExpires' => $this->alliance()->diplomacy()->expiries($this->allianceData),
        ]);
    }

    /** accept / reject / delete a treaty by its id (method: accept|reject|delete of AllianceDiplomacyService) */
    private function diplomacyAction(string $method): Response
    {
        if ($denied = $this->requireDiplomaticRight()) {
            return $denied;
        }

        try {
            $this->alliance()->diplomacy()->$method($this->allianceData, $this->input()->int('id'));
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->redirectToRoute('game_alliance_admin_diplomacy');
    }

    #[GameRoute('game', 'game/alliance/admin/diplomacy/{id}/accept', 'alliance', 'adminDiplomacyAccept', ['POST'], ['id' => '\d+'])]
    public function adminDiplomacyAccept(): Response
    {
        return $this->diplomacyAction('accept');
    }

    #[GameRoute('game', 'game/alliance/admin/diplomacy/{id}/reject', 'alliance', 'adminDiplomacyReject', ['POST'], ['id' => '\d+'])]
    public function adminDiplomacyReject(): Response
    {
        return $this->diplomacyAction('reject');
    }

    #[GameRoute('game', 'game/alliance/admin/diplomacy/{id}/delete', 'alliance', 'adminDiplomacyDelete', ['POST'], ['id' => '\d+'])]
    public function adminDiplomacyDelete(): Response
    {
        return $this->diplomacyAction('delete');
    }

    /**
     * Form for a new diplomatic proposal (request: diploMode, id of the other alliance).
     */
    #[GameRoute('game', 'game/alliance/admin/diplomacy/create', 'alliance', 'adminDiplomacyCreate')]
    public function adminDiplomacyCreate(): Response
    {
        if ($denied = $this->requireDiplomaticRight()) {
            return $denied;
        }
        $this->setWindow('popup');

        $diplomaticMode = $this->input()->int('diploMode');
        $form = $this->alliance()->diplomacy()->createFormData($diplomaticMode, $this->input()->int('id'));

        return $this->render('game/page.alliance.admin.diplomacy.create.html.twig', [
            'diploLevel' => $form['diploLevel'],
            'diploMode' => $diplomaticMode,
            'AllyList' => $form['AllyList'],
            'IdList' => $form['IdList'],
        ]);
    }

    /**
     * Stores a diplomatic proposal (request: ally_id = tag or name of the other alliance, level, text); answers JSON {error, message}.
     */
    #[GameRoute('game', 'game/alliance/admin/diplomacy/create', 'alliance', 'adminDiplomacyCreateSend', ['POST'])]
    public function adminDiplomacyCreateSend(): Response
    {
        if ($denied = $this->requireDiplomaticRight()) {
            return $denied;
        }

        $id = $this->input()->string('ally_id', '', UTF8_SUPPORT);
        $level = $this->input()->int('level');
        $text = $this->input()->string('text', '', true);
        $expiresDays = $this->input()->int('expires');

        try {
            $this->alliance()->diplomacy()->create($this->allianceData, $id, $level, $text, $expiresDays);
        } catch (AllianceException $exit) {
            return $this->exitWith($exit);
        }

        return $this->jsonResponse([
            'error' => false,
            'message' => $this->state->LNG['al_diplo_create_done'],
        ]);
    }
}
