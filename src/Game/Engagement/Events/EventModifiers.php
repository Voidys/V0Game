<?php

declare(strict_types=1);

namespace App\Game\Engagement\Events;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Factors of the universe events that run right now. factor() is 1.0 when the feature is off or no event runs;
 * overlapping events multiply (product clamped to 0.25 - 4.0). The running events are read once per universe and
 * then memoised for the lifetime of the instance (one request).
 */
final class EventModifiers
{
    public const EXPEDITION_FIND = 'expedition_find';
    public const FLEET_SPEED = 'fleet_speed';
    public const TRADER_COST = 'trader_cost';
    public const SOLAR = 'solar';
    public const PIRATE_STRENGTH = 'pirate_strength';
    public const PIRATE_LOOT = 'pirate_loot';
    public const BUILD_SPEED = 'build_speed';
    public const RESEARCH_SPEED = 'research_speed';

    /** every factor key an event may carry */
    public const KEYS = [
        self::EXPEDITION_FIND, self::FLEET_SPEED, self::TRADER_COST, self::SOLAR,
        self::PIRATE_STRENGTH, self::PIRATE_LOOT, self::BUILD_SPEED, self::RESEARCH_SPEED,
    ];

    public const MIN_FACTOR = 0.5;
    public const MAX_FACTOR = 3.0;
    public const MIN_PRODUCT = 0.25;
    public const MAX_PRODUCT = 4.0;

    /** @var array<int, list<array<string, mixed>>> running events per universe */
    private array $running = [];

    /** @var array<int, list<array<string, mixed>>> events of the last 30 days and later per universe */
    private array $recentEvents = [];

    /** @var array<string, float> window averages by universe:key:from:to */
    private array $windows = [];

    /** @var array<int, array<string, float>> combined factors per universe */
    private array $combined = [];

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /** Combined factor of the key for the universe: 1.0 when the feature is off or no event with that key runs. */
    public function factor(int $universe, string $key): float
    {
        return $this->combined($universe)[$key] ?? 1.0;
    }

    /**
     * Events running now, earliest first, with decoded modifiers.
     *
     * @return list<array<string, mixed>>
     */
    public function running(int $universe): array
    {
        if (!isset($this->running[$universe])) {
            $this->running[$universe] = [];
            if ((bool) ($this->configs->get($universe)->events_enabled ?? false)) {
                $now = $this->state->runtime->timestamp;
                $rows = $this->db->select(
                    'SELECT * FROM %%ENG_EVENTS%% WHERE universe = :uni AND starts_at <= :start AND ends_at > :end ORDER BY starts_at;',
                    [':uni' => $universe, ':start' => $now, ':end' => $now],
                );
                foreach ($rows as $row) {
                    $row['modifiers'] = self::decode((string) $row['modifiers']);
                    $this->running[$universe][] = $row;
                }
            }
        }

        return $this->running[$universe];
    }

    /**
     * Time-weighted average factor of the key over the window [$from, $to]: the window is split at the event
     * boundaries, each segment uses the product of the active factors (like factor(), clamped to 0.25 - 4.0), 1.0
     * outside events. Used where lazily integrated production must not be applied retroactively with today's factor.
     * Events are read once per universe and request (those that ended within the last 30 days or run later).
     */
    public function factorOver(int $universe, string $key, int $from, int $to): float
    {
        if ($to <= $from || !(bool) ($this->configs->get($universe)->events_enabled ?? false)) {
            return $this->factor($universe, $key);
        }
        $cacheKey = $universe . ':' . $key . ':' . $from . ':' . $to;
        if (isset($this->windows[$cacheKey])) {
            return $this->windows[$cacheKey];
        }

        $events = [];
        foreach ($this->recent($universe) as $row) {
            if (isset($row['modifiers'][$key]) && (int) $row['starts_at'] < $to && (int) $row['ends_at'] > $from) {
                $events[] = [(int) $row['starts_at'], (int) $row['ends_at'], $row['modifiers'][$key]];
            }
        }
        $points = [$from, $to];
        foreach ($events as [$start, $end]) {
            foreach ([$start, $end] as $point) {
                if ($point > $from && $point < $to) {
                    $points[] = $point;
                }
            }
        }
        sort($points);

        $sum = 0.0;
        for ($i = 0, $n = count($points) - 1; $i < $n; $i++) {
            $length = $points[$i + 1] - $points[$i];
            if ($length <= 0) {
                continue;
            }
            $product = 1.0;
            foreach ($events as [$start, $end, $value]) {
                if ($start <= $points[$i] && $end >= $points[$i + 1]) {
                    $product *= $value;
                }
            }
            $sum += min(self::MAX_PRODUCT, max(self::MIN_PRODUCT, $product)) * $length;
        }

        return $this->windows[$cacheKey] = $sum / ($to - $from);
    }

    /**
     * Events of the universe that ended within the last 30 days or have not ended yet, with decoded modifiers (one query per universe).
     *
     * @return list<array<string, mixed>>
     */
    private function recent(int $universe): array
    {
        if (!isset($this->recentEvents[$universe])) {
            $rows = $this->db->select(
                'SELECT modifiers, starts_at, ends_at FROM %%ENG_EVENTS%% WHERE universe = :uni AND ends_at > :since;',
                [':uni' => $universe, ':since' => $this->state->runtime->timestamp - 30 * 86400],
            );
            foreach ($rows as &$row) {
                $row['modifiers'] = self::decode((string) $row['modifiers']);
            }
            $this->recentEvents[$universe] = $rows;
        }

        return $this->recentEvents[$universe];
    }

    /** Forgets the memoised events (after the admin changed them within the same request). */
    public function reset(): void
    {
        $this->running = [];
        $this->combined = [];
        $this->windows = [];
        $this->recentEvents = [];
    }

    /**
     * Decodes a stored modifiers JSON to key => factor, dropping unknown keys and clamping every factor to the single-event bounds.
     *
     * @return array<string, float>
     */
    public static function decode(string $json): array
    {
        $out = [];
        foreach ((array) json_decode($json, true) as $key => $value) {
            if (in_array($key, self::KEYS, true) && is_numeric($value)) {
                $out[$key] = min(self::MAX_FACTOR, max(self::MIN_FACTOR, (float) $value));
            }
        }

        return $out;
    }

    /** @return array<string, float> */
    private function combined(int $universe): array
    {
        if (!isset($this->combined[$universe])) {
            $product = [];
            foreach ($this->running($universe) as $event) {
                foreach ($event['modifiers'] as $key => $value) {
                    $product[$key] = ($product[$key] ?? 1.0) * $value;
                }
            }
            $this->combined[$universe] = array_map(
                static fn(float $v): float => min(self::MAX_PRODUCT, max(self::MIN_PRODUCT, $v)),
                $product,
            );
        }

        return $this->combined[$universe];
    }
}
