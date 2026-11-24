<?php

declare(strict_types=1);

namespace App\Game\Engagement\Events;

use App\Game\Core\Language;
use App\Game\Engagement\EngagementFeature;

/**
 * Universe events: timed events planned by the admin that change a few game factors while they run (see EventModifiers
 * for the factors and where they apply). This class holds the presets, validation and storage used by the admin page,
 * the banner data of the player pages and the tick that announces starts and ends exactly once (news entry, Discord).
 */
final class UniverseEvents extends EngagementFeature
{
    /** longest event the admin may plan */
    public const MAX_SECONDS = 14 * 86400;

    /** furthest start the admin may plan ahead */
    public const MAX_AHEAD = 90 * 86400;

    /** preset => factors; "custom" starts with every factor at 1.0 */
    public const PRESETS = [
        'ion_storm' => [EventModifiers::EXPEDITION_FIND => 1.25, EventModifiers::FLEET_SPEED => 0.85],
        'trade_fair' => [EventModifiers::TRADER_COST => 0.9],
        'solar_maximum' => [EventModifiers::SOLAR => 1.2],
        'pirate_surge' => [EventModifiers::PIRATE_STRENGTH => 1.3, EventModifiers::PIRATE_LOOT => 1.3],
        'speed_weekend' => [EventModifiers::BUILD_SPEED => 1.5, EventModifiers::RESEARCH_SPEED => 1.5, EventModifiers::FLEET_SPEED => 1.25],
        'custom' => [],
    ];

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'events_enabled', false);
    }

    /**
     * Announces events that started and marks the ones that ended; every step claims its row first
     * (UPDATE ... WHERE announced_x = 0), so it runs once even when two ticks overlap.
     */
    public function tick(int $universe, int $now): void
    {
        if (!$this->enabled($universe)) {
            return;
        }

        $started = $this->db->select(
            'SELECT * FROM %%ENG_EVENTS%% WHERE universe = :uni AND starts_at <= :now AND announced_start = 0 ORDER BY starts_at;',
            [':uni' => $universe, ':now' => $now],
        );
        foreach ($started as $event) {
            // announced_start 1 = announced, 2 = claimed silently (it was over before the tick saw it: no start, no end message)
            $over = (int) $event['ends_at'] <= $now;
            if ($this->claim((int) $event['id'], 'announced_start', $over ? 2 : 1) && !$over) {
                $this->announceStart($universe, $event);
            }
        }

        $ended = $this->db->select(
            'SELECT * FROM %%ENG_EVENTS%% WHERE universe = :uni AND ends_at <= :now AND announced_end = 0;',
            [':uni' => $universe, ':now' => $now],
        );
        foreach ($ended as $event) {
            if ($this->claim((int) $event['id'], 'announced_end', 1) && (int) $event['announced_start'] === 1) {
                $this->announceEnd($universe, $event);
            }
        }
    }

    /**
     * Banner data for the overview: the running events and the ones starting within events_show_upcoming_hours,
     * each with title, effects in words and the start/end time. Empty when the feature is off or nothing is planned.
     *
     * @return list<array<string, mixed>>
     */
    public function banner(int $universe, int $now, Language $LNG): array
    {
        if (!$this->enabled($universe)) {
            return [];
        }

        $hours = max(0, (int) $this->setting($universe, 'events_show_upcoming_hours', 24));
        $rows = $this->db->select(
            'SELECT * FROM %%ENG_EVENTS%% WHERE universe = :uni AND ends_at > :now AND starts_at <= :limit ORDER BY starts_at;',
            [':uni' => $universe, ':now' => $now, ':limit' => $now + $hours * 3600],
        );
        $out = [];
        foreach ($rows as $row) {
            $running = (int) $row['starts_at'] <= $now;
            $out[] = [
                'title' => $this->titleOf($row, $LNG),
                'effects' => self::describe(EventModifiers::decode((string) $row['modifiers']), $LNG),
                'running' => $running,
                'startsIn' => max(0, (int) $row['starts_at'] - $now),
                'endsIn' => max(0, (int) $row['ends_at'] - $now),
            ];
        }

        return $out;
    }

    /**
     * Effects in words, e.g. "Expedition finds +25 %".
     *
     * @param array<string, float> $modifiers
     * @return list<string>
     */
    public static function describe(array $modifiers, Language $LNG): array
    {
        $out = [];
        foreach (EventModifiers::KEYS as $key) {
            if (isset($modifiers[$key]) && abs($modifiers[$key] - 1.0) > 0.0001) {
                $out[] = $LNG['ev_fx_' . $key] . ' ' . sprintf('%+d', (int) round(($modifiers[$key] - 1) * 100)) . ' %';
            }
        }

        return $out;
    }

    /** Title of the event, the translated preset name when the admin left it empty. */
    public function titleOf(array $event, Language $LNG): string
    {
        $title = trim((string) $event['title']);

        return $title !== '' ? $title : (string) $LNG['ev_preset_' . $event['preset']];
    }

    /**
     * Validates the admin form. Rules: preset whitelisted, ends_at after starts_at and at most 14 days later, every
     * factor within 0.5 - 3.0 (unknown keys are dropped), at least one factor different from 1.0.
     *
     * @param array<string, mixed> $post preset, title, factor[key], starts_at / ends_at as timestamps (already parsed)
     * @return array{0: array<string, mixed>, 1: list<string>} the clean values and the LNG keys of the errors
     */
    public function validate(array $post, int $starts, int $ends, int $now): array
    {
        $errors = [];
        $preset = (string) ($post['preset'] ?? 'custom');
        if (!isset(self::PRESETS[$preset])) {
            $errors[] = 'ev_err_preset';
            $preset = 'custom';
        }

        $modifiers = [];
        foreach (EventModifiers::KEYS as $key) {
            $raw = is_array($post['factor'] ?? null) ? ($post['factor'][$key] ?? 1) : 1;
            $value = is_numeric($raw) ? (float) $raw : 1.0;
            if ($value < EventModifiers::MIN_FACTOR || $value > EventModifiers::MAX_FACTOR) {
                $errors[] = 'ev_err_factor';
            }
            $value = min(EventModifiers::MAX_FACTOR, max(EventModifiers::MIN_FACTOR, round($value, 2)));
            if (abs($value - 1.0) > 0.0001) {
                $modifiers[$key] = $value;
            }
        }
        if ($modifiers === []) {
            $errors[] = 'ev_err_no_effect';
        }
        if ($starts <= 0 || $ends <= $starts) {
            $errors[] = 'ev_err_time';
        } elseif ($ends - $starts > self::MAX_SECONDS) {
            $errors[] = 'ev_err_length';
        } elseif ($ends <= $now) {
            $errors[] = 'ev_err_past';
        } elseif ($starts > $now + self::MAX_AHEAD) {
            $errors[] = 'ev_err_far';
        }
        $title = mb_substr(trim(strip_tags((string) ($post['title'] ?? ''))), 0, 80);
        if ($title === '') {
            $errors[] = 'ev_err_title';
        }

        return [[
            'preset' => $preset,
            'title' => $title,
            'modifiers' => $modifiers,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ], array_values(array_unique($errors))];
    }

    /**
     * Inserts (id 0) or updates an event. An update that changes the times resets the announcement flags that are
     * still in the future, so a rescheduled event is announced when it really starts.
     *
     * @param array<string, mixed> $values the result of validate()
     */
    public function save(int $id, int $universe, array $values, int $now): int
    {
        $params = [
            ':preset' => $values['preset'], ':title' => $values['title'], ':mods' => json_encode($values['modifiers']),
            ':start' => $values['starts_at'], ':end' => $values['ends_at'],
        ];
        if ($id === 0) {
            $this->db->insert(
                'INSERT INTO %%ENG_EVENTS%% (universe, preset, title, modifiers, starts_at, ends_at, created_at)
                VALUES (:uni, :preset, :title, :mods, :start, :end, :now);',
                $params + [':uni' => $universe, ':now' => $now],
            );

            return $this->db->lastInsertId();
        }

        $this->db->update(
            'UPDATE %%ENG_EVENTS%% SET preset = :preset, title = :title, modifiers = :mods, starts_at = :start, ends_at = :end,
                announced_start = IF(:start2 > :now, 0, announced_start), announced_end = IF(:end2 > :now2, 0, announced_end)
            WHERE id = :id AND universe = :uni;',
            $params + [':start2' => $values['starts_at'], ':end2' => $values['ends_at'], ':now' => $now, ':now2' => $now, ':id' => $id, ':uni' => $universe],
        );

        return $id;
    }

    /** Ends a running event now (the tick announces the end). */
    public function endNow(int $id, int $universe, int $now): bool
    {
        $this->db->update(
            'UPDATE %%ENG_EVENTS%% SET ends_at = :end WHERE id = :id AND universe = :uni AND starts_at <= :start AND ends_at > :end2;',
            [':end' => $now, ':id' => $id, ':uni' => $universe, ':start' => $now, ':end2' => $now],
        );

        return $this->db->rowCount() === 1;
    }

    /** Deletes an event. */
    public function delete(int $id, int $universe): bool
    {
        $this->db->delete('DELETE FROM %%ENG_EVENTS%% WHERE id = :id AND universe = :uni;', [':id' => $id, ':uni' => $universe]);

        return $this->db->rowCount() === 1;
    }

    /**
     * Sets one announcement flag when it is still 0 (the claim); false when another tick was faster.
     */
    private function claim(int $id, string $column, int $value = 1): bool
    {
        $this->db->update("UPDATE %%ENG_EVENTS%% SET `$column` = :v WHERE id = :id AND `$column` = 0;", [':id' => $id, ':v' => $value]);

        return $this->db->rowCount() === 1;
    }

    /** News entry and Discord message of a started event. */
    private function announceStart(int $universe, array $event): void
    {
        $LNG = $this->state->LNG;
        $title = $this->titleOf($event, $LNG);
        $effects = self::describe(EventModifiers::decode((string) $event['modifiers']), $LNG);
        $until = $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $event['ends_at']);
        $text = $LNG->format('ev_news_start_text', $title, $until) . "\n\n" . implode("\n", $effects);

        $this->postNews($LNG->format('ev_news_start_title', $title), $text);
        $this->discordMessage($universe, $LNG->format('ev_news_start_title', $title), ['effects' => implode(', ', $effects), 'until' => $until]);
    }

    /** Discord message of an ended event. */
    private function announceEnd(int $universe, array $event): void
    {
        $LNG = $this->state->LNG;
        $this->discordMessage($universe, $LNG->format('ev_news_end_title', $this->titleOf($event, $LNG)), []);
    }

    /**
     * @param array<int|string, string> $lines
     */
    private function discordMessage(int $universe, string $title, array $lines): void
    {
        if (!(bool) $this->setting($universe, 'events_discord', true)) {
            return;
        }
        $this->discord()->sendLog($title, $lines === [] ? ['status' => 'ended'] : $lines);
    }

    /** Publishes a news entry (the login page news list). */
    private function postNews(string $title, string $text): void
    {
        $now = $this->state->runtime->timestamp;
        $this->db->insert(
            "INSERT INTO %%NEWS%% (user, date, title, summary, text, status, pinned, updated_at, format, image)
            VALUES (:user, :date, :title, '', :text, 1, 0, :date2, 'text', '');",
            [':user' => 'events', ':date' => $now, ':title' => mb_substr($title, 0, 160), ':text' => $text, ':date2' => $now],
        );
    }
}
