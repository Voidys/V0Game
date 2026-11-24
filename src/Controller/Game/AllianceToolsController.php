<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Controller\RequireModule;
use App\Game\Alliance\AllianceActivityService;
use App\Game\Alliance\AllianceServices;
use App\Game\Alliance\AllianceTargetService;
use App\Game\Alliance\AllianceText;
use App\Game\Core\AcsService;
use App\Game\Core\EndOfRequest;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alliance tools for members: the shared target list (claim / unclaim), the ACS overview, the member activity grid
 * and the notes pages. Everything below /game/alliance/{targets,acs,activity,wiki}; every state change is a POST route.
 *
 * Rights (alliance_ranks columns, the leader holds all): TARGETS manages every target, ACTIVITY sees the activity grid,
 * WIKI edits the notes pages. Reading targets, ACS groups and notes is open to all members. The rules live in
 * AllianceTargetService, AllianceActivityService and AllianceWikiService.
 */
#[RequireModule('MODULE_ALLIANCE')]
class AllianceToolsController extends AbstractGameController
{
    private ?AllianceServices $services = null;
    /** @var array<string, mixed> */
    private array $allianceData = [];
    /** @var array<string, bool|int|string> */
    private array $rights = [];

    /** Only for alliance members: loads the own alliance and the player's rank rights, everybody else lands on the alliance page. */
    public function initialize(): void
    {
        parent::initialize();
        if ($this->state->USER['ally_id'] == 0) {
            throw EndOfRequest::withResponse($this->redirectToRoute('game_alliance_show'));
        }
        $alliance = $this->alliance()->repository()->find((int) $this->state->USER['ally_id']);
        if (!$alliance) {
            throw EndOfRequest::withResponse($this->redirectToRoute('game_alliance_show'));
        }
        $this->allianceData = $alliance;
        $this->rights = $this->alliance()->repository()->rightsOf($this->state->USER, $alliance);
    }

    private function alliance(): AllianceServices
    {
        return $this->services ??= new AllianceServices($this->db, $this->configs, $this->state);
    }

    private function allianceId(): int
    {
        return (int) $this->allianceData['id'];
    }

    private function userId(): int
    {
        return (int) $this->state->USER['id'];
    }

    /** @return array<string, bool> which tool the player may use, for the tool navigation of every page */
    private function nav(): array
    {
        return ['activity' => (bool) $this->rights['ACTIVITY'], 'wikiEdit' => (bool) $this->rights['WIKI']];
    }

    /** message page for a refused action with a button back to `$route` */
    private function refused(string $key, string $route): Response
    {
        return $this->messageResponse($this->state->LNG[$key], [['url' => $this->router->generate($route), 'label' => $this->state->LNG['al_back']]]);
    }

    // ----- targets ---------------------------------------------------------------------------------------------

    /** The shared target list of the alliance with the form to add one. */
    #[GameRoute('game', 'game/alliance/targets', 'alliance', 'targets')]
    public function targets(): Response
    {
        return $this->render('game/page.alliance.targets.html.twig', [
            'targets' => $this->alliance()->targets()->list($this->allianceId()),
            'canManage' => (bool) $this->rights['TARGETS'],
            'selfId' => $this->userId(),
            'nav' => $this->nav(),
            'noteLength' => AllianceTargetService::NOTE_LENGTH,
            'claimHours' => [1, 2, 4, 8, 12, 24, 48, AllianceTargetService::MAX_CLAIM_HOURS],
            'now' => $this->state->runtime->timestamp,
            'maxGalaxy' => $this->configs->get()->max_galaxy,
            'maxSystem' => $this->configs->get()->max_system,
            'maxPlanets' => $this->configs->get()->max_planets + 3,
        ]);
    }

    /** Adds a target (request: galaxy, system, planet, planet_type, note). */
    #[GameRoute('game', 'game/alliance/targets', 'alliance', 'targetAdd', ['POST'])]
    public function targetAdd(): Response
    {
        $input = $this->input();
        $result = $this->alliance()->targets()->add(
            $this->allianceId(),
            $this->userId(),
            $input->int('galaxy'),
            $input->int('system'),
            $input->int('planet'),
            $input->int('planet_type', 1),
            $input->string('note', '', true),
        );

        return $this->targetAnswer($result);
    }

    /** Claims a target for `hours` hours. */
    #[GameRoute('game', 'game/alliance/targets/{id}/claim', 'alliance', 'targetClaim', ['POST'], ['id' => '\d+'])]
    public function targetClaim(): Response
    {
        return $this->targetAnswer($this->alliance()->targets()->claim(
            $this->allianceId(),
            $this->input()->int('id'),
            $this->userId(),
            $this->input()->int('hours', 1),
        ));
    }

    /** Lifts a claim (the claimer or a manager). */
    #[GameRoute('game', 'game/alliance/targets/{id}/unclaim', 'alliance', 'targetUnclaim', ['POST'], ['id' => '\d+'])]
    public function targetUnclaim(): Response
    {
        return $this->targetAnswer($this->alliance()->targets()->unclaim(
            $this->allianceId(),
            $this->input()->int('id'),
            $this->userId(),
            (bool) $this->rights['TARGETS'],
        ));
    }

    /** Deletes a target (a manager, or the member who added it). */
    #[GameRoute('game', 'game/alliance/targets/{id}/delete', 'alliance', 'targetDelete', ['POST'], ['id' => '\d+'])]
    public function targetDelete(): Response
    {
        return $this->targetAnswer($this->alliance()->targets()->delete(
            $this->allianceId(),
            $this->input()->int('id'),
            $this->userId(),
            (bool) $this->rights['TARGETS'],
        ));
    }

    /** back to the list on success, a message page naming the refusal otherwise */
    private function targetAnswer(string $result): Response
    {
        if ($result === AllianceTargetService::OK) {
            return $this->redirectToRoute('game_alliance_targets');
        }

        return $this->refused('alt_target_' . $result, 'game_alliance_targets');
    }

    // ----- ACS overview and activity ---------------------------------------------------------------------------

    /** Running ACS groups of the alliance members: target, countdown to the arrival, participants, link to join. */
    #[GameRoute('game', 'game/alliance/acs', 'alliance', 'acs')]
    public function acs(): Response
    {
        $now = $this->state->runtime->timestamp;
        $groups = (new AcsService($this->db, $this->configs, $this->state))->groupsForAlliance($this->allianceId(), (int) $this->state->USER['id']);
        foreach ($groups as &$group) {
            $group['remaining'] = max(0, (int) $group['ankunft'] - $now);
        }
        unset($group);

        return $this->render('game/page.alliance.acs.html.twig', [
            'groups' => $groups,
            'nav' => $this->nav(),
            'selfAlly' => $this->allianceId(),
        ]);
    }

    /** Member activity in coarse buckets (needs the ACTIVITY right). */
    #[GameRoute('game', 'game/alliance/activity', 'alliance', 'activity')]
    public function activity(): Response
    {
        if (!$this->rights['ACTIVITY']) {
            return $this->redirectToRoute('game_alliance_show');
        }

        return $this->render('game/page.alliance.activity.html.twig', [
            'grid' => $this->alliance()->activity()->grid($this->allianceId()),
            'buckets' => AllianceActivityService::BUCKETS,
            'nav' => $this->nav(),
        ]);
    }

    // ----- notes pages -----------------------------------------------------------------------------------------

    /** The notes pages of the alliance (list, no page open). */
    #[GameRoute('game', 'game/alliance/wiki', 'alliance', 'wiki')]
    public function wiki(): Response
    {
        return $this->wikiPage(null);
    }

    /** One notes page with the list next to it. */
    #[GameRoute('game', 'game/alliance/wiki/{id}', 'alliance', 'wikiPage', requirements: ['id' => '\d+'])]
    public function wikiShow(): Response
    {
        $page = $this->alliance()->wiki()->page($this->allianceId(), $this->input()->int('id'));
        if ($page === null) {
            return $this->redirectToRoute('game_alliance_wiki');
        }

        return $this->wikiPage($page);
    }

    /** @param array<string, mixed>|null $page */
    private function wikiPage(?array $page): Response
    {
        if ($page !== null) {
            $page['rendered'] = AllianceText::multiline($page['body']);
        }

        return $this->render('game/page.alliance.wiki.html.twig', [
            'pages' => $this->alliance()->wiki()->pages($this->allianceId()),
            'page' => $page,
            'canEdit' => (bool) $this->rights['WIKI'],
            'nav' => $this->nav(),
        ]);
    }

    /** The form for a new notes page (needs the WIKI right). */
    #[GameRoute('game', 'game/alliance/wiki/new', 'alliance', 'wikiNew')]
    public function wikiNew(): Response
    {
        return $this->wikiForm(['id' => 0, 'title' => '', 'body' => '']);
    }

    /** The form to edit a notes page (needs the WIKI right). */
    #[GameRoute('game', 'game/alliance/wiki/{id}/edit', 'alliance', 'wikiEdit', requirements: ['id' => '\d+'])]
    public function wikiEdit(): Response
    {
        $page = $this->alliance()->wiki()->page($this->allianceId(), $this->input()->int('id'));
        if ($page === null) {
            return $this->redirectToRoute('game_alliance_wiki');
        }

        return $this->wikiForm($page);
    }

    /** @param array<string, mixed> $page */
    private function wikiForm(array $page): Response
    {
        if (!$this->rights['WIKI']) {
            return $this->redirectToRoute('game_alliance_wiki');
        }

        return $this->render('game/page.alliance.wikiEdit.html.twig', [
            'page' => $page,
            'nav' => $this->nav(),
        ]);
    }

    /** Saves a notes page (request: id (0 = new), title, body; needs the WIKI right). */
    #[GameRoute('game', 'game/alliance/wiki/save', 'alliance', 'wikiSave', ['POST'])]
    public function wikiSave(): Response
    {
        if (!$this->rights['WIKI']) {
            return $this->redirectToRoute('game_alliance_wiki');
        }

        $id = $this->alliance()->wiki()->save(
            $this->allianceId(),
            $this->userId(),
            $this->input()->int('id'),
            $this->input()->string('title', '', true),
            $this->input()->string('body', '', true),
        );
        if ($id <= 0) {
            return $this->refused($id < 0 ? 'alt_wiki_full' : 'alt_wiki_invalid', 'game_alliance_wiki');
        }

        return $this->redirectToRoute('game_alliance_wiki_page', ['id' => $id]);
    }

    /** Deletes a notes page (needs the WIKI right). */
    #[GameRoute('game', 'game/alliance/wiki/{id}/delete', 'alliance', 'wikiDelete', ['POST'], ['id' => '\d+'])]
    public function wikiDelete(): Response
    {
        if ($this->rights['WIKI']) {
            $this->alliance()->wiki()->delete($this->allianceId(), $this->input()->int('id'));
        }

        return $this->redirectToRoute('game_alliance_wiki');
    }
}
