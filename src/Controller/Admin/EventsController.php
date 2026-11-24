<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Engagement\Events\EventModifiers;
use App\Game\Engagement\Events\UniverseEvents;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Universe events of the universe the admin acts in: list (upcoming, running, past), create from a preset, edit,
 * end a running event early and delete. The factors apply through EventModifiers while an event runs.
 */
class EventsController extends AbstractAdminController
{
    /** The list; `saved`, `ended` and `deleted` show a notice. */
    #[GameRoute('admin', 'admin/events', 'events')]
    public function show(): Response
    {
        return $this->renderList();
    }

    /** The form of a new event; `preset` fills the factors of a preset. */
    #[GameRoute('admin', 'admin/events/new', 'events', 'create')]
    public function create(): Response
    {
        $preset = (string) $this->input()->string('preset');
        $preset = isset(UniverseEvents::PRESETS[$preset]) ? $preset : 'custom';
        $now = $this->state->runtime->timestamp;

        return $this->renderEditor([
            'id' => 0, 'preset' => $preset, 'title' => $preset === 'custom' ? '' : (string) $this->state->LNG['ev_preset_' . $preset], 'modifiers' => UniverseEvents::PRESETS[$preset],
            'starts_at' => $now, 'ends_at' => $now + 86400,
        ], []);
    }

    /** The form of the event `id`; an unknown event shows the list. */
    #[GameRoute('admin', 'admin/events/{id}/edit', 'events', 'edit', requirements: ['id' => '\d+'])]
    public function edit(): Response
    {
        $event = $this->find($this->input()->int('id'));
        if ($event === null) {
            return $this->renderList();
        }
        $event['modifiers'] = EventModifiers::decode((string) $event['modifiers']);

        return $this->renderEditor($event, []);
    }

    /**
     * Validates the form (id 0 = new) and stores the event; errors show the form again with the entered values.
     * Times are entered in the admin's time zone.
     */
    #[GameRoute('admin', 'admin/events/save', 'events', 'save', ['POST'])]
    public function save(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $post = $this->state->requestPost ?? [];
        $id = $this->input()->int('id');
        if ($id > 0 && $this->find($id) === null) {
            return $this->redirectToRoute('admin_events_show');
        }

        $starts = $this->parseTime((string) ($post['starts_at'] ?? ''));
        $ends = $this->parseTime((string) ($post['ends_at'] ?? ''));
        [$values, $errors] = $this->universeEvents()->validate($post, $starts, $ends, $this->state->runtime->timestamp);
        if ($errors !== []) {
            return $this->renderEditor(['id' => $id] + $values, $errors);
        }

        $this->universeEvents()->save($id, $uni, $values, $this->state->runtime->timestamp);

        return $this->redirectToRoute('admin_events_show', ['saved' => 1]);
    }

    /** Ends a running event now. */
    #[GameRoute('admin', 'admin/events/end', 'events', 'end', ['POST'])]
    public function end(): Response
    {
        $done = $this->universeEvents()->endNow($this->input()->int('id'), (int) $this->universe()->getEmulated(), $this->state->runtime->timestamp);

        return $this->redirectToRoute('admin_events_show', $done ? ['ended' => 1] : ['missing' => 1]);
    }

    /** Deletes an event. */
    #[GameRoute('admin', 'admin/events/delete', 'events', 'delete', ['POST'])]
    public function delete(): Response
    {
        $done = $this->universeEvents()->delete($this->input()->int('id'), (int) $this->universe()->getEmulated());

        return $this->redirectToRoute('admin_events_show', $done ? ['deleted' => 1] : ['missing' => 1]);
    }

    /** @return array<string, mixed>|null the event of the acting universe */
    private function find(int $id): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT * FROM %%ENG_EVENTS%% WHERE id = :id AND universe = :uni;',
            [':id' => $id, ':uni' => (int) $this->universe()->getEmulated()],
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /** Timestamp of a datetime-local value in the admin's time zone (0 when invalid). */
    private function parseTime(string $value): int
    {
        try {
            $zone = new \DateTimeZone($this->state->USER['timezone'] ?? date_default_timezone_get());
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $zone);
        } catch (\Exception) {
            return 0;
        }

        return $date === false ? 0 : $date->getTimestamp();
    }

    /** datetime-local value of a timestamp in the admin's time zone. */
    private function formatTime(int $time): string
    {
        $zone = new \DateTimeZone($this->state->USER['timezone'] ?? date_default_timezone_get());

        return (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format('Y-m-d\TH:i');
    }

    /**
     * @param array<string, mixed> $event
     * @param list<string> $errors LNG keys
     */
    private function renderEditor(array $event, array $errors): Response
    {
        $LNG = $this->state->LNG;
        $event['starts_value'] = $this->formatTime((int) $event['starts_at']);
        $event['ends_value'] = $this->formatTime((int) $event['ends_at']);
        $presets = [];
        foreach (UniverseEvents::PRESETS as $key => $factors) {
            $presets[$key] = ['label' => $LNG['ev_preset_' . $key], 'factors' => $factors];
        }
        $factors = [];
        foreach (EventModifiers::KEYS as $key) {
            $factors[] = ['key' => $key, 'label' => $LNG['ev_fx_' . $key], 'value' => $event['modifiers'][$key] ?? 1.0];
        }

        return $this->render('admin/EventsPage.html.twig', [
            'editor' => true,
            'event' => $event,
            'eventErrors' => array_map(static fn(string $key): string => $LNG[$key], $errors),
            'eventPresets' => $presets,
            'eventFactors' => $factors,
            'eventMin' => EventModifiers::MIN_FACTOR,
            'eventMax' => EventModifiers::MAX_FACTOR,
        ]);
    }

    /** The events of the universe in three groups: running, upcoming, past (latest 30). */
    private function renderList(): Response
    {
        $LNG = $this->state->LNG;
        $uni = (int) $this->universe()->getEmulated();
        $now = $this->state->runtime->timestamp;
        $timezone = $this->state->USER['timezone'];
        $groups = ['running' => [], 'upcoming' => [], 'past' => []];

        $rows = $this->db->select('SELECT * FROM %%ENG_EVENTS%% WHERE universe = :uni ORDER BY starts_at DESC LIMIT 100;', [':uni' => $uni]);
        foreach ($rows as $row) {
            $group = (int) $row['ends_at'] <= $now ? 'past' : ((int) $row['starts_at'] > $now ? 'upcoming' : 'running');
            $row['name'] = $this->universeEvents()->titleOf($row, $LNG);
            $row['effects'] = UniverseEvents::describe(EventModifiers::decode((string) $row['modifiers']), $LNG);
            $row['start'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $row['starts_at'], $timezone);
            $row['end'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $row['ends_at'], $timezone);
            $groups[$group][] = $row;
        }
        $groups['upcoming'] = array_reverse($groups['upcoming']);
        $groups['past'] = array_slice($groups['past'], 0, 30);

        $notice = $this->input()->int('missing') !== 0 ? $LNG['ev_missing'] : ($this->input()->int('saved') !== 0 ? $LNG['ev_saved']
            : ($this->input()->int('ended') !== 0 ? $LNG['ev_ended'] : ($this->input()->int('deleted') !== 0 ? $LNG['ev_deleted'] : null)));

        return $this->render('admin/EventsPage.html.twig', [
            'editor' => false,
            'eventGroups' => $groups,
            'eventNotice' => $notice,
            'eventsOn' => $this->universeEvents()->enabled($uni),
            'eventPresetKeys' => array_keys(UniverseEvents::PRESETS),
        ]);
    }
}
