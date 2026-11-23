<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Pure builder of the JSON payload of the live event feed (`/game/events`): turns the event table of
 * FlyingFleetsTable::renderTable() into plain-text, structured entries plus a hash of the list, so the
 * client re-renders only when something changed. Contains no HTML: the client writes the strings with textContent.
 */
final class LiveEventsPayload
{
    /**
     * @param array<string, array<string, mixed>> $table event table keyed by event time + fleet id
     * @return array{events: list<array<string, mixed>>, hostile: int, hash: string}
     */
    public static function build(array $table): array
    {
        $events = [];
        $hostile = 0;
        foreach ($table as $key => $row) {
            $isHostile = (bool) ($row['hostile'] ?? false);
            $hostile += $isHostile ? 1 : 0;
            $events[] = [
                'key'       => (string) $key,
                'id'        => (int) ($row['id'] ?? 0),
                'mission'   => (int) ($row['mission'] ?? 0),
                'class'     => (string) ($row['cssClass'] ?? ''),
                'own'       => (bool) ($row['own'] ?? false),
                'hostile'   => $isHostile,
                'returning' => (bool) ($row['returning'] ?? false),
                'origin'    => $row['origin'] ?? null,
                'target'    => $row['target'] ?? null,
                'arrival'   => (int) ($row['returntime'] ?? 0),
                'rest'      => (int) ($row['resttime'] ?? 0),
                'text'      => self::plainText((string) ($row['text'] ?? '')),
            ];
        }

        return ['events' => $events, 'hostile' => $hostile, 'hash' => self::hash($events)];
    }

    /** Hash over everything that is rendered except the moving countdown (`rest`). @param list<array<string, mixed>> $events */
    public static function hash(array $events): string
    {
        foreach ($events as &$event) {
            unset($event['rest']);
        }

        return md5((string) json_encode($events));
    }

    /** Event line without markup: tags removed (line breaks become " / "), entities decoded. */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>(\s*<br\s*/?>)*#i', ' / ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
