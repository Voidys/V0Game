<?php

namespace App\Controller\Admin;

use App\Game\Engagement\Derelicts\Derelicts;
use App\Game\Engagement\Mothership\Mothership;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Spawn buttons and the live list of the map content features: derelict fields and the pirate mothership. */
class MapContentController extends AbstractAdminController
{
    /** The page of the emulated universe (admins switch it with ?uni=, like every admin page). */
    #[GameRoute('admin', 'admin/map-content', 'mapContent')]
    public function show(): Response
    {
        return $this->renderPage();
    }

    /** POST: spawns a round of derelict fields now (ignores the interval; the feature has to be enabled). */
    #[GameRoute('admin', 'admin/map-content/derelicts', 'mapContent', 'derelicts', ['POST'])]
    public function derelicts(): Response
    {
        $universe = $this->universeId();
        $derelicts = new Derelicts($this->db, $this->configs, $this->state);
        if (!$derelicts->enabled($universe)) {
            return $this->renderPage($this->state->LNG['map_content_disabled'], 'error');
        }
        $count = $derelicts->spawn($universe, $this->state->runtime->timestamp);

        return $this->renderPage($count > 0 ? $this->state->LNG->format('map_content_spawned', $count) : $this->state->LNG['map_content_nothing_spawned'], $count > 0 ? 'success' : 'error');
    }

    /** POST: spawns the pirate mothership now (ignores the interval; only when none lives and the feature is enabled). */
    #[GameRoute('admin', 'admin/map-content/mothership', 'mapContent', 'mothership', ['POST'])]
    public function mothership(): Response
    {
        $universe = $this->universeId();
        $mothership = new Mothership($this->db, $this->configs, $this->state);
        if (!$mothership->enabled($universe)) {
            return $this->renderPage($this->state->LNG['map_content_disabled'], 'error');
        }
        $id = $mothership->spawn($universe, $this->state->runtime->timestamp);

        return $this->renderPage($id !== null ? $this->state->LNG->format('map_content_spawned', 1) : $this->state->LNG['map_content_nothing_spawned'], $id !== null ? 'success' : 'error');
    }

    private function universeId(): int
    {
        return (int) $this->universe()->getEmulated();
    }

    /**
     * Renders the page with the live derelict fields and mothership of the universe, the spawn buttons and an optional result message.
     */
    private function renderPage(string $message = '', string $messageType = ''): Response
    {
        $universe = $this->universeId();
        $now = $this->state->runtime->timestamp;
        $derelicts = new Derelicts($this->db, $this->configs, $this->state);
        $mothership = new Mothership($this->db, $this->configs, $this->state);
        $live = $derelicts->live($universe);
        foreach ($live as &$row) {
            $row['hours_left'] = max(0, (int) ceil(((int) $row['expires_at'] - $now) / 3600));
        }
        unset($row);
        $ship = $mothership->live($universe);
        if ($ship !== null) {
            $ship['hours_left'] = max(0, (int) ceil(((int) $ship['expires_at'] - $now) / 3600));
        }

        return $this->render('admin/MapContent.html.twig', [
            'universe' => $universe,
            'derelictsEnabled' => $derelicts->enabled($universe),
            'mothershipEnabled' => $mothership->enabled($universe),
            'derelicts' => $live,
            'mothership' => $ship,
            'message' => $message,
            'messageType' => $messageType,
        ]);
    }
}
